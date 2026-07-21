(function () {
	'use strict';

	const ajax = (window.EmblemStudio && window.EmblemStudio.ajaxUrl) || '/wp-admin/admin-ajax.php';
	const postId = (window.EmblemStudio && window.EmblemStudio.postId) || 0;

	// Boot CSS injected into every shadow root.
	// `all: initial !important` on :host cuts inheritance from the
	// surrounding theme and also guards against aggressive `body *`
	// rules in the outer document. But `all: initial` resets THREE
	// layout-critical properties to bad defaults:
	//   - `display` → `inline` (a full-width ribbon would shrink to
	//     content). Fixed with `display: block`.
	//   - `width` → `auto`. In a normal block context that means
	//     "fill parent", which is fine — but when the host's parent
	//     is a flex/grid container (e.g. the preview iframe's body
	//     for non-top ribbon positions), `auto` becomes "shrink to
	//     content" and the host renders narrower than its parent.
	//     Fixed with `width: 100%`.
	//   - `box-sizing` → `content-box`. Any host-level padding or
	//     border the author adds would push the host past 100% and
	//     cause overflow. Fixed with `box-sizing: border-box`.
	// All three carry `!important` so they outlive the `all: initial`
	// reset above them in the same rule.
	const BOOT_CSS = ':host{all:initial!important;display:block!important;width:100%!important;box-sizing:border-box!important;}';

	// A leading @import is only valid as the FIRST rule, but a valid @import is
	// ALSO render-blocking — the browser holds back the whole stylesheet until
	// the import loads, which on a slow/blocked font host leaves the asset
	// unstyled. So we LIFT any leading @charset/@import out of the asset's
	// stylesheet entirely and load each as a non-blocking <link> in the
	// document head (its @font-face registers document-wide, so the fonts still
	// reach the shadow). The asset's own rules then apply immediately.
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
		link.media = 'print'; // non-blocking; switched to all once loaded
		link.onload = function () { this.media = 'all'; };
		(document.head || document.documentElement).appendChild(link);
	}
	// Strip leading @charset/@import (loading any fonts non-blocking) and return
	// the rest, optionally prefixed with the shadow reset.
	function prepareCss(css, withBoot) {
		css = css || '';
		// Quote-aware: a Google-Fonts @import URL can itself contain ';'
		// (e.g. wght@400;500;600), so we must not stop at the first semicolon —
		// match the quoted/parenthesised value, then the statement's own ';'.
		var re = /^\s*(?:\/\*[\s\S]*?\*\/\s*)*(@charset\b[^;]*;|@import\b(?:\s*url\()?\s*(?:'[^']*'|"[^"]*"|[^;'")]*)(?:\s*\))?\s*[^;]*;)/i;
		var m;
		while ((m = re.exec(css)) !== null) {
			loadFontImport(m[1]);
			css = css.slice(m.index + m[0].length);
		}
		return (withBoot ? BOOT_CSS : '') + css;
	}

	// ============================================================
	// Per-shell dismiss memory (localStorage)
	// ============================================================
	// When the visitor closes an auto-injected shell, we may write a marker
	// to localStorage so it stays suppressed across page loads. Behavior:
	//   show_again  → don't write; suppression doesn't apply
	//   hide_hours  → write { until: epoch_ms } expiring in N hours
	//   never       → write { until: Infinity }
	// On init we check the marker before wiring up the trigger; if it says
	// "still suppressed", we skip the shell entirely (no events fire).

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
			// Expired — clean up.
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
			// show_again: no-op
		} catch (e) { /* swallow */ }
	}

	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	// --- Client hint (local only) ----------------------------------------------
	// No third-party call. This once fetched IP-derived location from ipapi.co;
	// that's removed (see below). All it does now is pass the browser's own
	// timezone (from Intl, no network) plus anything window.EmblemStudio.autofill
	// injects. Visible form inputs always win — we only append a value if
	// FormData doesn't already carry that key.
	let geoPromise = null;
	function fetchGeo() {
		if (geoPromise) return geoPromise;
		geoPromise = (async () => {
			const data = {};
			try { data.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}

			// In the preview/editor there's no real submission to enrich, so skip
			// the external IP lookup entirely — it only adds latency (and noise).
			if (window.EmblemStudio && window.EmblemStudio.preview) return data;

			// Override hook for power users: window.EmblemStudio.autofill = async () => ({...})
			if (window.EmblemStudio && typeof window.EmblemStudio.autofill === 'function') {
				try {
					const ov = await window.EmblemStudio.autofill();
					if (ov && typeof ov === 'object') return Object.assign(data, ov);
				} catch (e) { /* keep the local-only data below */ }
			}

			// NO third-party geo call. The plugin used to fetch the visitor's
			// country/city from ipapi.co on every page load — which sent their IP
			// to an outside company before they'd done anything, and brushes
			// against WordPress.org's "no external requests without consent" rule.
			// It's gone. IP-derived location is a hosted-Emblem-Studio feature
			// (Free tier onwards, and on connected sites); standalone Lite does not
			// guess a visitor's location. A country/state field the visitor
			// actually fills in still works through explicit Field Mapping.
			//
			// We still pass the browser's own timezone (from Intl, above — no
			// network), plus anything the autofill override injected.
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
		// Hints land in a separate namespace so they never collide with user-named
		// inputs. Country / region / city are NOT sent from here any more (no
		// third-party lookup). Timezone (local) and any autofill override still
		// ride along; a mapped country/state field the visitor fills is unaffected.
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

			return shadow;
		}

		// Light DOM mount: render directly inside the host so theme styles
		// reach the form and the form's CSS is part of the page. NOTE: this
		// also means the form's CSS can leak — author's responsibility to
		// scope it (e.g. wrap selectors with the form's container).
		if (payload.css) {
			const style = document.createElement('style');
			style.textContent = prepareCss(payload.css, false);
			host.appendChild(style);
		}
		const tpl = document.createElement('template');
		tpl.innerHTML = payload.html || '';
		host.appendChild(tpl.content);
		return host;
	}

	function fireEvent(host, action) {
		// In preview mode (form-edit's Preview button), don't fire any events —
		// we don't want stats polluted by admin previews. Same applies to
		// submissions handled below.
		if (window.EmblemStudio && window.EmblemStudio.preview) return;
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
		} catch (e) { /* swallow */ }
	}

	// ============================================================
	// Click tracking
	// ============================================================
	// One delegated capturing listener per mounted form. Walks the
	// composed path to find the nearest clickable element. Builds a stable
	// label using a priority chain: data-es-track > id > visible text
	// (truncated) > tag fallback. Beacon uses navigator.sendBeacon so the
	// event survives the link navigation when the click target is a real <a>.
	//
	// AUTO-TRACKED: links (<a>), buttons (<button>, input[type=submit|button|
	// reset], [role=button]) that live OUTSIDE a <form>, plus the shell close
	// button / backdrop (handled separately). Click tracking is for non-form
	// assets — CTAs (links and buttons) that are not part of a form. Anything
	// inside a <form> is NOT auto-tracked: the submit button, fields, and labels
	// are the form itself, and submitting / filling it in is conversion and
	// engagement, which are measured on their own. Counting the submit button
	// as a "click" would just double-count the same intent.
	//
	// OPT-IN: an author can force tracking on ANY element — including the submit
	// button, a field, or a label inside a form — by adding a
	// data-es-track="my-label" attribute to it. The attribute's value also
	// becomes that element's stable name in the report.

	const CLICK_LABEL_MAX = 60;

	function isTrackableClickTarget(el) {
		if (!el || el.nodeType !== 1) return false;
		// Explicit opt-in always wins: a tagged element is tracked even if it's
		// a form element we'd otherwise leave alone. This is the documented way
		// for power users to bring a submit button, field, or label into click stats.
		if (el.hasAttribute && el.hasAttribute('data-es-track')) return true;
		// Form elements are not auto-tracked. Click tracking is for non-form
		// assets — links and buttons that aren't part of a form. Authors who
		// want an in-form element counted opt in with data-es-track (above).
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
			// Stop walking when we exit the form — a wrapping <a> around the
			// shortcode shouldn't be attributed as an in-form click.
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
		// Last resort: tag + first class. Stable enough to group.
		const tag = (el.tagName || '').toLowerCase();
		const cls = (el.className && typeof el.className === 'string') ? el.className.split(/\s+/)[0] : '';
		return (cls ? tag + '.' + cls : tag).slice(0, CLICK_LABEL_MAX);
	}

	function fireClickEvent(host, label) {
		if (window.EmblemStudio && window.EmblemStudio.preview) return;
		const formId = host && host.getAttribute('data-esf-id');
		if (!formId) return;
		// Honor per-form opt-out — host carries the flag from PHP.
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
			// sendBeacon survives same-tab navigation when clicking a real link.
			// Fall back to fetch with keepalive when sendBeacon isn't available.
			if (navigator.sendBeacon) {
				const blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
				navigator.sendBeacon(ajax, blob);
			} else {
				fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
			}
		} catch (e) { /* swallow */ }
	}

	function attachClickTracking(host, root) {
		if (!host || !root) return;
		if (host.getAttribute('data-esf-track-clicks') === '0') return;
		if (host._osClickHooked) return;
		host._osClickHooked = true;
		// For shadow DOM, root is the ShadowRoot and host is its host element.
		// For light DOM, mount() returns the host itself; we want to stop at the
		// host's parent (so the host is still trackable if it has a role=button).
		const stopAt = (root === host) ? host.parentNode : host;
		// Capturing phase so we still observe the click before stopPropagation handlers.
		root.addEventListener('click', function (e) {
			const path = (typeof e.composedPath === 'function') ? e.composedPath() : [e.target];
			const el = findClickable(path, stopAt);
			if (!el) return;
			fireClickEvent(host, clickLabel(el));
		}, true);
	}

	// ============================================================
	// Engagement + drop-off tracking
	// ============================================================
	// Two events, recorded as the visitor's intent unfolds:
	//   • `engage`  — fired once the moment they first interact with a field
	//                 (type / tick / pick). "Started filling in."
	//   • `dropoff` — fired when they LEAVE the page having engaged but never
	//                 submitted. This is the drop-off, recorded directly as its
	//                 own event (not derived by subtracting conversions, which
	//                 breaks against historical data).
	// We bind on the <form> itself (inside the shadow root) rather than
	// delegating from document, because `change` events don't cross the shadow
	// boundary — a document listener would miss checkbox / select / radio picks.

	// Hosts that have engaged this page load. Held by reference so a drop-off
	// can still fire on unload even if the host (e.g. a popup) left the DOM.
	const engagedHosts = new Set();

	function sendEvent(host, action) {
		// In preview mode (form-edit's Preview button), don't fire — admin
		// testing must never pollute stats.
		if (window.EmblemStudio && window.EmblemStudio.preview) return;
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
			// sendBeacon so the event still lands when the visitor interacts and
			// then immediately closes the tab — a normal fetch can be cancelled
			// by the unloading page; a beacon can't. Essential for `dropoff`.
			if (navigator.sendBeacon) {
				const blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
				navigator.sendBeacon(ajax, blob);
			} else {
				fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
			}
		} catch (e) { /* swallow */ }
	}

	function fireDropoffs() {
		// On page leave, every host that engaged but didn't submit is a drop-off.
		engagedHosts.forEach(host => {
			if (host._osSubmitted || host._osDropoffFired) return;
			host._osDropoffFired = true;
			sendEvent(host, 'es_dropoff');
		});
	}

	// `pagehide` is the reliable "leaving the page" signal (navigation, tab
	// close, back/forward cache). We deliberately do NOT use visibilitychange,
	// which also fires on a mere tab switch and would mis-count a visitor who
	// looks away and comes back to submit.
	window.addEventListener('pagehide', fireDropoffs);

	// ============================================================
	// Connections — "already subscribed" suppression
	// ============================================================
	// On conversion we record the completed form in a browser cookie (esf_sub)
	// — the same cookie the server reads on the next page load to hide/replace
	// connected forms. We also hide any connected forms already on THIS page so
	// the visitor doesn't keep seeing them after subscribing.
	function readCookie(name) {
		const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}

	// Anonymous, first-party per-visitor id (a random value — no PII, no IP) so
	// reports can count unique visitors + new-vs-returning. Reuses the esf_visitor
	// cookie the new/returning display gate already reads; a legacy '1' flag is
	// upgraded to a real id. ~30-day cookie (browsers cap this; per-person stats
	// are short-window by nature). Created once per page load and reused.
	let _esVid = null;
	function esVisitorId() {
		if (_esVid) return _esVid;
		let v = readCookie('esf_visitor');
		if (!v || v.length < 16) {
			v = (window.crypto && crypto.randomUUID)
				? crypto.randomUUID()
				: (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
			try { document.cookie = 'esf_visitor=' + v + '; max-age=2592000; path=/; samesite=lax'; } catch (e) { /* ignore */ }
		}
		_esVid = v;
		return v;
	}

	function markSubscribed(host) {
		const daysAttr = host.getAttribute('data-esf-conn-days');
		const formId = host.getAttribute('data-esf-id');
		if (daysAttr === null || !formId) return; // form takes no part in connections
		const days = parseInt(daysAttr, 10) || 0;        // 0 = forever
		const now = Math.floor(Date.now() / 1000);
		const exp = days === 0 ? 0 : now + days * 86400;
		// Merge into the existing cookie, dropping any expired entries.
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
			if (e !== 0 && e <= now) return;             // expired
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
				// Hide the mounted host, its bootstrap, or any open overlay/bar.
				const wrap = el.closest && el.closest('.esf-popup-overlay, .esf-ribbon, .esf-slide-in');
				(wrap || el).style.display = 'none';
			});
		});
	}

	// Replace mode: show the author's [data-esf-subscribed] block instead of
	// the form. Returns true when such a block exists.
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
			// The hidden honeypot is never touched by a real human; ignore it so
			// browser autofill or a bot write can't register a phantom engagement.
			const t = e.target;
			if (t && t.name === 'esf_hp') return;
			form.removeEventListener('input', onFirstInput, true);
			form.removeEventListener('change', onFirstInput, true);
			host._osEngaged = true;
			engagedHosts.add(host);
			sendEvent(host, 'es_engage');
		}
		// Capture phase, self-removing after the first real interaction.
		// `input` covers typing; `change` covers checkbox / radio / select
		// where `input` may not fire.
		form.addEventListener('input', onFirstInput, true);
		form.addEventListener('change', onFirstInput, true);
	}

	function observeViewport(host) {
		// Fire a "view" event the first time at least 10% of the host is visible.
		if (!('IntersectionObserver' in window)) {
			// No viewport API — best-effort: count as viewed immediately.
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
		if (form.querySelector('input[name="esf_hp"]')) return;
		const wrap = document.createElement('div');
		wrap.style.cssText = 'position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;';
		wrap.setAttribute('aria-hidden', 'true');
		const input = document.createElement('input');
		input.type = 'text';
		input.name = 'esf_hp';
		input.tabIndex = -1;
		input.autocomplete = 'off';
		wrap.appendChild(input);
		form.appendChild(wrap);
	}

	function showInlineThankYou(shadow) {
		// Includes the legacy `data-osf-*` markers from pre-rename assets.
		const el = shadow.querySelector('[data-esf-thankyou], [data-esf-success], [data-osf-thankyou], [data-osf-success]');
		const form = shadow.querySelector('form');
		if (el) {
			el.hidden = false;
			el.removeAttribute('hidden');
			el.style.display = '';
		}
		if (!form) return;
		if (el && form.contains(el)) {
			// The thank-you lives INSIDE the form (the common authored pattern, e.g.
			// the starter markup). Hiding the whole form would hide it too — instead
			// hide the form's other top-level branches and keep the chain down to the
			// thank-you visible.
			Array.prototype.forEach.call(form.children, function (child) {
				if (!child.contains(el)) child.style.display = 'none';
			});
			let n = el;
			while (n && n !== form) {
				n.hidden = false;
				if (n.style && n.style.display === 'none') n.style.display = '';
				n = n.parentElement;
			}
		} else {
			form.style.display = 'none';
		}
	}

	function showCustomMessage(shadow, message) {
		const form = shadow.querySelector('form');
		const msg = document.createElement('div');
		msg.className = 'esf-thank-you';
		msg.innerHTML = message || 'Thanks!';
		if (form) form.replaceWith(msg); else shadow.appendChild(msg);
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

	function setSubmittingState(form, isSubmitting) {
		const btn = form.querySelector('button[type="submit"], input[type="submit"]');
		if (btn) btn.disabled = isSubmitting;
		form.classList.toggle('esf-submitting', isSubmitting);
	}

	async function handleSubmit(host, shadow, form, e) {
		e.preventDefault();
		// In preview mode, simulate the success state visually and DON'T POST —
		// unless the "Submit for real" preview toggle is on, in which case we fall
		// through to the real submission path (creates a lead, pushes to the CRM).
		if (window.EmblemStudio && window.EmblemStudio.preview && !window.EmblemStudio.previewSend) {
			const mode = host.getAttribute('data-esf-success-mode') || 'inline_state';
			if (mode === 'redirect') {
				alert('(Preview) Would redirect to the configured URL.');
			} else if (mode === 'custom_message') {
				showCustomMessage(shadow, '<em>(Preview)</em> Your custom success message would show here.');
			} else {
				showInlineThankYou(shadow);
			}
			return;
		}
		const formId = host.getAttribute('data-esf-id');
		if (!formId) return;

		setSubmittingState(form, true);

		const data = new FormData(form);
		data.append('action', 'es_submit');
		data.append('form_id', formId);
		data.append('post_id', postId);
		data.append('vid', esVisitorId());

		// Tag the conversion with the shell type so reports can split by it.
		const shell = host.getAttribute('data-esf-type') || 'inline';
		data.append('type', shell);

		// "Submit for real" from the Design-tab previewer: a genuine submission
		// (lead saved, CRM push) but flagged so the server keeps it OUT of the
		// conversion stats. Only meaningful for logged-in editors (the server
		// re-checks the capability before honoring it).
		if (window.EmblemStudio && window.EmblemStudio.preview) data.append('es_preview', '1');

		// Silently enrich with IP-derived country/state/city/timezone, but only
		// for keys not already present (a visible input always wins). This step
		// is best-effort — any error here MUST NOT block submission.
		try { await enrichWithGeo(data); } catch (e) { /* swallow */ }

		fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
			.then(({ ok, json }) => {
				if (!ok || !json || !json.success) {
					const msg = (json && json.data && json.data.message) || 'Something went wrong. Please try again.';
					showInlineError(form, msg);
					setSubmittingState(form, false);
					return;
				}
				// Converted — exclude this host from the page-leave drop-off sweep,
				// and record the subscription (cookie + hide same-page peers).
				host._osSubmitted = true;
				markSubscribed(host);
				const d = json.data || {};
				if (d.mode === 'redirect' && d.redirect_url) {
					window.location.assign(d.redirect_url);
					return;
				}
				if (d.mode === 'custom_message') {
					showCustomMessage(shadow, d.success_message);
					return;
				}
				showInlineThankYou(shadow);
			})
			.catch(() => {
				showInlineError(form, 'Network error. Please try again.');
				setSubmittingState(form, false);
			});
	}

	// Preview-only: the Design-tab preview is a sandbox. By default the form does
	// NOT enforce required fields (free click-through), and submitting never POSTs.
	// The "Enforce required" / "Submit for real" toggles flip native validation
	// back on (and `previewSend` lets the submit hit the real endpoint). Live
	// pages never reach this — `preview` is false there.
	function applyPreviewFormMode(form) {
		if (!(window.EmblemStudio && window.EmblemStudio.preview)) return;
		const es = window.EmblemStudio;
		form.noValidate = !(es.previewValidate || es.previewSend);
	}

	// --- A/B variant cookie persistence ---------------------------------------
	// The server picks the variant and stamps data-esf-variant="A"/"B" on every
	// host / trigger / bootstrap element for forms with a running A/B test. We
	// mirror that pick into a cookie so the next page load reads it server-side
	// and serves the same variant. One letter; no PII; 30-day TTL. Same legal
	// category as the existing esf_visitor cookie (strictly necessary).
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
			// Only write if not already set to the same value — avoids
			// touching the cookie store on every render.
			const current = (document.cookie.match(new RegExp('(?:^|; )' + key + '=([^;]*)')) || [])[1];
			if (current && current.toUpperCase() === slot) return;
			document.cookie = key + '=' + slot + '; max-age=2592000; path=/; samesite=lax';
		});
	}

	function init() {
		persistVariantCookies();

		// Inline forms: mount immediately, fire page_view, observe viewport for view.
		document.querySelectorAll('.esf-host[data-esf-id]:not(.esf-popup-host)').forEach(host => {
			if (host._osMounted || host.shadowRoot) return;
			host._osMounted = true;
			const shadow = mount(host);
			if (!shadow) return;

			// Replace mode: server flagged this visitor as already subscribed.
			// Show the [data-esf-subscribed] block instead of the form and skip
			// all tracking / submit wiring.
			if (host.getAttribute('data-esf-subscribed') === '1') {
				showSubscribedState(shadow);
				return;
			}

			fireEvent(host, 'es_page_view');
			observeViewport(host);
			fetchGeo();
			// Click tracking is wired even when there's no <form> — the
			// container may be a pure click-through CTA with no inputs.
			attachClickTracking(host, shadow);

			const form = shadow.querySelector('form');
			if (!form) return;
			injectHoneypot(form);
			attachEngagementTracking(host, form);
			applyPreviewFormMode(form);
			form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
		});

		// Click-popup triggers: fire page_view on render, observe trigger for trigger_view,
		// on click build the modal overlay, mount form, fire view, wire submit.
		document.querySelectorAll('.esf-popup-trigger[data-esf-popup-id]').forEach(trigger => {
			if (trigger._osPopupHooked) return;
			trigger._osPopupHooked = true;

			fetchGeo();
			fireEvent(trigger, 'es_page_view');
			observeTrigger(trigger);

			trigger.addEventListener('click', e => {
				e.preventDefault();
				openPopup(trigger.getAttribute('data-esf-popup-id'));
			});
		});

		// Auto popups: each bootstrap element carries trigger config + a sibling
		// <template> with the host. Fire page_view on render, then wire whichever
		// triggers are enabled; the first one to fire opens the modal.
		document.querySelectorAll('.esf-popup-auto-bootstrap[data-esf-popup-id]').forEach(boot => {
			if (boot._osAutoHooked) return;
			boot._osAutoHooked = true;
			initAutoPopup(boot);
		});

		// Ribbons: same trigger model as auto popups, but instead of opening a
		// modal the trigger reveals a fixed-position sticky bar at top or bottom.
		document.querySelectorAll('.esf-ribbon-bootstrap[data-esf-ribbon-id]').forEach(boot => {
			if (boot._osRibbonHooked) return;
			boot._osRibbonHooked = true;
			initRibbon(boot);
		});

		// Slide-ins: small corner card. Same trigger model. Slides in from
		// the matching side; mirrored close button on the opposite corner.
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
		if (!config || !config.trigger) return;

		// Honour persistent dismissal — if the visitor closed this popup with
		// a "don't show again" rule still in effect, skip entirely.
		if (isShellSuppressed(popupId, 'popup-auto')) return;

		fetchGeo();
		fireEvent(boot, 'es_page_view');

		const showClose = config.close_button !== false;
		const fire = () => openPopup(popupId, {
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
				// Device-appropriate:
				// - Desktop: cursor leaves viewport at top.
				// - Mobile/tablet: window blur while page is still visible (URL bar,
				//   menu, share sheet — anything that pulls focus to browser chrome
				//   without backgrounding the page).
				// - Optional: include real tab/app switches via visibilitychange.
				let fired = false;
				const onMouseLeave = e => { if (e.clientY <= 0) fireOnce(); };
				const onBlur = () => { if (document.visibilityState === 'visible') fireOnce(); };
				const onVisibility = () => { if (document.hidden) fireOnce(); };
				function fireOnce() {
					if (fired) return;
					fired = true;
					document.removeEventListener('mouseleave', onMouseLeave);
					window.removeEventListener('blur', onBlur);
					document.removeEventListener('visibilitychange', onVisibility);
					fire();
				}
				// Buffer: don't ARM exit-intent until the visitor has been on the page
				// for exit_buffer_seconds. If they bounce inside that window nothing is
				// armed — so the shell never opens and no impression (es_view) is logged.
				const arm = () => {
					document.addEventListener('mouseleave', onMouseLeave);
					window.addEventListener('blur', onBlur);
					if (config.exit_include_hidden) {
						document.addEventListener('visibilitychange', onVisibility);
					}
				};
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

	function ensurePopupStyles() {
		if (document.getElementById('esf-popup-styles')) return;
		const style = document.createElement('style');
		style.id = 'esf-popup-styles';
		// `all: revert !important` on the close button neutralises whatever the
		// host theme has set on <button> selectors so the X stays visible and
		// looks the same on every site.
		style.textContent =
			'.esf-popup-overlay{position:fixed;inset:0;z-index:99999;' +
			'background:rgba(15,17,21,.55);' +
			'-webkit-backdrop-filter:blur(8px) saturate(120%);backdrop-filter:blur(8px) saturate(120%);' +
			'display:flex;justify-content:safe center;align-items:safe center;padding:24px;overflow:auto;' +
			'animation:esf-popup-fade .18s ease-out both;}' +
			// Fullscreen mode: kill the backdrop padding so the modal can fill the
			// viewport edge-to-edge. Modal goes to 100vw/100vh with no rounded
			// corners, and the content area expands to match.
			'.esf-popup-overlay[data-fullscreen="1"]{padding:0;}' +
			// Centering used to be margin:auto on the modal, but a flex item
			// centered via margin:auto (or via the container's align-items/
			// justify-content:center) can't be scrolled to its start edge once
			// it's taller than the overlay — the auto-margin/centering math
			// eats the space overflow:auto would otherwise turn into scroll
			// room. `safe center` above is the fix: center when it fits,
			// fall back to start-aligned (fully scrollable) when it doesn't.
			'.esf-popup-modal{position:relative;max-width:min(92vw,var(--esf-max-width,720px));margin:0;overflow:visible;' +
			'background:transparent;animation:esf-popup-rise .22s ease-out both;}' +
			'.esf-popup-modal[data-fullscreen="1"]{max-width:100vw;width:100vw;height:100vh;border-radius:0;margin:0;}' +
			// The overlay (not modal/content) is the scroll container for a form
			// taller than the viewport, so modal/content stay overflow:visible and
			// a rounded asset's drop-shadow is never clipped. The close button
			// lives in a sticky, zero-height, float:right wrapper: it's still a
			// normal child of modal (part of the card, scrolls with it) but can
			// never scroll out of view while any part of the card is still on
			// screen — see esf-popup-close-wrap below.
			//
			// min-width: the overlay→modal→content→host chain is all shrink-to-fit,
			// so it has no definite width of its own. A form whose root CSS uses
			// `container-type: inline-size` (or any container query) ignores its own
			// contents when sizing, and with nothing above it pinning a width it
			// collapses to 0 — a blank popup with no error. The floor gives the chain
			// a definite width to resolve against. min(...,--esf-max-width,...) keeps
			// the floor from ever exceeding the form's own configured max-width, so
			// normal forms (always wider than 320px) are visually unchanged.
			'.esf-popup-content{position:relative;min-width:min(320px,var(--esf-max-width,720px),92vw);overflow:visible;}' +
			// Minimal scrollbar — thin, no arrow buttons, semi-transparent thumb
			// so it overlays the form's edge instead of looking like a separate
			// native widget. No scrollbar-gutter: the slot is taken only when the
			// form actually scrolls. The close button is JS-offset (see openPopup)
			// to stay a constant distance from the form whether or not it shows.
			'.esf-popup-overlay{scrollbar-width:thin;scrollbar-color:rgba(190,190,190,0.5) transparent;}' +
			'.esf-popup-overlay::-webkit-scrollbar{width:8px;height:8px;}' +
			'.esf-popup-overlay::-webkit-scrollbar-track{background:transparent;}' +
			'.esf-popup-overlay::-webkit-scrollbar-thumb{background:rgba(190,190,190,0.5);border-radius:4px;}' +
			'.esf-popup-overlay::-webkit-scrollbar-thumb:hover{background:rgba(150,150,150,0.5);}' +
			'.esf-popup-overlay::-webkit-scrollbar-button{display:none;width:0;height:0;}' +
			'.esf-popup-overlay::-webkit-scrollbar-corner{background:transparent;}' +
			'.esf-popup-content[data-fullscreen="1"]{height:100vh;overflow:auto;-webkit-overflow-scrolling:touch;}' +
			// Sticky + float:right + height:0 keeps the wrapper out of the modal's
			// normal flow (it doesn't push the form down) while sticking the close
			// button to the top of the nearest scrolling ancestor (the overlay).
			'.esf-popup-close-wrap{position:sticky!important;top:12px!important;float:right!important;height:0!important;z-index:10!important;}' +
			'.esf-popup-close{all:revert!important;' +
			'position:relative!important;margin-right:12px!important;z-index:10!important;' +
			'width:26px!important;height:26px!important;min-width:0!important;min-height:0!important;' +
			'padding:0!important;margin-top:0!important;margin-bottom:0!important;margin-left:0!important;' +
			'display:inline-flex!important;align-items:center!important;justify-content:center!important;' +
			'border:1px solid var(--esf-close-border,rgba(255,255,255,0.15))!important;border-radius:50%!important;' +
			'background:var(--esf-close-bg,rgba(255,255,255,0.10))!important;color:var(--esf-close-fg,rgba(255,255,255,0.7))!important;cursor:pointer!important;' +
			'-webkit-backdrop-filter:blur(6px)!important;backdrop-filter:blur(6px)!important;' +
			'text-shadow:none!important;text-decoration:none!important;letter-spacing:0!important;opacity:1!important;' +
			'transition:background .15s, color .15s, transform .25s!important;}' +
			'.esf-popup-close:hover{background:var(--esf-close-hover-bg,rgba(255,255,255,0.18))!important;color:var(--esf-close-hover-fg,#fff)!important;transform:rotate(90deg)!important;}' +
			'.esf-popup-close:focus-visible{outline:2px solid #2271b1!important;outline-offset:2px!important;}' +
			'.esf-popup-overlay[data-esf-close-theme="dark"]{--esf-close-fg:rgba(0,0,0,0.55);--esf-close-bg:rgba(0,0,0,0.06);--esf-close-border:rgba(0,0,0,0.12);--esf-close-hover-fg:#1d2327;--esf-close-hover-bg:rgba(0,0,0,0.10);}' +
			// isolation:isolate walls off the authored form's own stacking context —
			// without it, a z-index the author sets on their own content (even just to
			// layer two of their own elements) competes directly with the close
			// button's z-index one level up, and a high enough one paints over the
			// button and silently swallows its clicks/taps. Isolating means the
			// button can never be out-stacked no matter what z-index authored CSS uses.
			'.esf-popup-content .esf-host{display:block;isolation:isolate;}' +
			'html.esf-popup-open,body.esf-popup-open{overflow:hidden;}' +
			'@keyframes esf-popup-fade{from{opacity:0}to{opacity:1}}' +
			'@keyframes esf-popup-rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}';
		document.head.appendChild(style);
	}

	function openPopup(popupId, opts) {
		if (!popupId) return;
		opts = opts || {};
		const showClose = opts.showClose !== false;
		const onCloseCb = opts.onClose;

		// Reuse an existing open overlay for the same popup (idempotent clicks).
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

		// Clone the host out of the template (deep clone — includes the JSON payload).
		const cloned = tpl.content.querySelector('.esf-host');
		if (!cloned) return;
		const host = cloned.cloneNode(true);
		content.appendChild(host);

		// Apply the per-form popup max-width. CSS clamps it to 92vw on small
		// screens via min(92vw, var(--esf-max-width, 720px)).
		const maxWidth = parseInt(host.getAttribute('data-esf-max-width'), 10);
		if (maxWidth > 0) {
			modal.style.setProperty('--esf-max-width', maxWidth + 'px');
		}

		// Fullscreen mode overrides the max-width / max-height / padding rules.
		if (host.getAttribute('data-esf-fullscreen') === '1') {
			overlay.dataset.fullscreen = '1';
			modal.dataset.fullscreen = '1';
			content.dataset.fullscreen = '1';
		}

		// Close-button theme — defaults to 'light' (translucent white) for dark
		// form backgrounds. 'dark' flips to translucent black for light forms.
		if (host.getAttribute('data-esf-close-theme') === 'dark') {
			overlay.dataset.esfCloseTheme = 'dark';
		}

		let closeBtn = null;
		let closeWrap = null;
		if (showClose) {
			closeWrap = document.createElement('div');
			closeWrap.className = 'esf-popup-close-wrap';
			closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'esf-popup-close';
			closeBtn.setAttribute('aria-label', 'Close');
			closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"/></svg>';
			closeWrap.appendChild(closeBtn);
		}

		// Close-wrap is a sibling of content (direct child of modal), not a
		// child of content — still part of the card, but sticky (see CSS) so
		// it can't scroll out of view while the card is taller than the screen.
		modal.appendChild(content);
		if (closeWrap) modal.appendChild(closeWrap);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		document.documentElement.classList.add('esf-popup-open');
		document.body.classList.add('esf-popup-open');

		// Keep the close button a constant 12px from the FORM's right edge,
		// not the card edge. A scrollbar takes layout space and widens the
		// card, so offset the button by the scrollbar's measured width. The
		// ResizeObserver re-syncs when a form height change (e.g. a success
		// state) makes the scrollbar appear or disappear.
		let closeObserver = null;
		const syncCloseOffset = () => {
			if (!closeWrap) return;
			// content.offsetWidth includes the scrollbar; clientWidth doesn't.
			const sbw = Math.max(0, content.offsetWidth - content.clientWidth);
			// setProperty with 'important' is required — the .esf-popup-close
			// rule sets margin-right:12px!important (theme-proofing), which a
			// plain inline style cannot override.
			closeWrap.style.setProperty('margin-right', ( 12 + sbw ) + 'px', 'important');
		};
		if (closeBtn && window.ResizeObserver) {
			// Observe `content`: its width changes precisely when the
			// scrollbar appears or disappears, whatever the cause.
			closeObserver = new ResizeObserver(syncCloseOffset);
			closeObserver.observe(content);
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
		// Backdrop click closes only when there's a close button (otherwise the
		// author probably wants the popup to stay until form-driven dismissal).
		if (showClose) {
			overlay.addEventListener('click', e => { if (e.target === overlay) { fireClickEvent(host, '__backdrop__'); close(); } });
		}
		document.addEventListener('keydown', onKey);

		// Mount the form inside the cloned host and wire its submit.
		const shadow = mount(host);
		if (!shadow) return;
		syncCloseOffset(); // form is in the DOM now — measure real scrollbar state
		// Images load async and can push the form past the scroll threshold
		// after open — re-measure as each one finishes.
		shadow.querySelectorAll('img').forEach(img => {
			if (!img.complete) img.addEventListener('load', syncCloseOffset);
		});
		fireEvent(host, 'es_view'); // form became visible (popup opened)
		attachClickTracking(host, shadow);

		const form = shadow.querySelector('form');
		if (!form) return;
		injectHoneypot(form);
		attachEngagementTracking(host, form);
		applyPreviewFormMode(form);
		form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
	}

	// ============================================================
	// Ribbon shell
	// ============================================================
	// A sticky bar at the top or bottom of the page. Same trigger model as
	// auto popups (delay / scroll / inactivity / exit). When the trigger
	// fires, the bar slides into view; close button or successful submit
	// dismisses it for the rest of the page load.

	function initRibbon(boot) {
		const ribbonId = boot.getAttribute('data-esf-ribbon-id');
		if (!ribbonId) return;

		let config;
		try { config = JSON.parse(boot.getAttribute('data-esf-auto-config') || '{}'); }
		catch (e) { return; }
		if (!config || !config.trigger) return;

		// Honour persistent dismissal — if the visitor closed this ribbon
		// with a "don't show again" rule still in effect, skip entirely.
		if (isShellSuppressed(ribbonId, 'ribbon')) return;

		fetchGeo();
		fireEvent(boot, 'es_page_view');

		const position  = boot.getAttribute('data-esf-position') === 'bottom' ? 'bottom' : 'top';
		const showClose = config.close_button !== false;
		const sticky    = config.sticky !== false; // default true
		const fire = () => openRibbon(ribbonId, position, {
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
				const onMouseLeave = e => { if (e.clientY <= 0) fireOnce(); };
				const onBlur = () => { if (document.visibilityState === 'visible') fireOnce(); };
				const onVisibility = () => { if (document.hidden) fireOnce(); };
				function fireOnce() {
					if (fired) return;
					fired = true;
					document.removeEventListener('mouseleave', onMouseLeave);
					window.removeEventListener('blur', onBlur);
					document.removeEventListener('visibilitychange', onVisibility);
					fire();
				}
				// Buffer: don't ARM exit-intent until the visitor has been on the page
				// for exit_buffer_seconds. If they bounce inside that window nothing is
				// armed — so the shell never opens and no impression (es_view) is logged.
				const arm = () => {
					document.addEventListener('mouseleave', onMouseLeave);
					window.addEventListener('blur', onBlur);
					if (config.exit_include_hidden) {
						document.addEventListener('visibilitychange', onVisibility);
					}
				};
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
			// Transparent positioning wrapper — the author's form provides all
			// visuals (background, padding, max-width, etc.). For sticky ribbons,
			// body padding is applied dynamically in openRibbon() so page content
			// pushes down by the ribbon's actual height instead of being overlaid.
			'.esf-ribbon{position:fixed;left:0;right:0;z-index:99998;' +
			'box-sizing:border-box;animation:esf-ribbon-in .5s ease-out both;}' +
			'.esf-ribbon[data-position="top"]{top:0;}' +
			'.esf-ribbon[data-position="bottom"]{bottom:0;animation-name:esf-ribbon-in-bottom;}' +
			// Sit BELOW the WP admin bar (32px on desktop, 46px on mobile) when
			// it\'s present and the ribbon is sticky at the top of the viewport.
			'body.admin-bar .esf-ribbon[data-sticky="1"][data-position="top"]{top:32px;}' +
			'@media screen and (max-width:782px){' +
			'body.admin-bar .esf-ribbon[data-sticky="1"][data-position="top"]{top:46px;}' +
			'}' +
			// Non-sticky ribbon: scrolls with the page. Inserted at the top of
			// the body (or end for position=bottom) and positioned in flow,
			// not fixed. No body padding push needed — it takes its own space.
			'.esf-ribbon[data-sticky="0"]{position:relative;top:auto;bottom:auto;' +
			'animation-name:esf-ribbon-fade;}' +
			// isolation:isolate — see the identical comment on the popup's .esf-host
			// rule. This is what was actually letting Ribbon 2026's own z-index:10
			// content (.ribbon-inner) paint over and eat clicks on the close button
			// (z-index:2) on narrow viewports, where the authored content spans the
			// full ribbon width and reaches the corner the button lives in.
			'.esf-ribbon .esf-host{display:block;isolation:isolate;}' +
			// Close button: floats over the ribbon at top-right of the viewport.
			// all:revert + !important neutralises theme button styles.
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
			'@keyframes esf-ribbon-in{from{transform:translateY(-100%)}to{transform:translateY(0)}}' +
			'@keyframes esf-ribbon-in-bottom{from{transform:translateY(100%)}to{transform:translateY(0)}}' +
			'@keyframes esf-ribbon-fade{from{opacity:0}to{opacity:1}}';
		document.head.appendChild(style);
	}

	function openRibbon(ribbonId, position, opts) {
		if (!ribbonId) return;
		opts = opts || {};
		var showClose = opts.showClose !== false;
		var sticky    = opts.sticky !== false; // default: sticky
		var onClose   = opts.onClose;

		// Idempotent: don't spawn a second ribbon for the same form on the same page.
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

		if (host.getAttribute('data-esf-close-theme') === 'dark') {
			ribbon.dataset.esfCloseTheme = 'dark';
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

		// Where to insert:
		//   sticky top:     anywhere is fine (position:fixed); use body end so it's late in z-order
		//   sticky bottom:  same
		//   non-sticky top: prepend to body so it's at the very top of document flow
		//   non-sticky btm: append to body so it lands at the very end
		if (!sticky && position !== 'bottom') {
			document.body.insertBefore(ribbon, document.body.firstChild);
		} else {
			document.body.appendChild(ribbon);
		}

		// Body padding push — only for sticky ribbons. Non-sticky lives in
		// document flow and naturally occupies its own space. The transition
		// makes the page content slide down to match the ribbon sliding in
		// (and back up when it closes) over the same 0.5s window.
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
				// A sticky BOTTOM ribbon also lifts any slide-in corner card by its
				// height (published as a CSS var) so the two never overlap.
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
			// Animate ribbon out in the direction it came from, while body
			// padding eases back to zero — both over the same 0.5s.
			ribbon.style.transition = 'transform 0.5s ease-out';
			ribbon.style.transform = position === 'bottom' ? 'translateY(100%)' : 'translateY(-100%)';
			if (sticky) document.body.style[padProp] = originalPad || '';
			// Let any lifted slide-in ease back down as the bottom ribbon leaves.
			if (sticky && position === 'bottom') document.documentElement.style.setProperty('--esf-ribbon-bottom-h', '0px');
			setTimeout(function () {
				ribbon.remove();
				if (sticky) document.body.style.transition = originalTransition || '';
				if (ro) ro.disconnect();
				if (typeof onClose === 'function') onClose();
			}, 500);
		}
		// No Esc-to-close: a ribbon is a non-modal banner, not a modal dialog.
		// Esc closing it would surprise a visitor pressing Esc for another reason.
		if (closeBtn) closeBtn.addEventListener('click', function () { fireClickEvent(host, '__close__'); close(); });

		const shadow = mount(host);
		if (!shadow) return;
		fireEvent(host, 'es_view'); // form became visible (ribbon revealed)
		attachClickTracking(host, shadow);

		const form = shadow.querySelector('form');
		if (!form) return;
		injectHoneypot(form);
		attachEngagementTracking(host, form);
		applyPreviewFormMode(form);
		form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
	}

	// ============================================================
	// Slide-in shell
	// ============================================================
	// A small fixed-position card that slides in from the matching side of
	// the screen (bottom-right corner → slides in from the right). Close
	// button is mirrored to the opposite top corner of the card.

	function initSlideIn(boot) {
		const slideInId = boot.getAttribute('data-esf-slide-in-id');
		if (!slideInId) return;

		let config;
		try { config = JSON.parse(boot.getAttribute('data-esf-auto-config') || '{}'); }
		catch (e) { return; }
		if (!config || !config.trigger) return;

		if (isShellSuppressed(slideInId, 'slide-in')) return;

		fetchGeo();
		fireEvent(boot, 'es_page_view');

		const corner    = boot.getAttribute('data-esf-corner') === 'bottom-left' ? 'bottom-left' : 'bottom-right';
		const showClose = config.close_button !== false;
		const fire = () => openSlideIn(slideInId, corner, {
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
				const onMouseLeave = e => { if (e.clientY <= 0) fireOnce(); };
				const onBlur = () => { if (document.visibilityState === 'visible') fireOnce(); };
				const onVisibility = () => { if (document.hidden) fireOnce(); };
				function fireOnce() {
					if (fired) return;
					fired = true;
					document.removeEventListener('mouseleave', onMouseLeave);
					window.removeEventListener('blur', onBlur);
					document.removeEventListener('visibilitychange', onVisibility);
					fire();
				}
				// Buffer: don't ARM exit-intent until the visitor has been on the page
				// for exit_buffer_seconds. If they bounce inside that window nothing is
				// armed — so the shell never opens and no impression (es_view) is logged.
				const arm = () => {
					document.addEventListener('mouseleave', onMouseLeave);
					window.addEventListener('blur', onBlur);
					if (config.exit_include_hidden) {
						document.addEventListener('visibilitychange', onVisibility);
					}
				};
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
			// Card itself — fixed position in the chosen corner. max-width is
			// driven by the form's --esf-max-width custom property (set in JS
			// from the data attribute) with a 92vw fallback for mobile.
			'.esf-slide-in{position:fixed;bottom:calc(20px + var(--esf-ribbon-bottom-h, 0px));transition:bottom .5s ease-out;z-index:99998;' +
			'max-width:min(92vw,var(--esf-max-width,380px));' +
			// Same container-query collapse guard as the popup (see ensurePopupStyles):
			// a fixed-position card with only max-width is shrink-to-fit, so a form
			// using container queries collapses to 0. Floor it, capped at the card's
			// own max-width so a narrow custom max-width is still respected.
			'min-width:min(320px,var(--esf-max-width,380px),92vw);' +
			'box-sizing:border-box;animation:0.5s ease-out both;}' +
			'.esf-slide-in[data-corner="bottom-right"]{right:20px;animation-name:esf-slide-in-from-right;}' +
			'.esf-slide-in[data-corner="bottom-left"]{left:20px;animation-name:esf-slide-in-from-left;}' +
			// isolation:isolate — see the identical comment on the popup's .esf-host rule.
			'.esf-slide-in .esf-host{display:block;isolation:isolate;}' +
			// Close button — mirrored: card at right → close at left, and vice versa.
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
		if (cloned.getAttribute('data-esf-close-theme') === 'dark') {
			card.dataset.esfCloseTheme = 'dark';
		}
		const host = cloned.cloneNode(true);
		card.appendChild(host);

		// Apply max-width via CSS custom property (same pattern as popup modal).
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
			// Animate out in the same direction it came from, then remove.
			card.style.transition = 'transform 0.5s ease-out';
			card.style.transform = corner === 'bottom-left'
				? 'translateX(calc(-100% - 30px))'
				: 'translateX(calc(100% + 30px))';
			setTimeout(function () {
				card.remove();
				if (typeof onClose === 'function') onClose();
			}, 500);
		}
		// No Esc-to-close: a slide-in is a non-modal corner card, not a modal
		// dialog. Esc closing it would surprise a visitor pressing Esc otherwise.
		if (closeBtn) closeBtn.addEventListener('click', function () { fireClickEvent(host, '__close__'); close(); });

		const shadow = mount(host);
		if (!shadow) return;
		fireEvent(host, 'es_view');
		attachClickTracking(host, shadow);

		const form = shadow.querySelector('form');
		if (!form) return;
		injectHoneypot(form);
		attachEngagementTracking(host, form);
		applyPreviewFormMode(form);
		form.addEventListener('submit', e => handleSubmit(host, shadow, form, e));
	}

	// Preview-only: following a link would navigate the preview iframe away from
	// the asset and leave the previewer broken until a reload. Instead we float a
	// hint next to the link showing where it WOULD go on the live page.
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
		} catch (e) { /* swallow */ }
	}

	// One document-level capturing guard: in preview mode, intercept clicks on a
	// real <a href> so the preview iframe never navigates away. Reads the preview
	// flag at click time, so it's a no-op on live pages. composedPath so the link
	// is still found when the asset renders inside a shadow root.
	function installPreviewLinkGuard() {
		document.addEventListener('click', function (e) {
			if (!(window.EmblemStudio && window.EmblemStudio.preview)) return;
			var path = (typeof e.composedPath === 'function') ? e.composedPath() : [e.target];
			var a = null;
			for (var i = 0; i < path.length; i++) {
				var n = path[i];
				if (!n || n.nodeType !== 1) continue;
				// The shell close button has its own preview hint — leave it alone.
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
