( function () {
	'use strict';
	var CFG = window.EmblemStudioPreview;
	if ( ! CFG || ! CFG.ajaxUrl ) {
		return;
	}

	var SCALE    = 450 / 690;
	var BOOT_CSS = ':host{all:initial!important;display:block!important;width:100%!important;box-sizing:border-box!important;pointer-events:none!important;}';

	var CENTER_CSS = ':where(.esf-form){margin-inline:auto;}';

	var STRIP_H = 26;

	var cache       = {};
	var fontsLoaded = {};
	var current     = null;

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
		link.media = 'print';
		link.onload = function () { link.media = 'all'; };
		( document.head || document.documentElement ).appendChild( link );
	}

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
			css = css.replace(/(^|[;{\s])(font-family|font)(\s*:)([^;{}]*)/gi, function ( _d, pre, prop, colon, val ) {
				return pre + prop + colon + val.replace( nameRe, function ( _all, q ) { return q + scoped + q; } );
			} );
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
		return ( withBoot ? BOOT_CSS : '' ) + CENTER_CSS + css;
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
				return { html: j.data.html || '', css: j.data.css || '', shadow: j.data.shadow !== false };
			} )
			.catch( function ( e ) {
				delete cache[ id ];
				throw e;
			} );
		return cache[ id ];
	}

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

	var LIGHT_DOM_HOST_CSS = ':host{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif!important;color:#1d2327!important;}';

	function mount( container, html, css, shadowDom ) {
		container.textContent = '';
		var host = document.createElement( 'div' );
		host.style.width = '100%';
		var shadow = host.attachShadow( { mode: 'open' } );
		var style = document.createElement( 'style' );
		style.textContent = prepareCss( css, true ) + ( shadowDom === false ? LIGHT_DOM_HOST_CSS : '' );
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
		var wide     = anchor.getAttribute( 'data-es-preview-wide' ) === '1';
		var RENDER_W = wide ? 1380 : 690;
		var MAX_W    = wide ? 900 : 450;
		var rect = anchor.getBoundingClientRect();

		var card = document.createElement( 'div' );
		card.style.cssText = 'position:fixed;z-index:99999;width:' + MAX_W + 'px;height:' + ( 170 + STRIP_H ) + 'px;' +
			'pointer-events:none;opacity:0;transition:opacity .12s ease;';
		var offWrap = document.createElement( 'div' );
		offWrap.style.filter = 'drop-shadow(0 12px 30px rgba(20,16,30,.20))';
		offWrap.style.marginTop = STRIP_H + 'px';
		var scaler = document.createElement( 'div' );
		scaler.style.cssText = 'width:' + RENDER_W + 'px;transform:scale(' + SCALE + ');transform-origin:top left;';
		var hostWrap = document.createElement( 'div' );
		scaler.appendChild( hostWrap );
		offWrap.appendChild( scaler );
		card.appendChild( offWrap );

		var assetName = anchor.getAttribute( 'data-es-preview-name' ) || ( anchor.textContent || '' ).trim();
		var strip = document.createElement( 'div' );
		strip.style.cssText = 'position:absolute;top:0;left:0;right:0;z-index:1;height:' + STRIP_H + 'px;line-height:' + STRIP_H + 'px;' +
			'padding:0 10px;box-sizing:border-box;background:#1d2327;color:#fff;' +
			'font:600 11.5px/' + STRIP_H + 'px system-ui,-apple-system,"Segoe UI",sans-serif;' +
			'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
		strip.textContent = assetName;
		card.appendChild( strip );

		var note = document.createElement( 'div' );
		note.style.cssText = 'position:absolute;top:' + STRIP_H + 'px;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;' +
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
		position( MAX_W, 170 + STRIP_H );

		fetchPayload( id ).then( function ( p ) {
			if ( current !== card ) {
				return;
			}
			var host = mount( hostWrap, p.html, p.css, p.shadow );
			if ( note.parentNode ) {
				note.parentNode.removeChild( note );
			}
			card.style.opacity = '1';
			var measure = function () {
				if ( current !== card ) {
					return;
				}
				var box = contentBox( host );
				var w  = wide ? MAX_W : Math.min( box.w || MAX_W, MAX_W );
				var h  = Math.min( box.h || 200, window.innerHeight - 24 - STRIP_H );
				var ox = wide ? 0 : box.x;
				card.style.width = w + 'px';
				card.style.height = ( h + STRIP_H ) + 'px';
				offWrap.style.transform = 'translate(' + ( -ox ) + 'px,' + ( -box.y ) + 'px)';
				position( w, h + STRIP_H );
			};
			requestAnimationFrame( measure );
			setTimeout( measure, 240 );
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
		window.addEventListener( 'scroll', remove, true );
		prefetch( names );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
