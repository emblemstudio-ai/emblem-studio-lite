(function () {
	'use strict';

	var CFG = window.EmblemStudioBuy || {};
	var KEY = 'es';

	var MAX_AGE = 6 * 3600;

	var SAFE = /^[A-Za-z0-9_\-.:]{1,120}$/;

	var tag = '';
	var pendingAnchor = '';

	function now() { return Math.floor(Date.now() / 1000); }

	function fresh(value) {
		var m = /\.(\d{9,12})$/.exec(value);
		if (!m) { return true; }
		var at = parseInt(m[1], 10);
		var age = now() - at;

		return age >= -3600 && age <= MAX_AGE;
	}

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

	function hostOf(path) {
		for (var i = 0; i < path.length; i++) {
			var node = path[i];
			if (node && node.classList && node.classList.contains('esf-host')) { return node; }
		}

		return null;
	}

	function optedOut(el) {
		var node = el;
		while (node && node.getAttribute) {
			var want = (node.getAttribute('data-es-buy') || '').toLowerCase();
			if (want === 'off' || want === 'no' || want === '0' || want === 'none') { return true; }
			node = node.parentElement;
		}

		return false;
	}

	function tagFor(path) {
		var host = hostOf(path);
		if (!host) { return tag; }
		if (host.getAttribute('data-esf-track-purchases') !== '1') { return tag; }

		var id = host.getAttribute('data-esf-id') || '';
		if (!id) { return tag; }
		tag = id + '.' + now();

		return tag;
	}

	function sameSite(url) {
		try {
			var u = new URL(url, window.location.href);

			return (u.protocol === 'http:' || u.protocol === 'https:') && u.host === window.location.host;
		} catch (e) { return false; }
	}

	function withTag(url, value) {
		var u;
		try { u = new URL(url, window.location.href); } catch (e) { return url; }

		var parts = (u.hash || '').replace(/^#/, '').split('&').filter(function (p) {
			return p !== '' && p.indexOf(KEY + '=') !== 0;
		});
		parts.push(KEY + '=' + encodeURIComponent(value));
		u.hash = parts.join('&');

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

		if (addButton) {
			addButton.setAttribute('data-es_source', value);
			if (window.jQuery) {
				try { window.jQuery(addButton).data('es_source', value); } catch (err) { }
			}
		}

		if (link && sameSite(link.href)) {
			link.setAttribute('href', withTag(link.href, value));
		}
	}

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

	function stampLastLine(productId) {
		if (!tag || !CFG.ajaxUrl) { return; }
		try {
			var body = new URLSearchParams();
			body.append('action', 'es_click_tag');
			body.append('es_source', tag);
			if (productId) { body.append('product_id', String(productId)); }
			fetch(CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
		} catch (e) { }
	}

	function watchAjaxAdds() {
		if (window.jQuery) {
			try {
				window.jQuery(document.body).on('added_to_cart', function (event, fragments, hash, button) {
					var id = button && window.jQuery(button).data('product_id');
					stampLastLine(id);
				});
			} catch (e) { }
		}

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
