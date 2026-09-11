/**
 * Forms & Assets — live hover thumbnail.
 *
 * On hover over an asset name, lazily fetch its render payload (html +
 * Design-System-composed css, via admin-ajax) and mount it into a Shadow DOM
 * node IN the page — no iframe, no front-end bootstrap — scaled into a small
 * popover. Skips the slow part of the real previewer, so the peek is instant;
 * re-hovering is cached. Mirrors the SaaS AssetHoverPreview + embed.js mount.
 */
( function () {
	'use strict';
	var CFG = window.EmblemStudioPreview;
	if ( ! CFG || ! CFG.ajaxUrl ) {
		return;
	}

	var SCALE    = 450 / 690; // = original 300/460; widen the render VIEWPORT (not the zoom) so the preview gets wider, not taller. Ribbons double both widths (below).
	// pointer-events:none matters: all:initial resets pointer-events back to auto
	// on the host, and the host is the full render-width — so for a narrow, centred
	// asset (e.g. a slide-in) the empty space around it would otherwise capture
	// clicks/hovers, an invisible dead layer over the page. This hover card is
	// display-only; the live frontend keeps its own (interactive) reset.
	var BOOT_CSS = ':host{all:initial!important;display:block!important;width:100%!important;box-sizing:border-box!important;pointer-events:none!important;}';

	var cache       = {}; // id -> Promise<{html, css}>
	var fontsLoaded = {};
	var current     = null; // active popover element

	function loadFontImport( rule ) {
		if ( /^\s*@charset/i.test( rule ) ) {
			return;
		}
		var hm   = /url\(\s*['"]?([^'")]+)['"]?\s*\)|@import\s+['"]([^'"]+)['"]/i.exec( rule );
		var href = hm ? ( hm[1] || hm[2] ) : '';
		if ( ! href || fontsLoaded[ href ] ) {
			return;
		}
		fontsLoaded[ href ] = true;
		var link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = href;
		link.media = 'print'; // non-blocking; flipped to all once loaded
		link.onload = function () { link.media = 'all'; };
		( document.head || document.documentElement ).appendChild( link );
	}

	// A shadow-root <style> that declares @font-face registers that font for
	// the WHOLE document per spec, not just its own tree — so an unscoped name
	// is a shared global slot. Two renders with different CSS (e.g. the live
	// "current" asset vs. an older revision that references the same font
	// NAME but never carried its own @font-face) would then either borrow
	// each other's registration or stomp on it depending on mount/unmount
	// order — a revision with no font of its own should consistently show the
	// fallback, not sometimes-right depending on which sibling preview
	// happens to be open. So instead of lifting @font-face rules as-written,
	// we scope each one to a name unique to ITS OWN content (family + a hash
	// of its own rule text), rewrite every font-family reference to that name
	// within this SAME css string, then lift the renamed rules into one
	// shared, persistent <style> (deduped by exact rule text). Only a render
	// that carries the identical @font-face rule(s) can ever resolve to that
	// scoped name.
	var liftedFontFaceRules = {};
	var fontFaceStyleEl = null;
	function hashStr( s ) {
		var h = 5381;
		for ( var i = 0; i < s.length; i++ ) h = ( h * 33 ) ^ s.charCodeAt( i );
		return ( h >>> 0 ).toString( 36 );
	}
	function escapeRegExp( s ) {
		return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}
	function scopeAndLiftFontFaces( css ) {
		var blockRe = /@font-face\s*\{[^{}]*\}/gi;
		var familyRe = /font-family\s*:\s*(['"]?)([^;'"]+?)\1\s*;/i;
		var blocksByFamily = {};
		var m;
		while ( ( m = blockRe.exec( css ) ) !== null ) {
			var fm = familyRe.exec( m[0] );
			if ( ! fm ) continue;
			var family = fm[2].trim();
			if ( ! blocksByFamily[ family ] ) blocksByFamily[ family ] = [];
			blocksByFamily[ family ].push( m[0] );
		}
		var families = Object.keys( blocksByFamily );
		if ( ! families.length ) return css;
		for ( var fi = 0; fi < families.length; fi++ ) {
			var fam = families[ fi ];
			var scoped = fam + '__es' + hashStr( blocksByFamily[ fam ].join( '\n' ) );
			var nameRe = new RegExp( "(['\"]?)\\b" + escapeRegExp( fam ) + "\\b\\1", 'g' );
			css = css.replace( nameRe, function ( _all, q ) { return q + scoped + q; } );
		}
		return css.replace( blockRe, function ( block ) {
			if ( ! liftedFontFaceRules[ block ] ) {
				liftedFontFaceRules[ block ] = true;
				if ( ! fontFaceStyleEl ) {
					fontFaceStyleEl = document.createElement( 'style' );
					fontFaceStyleEl.setAttribute( 'data-es-lifted-fonts', '' );
					( document.head || document.documentElement ).appendChild( fontFaceStyleEl );
				}
				fontFaceStyleEl.appendChild( document.createTextNode( block ) );
			}
			return '';
		} );
	}

	function prepareCss( css, withBoot ) {
		css = css || '';
		css = scopeAndLiftFontFaces( css );
		var re = /^\s*(?:\/\*[\s\S]*?\*\/\s*)*(@charset\b[^;]*;|@import\b(?:\s*url\()?\s*(?:'[^']*'|"[^"]*"|[^;'")]*)(?:\s*\))?\s*[^;]*;)/i;
		var m;
		while ( ( m = re.exec( css ) ) !== null ) {
			loadFontImport( m[1] );
			css = css.slice( m.index + m[0].length );
		}
		return ( withBoot ? BOOT_CSS : '' ) + css;
	}

	function fetchPayload( id ) {
		if ( cache[ id ] ) {
			return cache[ id ];
		}
		var url = CFG.ajaxUrl + '?action=es_preview_payload&id=' + encodeURIComponent( id ) +
			'&nonce=' + encodeURIComponent( CFG.nonce );
		cache[ id ] = fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( 'preview failed' );
				}
				return { html: j.data.html || '', css: j.data.css || '' };
			} )
			// A FAILURE MUST NOT STAY CACHED. The rejected promise used to sit in
			// the cache for the life of the page, so one failed hover (an expired
			// nonce, a slow admin-ajax) broke that asset's preview until reload.
			// The background prefetch below would have made it worse: a failure
			// nobody saw would have broken the hover they tried next.
			.catch( function ( e ) {
				delete cache[ id ];
				throw e;
			} );
		return cache[ id ];
	}

	/**
	 * Warm the cache for the assets on the page, so the first hover is instant.
	 *
	 * The first hover used to wait for a whole admin-ajax round trip, which
	 * boots WordPress for every call and is the slow part of this peek. This
	 * does those trips ahead of time, after the page has settled. Same shape
	 * as the SaaS asset list: idle time, two at a time, thirty at most, and
	 * silent on failure, because a failed prefetch only means that asset loads
	 * on hover the way it always did. A hover that lands while its prefetch is
	 * in flight joins the same request, because they share the cache.
	 */
	function prefetch( names ) {
		var MAX     = 30;
		var AT_ONCE = 2;
		var seen    = {};
		var queue   = [];
		for ( var i = 0; i < names.length && queue.length < MAX; i++ ) {
			var id = names[ i ].getAttribute( 'data-es-preview-id' );
			if ( id && ! seen[ id ] && ! cache[ id ] ) {
				seen[ id ] = true;
				queue.push( id );
			}
		}
		function next() {
			var id = queue.shift();
			if ( ! id ) {
				return;
			}
			fetchPayload( id ).catch( function () {} ).then( next );
		}
		function start() {
			for ( var k = 0; k < AT_ONCE; k++ ) {
				next();
			}
		}
		if ( window.requestIdleCallback ) {
			window.requestIdleCallback( start, { timeout: 2000 } );
		} else {
			window.setTimeout( start, 600 );
		}
	}

	function mount( container, html, css ) {
		container.textContent = '';
		var host = document.createElement( 'div' );
		host.style.width = '100%';
		var shadow = host.attachShadow( { mode: 'open' } );
		var style = document.createElement( 'style' );
		style.textContent = prepareCss( css, true );
		shadow.appendChild( style );
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = html;
		shadow.appendChild( tpl.content );
		container.appendChild( host );
		return host;
	}

	function remove() {
		if ( current && current.parentNode ) {
			current.parentNode.removeChild( current );
		}
		current = null;
	}

	// The asset's real rendered box (union of the shadow root's top-level elements),
	// in VISIBLE (already-scaled) px, as an offset + size relative to the host's
	// top-left. Lets the card hug the actual content — no empty min-width/height
	// padding around a narrow or short asset.
	function contentBox( host ) {
		var hostRect = host.getBoundingClientRect();
		var sr = host.shadowRoot;
		if ( ! sr ) {
			return { x: 0, y: 0, w: hostRect.width, h: hostRect.height };
		}
		var L = Infinity, T = Infinity, R = -Infinity, B = -Infinity;
		var kids = sr.children;
		for ( var i = 0; i < kids.length; i++ ) {
			if ( kids[ i ].tagName === 'STYLE' ) {
				continue;
			}
			var r = kids[ i ].getBoundingClientRect();
			if ( ! r.width && ! r.height ) {
				continue;
			}
			if ( r.left < L ) { L = r.left; }
			if ( r.top < T ) { T = r.top; }
			if ( r.right > R ) { R = r.right; }
			if ( r.bottom > B ) { B = r.bottom; }
		}
		if ( L === Infinity ) {
			return { x: 0, y: 0, w: hostRect.width, h: hostRect.height };
		}
		return { x: L - hostRect.left, y: T - hostRect.top, w: R - L, h: B - T };
	}

	function show( anchor ) {
		remove();
		var id = anchor.getAttribute( 'data-es-preview-id' );
		if ( ! id ) {
			return;
		}
		// Render in a realistic viewport (ribbons are full-width → double it), then
		// hug the card to the asset's actual rendered box (measure below), no min.
		var wide     = anchor.getAttribute( 'data-es-preview-wide' ) === '1';
		var RENDER_W = wide ? 1380 : 690; // 1.5× the original 920 / 460 (wider layout, same zoom)
		var MAX_W    = wide ? 900 : 450;  // 1.5× the original 600 / 300 — the width cap
		var rect = anchor.getBoundingClientRect();

		var card = document.createElement( 'div' );
		// Transparent, un-rounded, un-clipped viewport — the asset's own box (its real
		// corners, background, shadow) is what shows, so the peek is accurate. A
		// container radius/clip would paint fake corners on a square asset and clip a
		// rounded/shadowed one. (Loading/"No preview" overlay below keeps its own bg.)
		card.style.cssText = 'position:fixed;z-index:99999;width:' + MAX_W + 'px;height:170px;' +
			'pointer-events:none;opacity:0;transition:opacity .12s ease;';
		// offWrap translates the scaled content so its top-left sits at the card corner.
		// Shape-accurate float: drop-shadow follows the asset's real painted outline
		// (rounded where rounded, square where square) — a float without imposing a box.
		var offWrap = document.createElement( 'div' );
		offWrap.style.filter = 'drop-shadow(0 12px 30px rgba(20,16,30,.20))';
		var scaler = document.createElement( 'div' );
		scaler.style.cssText = 'width:' + RENDER_W + 'px;transform:scale(' + SCALE + ');transform-origin:top left;';
		var hostWrap = document.createElement( 'div' );
		scaler.appendChild( hostWrap );
		offWrap.appendChild( scaler );
		card.appendChild( offWrap );

		var note = document.createElement( 'div' );
		note.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;' +
			'background:#fff;color:#94a3b8;font:13px system-ui,sans-serif;';
		note.textContent = '…';
		card.appendChild( note );

		document.body.appendChild( card );
		current = card;

		function position( w, h ) {
			var vw = window.innerWidth, vh = window.innerHeight, margin = 12;
			var left = rect.right + margin;
			if ( left + w + 8 > vw ) {
				left = Math.max( 8, rect.left - w - margin );
			}
			var top = rect.top - 6;
			if ( top + h + 8 > vh ) {
				top = Math.max( 8, vh - h - 8 );
			}
			card.style.left = left + 'px';
			card.style.top = top + 'px';
		}
		position( MAX_W, 170 );

		fetchPayload( id ).then( function ( p ) {
			if ( current !== card ) {
				return;
			}
			var host = mount( hostWrap, p.html, p.css );
			if ( note.parentNode ) {
				note.parentNode.removeChild( note );
			}
			card.style.opacity = '1';
			var measure = function () {
				if ( current !== card ) {
					return;
				}
				// Hug the asset's real (scaled) rendered box — no min — and offset so
				// its top-left sits at the card corner. So a ribbon isn't padded below,
				// and a narrow popup isn't padded at the sides.
				var box = contentBox( host );
				// Ribbons (wide) are full-bleed bars — always show the full frame width
				// so they don't get cramped; only narrow popups hug their content width.
				var w  = wide ? MAX_W : Math.min( box.w || MAX_W, MAX_W );
				var h  = Math.min( box.h || 200, window.innerHeight - 24 );
				var ox = wide ? 0 : box.x;
				card.style.width = w + 'px';
				card.style.height = h + 'px';
				offWrap.style.transform = 'translate(' + ( -ox ) + 'px,' + ( -box.y ) + 'px)';
				position( w, h );
			};
			requestAnimationFrame( measure );
			setTimeout( measure, 240 ); // re-measure once fonts settle
		} ).catch( function () {
			if ( current === card ) {
				note.textContent = 'No preview';
			}
		} );
	}

	function init() {
		var names = document.querySelectorAll( '[data-es-preview-id]' );
		for ( var i = 0; i < names.length; i++ ) {
			names[ i ].addEventListener( 'mouseenter', function ( e ) { show( e.currentTarget ); } );
			names[ i ].addEventListener( 'mouseleave', remove );
		}
		// The popover is anchored at hover time, so close it if the page scrolls.
		window.addEventListener( 'scroll', remove, true );
		prefetch( names );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
