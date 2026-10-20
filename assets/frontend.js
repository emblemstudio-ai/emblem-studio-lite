(function () {
	'use strict';

	const ajax = (window.EmblemStudio && window.EmblemStudio.ajaxUrl) || '/wp-admin/admin-ajax.php';
	const postId = (window.EmblemStudio && window.EmblemStudio.postId) || 0;
	const IN_ADMIN_FRAME = (function () {
		if (window.top === window.self) return false;
		try {
			if (window.top.location.pathname.indexOf('/wp-admin/') !== -1) return true;
		} catch (e) { }
		return String(document.referrer || '').indexOf('/wp-admin/') !== -1;
	})();


	const BOOT_CSS = ':host{all:initial!important;display:block!important;width:100%!important;box-sizing:border-box!important;isolation:isolate!important;}';

	const IBEAM = "url(\"data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='16'%20height='16'%3E%3Cg%20fill='none'%20stroke-linecap='round'%3E%3Cpath%20stroke='%23ffffff'%20stroke-width='2'%20stroke-opacity='0.85'%20d='M7%202.5h3M7%2014.5h3M8.5%202.5v12'/%3E%3Cpath%20stroke='%2352585b'%20stroke-width='1'%20d='M7%202.5h3M7%2014.5h3M8.5%202.5v12'/%3E%3C/g%3E%3C/svg%3E\") 8 8, text";
	const TEXTY = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=reset]):not([type=file]):not([type=color]):not([type=range]):not([type=image]),textarea,[contenteditable]';
	const FIELD_CSS = ':where(' + TEXTY + '){cursor:' + IBEAM + ';}';
	const FIELD_CSS_SCOPED = ':where(.esf-host) :where(' + TEXTY + '){cursor:' + IBEAM + ';}';

	const CENTER_CSS = ':where(.esf-form){margin-inline:auto;}';
	const CENTER_CSS_SCOPED = ':where(.esf-host) :where(.esf-form){margin-inline:auto;}';

	const SUBMIT_BEAT_MS = 1000;

	function busyCss(scoped) {
		const p = scoped ? ':where(.esf-host) ' : '';
		return '@keyframes es-btn-spin{to{transform:rotate(360deg)}}'
			+ p + ':where(.esf-submitting [type="submit"]){cursor:progress;opacity:.72;}'
			+ p + ':where(.esf-submitting button[type="submit"])::after{content:"";display:inline-block;'
			+ 'width:.85em;height:.85em;margin-inline-start:.55em;vertical-align:-.12em;'
			+ 'border:2px solid currentColor;border-top-color:transparent;border-radius:50%;'
			+ 'animation:es-btn-spin .6s linear infinite;}'
			+ '@media (prefers-reduced-motion:reduce){' + p
			+ ':where(.esf-submitting button[type="submit"])::after{animation-duration:2.4s;}}';
	}

	var _esFontsLoaded = {};
	function loadFontImport(rule) {
		if (/^\s*@charset/i.test(rule)) return;
		var hm = /url\(\s*['"]?([^'")]+)['"]?\s*\)|@import\s+['"]([^'"]+)['"]/i.exec(rule);
		var href = hm ? (hm[1] || hm[2]) : '';
		if (!href || _esFontsLoaded[href]) return;
		_esFontsLoaded[href] = true;
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = href;
		link.media = 'print';
		link.onload = function () { this.media = 'all'; };
		(document.head || document.documentElement).appendChild(link);
	}
	var _esLiftedFontFaceRules = {};
	var _esFontFaceStyleEl = null;
	function _esHashStr(s) {
		var h = 5381;
		for (var i = 0; i < s.length; i++) h = (h * 33) ^ s.charCodeAt(i);
		return (h >>> 0).toString(36);
	}
	function _esEscapeRegExp(s) {
		return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}
	function scopeAndLiftFontFaces(css) {
		var blockRe = /@font-face\s*\{[^{}]*\}/gi;
		var familyRe = /font-family\s*:\s*(['"]?)([^;'"]+?)\1\s*;/i;
		var blocksByFamily = {};
		var m;
		while ((m = blockRe.exec(css)) !== null) {
			var fm = familyRe.exec(m[0]);
			if (!fm) continue;
			var family = fm[2].trim();
			if (!blocksByFamily[family]) blocksByFamily[family] = [];
			blocksByFamily[family].push(m[0]);
		}
		var families = Object.keys(blocksByFamily);
		if (!families.length) return css;
		for (var fi = 0; fi < families.length; fi++) {
			var fam = families[fi];
			var scoped = fam + '__es' + _esHashStr(blocksByFamily[fam].join('\n'));
			var nameRe = new RegExp("(['\"]?)\\b" + _esEscapeRegExp(fam) + "\\b\\1", 'g');
			css = css.replace(nameRe, function (_all, q) { return q + scoped + q; });
		}
		return css.replace(blockRe, function (block) {
			if (!_esLiftedFontFaceRules[block]) {
				_esLiftedFontFaceRules[block] = true;
				if (!_esFontFaceStyleEl) {
					_esFontFaceStyleEl = document.createElement('style');
					_esFontFaceStyleEl.setAttribute('data-es-lifted-fonts', '');
					(document.head || document.documentElement).appendChild(_esFontFaceStyleEl);
				}
				_esFontFaceStyleEl.appendChild(document.createTextNode(block));
			}
			return '';
		});
	}
	function prepareCss(css, withBoot) {
		css = css || '';
		css = scopeAndLiftFontFaces(css);
		var re = /^\s*(?:\/\*[\s\S]*?\*\/\s*)*(@charset\b[^;]*;|@import\b(?:\s*url\()?\s*(?:'[^']*'|"[^"]*"|[^;'")]*)(?:\s*\))?\s*[^;]*;)/i;
		var m;
		while ((m = re.exec(css)) !== null) {
			loadFontImport(m[1]);
			css = css.slice(m.index + m[0].length);
		}
		return (withBoot ? BOOT_CSS + FIELD_CSS + CENTER_CSS : FIELD_CSS_SCOPED + CENTER_CSS_SCOPED) + busyCss(!withBoot) + css;
	}

	function dismissKey(formId, shell) {
		return 'esf_dismissed_' + shell + '_' + formId;
	}
	function isShellSuppressed(formId, shell) {
		try {
			const raw = localStorage.getItem(dismissKey(formId, shell));
			if (!raw) return false;
			const data = JSON.parse(raw);
			if (data && data.until === 'forever') return true;
			if (data && typeof data.until === 'number' && Date.now() < data.until) return true;
			localStorage.removeItem(dismissKey(formId, shell));
			return false;
		} catch (e) { return false; }
	}
	function markShellDismissed(formId, shell, behavior, hours) {
		try {
			if (behavior === 'never') {
				localStorage.setItem(dismissKey(formId, shell), JSON.stringify({ until: 'forever' }));
			} else if (behavior === 'hide_hours') {
				const ms = Math.max(1, +hours || 24) * 3600 * 1000;
				localStorage.setItem(dismissKey(formId, shell), JSON.stringify({ until: Date.now() + ms }));
			}
		} catch (e) { }
	}

	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	let geoPromise = null;
	function fetchGeo() {
		if (geoPromise) return geoPromise;
		geoPromise = (async () => {
			const data = {};
			try { data.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}

			if (window.EmblemStudio && window.EmblemStudio.preview) return data;

			if (window.EmblemStudio && typeof window.EmblemStudio.autofill === 'function') {
				try {
					const ov = await window.EmblemStudio.autofill();
					if (ov && typeof ov === 'object') return Object.assign(data, ov);
				} catch (e) { }
			}

			return data;
		})();
		return geoPromise;
	}

	async function enrichWithGeo(formData) {
		let geo = null;
		try {
			geo = await Promise.race([
				fetchGeo(),
				new Promise(resolve => setTimeout(() => resolve(null), 1500)),
			]);
		} catch (e) { return; }
		if (!geo) return;
		const autoKeys = ['country', 'country_name', 'state', 'state_name', 'city', 'postcode', 'timezone'];
		for (const k of autoKeys) {
			if (geo[k]) formData.append('__es_geo_' + k, geo[k]);
		}
	}

	function mount(host) {
		const dataEl = host.querySelector('script.esf-data');
		if (!dataEl) return null;

		let payload;
		try {
			payload = JSON.parse(dataEl.textContent);
		} catch (e) {
			return null;
		}
		dataEl.remove();

		const useShadow = host.getAttribute('data-esf-shadow') === '1';

		if (useShadow) {
			const shadow = host.attachShadow({ mode: 'open' });

			const style = document.createElement('style');
			style.textContent = prepareCss(payload.css, true);
			shadow.appendChild(style);

			const tpl = document.createElement('template');
			tpl.innerHTML = payload.html || '';
			shadow.appendChild(tpl.content);
			esLiveMounted(shadow, payload.live);

			return shadow;
		}

		{
			const style = document.createElement('style');
			style.textContent = prepareCss(payload.css || '', false);
			host.appendChild(style);
		}
		const tpl = document.createElement('template');
		tpl.innerHTML = payload.html || '';
		host.appendChild(tpl.content);
		esLiveMounted(host, payload.live);
		return host;
	}

	const esLiveRoots = [];
	const esLiveAnswers = {};
	let esLiveTimer = null;

	function esLiveKey(el) {
		const field = (el.getAttribute('data-es-live') || '').trim().toLowerCase();
		const raw = (el.getAttribute('data-es-product') || '').trim();
		if (!/^\d+$/.test(raw) || parseInt(raw, 10) < 1) return null;
		const pid = String(parseInt(raw, 10));
		if (field.indexOf('coupon.') !== 0) return 'p:' + pid;
		const code = (el.getAttribute('data-es-coupon') || '').trim().toLowerCase();
		return code ? 'c:' + pid + ':' + code : null;
	}

	function esLiveName(el) {
		const field = (el.getAttribute('data-es-live') || '').trim().toLowerCase();
		const fmt = (el.getAttribute('data-es-format') || '').trim().toLowerCase();
		return fmt ? field + '@' + fmt : field;
	}

	function esFillLive(root, live) {
		if (!root || !root.querySelectorAll) return;
		const markers = root.querySelectorAll('[data-es-live]');
		if (!markers.length) return;
		const map = live || {};

		markers.forEach(function (el) {
			if (el.__esFallback === undefined) el.__esFallback = el.innerHTML;
			const key = esLiveKey(el);
			const name = esLiveName(el);
			const values = key && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
			const text = values && Object.prototype.hasOwnProperty.call(values, name) ? values[name] : null;
			const nested = el.parentElement && el.parentElement.closest('[data-es-live]');

			if (typeof text === 'string' && text !== '' && !nested) {
				el.textContent = text;
				el.setAttribute('data-es-live-state', 'ok');
			} else {
				if (el.getAttribute('data-es-live-state') === 'ok') el.innerHTML = el.__esFallback;
				el.setAttribute('data-es-live-state', 'fallback');
			}
		});

		root.querySelectorAll('[data-es-live-show]').forEach(function (el) {
			const scope = el.closest('[data-es-live-group]') || root;
			const inScope = scope.querySelectorAll('[data-es-live]');
			let ok = inScope.length > 0;
			inScope.forEach(function (m) { if (m.getAttribute('data-es-live-state') !== 'ok') ok = false; });
			const want = (el.getAttribute('data-es-live-show') || '').trim().toLowerCase();
			const show = want === 'fallback' ? !ok : ok;
			if (show) {
				if (el.getAttribute('data-es-live-hidden') === '1') {
					el.removeAttribute('hidden');
					el.style.removeProperty('display');
					el.removeAttribute('data-es-live-hidden');
				}
			} else {
				el.setAttribute('hidden', '');
				el.style.setProperty('display', 'none', 'important');
				el.setAttribute('data-es-live-hidden', '1');
			}
		});
	}

	function esLiveMapFor(root) {
		const map = {};
		const inline = root.__esLiveInline || {};
		root.querySelectorAll('[data-es-live]').forEach(function (el) {
			const key = esLiveKey(el);
			if (!key) return;
			const name = esLiveName(el);
			const id = key + '|' + name;
			const text = Object.prototype.hasOwnProperty.call(esLiveAnswers, id)
				? esLiveAnswers[id]
				: (inline[key] && inline[key][name]);
			if (typeof text === 'string' && text !== '') {
				if (!map[key]) map[key] = {};
				map[key][name] = text;
			}
		});
		return map;
	}

	function esLiveMounted(root, inline) {
		if (!root || !root.querySelector || !root.querySelector('[data-es-live]')) return;
		root.__esLiveInline = inline || {};
		esLiveRoots.push(root);
		esFillLive(root, esLiveMapFor(root));
		if (!esLiveTimer) esLiveTimer = setTimeout(esLiveAsk, 30);
	}

	function esLiveAsk() {
		esLiveTimer = null;
		const pairs = [];
		const seen = {};
		esLiveRoots.forEach(function (root) {
			root.querySelectorAll('[data-es-live]').forEach(function (el) {
				const key = esLiveKey(el);
				if (!key) return;
				const name = esLiveName(el);
				const id = key + '|' + name;
				if (seen[id] || Object.prototype.hasOwnProperty.call(esLiveAnswers, id)) return;
				seen[id] = true;
				pairs.push([key, name]);
			});
		});
		if (!pairs.length) return;

		fetch(ajax + (ajax.indexOf('?') === -1 ? '?' : '&') + 'action=es_live_values&m=' + encodeURIComponent(JSON.stringify(pairs)), { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (res) {
				if (!res || !res.ok) return;
				const live = res.live || {};
				pairs.forEach(function (p) {
					esLiveAnswers[p[0] + '|' + p[1]] = (live[p[0]] && live[p[0]][p[1]]) || '';
				});
				esLiveRoots.forEach(function (root) { esFillLive(root, esLiveMapFor(root)); });
			})
			.catch(function () { });
	}


	function fireEvent(host, action) {
		if (IN_ADMIN_FRAME || (window.EmblemStudio && window.EmblemStudio.preview)) return;
		const formId = host.getAttribute('data-esf-id');
		if (!formId) return;
		const shell = host.getAttribute('data-esf-type') || 'inline';
		try {
			const body = new URLSearchParams();
			body.append('action', action);
			body.append('form_id', formId);
			body.append('post_id', postId);
			body.append('vid', esVisitorId());
			body.append('type', shell);
			fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
		} catch (e) { }
	}

	const CLICK_LABEL_MAX = 60;

	function isTrackableClickTarget(el) {
		if (!el || el.nodeType !== 1) return false;
		if (el.hasAttribute && el.hasAttribute('data-es-track')) return true;
		if (el.closest && el.closest('form')) return false;
		const tag = el.tagName;
		if (tag === 'A') return true;
		if (tag === 'BUTTON') return true;
		if (tag === 'INPUT') {
			const t = (el.getAttribute('type') || 'text').toLowerCase();
			return t === 'submit' || t === 'button' || t === 'reset';
		}
		if (el.getAttribute('role') === 'button') return true;
		return false;
	}

	function findClickable(path, stopAt) {
		for (let i = 0; i < path.length; i++) {
			const n = path[i];
			if (n === stopAt) return null;
			if (isTrackableClickTarget(n)) return n;
		}
		return null;
	}

	function clickLabel(el) {
		const explicit = el.getAttribute && el.getAttribute('data-es-track');
		if (explicit) return explicit.slice(0, CLICK_LABEL_MAX);
		const id = el.id;
		if (id) return ('#' + id).slice(0, CLICK_LABEL_MAX);
		const text = (el.textContent || el.getAttribute('aria-label') || el.getAttribute('value') || '').replace(/\s+/g, ' ').trim();
		if (text) return text.slice(0, CLICK_LABEL_MAX);
		const tag = (el.tagName || '').toLowerCase();
		const cls = (el.className && typeof el.className === 'string') ? el.className.split(/\s+/)[0] : '';
		return (cls ? tag + '.' + cls : tag).slice(0, CLICK_LABEL_MAX);
	}

	function fireClickEvent(host, label) {
		if (IN_ADMIN_FRAME || (window.EmblemStudio && window.EmblemStudio.preview)) return;
		const formId = host && host.getAttribute('data-esf-id');
		if (!formId) return;
		if (host.getAttribute('data-esf-track-clicks') === '0') return;
		const shell = host.getAttribute('data-esf-type') || 'inline';
		try {
			const body = new URLSearchParams();
			body.append('action', 'es_click');
			body.append('form_id', formId);
			body.append('post_id', postId);
			body.append('vid', esVisitorId());
			body.append('type', shell);
			body.append('target', label || '');
			if (navigator.sendBeacon) {
				const blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
				navigator.sendBeacon(ajax, blob);
			} else {
				fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
			}
		} catch (e) { }
	}

	function attachClickTracking(host, root) {
		if (!host || !root) return;
		if (host.getAttribute('data-esf-track-clicks') === '0') return;
		if (host._osClickHooked) return;
		host._osClickHooked = true;
		const stopAt = (root === host) ? host.parentNode : host;
		root.addEventListener('click', function (e) {
			const path = (typeof e.composedPath === 'function') ? e.composedPath() : [e.target];
			const el = findClickable(path, stopAt);
			if (!el) return;
			fireClickEvent(host, clickLabel(el));
		}, true);
	}

	const engagedHosts = new Set();

	function sendEvent(host, action) {
		if (IN_ADMIN_FRAME || (window.EmblemStudio && window.EmblemStudio.preview)) return;
		const formId = host.getAttribute('data-esf-id');
		if (!formId) return;
		const shell = host.getAttribute('data-esf-type') || 'inline';
		try {
			const body = new URLSearchParams();
			body.append('action', action);
			body.append('form_id', formId);
			body.append('post_id', postId);
			body.append('vid', esVisitorId());
			body.append('type', shell);
			if (navigator.sendBeacon) {
				const blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
				navigator.sendBeacon(ajax, blob);
			} else {
				fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
			}
		} catch (e) { }
	}

	function fireDropoffs() {
		engagedHosts.forEach(host => {
			if (host._osSubmitted || host._osDropoffFired) return;
			host._osDropoffFired = true;
			sendEvent(host, 'es_dropoff');
		});
	}

	window.addEventListener('pagehide', fireDropoffs);

	function readCookie(name) {
		const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}

	let _esAnalyticsConsent = true;
	let _esPendingVisitorWrite = null;
	function esWriteVisitorCookie(value) {
		if (_esAnalyticsConsent) {
			try { document.cookie = 'esf_visitor=' + value + '; max-age=2592000; path=/; samesite=lax'; } catch (e) { }
		} else {
			_esPendingVisitorWrite = value;
		}
	}
	function esApplyAnalyticsConsent(granted) {
		_esAnalyticsConsent = !!granted;
		if (_esAnalyticsConsent && _esPendingVisitorWrite) {
			esWriteVisitorCookie(_esPendingVisitorWrite);
			_esPendingVisitorWrite = null;
		}
	}
	window.EmblemStudio = window.EmblemStudio || {};
	window.EmblemStudio.setAnalyticsConsent = esApplyAnalyticsConsent;
	(function () {
		if (typeof window.wp_has_consent !== 'function') return;
		const granted = function () {
			try { return !!(window.wp_has_consent('statistics') || window.wp_has_consent('statistics-anonymous')); }
			catch (e) { return true; }
		};
		esApplyAnalyticsConsent(granted());
		document.addEventListener('wp_listen_for_consent_change', function (e) {
			const changed = (e && e.detail) || {};
			if ('statistics' in changed || 'statistics-anonymous' in changed) esApplyAnalyticsConsent(granted());
		});
	})();

	let _esVid = null;
	function esVisitorId() {
		if (_esVid) return _esVid;
		let v = readCookie('esf_visitor');
		if (!v || v.length < 16) {
			v = (window.crypto && crypto.randomUUID)
				? crypto.randomUUID()
				: (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
			esWriteVisitorCookie(v);
		}
		_esVid = v;
		return v;
	}

	function markSubscribed(host) {
		const daysAttr = host.getAttribute('data-esf-conn-days');
		const formId = host.getAttribute('data-esf-id');
		if (daysAttr === null || !formId) return;
		const days = parseInt(daysAttr, 10) || 0;
		const now = Math.floor(Date.now() / 1000);
		const exp = days === 0 ? 0 : now + days * 86400;
		const map = {};
		readCookie('esf_sub').split(',').forEach(p => {
			const b = p.split(':');
			if (b.length === 2 && +b[0] > 0) map[b[0]] = parseInt(b[1], 10) || 0;
		});
		map[formId] = exp;
		const parts = [];
		let maxAge = 86400;
		Object.keys(map).forEach(id => {
			const e = map[id];
			if (e !== 0 && e <= now) return;
			parts.push(id + ':' + e);
			const age = e === 0 ? 3650 * 86400 : (e - now);
			if (age > maxAge) maxAge = age;
		});
		document.cookie = 'esf_sub=' + parts.join(',') + '; max-age=' + maxAge + '; path=/; samesite=lax';
		hideConnectedOnPage(host);
	}

	function hideConnectedOnPage(host) {
		const sup = host.getAttribute('data-esf-conn-suppress');
		if (!sup) return;
		sup.split(',').map(s => s.trim()).filter(Boolean).forEach(id => {
			document.querySelectorAll('[data-esf-id="' + id + '"]').forEach(el => {
				const wrap = el.closest && el.closest('.esf-popup-overlay, .esf-ribbon, .esf-slide-in');
				(wrap || el).style.display = 'none';
				el.setAttribute('data-esf-conn-silenced', '1');
				document.querySelectorAll(
					'.esf-popup-auto-bootstrap[data-esf-id="' + id + '"],' +
					'.esf-ribbon-bootstrap[data-esf-id="' + id + '"],' +
					'.esf-slide-in-bootstrap[data-esf-id="' + id + '"]'
				).forEach(b => b.setAttribute('data-esf-conn-silenced', '1'));
			});
		});
	}

	function isConnSilenced(boot) {
		return !!boot && boot.getAttribute('data-esf-conn-silenced') === '1';
	}

	function showSubscribedState(shadow) {
		const el = shadow.querySelector('[data-esf-subscribed], [data-osf-subscribed]');
		if (!el) return false;
		const form = shadow.querySelector('form');
		if (form) form.style.display = 'none';
		el.hidden = false;
		if (el.style && el.style.display === 'none') el.style.display = '';
		return true;
	}

	function attachEngagementTracking(host, form) {
		if (!host || !form) return;
		if (host._osEngageHooked) return;
		host._osEngageHooked = true;
		function onFirstInput(e) {
			const t = e.target;
			if (t && t.name === 'esf_hp') return;
			form.removeEventListener('input', onFirstInput, true);
			form.removeEventListener('change', onFirstInput, true);
			host._osEngaged = true;
			engagedHosts.add(host);
			sendEvent(host, 'es_engage');
		}
		form.addEventListener('input', onFirstInput, true);
		form.addEventListener('change', onFirstInput, true);
	}

	function observeViewport(host) {
		if (!('IntersectionObserver' in window)) {
			fireEvent(host, 'es_view');
			return;
		}
		let fired = false;
		const obs = new IntersectionObserver((entries) => {
			entries.forEach(entry => {
				if (!fired && entry.isIntersecting && entry.intersectionRatio >= 0.1) {
					fired = true;
					fireEvent(host, 'es_view');
					obs.disconnect();
				}
			});
		}, { threshold: [0.1] });
		obs.observe(host);
	}

	function injectHoneypot(form) {
		if (form._esHoneypotInjected) return;
		form._esHoneypotInjected = true;
		const wrap = document.createElement('div');
		wrap.style.cssText = 'position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;';
		wrap.setAttribute('aria-hidden', 'true');
		const input = document.createElement('input');
		input.type = 'text';
		input.name = 'esf_hp';
		input.tabIndex = -1;
		input.autocomplete = 'off';
		wrap.appendChild(input);
		form._esHoneypot = input;
		form._esReadyAt = Date.now();
		(form.parentNode || form).insertBefore(wrap, form.nextSibling);
	}

	function hasInlineThankYou(shadow) {
		return !!shadow.querySelector('[data-esf-thankyou], [data-esf-success], [data-osf-thankyou], [data-osf-success]');
	}

	function showInlineThankYou(shadow) {
		const el = shadow.querySelector('[data-esf-thankyou], [data-esf-success], [data-osf-thankyou], [data-osf-success]');
		const form = shadow.querySelector('form');
		const undo = [];
		const snap = function (n) {
			undo.push({ n: n, hidden: n.hidden, attr: n.hasAttribute('hidden'), display: n.style ? n.style.display : '' });
		};
		if (el) {
			snap(el);
			el.hidden = false;
			el.removeAttribute('hidden');
			el.style.display = '';
		}
		if (!form) { shadow._esThankYouUndo = undo; return; }
		if (el && form.contains(el)) {
			Array.prototype.forEach.call(form.children, function (child) {
				if (!child.contains(el)) { snap(child); child.style.display = 'none'; }
			});
			let n = el;
			while (n && n !== form) {
				snap(n);
				n.hidden = false;
				if (n.style && n.style.display === 'none') n.style.display = '';
				n = n.parentElement;
			}
		} else {
			snap(form);
			form.style.display = 'none';
		}
		shadow._esThankYouUndo = undo;
	}

	function undoInlineThankYou(shadow) {
		const undo = shadow._esThankYouUndo;
		if (!undo) return;
		for (let i = undo.length - 1; i >= 0; i--) {
			const r = undo[i];
			if (r.attr) r.n.setAttribute('hidden', ''); else r.n.removeAttribute('hidden');
			r.n.hidden = r.hidden;
			if (r.n.style) r.n.style.display = r.display;
		}
		shadow._esThankYouUndo = null;
	}

	function showInlineError(form, message) {
		let err = form.querySelector('.esf-error');
		if (!err) {
			err = document.createElement('div');
			err.className = 'esf-error';
			err.setAttribute('role', 'alert');
			err.style.cssText = 'color:#b32d2e;font-size:.85em;margin-top:.5rem;';
			form.appendChild(err);
		}
		err.textContent = message;
	}

	function clearInlineError(form) {
		const err = form.querySelector('.esf-error');
		if (err) err.textContent = '';
	}

	const EMAIL_SHAPE = /^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)*\.[A-Za-z](?:[A-Za-z0-9-]*[A-Za-z0-9])$/;

	function findBadEmailInput(form) {
		const input = form.querySelector('input[name="email"], input[name="your-email"], input[name="email_address"], input[name="user_email"], input[name="e-mail"], input[type="email"]');
		if (!input) return null;
		return EMAIL_SHAPE.test(String(input.value || '').trim()) ? null : input;
	}

	function findUnfilledRequired(form) {
		const controls = form.querySelectorAll('input[required], select[required], textarea[required]');
		for (let i = 0; i < controls.length; i++) {
			const el = controls[i];
			if (el.disabled) continue;
			const type = String(el.type || '').toLowerCase();

			if (type === 'email' || el.name === 'email') continue;

			if (type === 'checkbox') {
				if (el.checked) continue;
				return { el: el, message: 'Please tick the box to continue.' };
			}

			if (type === 'radio') {
				if (!el.name) continue;
				const group = form.querySelectorAll('input[type="radio"][name="' + escapeAttrValue(el.name) + '"]');
				let checked = false;
				group.forEach(function (r) { if (r.checked) checked = true; });
				if (checked) continue;
				return { el: el, message: 'Please choose an option to continue.' };
			}

			if (String(el.value || '').trim() === '') {
				return { el: el, message: 'Please fill in every required field.' };
			}
		}
		return null;
	}

	function escapeAttrValue(value) {
		const BACKSLASH = String.fromCharCode(92);
		return String(value).split(BACKSLASH).join(BACKSLASH + BACKSLASH).split('"').join(BACKSLASH + '"');
	}

	function sendMultiValueNamesAsLists(form, data) {
		const counts = {};
		form.querySelectorAll('input[type="checkbox"]:checked').forEach(function (el) {
			const name = el.getAttribute('name');
			if (!name || name.slice(-2) === '[]') return;
			counts[name] = (counts[name] || 0) + 1;
		});
		const names = Object.keys(counts).filter(function (name) { return counts[name] >= 2; });

		form.querySelectorAll('select[multiple]').forEach(function (el) {
			const name = el.getAttribute('name');
			if (!name || name.slice(-2) === '[]' || names.indexOf(name) !== -1) return;
			let selected = 0;
			for (let i = 0; i < el.options.length; i++) if (el.options[i].selected) selected++;
			if (selected >= 2) names.push(name);
		});

		names.forEach(function (name) {
			const values = data.getAll(name);
			data.delete(name);
			values.forEach(function (value) { data.append(name + '[]', value); });
		});
	}

	function setSubmittingState(form, isSubmitting) {
		const btn = form.querySelector('button[type="submit"], input[type="submit"]');
		if (btn) btn.disabled = isSubmitting;
		form.classList.toggle('esf-submitting', isSubmitting);
		form._esBusy = isSubmitting;
	}

	async function handleSubmit(host, shadow, form, e) {
		e.preventDefault();
		if (form._esBusy) return;

		if (window.EmblemStudio && window.EmblemStudio.preview && !window.EmblemStudio.previewSend) {
			const mode = host.getAttribute('data-esf-success-mode') || 'inline_state';
			setSubmittingState(form, true);
			setTimeout(function () {
				setSubmittingState(form, false);
				if (mode === 'redirect') {
					alert('(Preview) Would redirect to the configured URL.');
				} else {
					showInlineThankYou(shadow);
				}
			}, SUBMIT_BEAT_MS);
			return;
		}
		const missing = findUnfilledRequired(form);
		if (missing) {
			showInlineError(form, missing.message);
			try { missing.el.focus(); } catch (err) { }
			return;
		}
		const badEmail = findBadEmailInput(form);
		if (badEmail) {
			showInlineError(form, 'Please enter a valid email address.');
			try { badEmail.focus(); } catch (err) { }
			return;
		}
		clearInlineError(form);

		const formId = host.getAttribute('data-esf-id');
		if (!formId) return;

		setSubmittingState(form, true);

		const data = new FormData(form);
		sendMultiValueNamesAsLists(form, data);
		data.append('action', 'es_submit');
		data.append('form_id', formId);

		if (form._esHoneypot) data.append('esf_hp', form._esHoneypot.value);
		if (form._esReadyAt) {
			data.append('es_el', String(Date.now() - form._esReadyAt));
			data.append('es_ts', String(form._esReadyAt));
		}
		data.append('post_id', postId);
		data.append('vid', esVisitorId());

		const shell = host.getAttribute('data-esf-type') || 'inline';
		data.append('type', shell);

		if (window.EmblemStudio && window.EmblemStudio.preview) data.append('es_preview', '1');

		const successMode = host.getAttribute('data-esf-success-mode') || 'inline_state';
		const optimistic = successMode === 'inline_state' && hasInlineThankYou(shadow);
		let revealed = false;
		let revealTimer = null;
		if (optimistic) {
			revealTimer = setTimeout(function () {
				revealTimer = null;
				revealed = true;
				showInlineThankYou(shadow);
			}, SUBMIT_BEAT_MS);
		}
		const abandonReveal = function () {
			if (revealTimer) { clearTimeout(revealTimer); revealTimer = null; }
			if (revealed) { undoInlineThankYou(shadow); revealed = false; }
		};

		try { await enrichWithGeo(data); } catch (e) { }

		fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
			.then(({ ok, json }) => {
				if (!ok || !json || !json.success) {
					const msg = (json && json.data && json.data.message) || 'Something went wrong. Please try again.';
					abandonReveal();
					showInlineError(form, msg);
					setSubmittingState(form, false);
					return;
				}
				host._osSubmitted = true;
				markSubscribed(host);
				const d = json.data || {};
				if (d.mode === 'redirect' && d.redirect_url) {
					window.location.assign(d.redirect_url);
					return;
				}
				if (!optimistic) showInlineThankYou(shadow);
			})
			.catch(() => {
				abandonReveal();
				showInlineError(form, 'Network error. Please try again.');
				setSubmittingState(form, false);
			});
	}

	function applyPreviewFormMode(form) {
		if (!(window.EmblemStudio && window.EmblemStudio.preview)) return;
		const es = window.EmblemStudio;
		form.noValidate = !(es.previewValidate || es.previewSend);
	}

	function persistVariantCookies() {
		const els = document.querySelectorAll('[data-esf-id][data-esf-variant]');
		const seen = {};
		els.forEach(el => {
			const formId = el.getAttribute('data-esf-id');
			const slot = (el.getAttribute('data-esf-variant') || '').toUpperCase();
			if (!formId || (slot !== 'A' && slot !== 'B')) return;
			const key = 'esf_ab_' + formId;
			if (seen[key]) return;
			seen[key] = 1;
			const current = (document.cookie.match(new RegExp('(?:^|; )' + key + '=([^;]*)')) || [])[1];
			if (current && current.toUpperCase() === slot) return;
			document.cookie = key + '=' + slot + '; max-age=2592000; path=/; samesite=lax';
		});
	}

	function isTemplateHostSubscribed(templateSelector) {
		const tpl = document.querySelector(templateSelector);
		const host = tpl && tpl.content && tpl.content.querySelector('.esf-host');
		return !!(host && host.getAttribute('data-esf-subscribed') === '1');
	}

	function init() {
		persistVariantCookies();

		document.querySelectorAll('.esf-host[data-esf-id]:not(.esf-popup-host)').forEach(host => {
			if (host._osMounted || host.shadowRoot) return;
			host._osMounted = true;
			const shadow = mount(host);
			if (!shadow) return;

			if (host.getAttribute('data-esf-subscribed') === '1') {
				showSubscribedState(shadow);
				return;
			}

			fireEvent(host, 'es_page_view');
			observeViewport(host);
			fetchGeo();
			attachClickTracking(host, shadow);

			const form = shadow.querySelector('form');
			if (!form) return;
			injectHoneypot(form);
			attachEngagementTracking(host, form);
			applyPreviewFormMode(form);
			form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
		});

		document.querySelectorAll('.esf-popup-trigger[data-esf-popup-id]').forEach(trigger => {
			if (trigger._osPopupHooked) return;
			trigger._osPopupHooked = true;

			const popupId = trigger.getAttribute('data-esf-popup-id');
			if (!isTemplateHostSubscribed('template.esf-popup-data[data-esf-popup-id="' + popupId + '"]')) {
				fetchGeo();
				fireEvent(trigger, 'es_page_view');
				observeTrigger(trigger);
			}

			trigger.addEventListener('click', e => {
				e.preventDefault();
				openPopup(popupId);
			});

			if (window.EmblemStudio && window.EmblemStudio.preview) openPopup(popupId);
		});

		document.querySelectorAll('.esf-popup-auto-bootstrap[data-esf-popup-id]').forEach(boot => {
			if (boot._osAutoHooked) return;
			boot._osAutoHooked = true;
			initAutoPopup(boot);
		});

		document.querySelectorAll('.esf-ribbon-bootstrap[data-esf-ribbon-id]').forEach(boot => {
			if (boot._osRibbonHooked) return;
			boot._osRibbonHooked = true;
			initRibbon(boot);
		});

		document.querySelectorAll('.esf-slide-in-bootstrap[data-esf-slide-in-id]').forEach(boot => {
			if (boot._osSlideInHooked) return;
			boot._osSlideInHooked = true;
			initSlideIn(boot);
		});
	}

	function initAutoPopup(boot) {
		const popupId = boot.getAttribute('data-esf-popup-id');
		if (!popupId) return;

		let config;
		try { config = JSON.parse(boot.getAttribute('data-esf-auto-config') || '{}'); }
		catch (e) { return; }
		if (!config) return;
		if (!config.trigger && !(window.EmblemStudio && window.EmblemStudio.preview)) return;

		if (!(window.EmblemStudio && window.EmblemStudio.preview) && isShellSuppressed(popupId, 'popup-auto')) return;

		if (!isTemplateHostSubscribed('template.esf-popup-data[data-esf-popup-id="' + popupId + '"]')) {
			fetchGeo();
			fireEvent(boot, 'es_page_view');
		}

		const showClose = config.close_button !== false;
		const fire = (autofired) => isConnSilenced(boot) || openPopup(popupId, {
			backdropCloses: config.trigger !== 'exit' || autofired === true,
			showClose: showClose,
			onClose: function () {
				markShellDismissed(popupId, 'popup-auto', config.dismiss_behavior || 'show_again', config.dismiss_hide_hours);
			},
		});

		switch (config.trigger) {
			case 'delay': {
				setTimeout(fire, Math.max(0, +config.delay_seconds || 0) * 1000);
				break;
			}
			case 'scroll': {
				const threshold = Math.max(1, Math.min(100, +config.scroll_percent || 50)) / 100;
				const onScroll = () => {
					const max = (document.documentElement.scrollHeight || document.body.scrollHeight) - window.innerHeight;
					if (max <= 0) return;
					if ((window.scrollY || window.pageYOffset || 0) / max >= threshold) {
						window.removeEventListener('scroll', onScroll);
						fire();
					}
				};
				window.addEventListener('scroll', onScroll, { passive: true });
				break;
			}
			case 'inactivity': {
				const seconds = Math.max(5, +config.inactivity_seconds || 30);
				let timer = setTimeout(fire, seconds * 1000);
				const reset = () => { clearTimeout(timer); timer = setTimeout(fire, seconds * 1000); };
				['mousemove', 'keydown', 'touchstart', 'scroll', 'click'].forEach(evt => {
					window.addEventListener(evt, reset, { passive: true });
				});
				break;
			}
			case 'exit': {
				let fired = false;
				let expired = false;
				const onMouseLeave = e => { if (e.clientY <= 0) fireOnce(); };
				const onBlur = () => { if (document.visibilityState === 'visible') fireOnce(); };
				const onVisibility = () => { if (document.hidden) fireOnce(); };
				const disarm = () => {
					document.removeEventListener('mouseleave', onMouseLeave);
					window.removeEventListener('blur', onBlur);
					document.removeEventListener('visibilitychange', onVisibility);
				};
				function fireOnce(viaWindow) {
					if (fired || expired) return;
					fired = true;
					disarm();
					fire(viaWindow === true);
				}
				const arm = () => {
					if (fired || expired) return;
					document.addEventListener('mouseleave', onMouseLeave);
					window.addEventListener('blur', onBlur);
					if (config.exit_include_hidden) {
						document.addEventListener('visibilitychange', onVisibility);
					}
				};
				const exitWindowMs = Math.max(0, +config.exit_window_seconds || 0) * 1000;
				if (exitWindowMs > 0) {
					setTimeout(() => {
						if (fired) return;
						if (config.exit_window_autofire) { fireOnce(true); return; }
						expired = true;
						disarm();
					}, exitWindowMs);
				}
				const exitBufferMs = Math.max(0, +config.exit_buffer_seconds || 0) * 1000;
				if (exitBufferMs > 0) setTimeout(arm, exitBufferMs); else arm();
				break;
			}
		}
	}

	function observeTrigger(trigger) {
		if (!('IntersectionObserver' in window)) {
			fireEvent(trigger, 'es_trigger_view');
			return;
		}
		let fired = false;
		const obs = new IntersectionObserver((entries) => {
			entries.forEach(entry => {
				if (!fired && entry.isIntersecting && entry.intersectionRatio >= 0.1) {
					fired = true;
					fireEvent(trigger, 'es_trigger_view');
					obs.disconnect();
				}
			});
		}, { threshold: [0.1] });
		obs.observe(trigger);
	}

	function esAutoCloseCss(scope, btn) {
		var sel = scope + '[data-esf-close-theme="auto"] ' + btn;
		var cross = "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 26 26'><path d='M9.5 9.5l7 7M16.5 9.5l-7 7' stroke='rgba(0,0,0,0.7)' stroke-width='1.6' stroke-linecap='round' fill='none'/></svg>\")";
		var ring = 'radial-gradient(circle at 50% 50%,transparent 0 11.6px,rgba(0,0,0,.15) 11.9px 12.5px,transparent 12.8px)';
		var fill = 'radial-gradient(circle at 50% 50%,rgba(0,0,0,.10) 0 11.8px,transparent 12.1px)';
		var mask = cross + ',' + ring + ',' + fill;
		var sizes = '26px 26px,26px 26px,26px 26px';
		var filter = function (c) { return 'blur(3px) grayscale(1) invert(1) contrast(' + c + ')'; };
		return '@supports (((-webkit-mask-image:none) or (mask-image:none)) and ((-webkit-backdrop-filter:invert(1)) or (backdrop-filter:invert(1)))){' +
			sel + '{background:transparent!important;border:0!important;box-shadow:none!important;' +
			'-webkit-backdrop-filter:' + filter(3) + '!important;backdrop-filter:' + filter(3) + '!important;' +
			'-webkit-mask-image:' + mask + '!important;mask-image:' + mask + '!important;' +
			'-webkit-mask-size:' + sizes + '!important;mask-size:' + sizes + '!important;' +
			'-webkit-mask-repeat:no-repeat!important;mask-repeat:no-repeat!important;' +
			'-webkit-mask-position:center,center,center!important;mask-position:center,center,center!important;}' +
			sel + ' svg{display:none!important;}' +
			sel + ':hover{background:transparent!important;' +
			'-webkit-backdrop-filter:' + filter(5) + '!important;backdrop-filter:' + filter(5) + '!important;}}';
	}

	function esCloseTheme(v) {
		return (v === 'dark' || v === 'auto') ? v : 'light';
	}

	function ensurePopupStyles() {
		if (document.getElementById('esf-popup-styles')) return;
		const style = document.createElement('style');
		style.id = 'esf-popup-styles';
		style.textContent =
			'.esf-popup-overlay{position:fixed;inset:0;z-index:99999;' +
			'background:rgba(15,17,21,.55);' +
			'-webkit-backdrop-filter:blur(8px) saturate(120%);backdrop-filter:blur(8px) saturate(120%);' +
			'display:flex;justify-content:safe center;align-items:safe center;padding:24px;overflow:auto;' +
			'animation:esf-popup-fade .18s ease-out both;}' +
			'.esf-popup-overlay[data-fullscreen="1"]{padding:0;}' +
			'.esf-popup-modal{position:relative;max-width:min(92vw,var(--esf-max-width,720px));margin:0;overflow:visible;' +
			'background:transparent;animation:esf-popup-rise .22s ease-out both;}' +
			'.esf-popup-modal[data-fullscreen="1"]{max-width:100vw;width:100vw;height:100vh;border-radius:0;margin:0;}' +
			'.esf-popup-content{position:relative;min-width:min(320px,var(--esf-max-width,720px),92vw);overflow:visible;}' +
			'.esf-popup-overlay{scrollbar-width:thin;scrollbar-color:rgba(190,190,190,0.5) transparent;}' +
			'.esf-popup-overlay::-webkit-scrollbar{width:8px;height:8px;}' +
			'.esf-popup-overlay::-webkit-scrollbar-track{background:transparent;}' +
			'.esf-popup-overlay::-webkit-scrollbar-thumb{background:rgba(190,190,190,0.5);border-radius:4px;}' +
			'.esf-popup-overlay::-webkit-scrollbar-thumb:hover{background:rgba(150,150,150,0.5);}' +
			'.esf-popup-overlay::-webkit-scrollbar-button{display:none;width:0;height:0;}' +
			'.esf-popup-overlay::-webkit-scrollbar-corner{background:transparent;}' +
			'.esf-popup-content[data-fullscreen="1"]{height:100vh;overflow:auto;-webkit-overflow-scrolling:touch;display:flex;flex-direction:column;justify-content:safe center;}' +
			'.esf-popup-close{all:revert!important;' +
			'position:absolute!important;top:12px!important;right:12px!important;z-index:10!important;' +
			'width:26px!important;height:26px!important;min-width:0!important;min-height:0!important;' +
			'padding:0!important;margin:0!important;' +
			'display:inline-flex!important;align-items:center!important;justify-content:center!important;' +
			'border:1px solid var(--esf-close-border,rgba(255,255,255,0.15))!important;border-radius:50%!important;' +
			'background:var(--esf-close-bg,rgba(255,255,255,0.10))!important;color:var(--esf-close-fg,rgba(255,255,255,0.7))!important;cursor:pointer!important;' +
			'-webkit-backdrop-filter:blur(6px)!important;backdrop-filter:blur(6px)!important;' +
			'text-shadow:none!important;text-decoration:none!important;letter-spacing:0!important;opacity:1!important;' +
			'transition:background .15s, color .15s, transform .25s!important;}' +
			'.esf-popup-close:hover{background:var(--esf-close-hover-bg,rgba(255,255,255,0.18))!important;color:var(--esf-close-hover-fg,#fff)!important;transform:rotate(90deg)!important;}' +
			'.esf-popup-close:focus-visible{outline:2px solid #2271b1!important;outline-offset:2px!important;}' +
			'.esf-popup-overlay[data-esf-close-theme="dark"]{--esf-close-fg:rgba(0,0,0,0.55);--esf-close-bg:rgba(0,0,0,0.06);--esf-close-border:rgba(0,0,0,0.12);--esf-close-hover-fg:#1d2327;--esf-close-hover-bg:rgba(0,0,0,0.10);}' +
			esAutoCloseCss('.esf-popup-overlay', '.esf-popup-close') +
			'.esf-popup-content .esf-host{display:block;isolation:isolate;}' +
			'html.esf-popup-open,body.esf-popup-open{overflow:hidden;}' +
			'@keyframes esf-popup-fade{from{opacity:0}to{opacity:1}}' +
			'@keyframes esf-popup-rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}';
		document.head.appendChild(style);
	}

	function esCardRect(root) {
		let box = null;
		for (const el of root.children) {
			if (el.tagName === 'STYLE' || el.tagName === 'SCRIPT' || el.tagName === 'TEMPLATE') continue;
			const r = el.getBoundingClientRect();
			if (r.width === 0 && r.height === 0) continue;
			box = box
				? { left: Math.min(box.left, r.left), top: Math.min(box.top, r.top), right: Math.max(box.right, r.right) }
				: { left: r.left, top: r.top, right: r.right };
		}
		return box;
	}

	function openPopup(popupId, opts) {
		if (!popupId) return;
		opts = opts || {};
		const showClose = opts.showClose !== false;
		const onCloseCb = opts.onClose;

		const existing = document.querySelector('.esf-popup-overlay[data-esf-popup-id="' + popupId + '"]');
		if (existing) return;

		const tpl = document.querySelector('template.esf-popup-data[data-esf-popup-id="' + popupId + '"]');
		if (!tpl) return;

		ensurePopupStyles();

		const overlay = document.createElement('div');
		overlay.className = 'esf-popup-overlay';
		overlay.dataset.esfPopupId = popupId;

		const modal = document.createElement('div');
		modal.className = 'esf-popup-modal';

		const content = document.createElement('div');
		content.className = 'esf-popup-content';

		const cloned = tpl.content.querySelector('.esf-host');
		if (!cloned) return;
		const host = cloned.cloneNode(true);
		content.appendChild(host);

		const maxWidth = parseInt(host.getAttribute('data-esf-max-width'), 10);
		if (maxWidth > 0) {
			modal.style.setProperty('--esf-max-width', maxWidth + 'px');
		}

		if (host.getAttribute('data-esf-fullscreen') === '1') {
			overlay.dataset.fullscreen = '1';
			modal.dataset.fullscreen = '1';
			content.dataset.fullscreen = '1';
		}

		const overlayCloseTheme = esCloseTheme(host.getAttribute('data-esf-close-theme'));
		if (overlayCloseTheme !== 'light') {
			overlay.dataset.esfCloseTheme = overlayCloseTheme;
		}

		let closeBtn = null;
		if (showClose) {
			closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'esf-popup-close';
			closeBtn.setAttribute('aria-label', 'Close');
			closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"/></svg>';
		}

		modal.appendChild(content);
		if (closeBtn) (content.dataset.fullscreen === '1' ? content : modal).appendChild(closeBtn);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		document.documentElement.classList.add('esf-popup-open');
		document.body.classList.add('esf-popup-open');

		const isFull = content.dataset.fullscreen === '1';
		let cardRoot = null;
		let closeObserver = null;
		const syncCloseOffset = () => {
			if (!closeBtn) return;
			if (isFull && cardRoot) {
				const card = esCardRect(cardRoot);
				if (card) {
					const box = content.getBoundingClientRect();
					const top = card.top - box.top + content.scrollTop + 12;
					const left = card.right - box.left + content.scrollLeft - 12 - closeBtn.offsetWidth;
					closeBtn.style.setProperty('top', top + 'px', 'important');
					closeBtn.style.setProperty('left', left + 'px', 'important');
					closeBtn.style.setProperty('right', 'auto', 'important');
					return;
				}
			}
			const sbw = Math.max(0, content.offsetWidth - content.clientWidth);
			closeBtn.style.setProperty('right', ( 12 + sbw ) + 'px', 'important');
		};
		if (closeBtn && window.ResizeObserver) {
			closeObserver = new ResizeObserver(syncCloseOffset);
			closeObserver.observe(content);
			if (isFull) closeObserver.observe(host);
		}

		function close() {
			if (closeObserver) closeObserver.disconnect();
			overlay.remove();
			document.documentElement.classList.remove('esf-popup-open');
			document.body.classList.remove('esf-popup-open');
			document.removeEventListener('keydown', onKey);
			if (typeof onCloseCb === 'function') onCloseCb();
		}
		function onKey(e) { if (e.key === 'Escape') close(); }
		if (closeBtn) closeBtn.addEventListener('click', function () { fireClickEvent(host, '__close__'); close(); });
		if (showClose && opts.backdropCloses !== false) {
			overlay.addEventListener('click', e => { if (e.target === overlay) { fireClickEvent(host, '__backdrop__'); close(); } });
		}
		document.addEventListener('keydown', onKey);

		const shadow = mount(host);
		if (!shadow) return;
		cardRoot = shadow;
		syncCloseOffset();
		shadow.querySelectorAll('img').forEach(img => {
			if (!img.complete) img.addEventListener('load', syncCloseOffset);
		});
		if (host.getAttribute('data-esf-subscribed') !== '1') fireEvent(host, 'es_view');
		attachClickTracking(host, shadow);

		const form = shadow.querySelector('form');
		if (!form) return;
		injectHoneypot(form);
		attachEngagementTracking(host, form);
		applyPreviewFormMode(form);
		form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
	}

	function initRibbon(boot) {
		const ribbonId = boot.getAttribute('data-esf-ribbon-id');
		if (!ribbonId) return;

		let config;
		try { config = JSON.parse(boot.getAttribute('data-esf-auto-config') || '{}'); }
		catch (e) { return; }
		if (!config) return;
		if (!config.trigger && !(window.EmblemStudio && window.EmblemStudio.preview)) return;

		if (!(window.EmblemStudio && window.EmblemStudio.preview) && isShellSuppressed(ribbonId, 'ribbon')) return;

		if (!isTemplateHostSubscribed('template.esf-ribbon-data[data-esf-ribbon-id="' + ribbonId + '"]')) {
			fetchGeo();
			fireEvent(boot, 'es_page_view');
		}

		const position  = boot.getAttribute('data-esf-position') === 'bottom' ? 'bottom' : 'top';
		const showClose = config.close_button !== false;
		const sticky    = config.sticky !== false;
		const fire = () => isConnSilenced(boot) || openRibbon(ribbonId, position, {
			showClose: showClose,
			sticky: sticky,
			onClose: function () {
				markShellDismissed(ribbonId, 'ribbon', config.dismiss_behavior || 'show_again', config.dismiss_hide_hours);
			},
		});

		switch (config.trigger) {
			case 'delay': {
				setTimeout(fire, Math.max(0, +config.delay_seconds || 0) * 1000);
				break;
			}
			case 'scroll': {
				const threshold = Math.max(1, Math.min(100, +config.scroll_percent || 50)) / 100;
				const onScroll = () => {
					const max = (document.documentElement.scrollHeight || document.body.scrollHeight) - window.innerHeight;
					if (max <= 0) return;
					if ((window.scrollY || window.pageYOffset || 0) / max >= threshold) {
						window.removeEventListener('scroll', onScroll);
						fire();
					}
				};
				window.addEventListener('scroll', onScroll, { passive: true });
				break;
			}
			case 'inactivity': {
				const seconds = Math.max(5, +config.inactivity_seconds || 30);
				let timer = setTimeout(fire, seconds * 1000);
				const reset = () => { clearTimeout(timer); timer = setTimeout(fire, seconds * 1000); };
				['mousemove', 'keydown', 'touchstart', 'scroll', 'click'].forEach(evt => {
					window.addEventListener(evt, reset, { passive: true });
				});
				break;
			}
			case 'exit': {
				let fired = false;
				let expired = false;
				const onMouseLeave = e => { if (e.clientY <= 0) fireOnce(); };
				const onBlur = () => { if (document.visibilityState === 'visible') fireOnce(); };
				const onVisibility = () => { if (document.hidden) fireOnce(); };
				const disarm = () => {
					document.removeEventListener('mouseleave', onMouseLeave);
					window.removeEventListener('blur', onBlur);
					document.removeEventListener('visibilitychange', onVisibility);
				};
				function fireOnce(viaWindow) {
					if (fired || expired) return;
					fired = true;
					disarm();
					fire(viaWindow === true);
				}
				const arm = () => {
					if (fired || expired) return;
					document.addEventListener('mouseleave', onMouseLeave);
					window.addEventListener('blur', onBlur);
					if (config.exit_include_hidden) {
						document.addEventListener('visibilitychange', onVisibility);
					}
				};
				const exitWindowMs = Math.max(0, +config.exit_window_seconds || 0) * 1000;
				if (exitWindowMs > 0) {
					setTimeout(() => {
						if (fired) return;
						if (config.exit_window_autofire) { fireOnce(true); return; }
						expired = true;
						disarm();
					}, exitWindowMs);
				}
				const exitBufferMs = Math.max(0, +config.exit_buffer_seconds || 0) * 1000;
				if (exitBufferMs > 0) setTimeout(arm, exitBufferMs); else arm();
				break;
			}
		}
	}

	function ensureRibbonStyles() {
		if (document.getElementById('esf-ribbon-styles')) return;
		const style = document.createElement('style');
		style.id = 'esf-ribbon-styles';
		style.textContent =
			'.esf-ribbon{position:fixed;left:0;right:0;z-index:99998;' +
			'box-sizing:border-box;animation:esf-ribbon-in .5s ease-out both;}' +
			'.esf-ribbon[data-position="top"]{top:0;}' +
			'.esf-ribbon[data-position="bottom"]{bottom:0;animation-name:esf-ribbon-in-bottom;}' +
			'body.admin-bar .esf-ribbon[data-sticky="1"][data-position="top"]{top:32px;}' +
			'@media screen and (max-width:782px){' +
			'body.admin-bar .esf-ribbon[data-sticky="1"][data-position="top"]{top:46px;}' +
			'}' +
			'.esf-ribbon[data-sticky="0"]{position:relative;top:auto;bottom:auto;' +
			'animation-name:esf-ribbon-fade;}' +
			'.esf-ribbon .esf-host{display:block;isolation:isolate;}' +
			'.esf-ribbon-close{all:revert!important;' +
			'position:absolute!important;top:12px!important;right:12px!important;z-index:2!important;' +
			'width:26px!important;height:26px!important;min-width:0!important;min-height:0!important;' +
			'padding:0!important;margin:0!important;' +
			'display:inline-flex!important;align-items:center!important;justify-content:center!important;' +
			'border:1px solid var(--esf-close-border,rgba(255,255,255,0.15))!important;border-radius:50%!important;' +
			'background:var(--esf-close-bg,rgba(255,255,255,0.10))!important;color:var(--esf-close-fg,rgba(255,255,255,0.7))!important;cursor:pointer!important;' +
			'-webkit-backdrop-filter:blur(6px)!important;backdrop-filter:blur(6px)!important;' +
			'text-shadow:none!important;text-decoration:none!important;letter-spacing:0!important;opacity:1!important;' +
			'transition:background .15s, color .15s, transform .25s!important;}' +
			'.esf-ribbon-close:hover{background:var(--esf-close-hover-bg,rgba(255,255,255,0.18))!important;color:var(--esf-close-hover-fg,#fff)!important;transform:rotate(90deg)!important;}' +
			'.esf-ribbon-close:focus-visible{outline:2px solid #2271b1!important;outline-offset:2px!important;}' +
			'.esf-ribbon[data-esf-close-theme="dark"]{--esf-close-fg:rgba(0,0,0,0.55);--esf-close-bg:rgba(0,0,0,0.06);--esf-close-border:rgba(0,0,0,0.12);--esf-close-hover-fg:#1d2327;--esf-close-hover-bg:rgba(0,0,0,0.10);}' +
			esAutoCloseCss('.esf-ribbon', '.esf-ribbon-close') +
			'@keyframes esf-ribbon-in{from{transform:translateY(-100%)}to{transform:translateY(0)}}' +
			'@keyframes esf-ribbon-in-bottom{from{transform:translateY(100%)}to{transform:translateY(0)}}' +
			'@keyframes esf-ribbon-fade{from{opacity:0}to{opacity:1}}';
		document.head.appendChild(style);
	}

	function openRibbon(ribbonId, position, opts) {
		if (!ribbonId) return;
		opts = opts || {};
		var showClose = opts.showClose !== false;
		var sticky    = opts.sticky !== false;
		var onClose   = opts.onClose;

		if (document.querySelector('.esf-ribbon[data-esf-ribbon-id="' + ribbonId + '"]')) return;

		const tpl = document.querySelector('template.esf-ribbon-data[data-esf-ribbon-id="' + ribbonId + '"]');
		if (!tpl) return;

		ensureRibbonStyles();

		const ribbon = document.createElement('div');
		ribbon.className = 'esf-ribbon';
		ribbon.dataset.esfRibbonId = ribbonId;
		ribbon.dataset.position = position;
		ribbon.dataset.sticky = sticky ? '1' : '0';

		const cloned = tpl.content.querySelector('.esf-host');
		if (!cloned) return;
		const host = cloned.cloneNode(true);
		ribbon.appendChild(host);

		const ribbonCloseTheme = esCloseTheme(host.getAttribute('data-esf-close-theme'));
		if (ribbonCloseTheme !== 'light') {
			ribbon.dataset.esfCloseTheme = ribbonCloseTheme;
		}

		let closeBtn = null;
		if (showClose) {
			closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'esf-ribbon-close';
			closeBtn.setAttribute('aria-label', 'Close');
			closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"/></svg>';
			ribbon.appendChild(closeBtn);
		}

		if (!sticky && position !== 'bottom') {
			document.body.insertBefore(ribbon, document.body.firstChild);
		} else {
			document.body.appendChild(ribbon);
		}

		const padProp = position === 'bottom' ? 'paddingBottom' : 'paddingTop';
		const originalPad = document.body.style[padProp];
		const originalTransition = document.body.style.transition;
		let ro = null;
		if (sticky) {
			document.body.style.transition = (padProp === 'paddingTop' ? 'padding-top' : 'padding-bottom') + ' 0.5s ease-out';
			let ribbonHeightApplied = 0;
			function applyPad() {
				const h = ribbon.getBoundingClientRect().height;
				if (h === ribbonHeightApplied) return;
				ribbonHeightApplied = h;
				document.body.style[padProp] = h + 'px';
				if (position === 'bottom') document.documentElement.style.setProperty('--esf-ribbon-bottom-h', h + 'px');
			}
			applyPad();
			if (typeof ResizeObserver === 'function') {
				ro = new ResizeObserver(applyPad);
				ro.observe(ribbon);
			} else {
				setTimeout(applyPad, 250);
			}
		}

		function close() {
			ribbon.style.transition = 'transform 0.5s ease-out';
			ribbon.style.transform = position === 'bottom' ? 'translateY(100%)' : 'translateY(-100%)';
			if (sticky) document.body.style[padProp] = originalPad || '';
			if (sticky && position === 'bottom') document.documentElement.style.setProperty('--esf-ribbon-bottom-h', '0px');
			setTimeout(function () {
				ribbon.remove();
				if (sticky) document.body.style.transition = originalTransition || '';
				if (ro) ro.disconnect();
				if (typeof onClose === 'function') onClose();
			}, 500);
		}
		if (closeBtn) closeBtn.addEventListener('click', function () { fireClickEvent(host, '__close__'); close(); });

		const shadow = mount(host);
		if (!shadow) return;
		if (host.getAttribute('data-esf-subscribed') !== '1') fireEvent(host, 'es_view');
		attachClickTracking(host, shadow);

		const form = shadow.querySelector('form');
		if (!form) return;
		injectHoneypot(form);
		attachEngagementTracking(host, form);
		applyPreviewFormMode(form);
		form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
	}

	function initSlideIn(boot) {
		const slideInId = boot.getAttribute('data-esf-slide-in-id');
		if (!slideInId) return;

		let config;
		try { config = JSON.parse(boot.getAttribute('data-esf-auto-config') || '{}'); }
		catch (e) { return; }
		if (!config) return;
		if (!config.trigger && !(window.EmblemStudio && window.EmblemStudio.preview)) return;

		if (!(window.EmblemStudio && window.EmblemStudio.preview) && isShellSuppressed(slideInId, 'slide-in')) return;

		if (!isTemplateHostSubscribed('template.esf-slide-in-data[data-esf-slide-in-id="' + slideInId + '"]')) {
			fetchGeo();
			fireEvent(boot, 'es_page_view');
		}

		const corner    = boot.getAttribute('data-esf-corner') === 'bottom-left' ? 'bottom-left' : 'bottom-right';
		const showClose = config.close_button !== false;
		const fire = () => isConnSilenced(boot) || openSlideIn(slideInId, corner, {
			showClose: showClose,
			onClose: function () {
				markShellDismissed(slideInId, 'slide-in', config.dismiss_behavior || 'show_again', config.dismiss_hide_hours);
			},
		});

		switch (config.trigger) {
			case 'delay': {
				setTimeout(fire, Math.max(0, +config.delay_seconds || 0) * 1000);
				break;
			}
			case 'scroll': {
				const threshold = Math.max(1, Math.min(100, +config.scroll_percent || 50)) / 100;
				const onScroll = () => {
					const max = (document.documentElement.scrollHeight || document.body.scrollHeight) - window.innerHeight;
					if (max <= 0) return;
					if ((window.scrollY || window.pageYOffset || 0) / max >= threshold) {
						window.removeEventListener('scroll', onScroll);
						fire();
					}
				};
				window.addEventListener('scroll', onScroll, { passive: true });
				break;
			}
			case 'inactivity': {
				const seconds = Math.max(5, +config.inactivity_seconds || 30);
				let timer = setTimeout(fire, seconds * 1000);
				const reset = () => { clearTimeout(timer); timer = setTimeout(fire, seconds * 1000); };
				['mousemove', 'keydown', 'touchstart', 'scroll', 'click'].forEach(evt => {
					window.addEventListener(evt, reset, { passive: true });
				});
				break;
			}
			case 'exit': {
				let fired = false;
				let expired = false;
				const onMouseLeave = e => { if (e.clientY <= 0) fireOnce(); };
				const onBlur = () => { if (document.visibilityState === 'visible') fireOnce(); };
				const onVisibility = () => { if (document.hidden) fireOnce(); };
				const disarm = () => {
					document.removeEventListener('mouseleave', onMouseLeave);
					window.removeEventListener('blur', onBlur);
					document.removeEventListener('visibilitychange', onVisibility);
				};
				function fireOnce(viaWindow) {
					if (fired || expired) return;
					fired = true;
					disarm();
					fire(viaWindow === true);
				}
				const arm = () => {
					if (fired || expired) return;
					document.addEventListener('mouseleave', onMouseLeave);
					window.addEventListener('blur', onBlur);
					if (config.exit_include_hidden) {
						document.addEventListener('visibilitychange', onVisibility);
					}
				};
				const exitWindowMs = Math.max(0, +config.exit_window_seconds || 0) * 1000;
				if (exitWindowMs > 0) {
					setTimeout(() => {
						if (fired) return;
						if (config.exit_window_autofire) { fireOnce(true); return; }
						expired = true;
						disarm();
					}, exitWindowMs);
				}
				const exitBufferMs = Math.max(0, +config.exit_buffer_seconds || 0) * 1000;
				if (exitBufferMs > 0) setTimeout(arm, exitBufferMs); else arm();
				break;
			}
		}
	}

	function ensureSlideInStyles() {
		if (document.getElementById('esf-slide-in-styles')) return;
		const style = document.createElement('style');
		style.id = 'esf-slide-in-styles';
		style.textContent =
			'.esf-slide-in{position:fixed;bottom:calc(20px + var(--esf-ribbon-bottom-h, 0px));transition:bottom .5s ease-out;z-index:99998;' +
			'max-width:min(92vw,var(--esf-max-width,380px));' +
			'min-width:min(320px,var(--esf-max-width,380px),92vw);' +
			'box-sizing:border-box;animation:0.5s ease-out both;}' +
			'.esf-slide-in[data-corner="bottom-right"]{right:20px;animation-name:esf-slide-in-from-right;}' +
			'.esf-slide-in[data-corner="bottom-left"]{left:20px;animation-name:esf-slide-in-from-left;}' +
			'.esf-slide-in .esf-host{display:block;isolation:isolate;}' +
			'.esf-slide-in-close{all:revert!important;' +
			'position:absolute!important;top:12px!important;z-index:2!important;' +
			'width:26px!important;height:26px!important;min-width:0!important;min-height:0!important;' +
			'padding:0!important;margin:0!important;' +
			'display:inline-flex!important;align-items:center!important;justify-content:center!important;' +
			'border:1px solid var(--esf-close-border,rgba(255,255,255,0.15))!important;border-radius:50%!important;' +
			'background:var(--esf-close-bg,rgba(255,255,255,0.10))!important;color:var(--esf-close-fg,rgba(255,255,255,0.7))!important;cursor:pointer!important;' +
			'-webkit-backdrop-filter:blur(6px)!important;backdrop-filter:blur(6px)!important;' +
			'text-shadow:none!important;text-decoration:none!important;letter-spacing:0!important;opacity:1!important;' +
			'transition:background .15s, color .15s, transform .25s!important;}' +
			'.esf-slide-in[data-corner="bottom-right"] .esf-slide-in-close{left:12px!important;right:auto!important;}' +
			'.esf-slide-in[data-corner="bottom-left"]  .esf-slide-in-close{right:12px!important;left:auto!important;}' +
			'.esf-slide-in-close:hover{background:var(--esf-close-hover-bg,rgba(255,255,255,0.18))!important;color:var(--esf-close-hover-fg,#fff)!important;transform:rotate(90deg)!important;}' +
			'.esf-slide-in-close:focus-visible{outline:2px solid #2271b1!important;outline-offset:2px!important;}' +
			'.esf-slide-in[data-esf-close-theme="dark"]{--esf-close-fg:rgba(0,0,0,0.55);--esf-close-bg:rgba(0,0,0,0.06);--esf-close-border:rgba(0,0,0,0.12);--esf-close-hover-fg:#1d2327;--esf-close-hover-bg:rgba(0,0,0,0.10);}' +
			esAutoCloseCss('.esf-slide-in', '.esf-slide-in-close') +
			'@keyframes esf-slide-in-from-right{from{transform:translateX(calc(100% + 30px))}to{transform:translateX(0)}}' +
			'@keyframes esf-slide-in-from-left{from{transform:translateX(calc(-100% - 30px))}to{transform:translateX(0)}}';
		document.head.appendChild(style);
	}

	function openSlideIn(slideInId, corner, opts) {
		if (!slideInId) return;
		opts = opts || {};
		var showClose = opts.showClose !== false;
		var onClose   = opts.onClose;

		if (document.querySelector('.esf-slide-in[data-esf-slide-in-id="' + slideInId + '"]')) return;

		const tpl = document.querySelector('template.esf-slide-in-data[data-esf-slide-in-id="' + slideInId + '"]');
		if (!tpl) return;

		ensureSlideInStyles();

		const card = document.createElement('div');
		card.className = 'esf-slide-in';
		card.dataset.esfSlideInId = slideInId;
		card.dataset.corner = corner;

		const cloned = tpl.content.querySelector('.esf-host');
		if (!cloned) return;
		const cardCloseTheme = esCloseTheme(cloned.getAttribute('data-esf-close-theme'));
		if (cardCloseTheme !== 'light') {
			card.dataset.esfCloseTheme = cardCloseTheme;
		}
		const host = cloned.cloneNode(true);
		card.appendChild(host);

		const maxWidth = parseInt(host.getAttribute('data-esf-max-width'), 10);
		if (maxWidth > 0) {
			card.style.setProperty('--esf-max-width', maxWidth + 'px');
		}

		let closeBtn = null;
		if (showClose) {
			closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'esf-slide-in-close';
			closeBtn.setAttribute('aria-label', 'Close');
			closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"/></svg>';
			card.appendChild(closeBtn);
		}

		document.body.appendChild(card);

		function close() {
			card.style.transition = 'transform 0.5s ease-out';
			card.style.transform = corner === 'bottom-left'
				? 'translateX(calc(-100% - 30px))'
				: 'translateX(calc(100% + 30px))';
			setTimeout(function () {
				card.remove();
				if (typeof onClose === 'function') onClose();
			}, 500);
		}
		if (closeBtn) closeBtn.addEventListener('click', function () { fireClickEvent(host, '__close__'); close(); });

		const shadow = mount(host);
		if (!shadow) return;
		if (host.getAttribute('data-esf-subscribed') !== '1') fireEvent(host, 'es_view');
		attachClickTracking(host, shadow);

		const form = shadow.querySelector('form');
		if (!form) return;
		injectHoneypot(form);
		attachEngagementTracking(host, form);
		applyPreviewFormMode(form);
		form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
	}

	function esPreviewLinkHint(anchorEl, text) {
		try {
			var id = 'esf-preview-link-hint', el = document.getElementById(id);
			if (!el) {
				el = document.createElement('div');
				el.id = id;
				el.style.cssText = 'position:fixed;z-index:2147483647;background:rgba(15,23,42,.92);color:#fff;'
					+ 'font:600 12px/1.4 Montserrat,system-ui,sans-serif;padding:6px 10px;border-radius:8px;'
					+ 'box-shadow:0 6px 20px rgba(0,0,0,.25);pointer-events:none;opacity:0;transition:opacity .12s;'
					+ 'max-width:min(360px,90vw);white-space:normal;overflow-wrap:anywhere;';
				(document.body || document.documentElement).appendChild(el);
			}
			el.textContent = text;
			var r = anchorEl && anchorEl.getBoundingClientRect ? anchorEl.getBoundingClientRect() : null;
			if (r && r.width) {
				el.style.top = Math.min(r.bottom + 8, window.innerHeight - 40) + 'px';
				el.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 200)) + 'px';
				el.style.right = 'auto'; el.style.bottom = 'auto'; el.style.transform = 'none';
			} else {
				el.style.top = 'auto'; el.style.bottom = '18px'; el.style.left = '50%'; el.style.right = 'auto'; el.style.transform = 'translateX(-50%)';
			}
			clearTimeout(el._t);
			el.style.opacity = '1';
			el._t = setTimeout(function () { el.style.opacity = '0'; }, 2200);
		} catch (e) { }
	}

	function installPreviewLinkGuard() {
		document.addEventListener('click', function (e) {
			if (!(window.EmblemStudio && window.EmblemStudio.preview)) return;
			var path = (typeof e.composedPath === 'function') ? e.composedPath() : [e.target];
			var a = null;
			for (var i = 0; i < path.length; i++) {
				var n = path[i];
				if (!n || n.nodeType !== 1) continue;
				if (n.classList && (n.classList.contains('esf-inspect-close') || n.classList.contains('esf-ribbon-close') || n.classList.contains('esf-popup-close'))) return;
				if (n.tagName === 'A' && n.hasAttribute && n.hasAttribute('href')) { a = n; break; }
			}
			if (!a) return;
			e.preventDefault();
			e.stopPropagation();
			var raw = (a.getAttribute('href') || '').trim();
			var navigable = raw && raw.charAt(0) !== '#' && raw.slice(0, 11).toLowerCase() !== 'javascript:';
			var dest = navigable ? (a.href || raw) : raw;
			esPreviewLinkHint(a, dest ? ('This opens ' + dest) : 'This link has no destination');
		}, true);
	}

	installPreviewLinkGuard();
	ready(init);
})();
