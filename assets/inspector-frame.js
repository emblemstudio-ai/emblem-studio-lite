/* =====================================================================
 * Emblem Studio — Inspector frame instrumentation.
 *
 * Runs INSIDE the takeover's middle iframe, in the same document as the
 * mounted form (rendered by ES_Preview::render_inspector_frame). Its job:
 *
 *   - find the mounted form (Shadow DOM or light DOM),
 *   - give every element a stable id (data-esi-uid) so the parent and
 *     this frame can refer to the same element,
 *   - draw a light border on hover and a strong border on the selected
 *     element (overlays float on top — the form's own markup is never
 *     mutated beyond the uid attribute, which is never persisted),
 *   - tell the parent takeover what got selected (postMessage),
 *   - accept "select this uid" / "clear" messages back from the parent.
 *
 * Communicates only with window.parent, same-origin. Vanilla JS.
 * ===================================================================== */
(function () {
	'use strict';

	var MSG = {
		READY: 'es-inspector:ready',
		SELECTED: 'es-inspector:selected',
		DESELECTED: 'es-inspector:deselected', // frame → parent: clicked empty space
		HOVERED: 'es-inspector:hovered',     // frame → parent: pointer moved onto a new element
		SELECT_UID: 'es-inspector:select-uid',
		HOVER_UID: 'es-inspector:hover-uid',
		HOVER_CLEAR: 'es-inspector:hover-clear',
		MATCH_REQUEST: 'es-inspector:match-request',   // parent → frame: do these selectors hit this element?
		MATCH_RESULT: 'es-inspector:match-result',     // frame → parent: the ones that do
		UPDATE_CSS: 'es-inspector:update-css',         // parent → frame: live-swap the form's CSS
		HIGHLIGHT_SELECTOR: 'es-inspector:highlight-selector', // parent → frame: outline all elements a selector hits
		HIGHLIGHT_CLEAR: 'es-inspector:highlight-clear',
		REPLAY: 'es-inspector:replay',                 // parent → frame: re-run entry animations
		ANIMATABLE: 'es-inspector:animatable',         // frame → parent: whether the asset has any CSS animation
		CLEAR: 'es-inspector:clear'
	};

	var affectedBoxes = []; // { box, el } pairs for the selector-affects highlight

	var styleEl = null;   // the form's <style> element inside the mount
	var isShadowMount = false; // true when the form mounts into a ShadowRoot

	// Mirror of frontend.js: a leading @import is render-blocking, so on every
	// live CSS swap we lift @charset/@import out into non-blocking <link>s and
	// keep only the reset + the asset's own rules in the (shadow) style.
	var BOOT_CSS = ':host{all:initial!important;display:block!important;width:100%!important;box-sizing:border-box!important;}';

	// A card narrower than the space it is given sits in the MIDDLE of that space.
	// Zero specificity, so any author margin rule wins without !important. TWIN of
	// CENTER_CSS in frontend.js and the SaaS prepareCss.ts: what this preview shows
	// and what a visitor gets have to be the same picture.
	var CENTER_CSS = ':where(.esf-form){margin-inline:auto;}';
	var _esFontsLoaded = {};
	// A shadow-root <style> that declares @font-face registers that font for
	// the WHOLE document per spec, not just its own tree — so an unscoped name
	// is a shared global slot. Two renders with different CSS (e.g. this
	// frame's live-preview mount vs. an older revision that references the
	// same font NAME but never carried its own @font-face) would then either
	// borrow each other's registration or stomp on it depending on
	// mount/unmount order — a revision with no font of its own should
	// consistently show the fallback, not sometimes-right depending on which
	// sibling preview happens to be open. So instead of lifting @font-face
	// rules as-written, we scope each one to a name unique to ITS OWN content
	// (family + a hash of its own rule text), rewrite every font-family
	// reference to that name within this SAME css string, then lift the
	// renamed rules into one shared, persistent <style> (deduped by exact
	// rule text). Only a render that carries the identical @font-face
	// rule(s) can ever resolve to that scoped name.
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
	// The Design System in front of the form's own CSS, with every @import from
	// both moved to the top (a browser ignores an @import anywhere else). Mirrors
	// ES_Design_System::compose().
	function composeCss(ds, css) {
		css = css || '';
		if (!ds || !ds.trim()) return css;
		var imports = [];
		var split = function (sheet) {
			return sheet.replace(/@import\b(?:\s*url\()?\s*(?:'[^']*'|"[^"]*"|[^;'")]*)(?:\s*\))?\s*[^;{}]*;|@charset\b[^;]*;/gi, function (m) {
				if (/^@import/i.test(m)) imports.push(m.trim());
				return '';
			});
		};
		var dsRules = split(ds);
		var cssRules = split(css);
		return (imports.length ? imports.join('\n') + '\n' : '') + dsRules + '\n' + cssRules;
	}
	function prepareCss(css, withBoot) {
		css = css || '';
		css = scopeAndLiftFontFaces(css);
		// Quote-aware (see frontend.js): font @import URLs may contain ';'.
		var re = /^\s*(?:\/\*[\s\S]*?\*\/\s*)*(@charset\b[^;]*;|@import\b(?:\s*url\()?\s*(?:'[^']*'|"[^"]*"|[^;'")]*)(?:\s*\))?\s*[^;]*;)/i;
		var m;
		while ((m = re.exec(css)) !== null) {
			var rule = m[1];
			css = css.slice(m.index + m[0].length);
			if (/^\s*@charset/i.test(rule)) continue;
			var hm = /url\(\s*['"]?([^'")]+)['"]?\s*\)|@import\s+['"]([^'"]+)['"]/i.exec(rule);
			var href = hm ? (hm[1] || hm[2]) : '';
			if (!href || _esFontsLoaded[href]) continue;
			_esFontsLoaded[href] = true;
			var link = document.createElement('link');
			link.rel = 'stylesheet'; link.href = href; link.media = 'print';
			link.onload = function () { this.media = 'all'; };
			(document.head || document.documentElement).appendChild(link);
		}
		return (withBoot ? BOOT_CSS : '') + CENTER_CSS + css;
	}

	var lastHoverUid = null; // dedupe HOVERED posts to element changes only

	var root = null;       // the form root: a ShadowRoot or the host element
	var uidMap = {};       // uid -> element
	var uidSeq = 0;
	var selectedUid = null;

	// ── Overlays ──────────────────────────────────────────────────
	// Two fixed-position boxes layered over everything. We position them
	// from each target's getBoundingClientRect(), which is in viewport
	// coordinates and works across the shadow boundary.
	var hoverBox = null;
	var selectBox = null;

	function makeBox(kind) {
		var b = document.createElement('div');
		b.setAttribute('data-esi-overlay', kind);
		b.style.cssText = [
			'position:fixed',
			'top:0', 'left:0',
			'pointer-events:none',
			'z-index:2147483646',
			'box-sizing:border-box',
			'display:none',
			'transition:transform 40ms ease, width 40ms ease, height 40ms ease'
		].join(';');
		if (kind === 'hover') {
			b.style.border = '1px solid rgba(79,70,229,0.5)';
			b.style.background = 'rgba(79,70,229,0.06)';
		} else if (kind === 'affected') {
			b.style.border = '1px dashed rgba(79,70,229,0.85)';
			b.style.background = 'rgba(79,70,229,0.10)';
		} else {
			b.style.border = '2px solid #4f46e5';
			b.style.boxShadow = '0 0 0 1px rgba(255,255,255,0.6)';
		}
		document.body.appendChild(b);
		return b;
	}

	function positionBox(box, el) {
		if (!el || !box) return;
		var r = el.getBoundingClientRect();
		if (r.width === 0 && r.height === 0) { box.style.display = 'none'; return; }
		box.style.display = 'block';
		box.style.width = r.width + 'px';
		box.style.height = r.height + 'px';
		box.style.transform = 'translate(' + r.left + 'px,' + r.top + 'px)';
	}

	function hideBox(box) { if (box) box.style.display = 'none'; }

	// ── Element identity ──────────────────────────────────────────
	function indexElements() {
		uidMap = {};
		uidSeq = 0;
		if (!root) return;
		var all = root.querySelectorAll('*');
		for (var i = 0; i < all.length; i++) {
			var el = all[i];
			// Skip our own overlays and any injected <style>/<script>.
			var tag = el.tagName.toLowerCase();
			if (tag === 'style' || tag === 'script') continue;
			var uid = 'u' + (++uidSeq);
			el.setAttribute('data-esi-uid', uid);
			uidMap[uid] = el;
		}
	}

	function describe(el) {
		var tag = el.tagName ? el.tagName.toLowerCase() : '';
		var id = el.id || '';
		var classes = [];
		if (el.classList && el.classList.length) {
			for (var i = 0; i < el.classList.length; i++) {
				classes.push(el.classList[i]);
			}
		}
		// A short, human-readable label: tag#id.first-class
		var label = tag;
		if (id) label += '#' + id;
		else if (classes.length) label += '.' + classes[0];
		var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
		if (text.length > 40) text = text.slice(0, 40) + '…';
		return { tag: tag, id: id, classes: classes, label: label, text: text };
	}

	// Build the layer forest the parent renders on the left: every
	// top-level element the asset mounts, not just the <form>. An asset
	// can wrap the form in a card, sit hero media / headline copy beside
	// the form, or be a pure CTA with no form at all — all of it shows.
	// Runs after indexElements(), so every node already has a uid.
	function buildForest() {
		var out = [];
		if (!root) return out;
		var kids = root.children;
		for (var i = 0; i < kids.length; i++) {
			var c = kids[i];
			var tag = c.tagName.toLowerCase();
			if (tag === 'style' || tag === 'script') continue;
			out.push(nodeToTree(c));
		}
		return out;
	}
	function nodeToTree(el) {
		var info = describe(el);
		var node = {
			uid: el.getAttribute('data-esi-uid'),
			tag: info.tag,
			id: info.id,
			classes: info.classes,
			label: info.label,
			text: info.text,
			children: []
		};
		var kids = el.children;
		for (var i = 0; i < kids.length; i++) {
			var c = kids[i];
			var tag = c.tagName.toLowerCase();
			if (tag === 'style' || tag === 'script') continue;
			node.children.push(nodeToTree(c));
		}
		return node;
	}

	// ── Selector matching (for the relevant-CSS panel) ───────────
	// Split a selector list on top-level commas (parens protect :not()
	// etc. from being split).
	function splitSelectorList(sel) {
		var parts = [], depth = 0, buf = '', inStr = null;
		for (var i = 0; i < sel.length; i++) {
			var c = sel[i];
			if (inStr) { buf += c; if (c === inStr) inStr = null; continue; }
			if (c === '"' || c === "'") { inStr = c; buf += c; continue; }
			if (c === '(') { depth++; buf += c; continue; }
			if (c === ')') { depth--; buf += c; continue; }
			if (c === ',' && depth === 0) { if (buf.trim()) parts.push(buf.trim()); buf = ''; continue; }
			buf += c;
		}
		if (buf.trim()) parts.push(buf.trim());
		return parts;
	}

	// State pseudo-classes and pseudo-elements depend on runtime state we
	// can't force, so we strip them and match the structural remainder —
	// that's how a ".esf-cta:hover" rule still counts as relevant to a
	// resting button.
	var RE_PSEUDO_EL = /::[a-z-]+(?:\([^)]*\))?/gi;
	var RE_STATE = /:(?:hover|focus|focus-within|focus-visible|active|visited|target|checked|enabled|disabled|valid|invalid|in-range|out-of-range|required|optional|placeholder-shown|default|read-only|read-write|user-invalid|user-valid|autofill|link|any-link)\b(?:\([^)]*\))?/gi;

	function stripState(part) {
		return part.replace(RE_PSEUDO_EL, '').replace(RE_STATE, '').trim();
	}

	// Returns null (no match) or { viaState, part } when the element
	// matches — `part` is the specific comma-part that hit, kept for
	// specificity/cascade work on the parent side.
	function matchInfo(selectorText, el) {
		var parts = splitSelectorList(selectorText);
		for (var i = 0; i < parts.length; i++) {
			var p = parts[i];
			try { if (el.matches(p)) return { viaState: false, part: p }; } catch (e) {}
			var s = stripState(p);
			if (s && s !== p) {
				try { if (el.matches(s)) return { viaState: true, part: p }; } catch (e) {}
			}
		}
		return null;
	}

	// Given an event, find the nearest form element (one carrying a uid).
	function elementFromEvent(e) {
		var path = e.composedPath ? e.composedPath() : [];
		for (var i = 0; i < path.length; i++) {
			var node = path[i];
			if (node && node.nodeType === 1 && node.hasAttribute && node.hasAttribute('data-esi-uid')) {
				return node;
			}
		}
		return null;
	}

	// ── Selection ─────────────────────────────────────────────────
	function selectElement(el, notifyParent) {
		if (!el) return;
		selectedUid = el.getAttribute('data-esi-uid');
		// Bring it into view only when it's fully off-screen (e.g. selected
		// from the tree/HTML while scrolled away). If any part is already
		// visible we leave the scroll alone — otherwise decorative elements
		// that poke past the fold (absolute, negative offsets) would jump the
		// asset around on every selection.
		try {
			var r = el.getBoundingClientRect();
			var vh = window.innerHeight || document.documentElement.clientHeight;
			var vw = window.innerWidth || document.documentElement.clientWidth;
			var visible = r.bottom > 0 && r.top < vh && r.right > 0 && r.left < vw;
			if (!visible) el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
		} catch (e) {}
		positionBox(selectBox, el);
		if (notifyParent) {
			var info = describe(el);
			info.uid = selectedUid;
			post(MSG.SELECTED, info);
		}
	}

	function clearSelection() {
		selectedUid = null;
		hideBox(selectBox);
	}

	// Outline every element a selector currently affects (the "what does
	// this rule touch?" highlight). Uses the same matcher as the CSS panel.
	function highlightSelector(sel) {
		clearAffected();
		if (!sel) return;
		for (var uid in uidMap) {
			if (!uidMap.hasOwnProperty(uid)) continue;
			var el = uidMap[uid];
			if (matchInfo(sel, el)) {
				var box = makeBox('affected');
				positionBox(box, el);
				affectedBoxes.push({ box: box, el: el });
			}
		}
	}
	function clearAffected() {
		for (var i = 0; i < affectedBoxes.length; i++) {
			var b = affectedBoxes[i].box;
			if (b && b.parentNode) b.parentNode.removeChild(b);
		}
		affectedBoxes = [];
	}

	// The node set Replay acts on: the shell-entrance wrapper, the host, and
	// every element inside the asset. Shared by replayAnimations() and the
	// animation-presence check so they agree on what "has animation" means.
	function animationNodes() {
		var nodes = [];
		// The shell entrance lives on the .esf-inspect-shell wrapper (the host
		// itself can't carry it — :host{all:initial} would reset it).
		var shell = document.querySelector('.esf-inspect-shell');
		if (shell) nodes.push(shell);
		var host = document.querySelector('.esf-host[data-esf-id]');
		if (host) nodes.push(host);
		if (root) {
			var els = root.querySelectorAll('*');
			for (var i = 0; i < els.length; i++) nodes.push(els[i]);
		}
		return nodes;
	}

	// True when any of those nodes declares a CSS animation (animation-name ≠
	// none) — i.e. there's something for Replay to actually replay.
	function detectAnimatable() {
		var nodes = animationNodes();
		for (var i = 0; i < nodes.length; i++) {
			var name = getComputedStyle(nodes[i]).animationName;
			if (name && name !== 'none') return true;
		}
		return false;
	}

	// Re-run entry animations (the asset's own CSS animations + the tool's
	// shell entrance on .esf-host) by briefly killing animation then forcing
	// a reflow — no re-mount, so selection/uids are preserved.
	function replayAnimations() {
		var nodes = animationNodes();
		for (var j = 0; j < nodes.length; j++) nodes[j].style.animation = 'none';
		void document.body.offsetWidth; // force reflow so the restart takes
		for (var k = 0; k < nodes.length; k++) nodes[k].style.animation = '';
		setTimeout(repositionAll, 0);
	}

	// ── Messaging ─────────────────────────────────────────────────
	function post(type, payload) {
		var msg = { type: type };
		if (payload) for (var k in payload) { if (payload.hasOwnProperty(k)) msg[k] = payload[k]; }
		try { window.parent.postMessage(msg, '*'); } catch (e) {}
	}

	window.addEventListener('message', function (e) {
		// Only trust messages from our parent window.
		if (e.source !== window.parent) return;
		var d = e.data || {};
		if (d.type === MSG.SELECT_UID) {
			var el = uidMap[d.uid];
			// notify=true so tree-driven and click-driven selection share
			// one path back to the parent (panels + tree highlight).
			if (el) selectElement(el, true);
		} else if (d.type === MSG.HOVER_UID) {
			var hel = uidMap[d.uid];
			if (hel) positionBox(hoverBox, hel);
			else hideBox(hoverBox);
		} else if (d.type === MSG.HOVER_CLEAR) {
			hideBox(hoverBox);
		} else if (d.type === MSG.HIGHLIGHT_SELECTOR) {
			highlightSelector(d.selector);
		} else if (d.type === MSG.HIGHLIGHT_CLEAR) {
			clearAffected();
		} else if (d.type === MSG.REPLAY) {
			replayAnimations();
		} else if (d.type === MSG.UPDATE_CSS) {
			if (styleEl) {
				// The parent sends the form's own CSS only. The Design System has to
				// be put back in front of it here, or the first edit silently drops
				// the site's shared layer from the editor (it rendered on open, then
				// vanished on the first keystroke). composeCss() moves every @import
				// to the top, the same rule ES_Design_System::compose() uses.
				styleEl.textContent = prepareCss(composeCss(window.__esInspectorDs || '', d.css), isShadowMount);
				// Layout may shift — realign the selection box. Editing the CSS
				// can add or remove animations, so re-report whether Replay has
				// anything to do (once styles have settled).
				setTimeout(repositionAll, 0);
				setTimeout(function () { post(MSG.ANIMATABLE, { animatable: detectAnimatable() }); }, 0);
			}
		} else if (d.type === MSG.MATCH_REQUEST) {
			var subject = uidMap[d.uid];
			var matches = [];
			if (subject && d.selectors) {
				for (var i = 0; i < d.selectors.length; i++) {
					var info = matchInfo(d.selectors[i].selector, subject);
					if (info) matches.push({ i: d.selectors[i].i, viaState: info.viaState, part: info.part });
				}
			}
			post(MSG.MATCH_RESULT, { uid: d.uid, matches: matches });
		} else if (d.type === MSG.CLEAR) {
			clearSelection();
		}
	});

	// ── Event wiring ──────────────────────────────────────────────
	function onPointerMove(e) {
		var el = elementFromEvent(e);
		if (el) positionBox(hoverBox, el);
		else hideBox(hoverBox);
		// Mirror the hover up to the parent's tree — but only when the
		// element actually changes, so we don't flood postMessage.
		var uid = el ? el.getAttribute('data-esi-uid') : null;
		if (uid !== lastHoverUid) {
			lastHoverUid = uid;
			post(MSG.HOVERED, { uid: uid });
		}
	}

	function onPointerLeave() {
		hideBox(hoverBox);
		if (lastHoverUid !== null) {
			lastHoverUid = null;
			post(MSG.HOVERED, { uid: null });
		}
	}

	function onClick(e) {
		var el = elementFromEvent(e);
		if (!el) {
			// Clicked empty space (not on an asset element) → deselect.
			clearSelection();
			post(MSG.DESELECTED, {});
			return;
		}
		// In the inspector we select rather than activate — stop links,
		// submits, and label/checkbox toggles from firing.
		e.preventDefault();
		e.stopPropagation();
		selectElement(el, true);
	}

	function repositionAll() {
		if (selectedUid && uidMap[selectedUid]) positionBox(selectBox, uidMap[selectedUid]);
		else hideBox(selectBox);
		hideBox(hoverBox);
		for (var i = 0; i < affectedBoxes.length; i++) positionBox(affectedBoxes[i].box, affectedBoxes[i].el);
	}

	// ── Boot: wait for the form to mount, then instrument ─────────
	function locateRoot() {
		var host = document.querySelector('.esf-host[data-esf-id]');
		if (!host) return null;
		// Shadow forms mount into host.shadowRoot; light-DOM forms mount
		// directly inside the host. We consider the asset mounted once the
		// scope holds any real content child (not just the injected
		// <style>) — this covers form assets and pure-CTA assets alike.
		var scope = host.shadowRoot || host;
		var kids = scope.children;
		for (var i = 0; i < kids.length; i++) {
			var tag = kids[i].tagName.toLowerCase();
			if (tag !== 'style' && tag !== 'script') return scope;
		}
		return null;
	}

	function boot() {
		var tries = 0;
		var t = setInterval(function () {
			root = locateRoot();
			if (root) {
				clearInterval(t);
				start();
			} else if (++tries > 100) {
				clearInterval(t); // give up quietly after ~10s
			}
		}, 100);
	}

	function start() {
		hoverBox = makeBox('hover');
		selectBox = makeBox('select');

		// Remember the form's <style> so we can live-swap CSS without a
		// reload (which would drop the selection + tree). Shadow mounts need
		// the reset + font-import handling rebuilt on each swap (see prepareCss).
		var hostEl = document.querySelector('.esf-host[data-esf-id]');
		isShadowMount = !!(hostEl && hostEl.shadowRoot && root === hostEl.shadowRoot);
		styleEl = root.querySelector('style');

		indexElements();
		var roots = buildForest();

		// Capture-phase so we see events before the form's own handlers,
		// and so we can intercept across the shadow boundary.
		document.addEventListener('mousemove', onPointerMove, true);
		document.addEventListener('mouseleave', onPointerLeave, true);
		document.addEventListener('click', onClick, true);
		window.addEventListener('scroll', repositionAll, true);
		window.addEventListener('resize', repositionAll, true);

		post(MSG.READY, {
			count: uidSeq,
			roots: roots,
			animatable: detectAnimatable(),
			shells: (typeof window.__esInspectorShells !== 'undefined') ? window.__esInspectorShells : null,
			shell: (typeof window.__esInspectorShell !== 'undefined') ? window.__esInspectorShell : null
		});

		// The shell's entrance CSS animation (on the .esf-inspect-shell wrapper)
		// fires at first paint — before the form mounts into the host — so it
		// plays on an empty shell and the content just pops in. Re-trigger it now
		// the form is in place so the entrance is actually visible on load
		// (Replay re-runs it on demand).
		var entryEl = document.querySelector('.esf-inspect-shell');
		if (entryEl) {
			requestAnimationFrame(function () {
				entryEl.style.animation = 'none';
				void document.body.offsetWidth;
				entryEl.style.animation = '';
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
