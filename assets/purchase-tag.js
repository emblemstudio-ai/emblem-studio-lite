/**
 * Which asset sent somebody to the shop (SER-1348) — the browser half.
 *
 * TWIN FILE. The same logic lives in the SaaS at public/embed/embed.js, because a
 * connected site is served by that one instead of this. Change one, change both.
 *
 * WHAT IT DOES, IN ONE BREATH. Somebody clicks a link inside an asset that has
 * purchase tracking switched on. This writes a short tag into the address, after
 * the `#`, and carries it from page to page. When they put something in the basket,
 * the tag goes along and WordPress writes it on that basket line, hidden from the
 * customer. If they buy, the shop can say which asset brought them.
 *
 * WHY AFTER THE `#`. Everything after a `#` stays inside the browser. The server
 * never receives it, so no page cache can make a second copy of a page because of
 * it, and no visitor ever sees a link with tracking bolted onto the end. This was
 * the whole reason for the design (Alex, 2026-09-16): a query string would have
 * split the cache on every page of the site.
 *
 * WHAT IS NOT HERE, DELIBERATELY. No cookie, no local storage, no fingerprint,
 * nothing written to the visitor's device, and nothing about the person. The tag
 * names the ASSET and the moment, and it lasts for the visit.
 *
 * OFF UNLESS ASKED FOR. Nothing happens for an asset whose switch is off, and a
 * single link can opt out with data-es-buy="off". Not data-es-track, which the
 * click-tracking already uses to name a button.
 */
(function () {
	'use strict';

	var CFG = window.EmblemStudioBuy || {};
	var KEY = 'es';

	/* A tag older than this has stopped meaning anything. Somebody who clicked a
	 * ribbon at breakfast and buys after lunch was not sent by the ribbon, and the
	 * question being asked is about the visit, not the week (Alex: "within a limited
	 * time span"). The basket line keeps whatever tag it was given, so a slow
	 * checkout is safe; this only limits how long the tag keeps travelling. */
	var MAX_AGE = 6 * 3600;

	/* What a tag may contain. Anything else is not ours and is ignored. */
	var SAFE = /^[A-Za-z0-9_\-.:]{1,120}$/;

	var tag = '';
	var pendingAnchor = '';

	function now() { return Math.floor(Date.now() / 1000); }

	/** Fresh enough to still be travelling? An undated tag is taken at face value. */
	function fresh(value) {
		var m = /\.(\d{9,12})$/.exec(value);
		if (!m) { return true; }
		var at = parseInt(m[1], 10);
		var age = now() - at;

		return age >= -3600 && age <= MAX_AGE;
	}

	/**
	 * Read the tag out of the address and take our part of it back out again.
	 *
	 * The address the visitor sees stays clean: they arrive, this reads it, and the
	 * `#es=…` is gone before they look. A real anchor in the same fragment is put
	 * back, and jumped to by hand, because the browser will not jump to one that had
	 * our part stuck on the end.
	 */
	function readAddress() {
		var raw = (window.location.hash || '').replace(/^#/, '');
		if (raw.indexOf(KEY + '=') < 0) { return; }

		var keep = [];
		var found = '';
		raw.split('&').forEach(function (part) {
			if (part.indexOf(KEY + '=') === 0) {
				found = decodeURIComponent(part.slice(KEY.length + 1));
			} else if (part !== '') {
				keep.push(part);
			}
		});

		if (found && SAFE.test(found) && fresh(found)) {
			tag = found;
		}

		var rest = keep.join('&');
		if (window.history && window.history.replaceState) {
			window.history.replaceState(
				window.history.state,
				'',
				window.location.pathname + window.location.search + (rest ? '#' + rest : '')
			);
		}
		if (rest) {
			pendingAnchor = rest;
			jumpToAnchor();
		}
	}

	function jumpToAnchor() {
		if (!pendingAnchor) { return; }
		var el = null;
		try {
			el = document.getElementById(pendingAnchor) || document.querySelector('[name="' + pendingAnchor + '"]');
		} catch (e) { el = null; }
		if (!el) { return; }
		pendingAnchor = '';
		el.scrollIntoView();
	}

	/* ── Where a tag comes from ─────────────────────────────────────────────── */

	/** The Emblem Studio asset this click came out of, if it came out of one. */
	function hostOf(path) {
		for (var i = 0; i < path.length; i++) {
			var node = path[i];
			if (node && node.classList && node.classList.contains('esf-host')) { return node; }
		}

		return null;
	}

	/**
	 * This link, or something it sits inside, said "not this one".
	 *
	 * The walk stops by itself at an asset's edge, because the top element inside a
	 * shadow root has no parent element. So the same walk answers for a link in an
	 * asset and a link anywhere else on the page.
	 */
	function optedOut(el) {
		var node = el;
		while (node && node.getAttribute) {
			var want = (node.getAttribute('data-es-buy') || '').toLowerCase();
			if (want === 'off' || want === 'no' || want === '0' || want === 'none') { return true; }
			node = node.parentElement;
		}

		return false;
	}

	/**
	 * A click inside a tracked asset mints a new tag, and THE NEWEST CLICK WINS.
	 *
	 * Somebody who left a class in their basket last week and came back through a
	 * slide-in was brought back by the slide-in, so the newer asset takes the credit
	 * (Alex, 2026-09-16). Everywhere else on the site, the tag already in hand is
	 * simply carried along.
	 */
	function tagFor(path) {
		var host = hostOf(path);
		if (!host) { return tag; }
		if (host.getAttribute('data-esf-track-purchases') !== '1') { return tag; }

		var id = host.getAttribute('data-esf-id') || '';
		if (!id) { return tag; }
		tag = id + '.' + now();

		return tag;
	}

	/* ── Carrying it ────────────────────────────────────────────────────────── */

	function sameSite(url) {
		try {
			var u = new URL(url, window.location.href);

			return (u.protocol === 'http:' || u.protocol === 'https:') && u.host === window.location.host;
		} catch (e) { return false; }
	}

	/** The address with our tag in the fragment, and an existing anchor kept. */
	function withTag(url, value) {
		var u;
		try { u = new URL(url, window.location.href); } catch (e) { return url; }

		var parts = (u.hash || '').replace(/^#/, '').split('&').filter(function (p) {
			return p !== '' && p.indexOf(KEY + '=') !== 0;
		});
		parts.push(KEY + '=' + encodeURIComponent(value));
		u.hash = parts.join('&');

		// An add-to-cart link is a request the shop acts on, never a cached page, so
		// this one may also travel as an ordinary parameter — which is what lets
		// WordPress write the tag on the line it is about to create.
		if (u.searchParams.has('add-to-cart')) {
			u.searchParams.set('es_source', value);
		}

		return u.toString();
	}

	function onClick(e) {
		var path = (typeof e.composedPath === 'function') ? e.composedPath() : [e.target];
		var clicked = path[0] && path[0].nodeType === 1 ? path[0] : e.target;
		if (!clicked) { return; }

		var link = null;
		var addButton = null;
		for (var i = 0; i < path.length; i++) {
			var node = path[i];
			if (!node || !node.tagName) { continue; }
			if (!link && node.tagName === 'A' && node.getAttribute('href')) { link = node; }
			if (!addButton && node.classList && node.classList.contains('ajax_add_to_cart')) { addButton = node; }
			if (link && addButton) { break; }
		}
		if (!link && !addButton) { return; }
		if (optedOut(clicked)) { return; }

		var value = tagFor(path);
		if (!value) { return; }

		// The shop's own quick-add button sends its data-* attributes with the
		// request, so the tag rides along and the line is stamped as it is made.
		if (addButton) {
			addButton.setAttribute('data-es_source', value);
			if (window.jQuery) {
				try { window.jQuery(addButton).data('es_source', value); } catch (err) { /* keep going */ }
			}
		}

		if (link && sameSite(link.href)) {
			link.setAttribute('href', withTag(link.href, value));
		}
	}

	/**
	 * The classic add-to-cart form, posted rather than followed.
	 *
	 * Two things at once: a hidden field so the request itself carries the tag, and
	 * the tag on the form's own address so the page the shop sends them back to
	 * still has it. Without the second one the tag is lost on the first add, which
	 * is exactly what the first test caught.
	 */
	function onSubmit(e) {
		if (!tag) { return; }
		var form = e.target;
		if (!form || form.tagName !== 'FORM' || form.method && form.method.toLowerCase() !== 'post') { return; }
		if (!form.querySelector('[name="add-to-cart"]') && !form.classList.contains('cart')) { return; }
		if (optedOut(form)) { return; }

		var field = form.querySelector('input[name="es_source"]');
		if (!field) {
			field = document.createElement('input');
			field.type = 'hidden';
			field.name = 'es_source';
			form.appendChild(field);
		}
		field.value = tag;

		var action = form.getAttribute('action') || window.location.href;
		if (sameSite(action)) {
			form.setAttribute('action', withTag(action, tag));
		}
	}

	/* ── The safety net ─────────────────────────────────────────────────────── */

	/**
	 * Some baskets are filled by a path this cannot see: a block-built shop talking
	 * to the Store API, a theme's own quick-add, a funnel plugin. The browser says
	 * "the thing that just went in belongs to this asset" and WordPress stamps the
	 * matching line. A missing tag is silent, so this is worth the one request.
	 */
	function stampLastLine(productId) {
		if (!tag || !CFG.ajaxUrl) { return; }
		try {
			var body = new URLSearchParams();
			body.append('action', 'es_click_tag');
			body.append('es_source', tag);
			if (productId) { body.append('product_id', String(productId)); }
			fetch(CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
		} catch (e) { /* the sale still happens; the credit is what is lost */ }
	}

	function watchAjaxAdds() {
		if (window.jQuery) {
			try {
				window.jQuery(document.body).on('added_to_cart', function (event, fragments, hash, button) {
					var id = button && window.jQuery(button).data('product_id');
					stampLastLine(id);
				});
			} catch (e) { /* keep going */ }
		}

		// A block-built shop adds through the Store API, which fires none of the
		// old events. Watching the request is the only way to know it happened.
		if (typeof window.fetch !== 'function') { return; }
		var original = window.fetch;
		window.fetch = function (input, init) {
			var url = '';
			try { url = typeof input === 'string' ? input : (input && input.url) || ''; } catch (e) { url = ''; }
			var isAdd = url.indexOf('/wc/store/') > -1 && url.indexOf('/cart/add-item') > -1;
			var result = original.apply(this, arguments);
			if (!isAdd || !tag) { return result; }

			return result.then(function (response) {
				if (response && response.ok) { stampLastLine(0); }

				return response;
			});
		};
	}

	/* ── Start ──────────────────────────────────────────────────────────────── */

	readAddress();
	window.addEventListener('hashchange', readAddress);
	document.addEventListener('click', onClick, true);
	document.addEventListener('submit', onSubmit, true);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { jumpToAnchor(); watchAjaxAdds(); });
	} else {
		jumpToAnchor();
		watchAjaxAdds();
	}
})();
