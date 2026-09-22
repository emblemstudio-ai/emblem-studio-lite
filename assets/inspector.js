/* =====================================================================
 * Emblem Studio — Inspector (semi-builder) takeover.
 *
 * A full-screen workspace opened from the Design tab. It does NOT save
 * to the database: on open it reads the HTML + CSS out of the page's
 * CodeMirror editors; on close it writes any changes back into those
 * same editors, and the normal Save button commits them.
 *
 * Build order (see plan): this file currently implements STEP 1 only —
 * the shell: open/close, top bar, three-column frame, and the middle
 * live form (reusing the existing preview render). The tree (left) and
 * relevant-CSS (right) panels are placeholders for the next steps.
 *
 * Vanilla JS, no jQuery. Loaded in the footer on the form-edit screen.
 * ===================================================================== */
(function () {
	'use strict';

	// v1 scope: single-version, saved, inline forms. The launch button is
	// only rendered server-side when those hold, but we re-check the
	// editor ids here so the module is inert if anything is missing.
	var HTML_ID = 'es-html';
	var CSS_ID = 'es-css';

	// ── Editor bridge ─────────────────────────────────────────────
	// Read/write the page's CodeMirror editors. WP's wp.codeEditor mounts
	// CodeMirror either on the textarea itself (el.CodeMirror) or as the
	// textarea's next sibling — handle both, fall back to the raw value.
	function cmFor(id) {
		var el = document.getElementById(id);
		if (!el) return null;
		if (el.CodeMirror) return el.CodeMirror;
		if (el.nextElementSibling && el.nextElementSibling.CodeMirror) {
			return el.nextElementSibling.CodeMirror;
		}
		return null;
	}
	function readEditor(id) {
		var cm = cmFor(id);
		if (cm) return cm.getValue();
		var el = document.getElementById(id);
		return el ? (el.value || '') : '';
	}
	function writeEditor(id, value) {
		var cm = cmFor(id);
		if (cm) {
			cm.setValue(value);
			try { cm.save(); } catch (e) {}
			return;
		}
		var el = document.getElementById(id);
		if (!el) return;
		if (el.value === value) return;
		el.value = value;
		// Setting .value fires nothing, and the Design tab keeps the code in these
		// hidden fields, so the page's unsaved-changes tracker (admin.js) never
		// heard about an edit kept with Done. Leaving then lost it with no warning
		// (SER-1421, item 21 of Catalina's test pass). A bubbling input event is
		// exactly what a person typing would have sent.
		el.dispatchEvent(new Event('input', { bubbles: true }));
	}

	// ── Module state ──────────────────────────────────────────────
	var state = {
		open: false,
		overlay: null,
		iframe: null,      // the middle live-form frame
		html: '',          // working copy, seeded from the editors on open
		css: '',           // working copy
		rules: [],         // parsed stylesheet rules (source order, with offsets)
		dirty: false,      // becomes true once a later step edits the copy
		previewUrl: '',
		selection: null,   // last element selected in the frame { uid, label, ... }
		lastMatches: [],   // matches for the current selection
		pinned: [],        // user-added selectors kept visible even when not matching
		focusSelector: null, // selector whose editor to focus after next render
		confirming: false, // true while a confirmation is showing
		confirmAction: null, // callback to run if the confirmation is accepted
		cards: [],         // rendered rule cards (for the cascade/cross pass)
		editSelector: null, // selector of the focused rule editor (sticky highlight)
		lastCssScrollUid: null, // selection the All view last auto-scrolled to (item 9)
		hoverSelector: null, // selector being hovered (temporary highlight)
		htmlEditing: false, // true while the Edit-HTML panel is open
		htmlCm: null,      // the HTML editor instance
		pushedHistory: false, // whether we pushed a history entry on open
		suppressPop: false, // guard so our own history.back() doesn't re-close
		stageFluid: true,  // canvas is full-bleed (vs a framed fixed width)
		currentShell: null, // which shell the canvas is previewing
		cssView: 'relevant', // 'relevant' (matching rules only) | 'all' (whole sheet)
		cssObserver: null, // IntersectionObserver lazy-mounting All-view editors
		allCm: null,       // the single All-view editor
		allMarks: [],      // dim + strike-through marks in the All-view editor
		allLineClasses: [], // line numbers given the "applies" background
		allRuler: null,    // overview-ruler element for the All-view editor
		rulerScale: 0.95,  // ruler-tick transform: × the position (fixes depth-drift)
		rulerOffset: -17,  // ruler-tick transform: vertical offset (px up)
		htmlView: 'html',  // left panel: 'html' (formatted source) | 'layers' (tree)
		treeRoots: null,   // cached layer forest from the frame
		htmlLeftCm: null,  // the editable formatted-HTML editor
		htmlRanges: {},    // uid -> {start,end} in the formatted HTML
		htmlMarks: [],     // dim/highlight marks in the HTML editor
		htmlErrBar: null,  // inline validation strip under the HTML editor
		htmlErrMark: null, // red-fade mark on the offending markup
		htmlCommitTimer: null, // debounce for re-mounting the asset after edits
		checksTimer: null, // debounce for the live Checks list (SER-1358)
		checksSeq: 0,      // drops a reply that a newer check has overtaken
		checksKey: null,   // the html+css the Checks list currently reflects
		checksOpen: false  // whether the Checks list is expanded
	};

	// ── Live-form render (middle column) ──────────────────────────
	// Reuse the existing preview endpoint: POST the working HTML/CSS into
	// a named iframe, exactly as the inline live-preview does. Later steps
	// will swap this for an instrumented render that supports hover/select.
	function renderStage() {
		if (!state.previewUrl || !state.overlay) return;
		var iframe = state.overlay.querySelector('.es-inspector-frame');
		if (!iframe) return;

		// Spinner until the frame reports ready (re-shown on every re-render).
		var loader = state.overlay.querySelector('.es-inspector-loader');
		if (loader) loader.hidden = false;

		var smodeEl = document.querySelector('input[name="success_mode"]:checked');
		var smode = smodeEl ? smodeEl.value : 'inline_state';
		var smsgEl = document.getElementById('es-success-message');
		var smsg = smsgEl ? smsgEl.value : '';

		var form = document.createElement('form');
		form.method = 'POST';
		form.action = state.previewUrl;
		form.target = iframe.name;
		form.style.display = 'none';
		function add(name, value) {
			var i = document.createElement('input');
			i.type = 'hidden';
			i.name = name;
			i.value = value;
			form.appendChild(i);
		}
		add('preview_html', state.html);
		add('preview_css', state.css);
		add('preview_success_mode', smode);
		add('preview_success_message', smsg);
		add('inspector', '1'); // request the chrome-less instrumented frame
		if (state.currentShell) add('inspector_shell', state.currentShell);
		document.body.appendChild(form);
		form.submit();
		setTimeout(function () {
			try { document.body.removeChild(form); } catch (e) {}
		}, 200);
	}

	// ── Build the takeover DOM ────────────────────────────────────
	function buildOverlay() {
		var nameEl = document.getElementById('es-name');
		var formName = (nameEl && nameEl.value.trim()) || 'Untitled form';

		var overlay = document.createElement('div');
		overlay.className = 'es-inspector-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-label', 'Asset editor');

		var frameName = 'es-inspector-frame-' + Date.now();

		overlay.innerHTML =
			'<div class="es-inspector-shell">' +
				'<div class="es-inspector-topbar">' +
					'<div class="es-inspector-brand">' +
						'<span class="es-inspector-eyebrow">Editor</span>' +
						'<span class="es-inspector-title"></span>' +
					'</div>' +
					// Checks list (SER-1358): a compact summary that expands.
					'<div class="es-inspector-checks" hidden>' +
						'<button type="button" class="es-inspector-checks-toggle" aria-expanded="false"></button>' +
						'<div class="es-inspector-checks-panel" hidden></div>' +
					'</div>' +
					'<div class="es-inspector-actions">' +
						'<button type="button" class="es-inspector-btn es-inspector-replay" data-es-tip="Replay entry animations">↻ Replay</button>' +
						'<button type="button" class="es-inspector-btn es-inspector-fullscreen" aria-pressed="false">Full screen</button>' +
						'<button type="button" class="es-inspector-btn es-inspector-cancel">Cancel</button>' +
						'<button type="button" class="es-inspector-btn is-primary es-inspector-done">Done</button>' +
					'</div>' +
				'</div>' +
				'<div class="es-inspector-body">' +
					'<aside class="es-inspector-col es-inspector-col-tree">' +
						'<div class="es-inspector-col-head">' +
							'<span class="es-inspector-col-title">Structure</span>' +
							'<div class="es-inspector-leftview" role="group" aria-label="Structure view">' +
								'<button type="button" class="es-inspector-leftview-btn" data-view="html">HTML</button>' +
								'<button type="button" class="es-inspector-leftview-btn" data-view="layers">Layers</button>' +
							'</div>' +
						'</div>' +
						'<div class="es-inspector-col-scroll">' +
							'<p class="es-inspector-placeholder">Reading form…</p>' +
						'</div>' +
					'</aside>' +
					'<main class="es-inspector-col es-inspector-col-stage">' +
						'<div class="es-inspector-col-head">' +
							'<span class="es-inspector-col-title">Live asset</span>' +
							'<div class="es-inspector-shells" role="group" aria-label="Shell"></div>' +
							'<div class="es-inspector-width" role="group" aria-label="Preview width">' +
								'<button type="button" data-w="320">320</button>' +
								'<button type="button" data-w="480">480</button>' +
								'<button type="button" data-w="768">768</button>' +
								'<button type="button" data-w="1024">1024</button>' +
								'<button type="button" data-w="fluid">Fluid</button>' +
								'<input type="range" class="es-inspector-width-slider" min="280" max="2560" step="10" aria-label="Custom width">' +
								'<span class="es-inspector-width-label">Fluid</span>' +
							'</div>' +
						'</div>' +
						'<div class="es-inspector-stage-wrap">' +
							'<iframe class="es-inspector-frame" name="' + frameName + '" title="Live asset"></iframe>' +
							'<div class="es-inspector-loader"><span class="es-inspector-spinner" aria-hidden="true"></span></div>' +
						'</div>' +
					'</main>' +
					'<aside class="es-inspector-col es-inspector-col-css">' +
						'<div class="es-inspector-col-head">' +
							'<span class="es-inspector-col-title">CSS</span>' +
							'<div class="es-inspector-cssview" role="group" aria-label="CSS view">' +
								'<button type="button" class="es-inspector-cssview-btn" data-view="all">All</button>' +
								'<button type="button" class="es-inspector-cssview-btn" data-view="relevant">Relevant</button>' +
							'</div>' +
						'</div>' +
						'<div class="es-inspector-col-scroll">' +
							'<p class="es-inspector-placeholder">Select an element to see the CSS that applies to it.</p>' +
						'</div>' +
					'</aside>' +
				'</div>' +
				'<div class="es-inspector-confirm" hidden>' +
					'<div class="es-inspector-confirm-card">' +
						'<p class="es-inspector-confirm-title"></p>' +
						'<p class="es-inspector-confirm-text"></p>' +
						'<div class="es-inspector-confirm-actions">' +
							'<button type="button" class="es-inspector-btn es-inspector-confirm-cancel">Cancel</button>' +
							'<button type="button" class="es-inspector-btn is-danger es-inspector-confirm-ok">OK</button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';

		// Text content set via property (avoids HTML-escaping the form name).
		overlay.querySelector('.es-inspector-title').textContent = formName;

		// Done = apply edits back to the editors; Cancel = discard.
		overlay.querySelector('.es-inspector-done').addEventListener('click', applyAndClose);
		overlay.querySelector('.es-inspector-cancel').addEventListener('click', requestCancel);
		overlay.querySelector('.es-inspector-replay').addEventListener('click', function () { postToFrame('es-inspector:replay'); });
		overlay.querySelector('.es-inspector-confirm-cancel').addEventListener('click', hideConfirm);
		overlay.querySelector('.es-inspector-confirm-ok').addEventListener('click', function () {
			var fn = state.confirmAction;
			hideConfirm();
			if (fn) fn();
		});

		overlay.querySelector('.es-inspector-checks-toggle').addEventListener('click', function () { setChecksOpen(!state.checksOpen); });

		// Full-screen toggle — hides BOTH side panels at once.
		overlay.querySelector('.es-inspector-fullscreen').addEventListener('click', toggleFullscreen);

		// Width toolbar: preset buttons + slider.
		var widthBtns = overlay.querySelectorAll('.es-inspector-width button');
		for (var w = 0; w < widthBtns.length; w++) {
			(function (btn) {
				btn.addEventListener('click', function () { setStageWidth(btn.getAttribute('data-w')); });
			})(widthBtns[w]);
		}
		var slider = overlay.querySelector('.es-inspector-width-slider');
		if (slider) slider.addEventListener('input', function () { setStageWidthFromSlider(slider.value); });

		// CSS view toggle (Relevant ⇄ All).
		var viewBtns = overlay.querySelectorAll('.es-inspector-cssview-btn');
		for (var v = 0; v < viewBtns.length; v++) {
			(function (btn) {
				btn.addEventListener('click', function () { setCssView(btn.getAttribute('data-view')); });
			})(viewBtns[v]);
		}

		// Left view toggle (HTML ⇄ Layers).
		var leftBtns = overlay.querySelectorAll('.es-inspector-leftview-btn');
		for (var lv = 0; lv < leftBtns.length; lv++) {
			(function (btn) {
				btn.addEventListener('click', function () { setHtmlView(btn.getAttribute('data-view')); });
			})(leftBtns[lv]);
		}

		return overlay;
	}

	// ── Full screen (both side panels) ────────────────────────────
	function toggleFullscreen() {
		var body = state.overlay && state.overlay.querySelector('.es-inspector-body');
		if (!body) return;
		var on = body.classList.toggle('is-fullscreen');
		setFullscreenButton(on);
		// Exiting re-reveals the CSS editors — refresh their layout.
		if (!on) setTimeout(refreshAllRuleEditors, 0);
	}
	function setFullscreenButton(on) {
		var btn = state.overlay && state.overlay.querySelector('.es-inspector-fullscreen');
		if (!btn) return;
		btn.classList.toggle('is-active', on);
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		btn.textContent = on ? 'Exit full screen' : 'Full screen';
	}
	function refreshAllRuleEditors() {
		for (var i = 0; i < state.cards.length; i++) {
			if (state.cards[i].cm) { try { state.cards[i].cm.refresh(); } catch (e) {} }
		}
	}

	// ── Canvas width toolbar ──────────────────────────────────────
	// Two modes: a fixed preset frames the canvas as a centered device;
	// Fluid is full-bleed. Switching to Fluid keeps the CURRENT width (it
	// doesn't jump) — the slider stays the source of truth.
	function widthEls() {
		var group = state.overlay && state.overlay.querySelector('.es-inspector-width');
		return {
			iframe: state.overlay && state.overlay.querySelector('.es-inspector-frame'),
			wrap: state.overlay && state.overlay.querySelector('.es-inspector-stage-wrap'),
			group: group,
			slider: group && group.querySelector('.es-inspector-width-slider'),
			label: group && group.querySelector('.es-inspector-width-label')
		};
	}
	function setActiveWidthBtn(w) {
		var els = widthEls();
		if (!els.group) return;
		var btns = els.group.querySelectorAll('button');
		for (var i = 0; i < btns.length; i++) {
			btns[i].classList.toggle('is-active', btns[i].getAttribute('data-w') === (w === null ? '\0' : String(w)));
		}
	}
	function setStageWidth(w) {
		if (!state.overlay) return;
		var els = widthEls();
		if (w === 'fluid') {
			// Keep whatever width is showing; don't jump to full width.
			state.stageFluid = true;
			if (els.label) els.label.textContent = 'Fluid';
			setActiveWidthBtn('fluid');
		} else {
			var px = parseInt(w, 10) || 0;
			state.stageFluid = false;
			if (els.iframe) els.iframe.style.width = px + 'px';
			if (els.slider) els.slider.value = String(px);
			if (els.label) els.label.textContent = px + 'px';
			setActiveWidthBtn(px);
		}
	}
	// Dragging the slider sets a custom width within the current mode.
	function setStageWidthFromSlider(value) {
		var els = widthEls();
		var px = parseInt(value, 10) || 0;
		if (els.iframe) els.iframe.style.width = px + 'px';
		if (els.label) els.label.textContent = px + 'px';
		setActiveWidthBtn(state.stageFluid ? 'fluid' : null);
	}

	// Lightweight HTML sanity check: balanced tags, ignoring void elements,
	// self-closing tags, comments, and the optional-close set (li, td, p, …).
	// Best-effort — catches the common "forgot to close a tag" mistakes.
	function validateHtml(html) {
		var voids = { area: 1, base: 1, br: 1, col: 1, embed: 1, hr: 1, img: 1, input: 1, link: 1, meta: 1, param: 1, source: 1, track: 1, wbr: 1 };
		var optional = { li: 1, dt: 1, dd: 1, p: 1, option: 1, thead: 1, tbody: 1, tfoot: 1, tr: 1, td: 1, th: 1, colgroup: 1, caption: 1 };
		var re = /<!--[\s\S]*?-->|<([a-zA-Z][\w:-]*)((?:"[^"]*"|'[^']*'|[^>"'])*?)(\/?)>|<\/([a-zA-Z][\w:-]*)\s*>/g;
		var stack = [], m;
		while ((m = re.exec(html)) !== null) {
			if (m[0].indexOf('<!--') === 0) continue;
			var here = { start: m.index, end: m.index + m[0].length };
			if (m[1]) {
				var tag = m[1].toLowerCase();
				if (voids[tag] || m[3] === '/') continue;
				stack.push({ tag: tag, start: here.start, end: here.end });
			} else if (m[4]) {
				var ctag = m[4].toLowerCase();
				if (voids[ctag]) continue;
				if (stack.length && stack[stack.length - 1].tag === ctag) { stack.pop(); continue; }
				// Look deeper, popping optional-close tags on the way.
				var found = -1;
				for (var i = stack.length - 1; i >= 0; i--) { if (stack[i].tag === ctag) { found = i; break; } }
				if (found < 0) {
					return { ok: false, error: 'Unexpected </' + ctag + '> with no matching open tag.', start: here.start, end: here.end };
				}
				for (var j = stack.length - 1; j > found; j--) {
					if (!optional[stack[j].tag]) {
						return { ok: false, error: 'Missing </' + stack[j].tag + '> before </' + ctag + '>.', start: stack[j].start, end: stack[j].end };
					}
				}
				stack.length = found;
			}
		}
		while (stack.length && optional[stack[stack.length - 1].tag]) stack.pop();
		if (stack.length) {
			var top = stack[stack.length - 1];
			return { ok: false, error: 'Unclosed <' + top.tag + '> tag.', start: top.start, end: top.end };
		}
		return { ok: true, error: null };
	}

	// Cancel intent: confirm first when there are unsaved edits, otherwise
	// just close without writing anything back.
	function requestCancel() {
		if (!state.dirty) { close(false); return; }
		openConfirm({
			title: 'Discard changes?',
			text: 'The edits you made in here will be lost.',
			confirmLabel: 'Discard',
			onConfirm: function () { close(false); }
		});
	}
	function applyAndClose() { close(true); }

	// Generic confirm dialog. opts: { title, text, confirmLabel, onConfirm }.
	function openConfirm(opts) {
		if (!state.overlay) return;
		var c = state.overlay.querySelector('.es-inspector-confirm');
		if (!c) return;
		c.querySelector('.es-inspector-confirm-title').textContent = opts.title || 'Are you sure?';
		c.querySelector('.es-inspector-confirm-text').textContent = opts.text || '';
		c.querySelector('.es-inspector-confirm-ok').textContent = opts.confirmLabel || 'OK';
		state.confirmAction = opts.onConfirm || null;
		c.hidden = false;
		state.confirming = true;
	}
	function hideConfirm() {
		if (!state.overlay) return;
		var c = state.overlay.querySelector('.es-inspector-confirm');
		if (c) c.hidden = true;
		state.confirming = false;
		state.confirmAction = null;
	}

	// ── Open / close ──────────────────────────────────────────────
	function open() {
		if (state.open) return;

		// The preview URL lives on the Design-tab preview (data-preview-url);
		// fall back to the legacy Preview button until it's removed.
		var urlSrc = document.querySelector('.es-design-preview[data-preview-url]') || document.querySelector('.es-preview-btn');
		state.previewUrl = urlSrc ? urlSrc.getAttribute('data-preview-url') : '';
		if (!state.previewUrl) {
			// Without a preview URL we can't render the middle stage.
			// (Should not happen on a saved form — fail quietly.)
			return;
		}

		// Seed the working copies from the live editors. Tidy the CSS to
		// standard indentation/spacing on open; if that changed anything,
		// mark dirty so Done saves the cleaned-up version (Cancel keeps the
		// original).
		// A/B forms: the editor targets whichever variant the tabs have active.
		var slot = (window.EmblemStudioAB && window.EmblemStudioAB.testRunning) ? window.EmblemStudioAB.activeSlot : '';
		state.variantSlot = slot || '';
		state.htmlId = slot ? ('es-html-' + slot) : HTML_ID;
		state.cssId = slot ? ('es-css-' + slot) : CSS_ID;
		state.html = readEditor(state.htmlId);
		var rawCss = readEditor(state.cssId);
		var tidy = formatCss(rawCss);
		state.css = tidy;
		state.rules = parseStylesheet(state.css);
		state.dirty = (tidy !== rawCss);

		state.overlay = buildOverlay();
		document.body.appendChild(state.overlay);
		// Show which A/B variant is being edited, if any.
		if (state.variantSlot) {
			var eyebrow = state.overlay.querySelector('.es-inspector-eyebrow');
			if (eyebrow) eyebrow.textContent = 'Editor · Variant ' + state.variantSlot;
		}
		// Width + full-screen always start at their defaults (Fluid, panels
		// shown) — they are session-only, never restored across opens.
		setStageWidth('fluid');
		state.iframe = state.overlay.querySelector('.es-inspector-frame');
		document.documentElement.classList.add('es-inspector-locked');
		document.body.classList.add('es-inspector-locked');
		document.addEventListener('keydown', onKeydown, true);
		window.addEventListener('message', onFrameMessage);
		window.addEventListener('popstate', onPopState);
		window.addEventListener('beforeunload', onBeforeUnload);
		document.addEventListener('visibilitychange', onVisibilityRefresh);
		window.addEventListener('focus', onVisibilityRefresh);
		pushUrlState();
		state.selection = null;
		state.lastCssScrollUid = null;
		state.lastMatches = [];
		state.pinned = [];
		state.focusSelector = null;
		state.confirming = false;
		state.confirmAction = null;
		state.cards = [];
		state.editSelector = null;
		state.hoverSelector = null;
		state.htmlEditing = false;
		state.htmlCm = null;
		state.suppressPop = false;
		state.currentShell = null;
		state.cssView = 'all'; // always open on the full stylesheet (not persisted)
		state.htmlView = 'html'; // left panel opens on the HTML source (not persisted)
		state.treeRoots = null;
		state.htmlLeftCm = null;
		state.htmlRanges = {};
		state.htmlMarks = [];
		state.checksKey = null;
		state.checksOpen = false;
		state.open = true;

		// Render both side panels now (they only need the working copies, not
		// the frame) so they open in their default views — no flash.
		applyCssViewUI();
		refreshCssPanel();
		applyHtmlViewUI();
		refreshLeftPanel();
		renderStage();
		runChecks(); // straight away on open, no debounce
	}

	// close(true)  → apply: write edits back to the editors (Done).
	// close(false) → discard: leave the editors untouched (Cancel).
	// fromHistory  → invoked by a Back navigation; don't touch history again.
	function close(apply, fromHistory) {
		if (!state.open) return;

		if (apply && state.dirty) {
			// Flush the live editors so the latest (possibly un-debounced) edits
			// are captured before writing back.
			if (state.allCm) { try { state.css = state.allCm.getValue(); } catch (e) {} }
			if (state.htmlLeftCm) { try { state.html = state.htmlLeftCm.getValue(); } catch (e) {} }
			writeEditor(state.htmlId || HTML_ID, state.html);
			writeEditor(state.cssId || CSS_ID, state.css);
		}

		if (state.checksTimer) { clearTimeout(state.checksTimer); state.checksTimer = null; }
		state.checksSeq++; // a reply still in flight must not touch the removed overlay
		state.checksOpen = false;
		teardownCssObserver();
		destroyAllCm();
		destroyHtmlLeftCm();
		if (window.ESInspectorColor && window.ESInspectorColor.close) window.ESInspectorColor.close();
		document.removeEventListener('keydown', onKeydown, true);
		window.removeEventListener('message', onFrameMessage);
		window.removeEventListener('popstate', onPopState);
		window.removeEventListener('beforeunload', onBeforeUnload);
		document.removeEventListener('visibilitychange', onVisibilityRefresh);
		window.removeEventListener('focus', onVisibilityRefresh);
		if (!fromHistory) restoreUrlState();
		document.documentElement.classList.remove('es-inspector-locked');
		document.body.classList.remove('es-inspector-locked');
		if (state.overlay && state.overlay.parentNode) {
			state.overlay.parentNode.removeChild(state.overlay);
		}
		state.overlay = null;
		state.iframe = null;
		state.selection = null;
		state.lastCssScrollUid = null;
		state.confirming = false;
		state.confirmAction = null;
		state.htmlEditing = false;
		state.htmlCm = null;
		state.open = false;

		// Let the page (e.g. the Design-tab preview) refresh from the editors.
		try { document.dispatchEvent(new CustomEvent('es:editor-closed', { detail: { applied: !!apply } })); } catch (e) {}
	}

	// ── URL state ─────────────────────────────────────────────────
	// Reflect the open inspector in the URL (?es-inspect=open) and push a
	// history entry so the browser Back button closes the takeover instead
	// of leaving the page.
	var INSPECT_PARAM = 'es-inspect';
	function urlWantsInspector() {
		try { return new URL(window.location.href).searchParams.get(INSPECT_PARAM) === 'open'; }
		catch (e) { return false; }
	}
	function pushUrlState() {
		try {
			var url = new URL(window.location.href);
			if (url.searchParams.get(INSPECT_PARAM) === 'open') {
				// Already present (e.g. opened from a refreshed link) — adopt
				// it without adding another history entry. close() will strip
				// the param via replaceState since we didn't push.
				state.pushedHistory = false;
				return;
			}
			url.searchParams.set(INSPECT_PARAM, 'open');
			history.pushState({ esInspector: true }, '', url.toString());
			state.pushedHistory = true;
		} catch (e) { state.pushedHistory = false; }
	}
	function restoreUrlState() {
		try {
			if (state.pushedHistory) {
				// Pop our pushed entry to restore the prior URL + history.
				state.pushedHistory = false;
				state.suppressPop = true;
				history.back();
			} else {
				var url = new URL(window.location.href);
				if (url.searchParams.has(INSPECT_PARAM)) {
					url.searchParams.delete(INSPECT_PARAM);
					history.replaceState(null, '', url.toString());
				}
			}
		} catch (e) {}
	}
	function onPopState() {
		// Our own history.back() (from restoreUrlState) — swallow once.
		if (state.suppressPop) { state.suppressPop = false; return; }
		if (!state.open) return;
		// Back with edits made in here: put the overlay's history entry back, so
		// nothing moves, and ask the same question Cancel asks. Back used to
		// throw the edits away without a word (SER-1421). If they say Discard,
		// close() pops the entry again through restoreUrlState().
		if (state.dirty) {
			state.pushedHistory = false;
			pushUrlState();
			requestCancel();
			return;
		}
		// Nothing to lose: close. The browser has already restored the URL, so
		// don't touch history again.
		state.pushedHistory = false;
		close(false, true);
	}

	// A reload or a closed tab while the overlay holds edits. The page's own
	// tracker cannot see them until Done writes them back (SER-1421).
	function onBeforeUnload(e) {
		if (!state.open || !state.dirty) return;
		e.preventDefault();
		e.returnValue = '';
	}

	// Enable/disable the Replay button to match whether the asset actually has
	// an entry animation — with an explanatory tooltip when it doesn't.
	function setReplayEnabled(on) {
		var btn = state.overlay && state.overlay.querySelector('.es-inspector-replay');
		if (!btn) return;
		btn.disabled = !on;
		btn.setAttribute('data-es-tip', on ? 'Replay entry animations' : 'This asset has no animation');
	}

	// ── Messages from the live-form frame ─────────────────────────
	function onFrameMessage(e) {
		// Only trust the takeover's own iframe.
		if (!state.iframe || e.source !== state.iframe.contentWindow) return;
		var d = e.data || {};
		if (d.type === 'es-inspector:ready') {
			var loader = state.overlay && state.overlay.querySelector('.es-inspector-loader');
			// One frame later, so the shell entrance starts under the veil.
			if (loader) requestAnimationFrame(function () { loader.hidden = true; });
			state.treeRoots = d.roots;
			setReplayEnabled(!!d.animatable);
			renderShellSwitcher(d.shells, d.shell);
			applyHtmlViewUI();
			// The HTML view doesn't depend on the frame (it's rendered on open
			// from the working copy); only Layers needs the fresh tree.
			if (state.htmlView === 'layers') refreshLeftPanel();
		} else if (d.type === 'es-inspector:selected') {
			state.selection = d;
			highlightTreeRow(d.uid);
			if (state.htmlView === 'html') highlightHtml(d.uid, true);
			updateCssHead(d);
			if (state.cssView === 'all') {
				requestCss(d.uid); // cards already built; result updates the dimming
			} else {
				setRulesMessage('Finding matching CSS…');
				requestCss(d.uid);
			}
		} else if (d.type === 'es-inspector:animatable') {
			// Live CSS edit may have added/removed an animation.
			setReplayEnabled(!!d.animatable);
		} else if (d.type === 'es-inspector:deselected') {
			// Clicked empty space → clear the selection everywhere.
			state.selection = null;
			state.lastMatches = [];
			state.lastCssScrollUid = null; // picking it again scrolls again
			highlightTreeRow(null);
			if (state.htmlView === 'html') highlightHtml(null);
			updateCssHead(null);
			if (state.cssView === 'all') decorateAllView();
			else setRulesMessage('Select an element to see the CSS that applies to it.');
		} else if (d.type === 'es-inspector:hovered') {
			hoverTreeRow(d.uid);
			// In HTML view, mirror the live hover; restore the selection's
			// highlight when the pointer leaves an element.
			if (state.htmlView === 'html' && state.htmlLeftCm) {
				highlightHtml(d.uid || (state.selection ? state.selection.uid : null), false);
			}
		} else if (d.type === 'es-inspector:match-result') {
			// Ignore stale results from a selection the user already moved off.
			if (state.selection && d.uid === state.selection.uid) {
				if (state.cssView === 'all') { state.lastMatches = d.matches || []; decorateAllView(); }
				else renderCssRules(d.matches);
			}
		}
	}

	// Soft-highlight the tree row matching the element hovered in the
	// middle (distinct from the persistent selection highlight).
	function hoverTreeRow(uid) {
		if (!state.overlay) return;
		var prev = state.overlay.querySelectorAll('.es-inspector-node.is-hovered');
		for (var i = 0; i < prev.length; i++) prev[i].classList.remove('is-hovered');
		if (!uid) return;
		var row = state.overlay.querySelector('.es-inspector-node[data-uid="' + uid + '"]');
		if (row) {
			row.classList.add('is-hovered');
			ensureRowVisible(row);
		}
	}

	// Scroll the tree's own container (never the page) just enough to
	// bring a row into view. No-op when the row is already visible.
	function ensureRowVisible(row) {
		var scroll = state.overlay && state.overlay.querySelector('.es-inspector-col-tree .es-inspector-col-scroll');
		if (!scroll || !row) return;
		var s = scroll.getBoundingClientRect();
		var r = row.getBoundingClientRect();
		var pad = 8;
		if (r.top < s.top) {
			scroll.scrollTop -= (s.top - r.top) + pad;
		} else if (r.bottom > s.bottom) {
			scroll.scrollTop += (r.bottom - s.bottom) + pad;
		}
	}

	// Send a message down into the live-form frame.
	function postToFrame(type, payload) {
		if (!state.iframe || !state.iframe.contentWindow) return;
		var msg = { type: type };
		if (payload) for (var k in payload) { if (payload.hasOwnProperty(k)) msg[k] = payload[k]; }
		state.iframe.contentWindow.postMessage(msg, '*');
	}

	// ── Shell switcher (middle header) ────────────────────────────
	// Tabs for only the shells this form has enabled. Switching reloads the
	// canvas in that shell (which resets selection — a new context).
	function renderShellSwitcher(shells, current) {
		var box = state.overlay && state.overlay.querySelector('.es-inspector-shells');
		if (!box) return;
		box.textContent = '';
		if (current) state.currentShell = current;
		// A single shell needs no switcher.
		if (!shells || shells.length < 2) return;
		for (var i = 0; i < shells.length; i++) {
			(function (sh) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'es-inspector-shell-tab';
				btn.textContent = sh.label;
				if (sh.key === state.currentShell) btn.classList.add('is-active');
				btn.addEventListener('click', function () {
					if (sh.key === state.currentShell) return;
					state.currentShell = sh.key;
					resetCanvasSelection();
					renderStage(); // reload the canvas in the chosen shell
				});
				box.appendChild(btn);
			})(shells[i]);
		}
	}

	// Clear the selection + CSS panel (the tree is rebuilt by the next READY).
	function resetCanvasSelection() {
		state.selection = null;
		state.lastCssScrollUid = null;
		state.lastMatches = [];
		clearHighlights();
		clearHtmlHighlight();
		refreshCssPanel();
	}

	// ── Left panel: HTML view ⇄ Layers view ───────────────────────
	function setHtmlView(view) {
		view = (view === 'layers') ? 'layers' : 'html';
		if (view === state.htmlView) return;
		state.htmlView = view;
		applyHtmlViewUI();
		refreshLeftPanel();
	}
	function applyHtmlViewUI() {
		if (!state.overlay) return;
		var btns = state.overlay.querySelectorAll('.es-inspector-leftview-btn');
		for (var i = 0; i < btns.length; i++) {
			btns[i].classList.toggle('is-active', btns[i].getAttribute('data-view') === state.htmlView);
		}
	}
	function leftScroll() {
		return state.overlay && state.overlay.querySelector('.es-inspector-col-tree .es-inspector-col-scroll');
	}
	function destroyHtmlLeftCm() {
		clearHtmlHighlight();
		if (state.htmlCommitTimer) { clearTimeout(state.htmlCommitTimer); state.htmlCommitTimer = null; }
		if (state.htmlErrMark) { try { state.htmlErrMark.clear(); } catch (e) {} state.htmlErrMark = null; }
		state.htmlLeftCm = null;
		state.htmlRanges = {};
		state.htmlErrBar = null;
	}
	function refreshLeftPanel() {
		if (!state.overlay) return;
		var col = state.overlay.querySelector('.es-inspector-col-tree');
		if (col) col.classList.toggle('is-htmlview', state.htmlView === 'html');
		if (state.htmlView === 'html') {
			renderHtmlView();
		} else {
			destroyHtmlLeftCm();
			renderTree(state.treeRoots);
			if (state.selection) highlightTreeRow(state.selection.uid);
		}
	}

	// Editable, auto-formatted HTML of the asset. Selecting/hovering an
	// element (here or in the live view) highlights its markup; typing edits
	// the working copy and re-mounts the live asset (debounced).
	function renderHtmlView() {
		var scroll = leftScroll();
		if (!scroll) return;
		destroyHtmlLeftCm();
		scroll.textContent = '';
		var host = document.createElement('div');
		host.className = 'es-inspector-htmlview';
		scroll.appendChild(host);
		var errBar = document.createElement('div');
		errBar.className = 'es-inspector-html-error';
		errBar.hidden = true;
		scroll.appendChild(errBar);
		state.htmlErrBar = errBar;

		var formatted = formatHtml(state.html);
		state.htmlRanges = parseHtmlRanges(formatted);
		var cm = createEditor(host, formatted, { mode: 'htmlmixed', lineNumbers: false, autoHeight: false });
		if (!cm) {
			var ta = document.createElement('textarea');
			ta.className = 'es-inspector-html-textarea';
			ta.spellcheck = false;
			ta.value = formatted;
			host.appendChild(ta);
			state.html = formatted;
			ta.addEventListener('input', function () { state.html = ta.value; state.dirty = true; scheduleHtmlCommit(); });
			return;
		}
		state.htmlLeftCm = cm;
		var htmlReady = false; // ignore the editor's own init before first paint
		cm.on('change', function (inst, ch) {
			if (!htmlReady || (ch && ch.origin === 'setValue')) return;
			state.html = cm.getValue();
			state.dirty = true;
			scheduleHtmlCommit();
		});

		var wrap = cm.getWrapperElement();
		// Click a line → select that element in the live view (and tree/CSS).
		wrap.addEventListener('mousedown', function () {
			setTimeout(function () {
				var uid = htmlPickUid(cm.indexFromPos(cm.getCursor()));
				if (uid) selectByUid(uid);
			}, 0);
		});
		// Hover a line → highlight that element in the live view.
		var lastHover = null;
		wrap.addEventListener('mousemove', function (e) {
			var uid = htmlPickUid(cm.indexFromPos(cm.coordsChar({ left: e.clientX, top: e.clientY }, 'window')));
			if (uid === lastHover) return;
			lastHover = uid;
			if (uid) postToFrame('es-inspector:hover-uid', { uid: uid });
			else postToFrame('es-inspector:hover-clear');
		});
		wrap.addEventListener('mouseleave', function () {
			if (lastHover === null) return;
			lastHover = null;
			postToFrame('es-inspector:hover-clear');
		});

		setTimeout(function () { htmlReady = true; try { cm.refresh(); if (state.selection) highlightHtml(state.selection.uid, true); } catch (e) {} }, 0);
	}

	function scheduleHtmlCommit() {
		if (state.htmlCommitTimer) clearTimeout(state.htmlCommitTimer);
		state.htmlCommitTimer = setTimeout(commitHtmlEdit, 600);
		scheduleChecks();
	}

	// ── Checks list (SER-1358) ────────────────────────────────────
	// Asks the server (es_check_asset, via window.EmblemStudioChecks in
	// admin.js) what is wrong with the working copy, debounced while typing.
	// It sits in the top bar so it stays visible in every view, full screen
	// included, and only expands when asked. It warns; Done still applies.
	// The unclosed-tag strip under the HTML editor is separate and unchanged.
	function scheduleChecks() {
		if (!state.open) return;
		if (state.checksTimer) clearTimeout(state.checksTimer);
		state.checksTimer = setTimeout(runChecks, 600);
	}
	function runChecks() {
		state.checksTimer = null;
		var checks = window.EmblemStudioChecks;
		if (!state.open || !checks) return;
		var html = state.html, css = state.css;
		var key = html + '\u0000' + css;
		if (key === state.checksKey) return;
		var mine = ++state.checksSeq;
		checks.request(html, css, function (items) {
			// Closed, overtaken by a newer check, or failed: leave the list as it is.
			if (!state.open || mine !== state.checksSeq || items === null) return;
			state.checksKey = key;
			renderChecks(items);
		});
	}
	function renderChecks(items) {
		var checks = window.EmblemStudioChecks;
		var wrap = state.overlay && state.overlay.querySelector('.es-inspector-checks');
		if (!wrap || !checks) return;
		var toggle = wrap.querySelector('.es-inspector-checks-toggle');
		var panel = wrap.querySelector('.es-inspector-checks-panel');
		var errors = checks.count(items, 'error');
		var warnings = checks.count(items, 'warning');
		if (!items.length) {
			wrap.hidden = true;
			setChecksOpen(false);
			return;
		}
		var parts = [];
		if (errors) parts.push(errors + ' ' + checks.labels.error.toLowerCase());
		if (warnings) parts.push(warnings + ' ' + checks.labels.warning.toLowerCase());
		toggle.textContent = parts.join(' · ');
		toggle.classList.toggle('has-errors', errors > 0);
		toggle.setAttribute('aria-label', checks.labels.title + ': ' + parts.join(', '));
		checks.renderGroups(panel, items);
		wrap.hidden = false;
	}
	function setChecksOpen(on) {
		state.checksOpen = !!on;
		var wrap = state.overlay && state.overlay.querySelector('.es-inspector-checks');
		if (!wrap) return;
		wrap.querySelector('.es-inspector-checks-panel').hidden = !state.checksOpen;
		wrap.querySelector('.es-inspector-checks-toggle').setAttribute('aria-expanded', state.checksOpen ? 'true' : 'false');
	}

	// Push edited HTML to the live asset: re-map element ranges, re-mount the
	// frame (which re-indexes ids), and drop the now-meaningless selection.
	// A non-blocking validation hint shows under the editor for unbalanced tags.
	function commitHtmlEdit() {
		state.htmlCommitTimer = null;
		if (!state.open) return;
		var html = state.html;
		state.htmlRanges = parseHtmlRanges(html);
		// Validation: inline strip + a red-fade mark on the offending markup.
		if (state.htmlErrMark) { try { state.htmlErrMark.clear(); } catch (e) {} state.htmlErrMark = null; }
		var v = validateHtml(html);
		if (state.htmlErrBar) {
			if (v.ok) { state.htmlErrBar.hidden = true; }
			else { state.htmlErrBar.textContent = v.error; state.htmlErrBar.hidden = false; }
		}
		if (!v.ok && state.htmlLeftCm && typeof v.start === 'number') {
			var cm0 = state.htmlLeftCm;
			try { state.htmlErrMark = cm0.markText(cm0.posFromIndex(v.start), cm0.posFromIndex(v.end), { className: 'es-inspector-html-err' }); } catch (e) {}
		}
		state.selection = null;
		state.lastMatches = [];
		clearHtmlHighlight();
		clearHighlights();
		updateCssHead(null);
		if (state.cssView === 'all') decorateAllView();
		else setRulesMessage('Select an element to see the CSS that applies to it.');
		renderStage(); // re-mount the live asset with the new markup
	}

	// Innermost element whose range contains a char index (deepest wins).
	function htmlUidAtIndex(idx) {
		var best = null, bestLen = Infinity;
		for (var uid in state.htmlRanges) {
			if (!state.htmlRanges.hasOwnProperty(uid)) continue;
			var r = state.htmlRanges[uid];
			if (idx >= r.start && idx < r.end && (r.end - r.start) < bestLen) { best = uid; bestLen = r.end - r.start; }
		}
		return best;
	}

	// Which element the user meant at a char index. If the cursor sits right
	// after an element's closing tag (same line, only whitespace between), pick
	// THAT element — clicking past a </tag> means "this child", not the parent
	// whose content merely continues after it.
	function htmlPickUid(idx) {
		var cm = state.htmlLeftCm;
		if (cm) {
			var val = cm.getValue();
			var pickUid = null, pickEnd = -1;
			for (var uid in state.htmlRanges) {
				if (!state.htmlRanges.hasOwnProperty(uid)) continue;
				var r = state.htmlRanges[uid];
				if (r.closeStart == null || r.end > idx || r.end <= pickEnd) continue;
				var gap = val.slice(r.end, idx);
				if (gap.indexOf('\n') === -1 && /^\s*$/.test(gap)) { pickUid = uid; pickEnd = r.end; }
			}
			if (pickUid) return pickUid;
		}
		return htmlUidAtIndex(idx);
	}

	function clearHtmlHighlight() {
		for (var i = 0; i < state.htmlMarks.length; i++) { try { state.htmlMarks[i].clear(); } catch (e) {} }
		state.htmlMarks = [];
	}

	// Highlight only the element's own markup — its opening and closing tags —
	// and dim everything else (including its children). scroll=true centers.
	function highlightHtml(uid, scroll) {
		var cm = state.htmlLeftCm;
		if (!cm) return;
		var r = uid ? state.htmlRanges[uid] : null;
		cm.operation(function () {
			clearHtmlHighlight();
			if (!r) return;
			var total = cm.getValue().length;
			var p = function (i) { return cm.posFromIndex(i); };
			function dim(a, b) { if (b > a) state.htmlMarks.push(cm.markText(p(a), p(b), { className: 'es-inspector-dim-text' })); }
			function sel(a, b) { if (b > a) state.htmlMarks.push(cm.markText(p(a), p(b), { className: 'es-inspector-html-sel' })); }
			dim(0, r.start);
			dim(r.end, total);
			if (r.closeStart != null) {
				sel(r.start, r.openEnd); // opening tag
				// In the body, highlight the element's own text but dim each
				// child element (those are selectable in their own right).
				var kids = [];
				for (var k in state.htmlRanges) {
					if (state.htmlRanges.hasOwnProperty(k) && state.htmlRanges[k].parent === uid) kids.push(state.htmlRanges[k]);
				}
				kids.sort(function (a, b) { return a.start - b.start; });
				var cur = r.openEnd;
				for (var ki = 0; ki < kids.length; ki++) {
					sel(cur, kids[ki].start);       // direct text before this child
					dim(kids[ki].start, kids[ki].end); // the child element
					cur = kids[ki].end;
				}
				sel(cur, r.closeStart);          // trailing direct text
				sel(r.closeStart, r.end);        // closing tag
			} else {
				sel(r.start, r.end);              // void / self-closing — the whole tag
			}
		});
		if (scroll && r) {
			try {
				var h = cm.getScrollInfo().clientHeight;
				var startTop = cm.charCoords(cm.posFromIndex(r.start), 'local').top;
				var endBottom = cm.charCoords(cm.posFromIndex(r.end), 'local').bottom;
				var elemH = endBottom - startTop;
				var target;
				if (elemH <= h - 16) {
					// Whole element fits — show opening AND closing, centered.
					target = startTop - Math.max(8, (h - elemH) / 2);
				} else {
					// Taller than the viewport — opening near the top so as much
					// of the element (and its closing tag) as possible is visible.
					target = startTop - h * 0.12;
				}
				cm.scrollTo(null, Math.max(0, target));
			} catch (e) {}
		}
	}

	// ── Layer tree (left column) ──────────────────────────────────
	// `roots` is a forest: every top-level element the asset mounts.
	function renderTree(roots) {
		var scroll = state.overlay && state.overlay.querySelector('.es-inspector-col-tree .es-inspector-col-scroll');
		if (!scroll) return;
		scroll.textContent = '';
		if (!roots || !roots.length) {
			var p = document.createElement('p');
			p.className = 'es-inspector-placeholder';
			p.textContent = 'No content found in the asset HTML.';
			scroll.appendChild(p);
			return;
		}
		var rootUl = document.createElement('ul');
		rootUl.className = 'es-inspector-tree';
		for (var i = 0; i < roots.length; i++) {
			rootUl.appendChild(nodeToLi(roots[i]));
		}
		scroll.appendChild(rootUl);
	}

	function nodeToLi(node) {
		var li = document.createElement('li');
		li.className = 'es-inspector-tree-li';

		var row = document.createElement('div');
		row.className = 'es-inspector-node';
		row.setAttribute('data-uid', node.uid || '');

		var hasKids = node.children && node.children.length;

		// Expand/collapse toggle (only when there are children).
		var toggle = document.createElement('span');
		toggle.className = 'es-inspector-node-toggle';
		if (hasKids) {
			toggle.classList.add('is-toggle');
			toggle.textContent = '▾';
			toggle.addEventListener('click', function (e) {
				e.stopPropagation();
				li.classList.toggle('is-collapsed');
				toggle.textContent = li.classList.contains('is-collapsed') ? '▸' : '▾';
			});
		}
		row.appendChild(toggle);

		// tag in accent, id/class dimmed.
		var tagSpan = document.createElement('span');
		tagSpan.className = 'es-inspector-node-tag';
		tagSpan.textContent = node.tag || 'node';
		row.appendChild(tagSpan);

		// Show the id and EVERY class so each layer is easy to tell apart.
		if (node.id) {
			var idSpan = document.createElement('span');
			idSpan.className = 'es-inspector-node-id';
			idSpan.textContent = '#' + node.id;
			row.appendChild(idSpan);
		}
		if (node.classes && node.classes.length) {
			var clSpan = document.createElement('span');
			clSpan.className = 'es-inspector-node-class';
			clSpan.textContent = '.' + node.classes.join('.');
			row.appendChild(clSpan);
		}

		// Selection + hover wiring (drives the middle frame).
		row.addEventListener('click', function () { selectByUid(node.uid); });
		row.addEventListener('mouseenter', function () { postToFrame('es-inspector:hover-uid', { uid: node.uid }); });
		row.addEventListener('mouseleave', function () { postToFrame('es-inspector:hover-clear'); });

		li.appendChild(row);

		if (hasKids) {
			var ul = document.createElement('ul');
			ul.className = 'es-inspector-tree';
			for (var i = 0; i < node.children.length; i++) {
				ul.appendChild(nodeToLi(node.children[i]));
			}
			li.appendChild(ul);
		}
		return li;
	}

	function selectByUid(uid) {
		if (!uid) return;
		postToFrame('es-inspector:select-uid', { uid: uid });
		// The frame echoes a 'selected' message back, which updates the
		// panels + tree highlight — so we don't duplicate that here.
	}

	function highlightTreeRow(uid) {
		if (!state.overlay) return;
		var rows = state.overlay.querySelectorAll('.es-inspector-node.is-selected');
		for (var i = 0; i < rows.length; i++) rows[i].classList.remove('is-selected');
		if (!uid) return;
		var row = state.overlay.querySelector('.es-inspector-node[data-uid="' + uid + '"]');
		if (row) {
			row.classList.add('is-selected');
			// Make sure collapsed ancestors are opened so the row is visible.
			var li = row.parentNode;
			while (li && li !== state.overlay) {
				if (li.classList && li.classList.contains('is-collapsed')) {
					li.classList.remove('is-collapsed');
					var tg = li.querySelector(':scope > .es-inspector-node .es-inspector-node-toggle');
					if (tg) tg.textContent = '▾';
				}
				li = li.parentNode;
			}
			ensureRowVisible(row);
		}
	}

	// ── CSS panel (right column) ──────────────────────────────────
	// Two views, toggled in the header and persisted:
	//   relevant — only the rules that target the selected element;
	//   all      — the whole stylesheet, with non-matching rules dimmed.

	function setCssView(view) {
		view = (view === 'all') ? 'all' : 'relevant';
		if (view === state.cssView) return;
		state.cssView = view; // session-only; resets to All on next open
		applyCssViewUI();
		refreshCssPanel();
	}
	function applyCssViewUI() {
		if (!state.overlay) return;
		var btns = state.overlay.querySelectorAll('.es-inspector-cssview-btn');
		for (var i = 0; i < btns.length; i++) {
			btns[i].classList.toggle('is-active', btns[i].getAttribute('data-view') === state.cssView);
		}
	}

	// Ensure the CSS scroll holds a persistent head (element chip) + rules box.
	function ensureCssScaffold() {
		var scroll = state.overlay && state.overlay.querySelector('.es-inspector-col-css .es-inspector-col-scroll');
		if (!scroll) return null;
		if (!scroll.querySelector('.es-inspector-css-rules')) {
			scroll.textContent = '';
			var head = document.createElement('div');
			head.className = 'es-inspector-css-head';
			var rules = document.createElement('div');
			rules.className = 'es-inspector-css-rules';
			scroll.appendChild(head);
			scroll.appendChild(rules);
		}
		return scroll;
	}
	function cssRulesBox() {
		ensureCssScaffold();
		return state.overlay && state.overlay.querySelector('.es-inspector-css-rules');
	}
	function updateCssHead(info) {
		ensureCssScaffold();
		var head = state.overlay && state.overlay.querySelector('.es-inspector-css-head');
		if (!head) return;
		head.textContent = '';
		if (info && info.label) {
			var code = document.createElement('code');
			code.textContent = info.label;
			head.appendChild(code);
		} else if (state.cssView === 'all') {
			var s = document.createElement('span');
			s.className = 'es-inspector-css-head-note';
			s.textContent = 'All styles';
			head.appendChild(s);
		}
	}
	function setRulesMessage(text) {
		var box = cssRulesBox();
		if (!box) return;
		destroyAllCm();
		teardownCssObserver();
		state.cards = [];
		box.textContent = '';
		var p = document.createElement('p');
		p.className = 'es-inspector-placeholder';
		p.textContent = text;
		box.appendChild(p);
	}

	// Render the panel from scratch for the current view + selection.
	function refreshCssPanel() {
		if (!state.overlay) return;
		var colCss = state.overlay.querySelector('.es-inspector-col-css');
		if (colCss) colCss.classList.toggle('is-allview', state.cssView === 'all');
		updateCssHead(state.selection);
		if (state.cssView === 'all') {
			renderAllText(); // single editor; requests matches itself if selected
		} else if (state.selection) {
			setRulesMessage('Finding matching CSS…');
			requestCss(state.selection.uid); // → renderCssRules
		} else {
			setRulesMessage('Select an element to see the CSS that applies to it.');
		}
	}

	// Ask the frame which parsed style rules match the given element.
	function requestCss(uid) {
		var selectors = [];
		for (var i = 0; i < state.rules.length; i++) {
			if (state.rules[i].type === 'style') {
				selectors.push({ i: i, selector: state.rules[i].selector });
			}
		}
		postToFrame('es-inspector:match-request', { uid: uid, selectors: selectors });
	}

	// Render the matching rules as editable cards, then any user-pinned
	// rules that don't currently match, then the "add selector" control.
	function renderCssRules(matches) {
		var box = cssRulesBox();
		if (!box) return;
		teardownCssObserver();
		destroyAllCm();
		state.lastMatches = matches || [];
		state.cards = [];
		clearHighlights();
		box.textContent = '';

		var shownIdx = {};
		var any = false;
		for (var m = 0; m < state.lastMatches.length; m++) {
			var rule = state.rules[state.lastMatches[m].i];
			if (!rule) continue;
			shownIdx[state.lastMatches[m].i] = true;
			box.appendChild(safeRuleCard(rule, { viaState: state.lastMatches[m].viaState, part: state.lastMatches[m].part }));
			any = true;
		}

		// Pinned (user-added) selectors that don't match the current
		// element — keep them visible so a just-written rule doesn't vanish.
		for (var p = 0; p < state.pinned.length; p++) {
			var idx = lastRuleIndexForSelector(state.pinned[p], shownIdx);
			if (idx < 0) continue;
			shownIdx[idx] = true;
			box.appendChild(safeRuleCard(state.rules[idx], { notMatching: true }));
			any = true;
		}

		if (!any) {
			var none = document.createElement('p');
			none.className = 'es-inspector-placeholder';
			none.textContent = 'No CSS rule targets this element yet.';
			box.appendChild(none);
		}

		box.appendChild(renderAddSelector());

		// Cross out declarations overridden by the cascade (DevTools-style).
		try { recrossAll(); } catch (e) { if (window.console) console.warn('Inspector: cascade pass failed', e); }

		// Focus a just-added rule's editor for immediate typing.
		focusPendingRule(box);
	}

	// All-CSS view: the whole stylesheet in ONE editor — reads like a real
	// .css file and is far lighter than a CodeMirror per rule. Non-matching
	// rules are dimmed and overridden declarations struck through via editor
	// decorations (not separate DOM cards).
	function renderAllText() {
		var box = cssRulesBox();
		if (!box) return;
		teardownCssObserver();
		destroyAllCm();
		state.cards = [];
		clearHighlights();
		box.textContent = '';

		var host = document.createElement('div');
		host.className = 'es-inspector-allcss';
		box.appendChild(host);

		// Fixed-height + virtualized (NOT autoHeight) — autoHeight renders
		// every line on every keystroke, which is what made typing lag.
		var cm = createEditor(host, state.css, { mode: 'css', lineNumbers: false, autoHeight: false });
		if (!cm) {
			// Plain textarea fallback (no swatches / decorations).
			var ta = document.createElement('textarea');
			ta.className = 'es-inspector-rule-textarea';
			ta.spellcheck = false;
			ta.value = state.css;
			ta.rows = Math.min(40, Math.max(6, state.css.split('\n').length));
			host.appendChild(ta);
			ta.addEventListener('input', function () {
				state.css = ta.value; state.dirty = true;
				state.rules = parseStylesheet(state.css);
				scheduleLiveCss();
			});
			return;
		}

		state.allCm = cm;
		cm._esSwatches = [];

		// Overview ruler: VSCode-style markers down the right edge at every
		// rule that targets the selected element.
		var ruler = document.createElement('div');
		ruler.className = 'es-inspector-allcss-ruler';
		host.appendChild(ruler);
		state.allRuler = ruler;
		var reSwatch = debounced(function () { addSwatches(cm); }, 200);
		// Heavy work (parse, live-swap, decorate) is debounced; but the working
		// copy itself must update on EVERY change, or a quick Done after the
		// last edit would write back a stale value.
		var onEditHeavy = debounced(function () {
			state.rules = parseStylesheet(state.css);
			scheduleLiveCss();
			if (state.selection) requestCss(state.selection.uid); // re-dim/cross
			else decorateAllView();
		}, 200);
		cm.on('change', function () {
			state.css = cm.getValue();
			state.dirty = true;
			onEditHeavy();
			reSwatch();
		});
		wireAllHover(cm);
		setTimeout(function () { try { cm.refresh(); addSwatches(cm); decorateAllView(); } catch (e) {} }, 0);

		if (state.selection) requestCss(state.selection.uid); // → decorateAllView
	}

	function destroyAllCm() {
		clearAllDecorations();
		state.allCm = null;
		state.allRuler = null;
	}

	// Dim non-matching rules + strike overridden declarations for the
	// selected element, as editor decorations on the single All-view editor.
	function decorateAllView() {
		var cm = state.allCm;
		if (!cm) return;
		if (!state.selection) { clearAllDecorations(); return; } // nothing to dim

		var matched = {};
		for (var m = 0; m < state.lastMatches.length; m++) matched[state.lastMatches[m].i] = state.lastMatches[m];

		// @keyframes/@font-face referenced by the matching rules also "apply".
		var appliedAt = appliedAtBlockIndices(matched);

		function bgLines(r) {
			var fromLine = cm.posFromIndex(r.start).line;
			var toLine = cm.posFromIndex(Math.max(r.start, r.end - 1)).line;
			for (var ln = fromLine; ln <= toLine; ln++) {
				cm.addLineClass(ln, 'background', 'es-line-applies');
				state.allLineClasses.push(ln);
			}
		}

		// Clear + re-apply every decoration in one CodeMirror operation.
		cm.operation(function () {
			clearAllMarksInline(cm);
			function dimRule(r) {
				try { state.allMarks.push(cm.markText(cm.posFromIndex(r.start), cm.posFromIndex(r.end), { className: 'es-inspector-dim-text' })); } catch (e) {}
			}
			for (var i = 0; i < state.rules.length; i++) {
				var r = state.rules[i];
				if (r.type === 'style') {
					if (matched[i]) bgLines(r); else dimRule(r);
				} else if (r.type === 'at-block' && /@(?:-[a-z]+-)?keyframes\b/i.test(r.selector || '')) {
					// @keyframes follow the same apply/dim as the rules that use
					// them: highlighted when the selected element animates with
					// them, dimmed otherwise. (@font-face/@property stay neutral.)
					if (appliedAt[i]) bgLines(r); else dimRule(r);
				}
			}
			crossAllText(cm, matched);
		});
		updateRuler(matched);
		scrollAllToFirstRule(matched);
	}

	// A selector whose last step is the universal `*` (`.co *`, `*`): it matches
	// every element, so it says nothing about the one picked.
	function isCatchAll(part) {
		var steps = String(part || '').trim().split(/\s*[>+~]\s*|\s+/);
		return /^\*(?::{1,2}[\w-]+(?:\([^)]*\))?)*$/.test(steps[steps.length - 1] || '');
	}

	// Centre the All view on the element's first rule for a NEW selection, as the
	// HTML view centres on the element (item 9 of Catalina's Lite test pass).
	// "First" skips a catch-all like `.co *`, which matches every element, sits
	// near the top, and so would never move the view; it also skips :hover-style
	// and @media rules, which are not how the element looks by default. With
	// nothing else it falls back to the first match. Once per selection, so
	// editing or re-matching never yanks the view away. Same rule as the web app.
	function scrollAllToFirstRule(matched) {
		var cm = state.allCm, sel = state.selection;
		if (!cm || !sel || !matched || state.lastCssScrollUid === sel.uid) return;
		var target = null, fallback = null;
		for (var i = 0; i < state.rules.length; i++) {
			var r = state.rules[i], m = matched[i];
			if (r.type !== 'style' || !m) continue;
			if (fallback === null) fallback = r;
			if (!m.viaState && !r.media && !isCatchAll(m.part)) { target = r; break; }
		}
		var rule = target || fallback;
		if (!rule) return;
		state.lastCssScrollUid = sel.uid;
		try {
			var top = cm.charCoords(cm.posFromIndex(rule.start), 'local').top;
			cm.scrollTo(null, Math.max(0, top - cm.getScrollInfo().clientHeight / 2));
		} catch (e) {}
	}

	// Place a clickable marker on the right-edge ruler for each rule that
	// targets the selected element (position = where it sits in the file).
	function updateRuler(matched) {
		var ruler = state.allRuler, cm = state.allCm;
		if (!ruler) return;
		ruler.textContent = '';
		if (!cm || !state.selection || !matched) return;
		// Sit the ruler just LEFT of the editor's scrollbar (don't overlap it).
		var scroller = cm.getScrollerElement();
		var sbw = scroller ? (scroller.offsetWidth - scroller.clientWidth) : 0;
		ruler.style.right = (sbw > 0 ? sbw + 2 : 2) + 'px';
		// Position each tick by the rule's REAL pixel offset over the total scroll
		// height (not a line-number fraction, which ignores the content's top
		// padding and drifts the ticks upward) — so each lines up with the
		// scrollbar position you'd land on by clicking it.
		var totalH = cm.getScrollInfo().height || 1;
		for (var i = 0; i < state.rules.length; i++) {
			var r = state.rules[i];
			if (r.type !== 'style' || !matched[i]) continue;
			var line = cm.posFromIndex(r.start).line;
			var tick = document.createElement('div');
			tick.className = 'es-inspector-ruler-tick';
			// Calibrated position: scale × the pixel fraction (the drift grows with
			// depth, so it needs a multiplier, not just a constant) then offset px up.
			tick.style.top = 'calc(' + (cm.heightAtLine(line, 'local') / totalH * 100 * state.rulerScale) + '% - ' + state.rulerOffset + 'px)';
			tick.title = r.selector;
			(function (ln) {
				tick.addEventListener('click', function () {
					try {
						// Center the rule vertically rather than just nudging it
						// into view (which lands it near an edge).
						var top = cm.charCoords({ line: ln, ch: 0 }, 'local').top;
						var h = cm.getScrollInfo().clientHeight;
						cm.scrollTo(null, Math.max(0, top - h / 2));
					} catch (e) {}
				});
			})(line);
			ruler.appendChild(tick);
		}
	}

	// Cascade strike-through across the matching, currently-applying rules.
	function crossAllText(cm, matched) {
		var entries = [];
		for (var i = 0; i < state.rules.length; i++) {
			var r = state.rules[i];
			if (r.type !== 'style') continue;
			var mm = matched[i];
			if (!mm || mm.viaState) continue;          // only resting matches
			if (r.media && !mediaActive(r.media)) continue;
			entries.push({
				rule: r,
				spec: specificity(mm.part || r.selector),
				order: r.start,
				decls: parseDeclRanges(state.css.slice(r.bodyStart, r.bodyEnd))
			});
		}
		var winners = {};
		for (var e = 0; e < entries.length; e++) {
			var en = entries[e];
			for (var d = 0; d < en.decls.length; d++) {
				var cand = { en: en, dec: en.decls[d] };
				var cur = winners[en.decls[d].prop];
				if (!cur || declBeats(cand, cur)) winners[en.decls[d].prop] = cand;
			}
		}
		for (var e2 = 0; e2 < entries.length; e2++) {
			var en2 = entries[e2];
			for (var d2 = 0; d2 < en2.decls.length; d2++) {
				var win = winners[en2.decls[d2].prop];
				if (win && win.en === en2 && win.dec === en2.decls[d2]) continue;
				var base = en2.rule.bodyStart;
				try {
					var mark = cm.markText(cm.posFromIndex(base + en2.decls[d2].start), cm.posFromIndex(base + en2.decls[d2].end), { className: 'es-inspector-decl-crossed' });
					state.allMarks.push(mark);
				} catch (e3) {}
			}
		}
	}

	function clearAllDecorations() {
		var cm = state.allCm;
		if (cm) { cm.operation(function () { clearAllMarksInline(cm); }); }
		else { state.allMarks = []; state.allLineClasses = []; }
	}
	// Which @keyframes (at-block) rules the matching rules reference via their
	// `animation` / `animation-name` — so the whole keyframes block (incl. its
	// from/to/% steps) gets the "applies" highlight too.
	function appliedAtBlockIndices(matched) {
		var applied = {};
		var names = {};
		for (var i = 0; i < state.rules.length; i++) {
			var r = state.rules[i];
			if (r.type !== 'style' || !matched[i] || typeof r.bodyStart !== 'number') continue;
			var body = state.css.slice(r.bodyStart, r.bodyEnd);
			var re = /animation(?:-name)?\s*:\s*([^;]+)/gi, m;
			while ((m = re.exec(body)) !== null) {
				var toks = m[1].split(/[\s,]+/);
				for (var t = 0; t < toks.length; t++) { var tok = toks[t].trim(); if (tok) names[tok] = true; }
			}
		}
		for (var j = 0; j < state.rules.length; j++) {
			if (state.rules[j].type !== 'at-block') continue;
			var mm = /@(?:-[a-z]+-)?keyframes\s+([\w-]+)/i.exec(state.rules[j].selector || '');
			if (mm && names[mm[1]]) applied[j] = true;
		}
		return applied;
	}

	function clearAllMarksInline(cm) {
		for (var i = 0; i < state.allMarks.length; i++) { try { state.allMarks[i].clear(); } catch (e) {} }
		state.allMarks = [];
		for (var j = 0; j < state.allLineClasses.length; j++) {
			try { cm.removeLineClass(state.allLineClasses[j], 'background', 'es-line-applies'); } catch (e) {}
		}
		state.allLineClasses = [];
	}

	// Hovering a rule in the All-view editor outlines its elements in the
	// live view (same as hovering a card).
	function wireAllHover(cm) {
		var wrap = cm.getWrapperElement();
		var last = null;
		var apply = function (e) {
			var pos = cm.coordsChar({ left: e.clientX, top: e.clientY }, 'window');
			var idx = cm.indexFromPos(pos);
			var sel = ruleSelectorAtIndex(idx);
			if (sel !== last) { last = sel; setHoverHighlight(sel); }
		};
		wrap.addEventListener('mousemove', apply);
		wrap.addEventListener('mouseleave', function () { last = null; setHoverHighlight(null); });
	}
	function ruleSelectorAtIndex(idx) {
		for (var i = 0; i < state.rules.length; i++) {
			var r = state.rules[i];
			if (r.type === 'style' && idx >= r.start && idx < r.end) return r.selector;
		}
		return null;
	}

	function focusPendingRule(box) {
		if (!state.focusSelector) return;
		var want = state.focusSelector;
		state.focusSelector = null;
		var card = box.querySelector('.es-inspector-rule[data-selector="' + cssAttrEscape(want) + '"]');
		if (card && card._focusEditor) card._focusEditor();
	}

	function lastRuleIndexForSelector(selector, shownIdx) {
		for (var i = state.rules.length - 1; i >= 0; i--) {
			if (state.rules[i].type === 'style' && state.rules[i].selector === selector && !shownIdx[i]) return i;
		}
		return -1;
	}

	function cssAttrEscape(s) { return String(s).replace(/"/g, '\\"'); }

	// One editable rule card: media badge + selector header + an editor
	// for the body with inline color swatches; edits live-update the form.
	function ruleCard(rule, opts) {
		opts = opts || {};
		var block = document.createElement('div');
		block.className = 'es-inspector-rule';
		block.setAttribute('data-selector', rule.selector);
		if (opts.notMatching) block.classList.add('is-not-matching');
		if (opts.dimmed) block.classList.add('is-dimmed');
		if (opts.atRule) block.classList.add('is-at-rule');

		if (rule.media) {
			var media = document.createElement('div');
			media.className = 'es-inspector-rule-media';
			media.textContent = rule.media;
			block.appendChild(media);
		}

		var sel = document.createElement('div');
		sel.className = 'es-inspector-rule-sel';
		var selText = document.createElement('span');
		selText.className = 'es-inspector-rule-sel-text';
		selText.textContent = rule.selector;
		sel.appendChild(selText);
		if (opts.viaState) sel.appendChild(tagPill('state', 'Matches via a :hover / :focus / etc. state'));
		if (opts.notMatching) sel.appendChild(tagPill('not matching', "This rule doesn't target the selected element"));

		// Delete this rule.
		var del = document.createElement('button');
		del.type = 'button';
		del.className = 'es-inspector-rule-del';
		del.title = 'Delete this rule';
		del.setAttribute('aria-label', 'Delete rule');
		del.innerHTML = '&times;';
		del.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			clearHighlights();
			openConfirm({
				title: 'Delete this rule?',
				text: rule.selector + ' will be removed from the stylesheet.',
				confirmLabel: 'Delete',
				onConfirm: function () { handleDeleteRule(rule); }
			});
		});
		sel.appendChild(del);
		block.appendChild(sel);

		var editorHost = document.createElement('div');
		editorHost.className = 'es-inspector-rule-editor';
		block.appendChild(editorHost);

		// Register the card. The heavy CodeMirror editor mounts now in the
		// Relevant view (few cards) but lazily on scroll-into-view in the All
		// view (could be dozens of rules) — until then it's a cheap read-only
		// <pre>, so switching to All and scrolling stays fast.
		var card = {
			rule: rule, block: block, editorHost: editorHost, cm: null, mounted: false,
			viaState: !!opts.viaState, notMatching: !!opts.notMatching, part: opts.part,
			index: (typeof opts.index === 'number') ? opts.index : -1,
			atRule: !!opts.atRule, dimmed: !!opts.dimmed
		};
		state.cards.push(card);
		block._esCard = card;

		var pre = document.createElement('pre');
		pre.className = 'es-inspector-rule-body';
		pre.textContent = formatBody(rule.body);
		editorHost.appendChild(pre);
		card.placeholder = pre;

		block._focusEditor = function () {
			mountRuleEditor(card);
			setTimeout(function () { if (card.cm) card.cm.focus(); else if (card._ta) card._ta.focus(); }, 0);
		};

		if (!opts.lazy) mountRuleEditor(card);

		// Hovering anywhere over the rule card outlines every element its
		// selector affects in the middle (mouseenter/leave don't bubble from
		// children, so it stays on while you move within the card).
		block.addEventListener('mouseenter', function () { setHoverHighlight(rule.selector); });
		block.addEventListener('mouseleave', function () { setHoverHighlight(null); });

		return block;
	}

	// A rule card that can never blank the whole panel: if anything throws
	// while building the editor, fall back to a read-only body so the rule
	// is at least visible.
	function safeRuleCard(rule, opts) {
		try {
			return ruleCard(rule, opts);
		} catch (e) {
			if (window.console) console.error('Inspector: rule card failed', e);
			var block = document.createElement('div');
			block.className = 'es-inspector-rule';
			var sel = document.createElement('div');
			sel.className = 'es-inspector-rule-sel';
			var st = document.createElement('span');
			st.className = 'es-inspector-rule-sel-text';
			st.textContent = rule.selector;
			sel.appendChild(st);
			block.appendChild(sel);
			var pre = document.createElement('pre');
			pre.className = 'es-inspector-rule-body';
			pre.textContent = formatBody(rule.body);
			block.appendChild(pre);
			return block;
		}
	}

	function tagPill(text, title) {
		var t = document.createElement('span');
		t.className = 'es-inspector-rule-tag';
		t.textContent = text;
		if (title) t.title = title;
		return t;
	}

	// ── Mount the editor for a card on demand (CodeMirror, else textarea) ──
	function mountRuleEditor(card) {
		if (card.mounted) return;
		card.mounted = true;
		var rule = card.rule, host = card.editorHost, block = card.block;
		if (card.placeholder && card.placeholder.parentNode) card.placeholder.parentNode.removeChild(card.placeholder);

		var cm = createEditor(host, rule.body.replace(/^\n+|\s+$/g, ''), { mode: 'css', lineNumbers: false, autoHeight: true });
		card.cm = cm;

		if (cm) {
			cm._esSwatches = [];
			cm._esCrossMarks = [];
			block._focusEditor = function () { cm.focus(); };
			var refreshSwatches = debounced(function () { addSwatches(cm); }, 150);
			cm.on('change', function () {
				applyBodyEdit(rule, cm.getValue());
				refreshSwatches();
				scheduleRecross();
			});
			// While editing a rule, outline what its selector affects — and
			// keep it on (sticky) even as the mouse moves around.
			cm.on('focus', function () { setEditHighlight(rule.selector); });
			cm.on('blur', function () { setEditHighlight(null); });
			setTimeout(function () { try { cm.refresh(); addSwatches(cm); } catch (e) {} }, 0);
			scheduleRecross(); // apply cascade strike-through once mounted
			return;
		}

		// Textarea fallback (no inline swatches / crossing, still editable).
		var ta = document.createElement('textarea');
		ta.className = 'es-inspector-rule-textarea';
		ta.spellcheck = false;
		ta.value = formatBody(rule.body);
		ta.rows = Math.min(14, Math.max(2, ta.value.split('\n').length));
		host.appendChild(ta);
		card._ta = ta;
		block._focusEditor = function () { ta.focus(); };
		ta.addEventListener('input', function () { applyBodyEdit(rule, ta.value); });
	}

	// Lazily mount editors as their cards scroll into the CSS panel viewport,
	// so the All view doesn't spin up dozens of CodeMirrors at once.
	function setupCssObserver() {
		teardownCssObserver();
		if (!window.IntersectionObserver) {
			for (var i = 0; i < state.cards.length; i++) mountRuleEditor(state.cards[i]);
			return;
		}
		var scroll = state.overlay && state.overlay.querySelector('.es-inspector-col-css .es-inspector-col-scroll');
		state.cssObserver = new IntersectionObserver(function (entries) {
			for (var i = 0; i < entries.length; i++) {
				if (!entries[i].isIntersecting) continue;
				var card = entries[i].target._esCard;
				if (card) mountRuleEditor(card);
				if (state.cssObserver) state.cssObserver.unobserve(entries[i].target);
			}
		}, { root: scroll || null, rootMargin: '800px 0px' });
		for (var j = 0; j < state.cards.length; j++) {
			if (!state.cards[j].mounted && state.cards[j].block) state.cssObserver.observe(state.cards[j].block);
		}
	}
	function teardownCssObserver() {
		if (state.cssObserver) { try { state.cssObserver.disconnect(); } catch (e) {} state.cssObserver = null; }
	}

	// Create a CodeMirror inside `host`, returning the instance or null.
	// Prefers wp.codeEditor (the exact mechanism the page's main editors use,
	// so it's known to work here); falls back to the raw constructor.
	function createEditor(host, value, opts) {
		opts = opts || {};
		var settings = {
			mode: opts.mode || 'css',
			lineNumbers: !!opts.lineNumbers,
			lineWrapping: true,
			lint: false,
			gutters: [],
			tabSize: 2,
			indentUnit: 2,
			// Don't re-indent on Enter / typing — just copy the previous
			// line's indentation, so new lines match the existing ones.
			smartIndent: false,
			electricChars: false
		};
		if (opts.autoHeight) settings.viewportMargin = Infinity;
		try {
			if (window.wp && wp.codeEditor && wp.codeEditor.initialize) {
				var ta = document.createElement('textarea');
				ta.value = value;
				host.appendChild(ta);
				var ed = wp.codeEditor.initialize(ta, { codemirror: settings });
				if (ed && ed.codemirror) return ed.codemirror;
				if (host.contains(ta)) host.removeChild(ta);
			}
		} catch (e) {
			if (window.console) console.warn('Inspector: wp.codeEditor failed', e);
		}
		try {
			var CM = window.CodeMirror || (window.wp && window.wp.CodeMirror);
			if (CM) {
				var ctorOpts = {};
				for (var k in settings) { if (settings.hasOwnProperty(k)) ctorOpts[k] = settings[k]; }
				ctorOpts.value = value;
				var cm = CM(host, ctorOpts);
				if (cm) return cm;
			}
		} catch (e2) {
			if (window.console) console.warn('Inspector: CodeMirror ctor failed', e2);
		}
		return null;
	}

	// Splice an edited rule body back into the working CSS and shift the
	// offsets of every rule after it, then live-update the form. No
	// re-parse, so the on-screen editors keep their focus/caret.
	function applyBodyEdit(rule, newBody) {
		var oldLen = rule.bodyEnd - rule.bodyStart;
		if (newBody === state.css.slice(rule.bodyStart, rule.bodyEnd)) return;
		var delta = newBody.length - oldLen;
		state.css = state.css.slice(0, rule.bodyStart) + newBody + state.css.slice(rule.bodyEnd);
		for (var i = 0; i < state.rules.length; i++) {
			var r = state.rules[i];
			if (r === rule) { r.bodyEnd += delta; r.end += delta; continue; }
			if (r.start > rule.start) {
				r.start += delta; r.end += delta;
				if (typeof r.bodyStart === 'number') r.bodyStart += delta;
				if (typeof r.bodyEnd === 'number') r.bodyEnd += delta;
			}
		}
		state.dirty = true;
		scheduleLiveCss();
	}

	// Throttle (leading + trailing), so a continuous color drag updates the
	// form live instead of only firing once the mouse is released.
	var _liveTimer = null, _livePending = false;
	function scheduleLiveCss() {
		scheduleChecks(); // every CSS edit path comes through here
		if (_liveTimer) { _livePending = true; return; }
		postToFrame('es-inspector:update-css', { css: state.css });
		_liveTimer = setTimeout(function () {
			_liveTimer = null;
			if (_livePending) { _livePending = false; scheduleLiveCss(); }
		}, 50);
	}

	// ── Inline color swatches ─────────────────────────────────────
	// Each color literal is wrapped in a markText whose ::before is the swatch
	// (see .es-color-mark in inspector.css), with white-space:nowrap so the
	// swatch can never wrap onto a different visual row than its color code.
	function addSwatches(cm) {
		if (!window.ESInspectorColor) return;
		var value = cm.getValue();
		var colors = window.ESInspectorColor.detect(value);
		// Batch every marker into a single CodeMirror operation (one reflow
		// instead of one per swatch) — critical for the All view.
		cm.operation(function () {
			for (var i = 0; i < cm._esSwatches.length; i++) { try { cm._esSwatches[i].clear(); } catch (e) {} }
			cm._esSwatches = [];
			for (var c = 0; c < colors.length; c++) {
				var color = colors[c];
				var parsed = window.ESInspectorColor.parse(color.value);
				var sw = parsed
					? 'rgba(' + Math.round(parsed.r) + ',' + Math.round(parsed.g) + ',' + Math.round(parsed.b) + ',' + parsed.a + ')'
					: 'transparent';
				// Hex is a single token, so glue the swatch to the whole literal.
				// Functional colors (rgba/hsla) are several tokens — marking the
				// whole range would repeat the ::before on each — so glue to the
				// first char there.
				var markEnd = color.value.charAt(0) === '#' ? color.end : color.start + 1;
				var marker = cm.markText(cm.posFromIndex(color.start), cm.posFromIndex(markEnd), {
					className: 'es-color-mark',
					css: '--sw: ' + sw + '; white-space: nowrap'
				});
				cm._esSwatches.push(marker);
			}
		});
		attachSwatchClicks(cm);
	}

	// One delegated mousedown per editor: a click on the ::before swatch (the
	// ~16px at the mark's left edge) opens the picker; clicking the code edits.
	function attachSwatchClicks(cm) {
		if (cm._esSwatchClick) return;
		cm._esSwatchClick = true;
		cm.getWrapperElement().addEventListener('mousedown', function (e) {
			var el = e.target && e.target.closest ? e.target.closest('.es-color-mark') : null;
			if (!el) return;
			var rect = el.getBoundingClientRect();
			if (e.clientX - rect.left > 16) return; // clicked the code — let it edit
			e.preventDefault();
			e.stopPropagation();
			var pos = cm.coordsChar({ left: rect.left + 2, top: rect.top + rect.height / 2 }, 'window');
			var idx = cm.indexFromPos(pos);
			var colors = window.ESInspectorColor.detect(cm.getValue());
			for (var i = 0; i < colors.length; i++) {
				if (idx >= colors[i].start && idx < colors[i].end) { openSwatchPickerAt(cm, colors[i], el); return; }
			}
		}, true);
	}

	function openSwatchPickerAt(cm, color, anchor) {
		var startIdx = color.start;
		var cur = color.value;
		window.ESInspectorColor.openPicker({
			anchor: anchor,
			value: cur,
			onChange: function (str) {
				var from = cm.posFromIndex(startIdx);
				var to = cm.posFromIndex(startIdx + cur.length);
				cm.replaceRange(str, from, to);
				cur = str;
			}
		});
	}

	// ── Add a new selector (appended to the end of the stylesheet) ──
	function renderAddSelector() {
		var wrap = document.createElement('div');
		wrap.className = 'es-inspector-add-rule';
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'es-inspector-add-input';
		input.placeholder = 'New selector, e.g. .esf-cta';
		input.spellcheck = false;
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'es-inspector-add-btn';
		btn.textContent = 'Add rule';
		function add() {
			var sel = input.value.trim();
			if (!sel) return;
			handleAddSelector(sel);
		}
		btn.addEventListener('click', add);
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
		wrap.appendChild(input);
		wrap.appendChild(btn);
		return wrap;
	}

	// Outline-what-a-selector-affects, with two sources: the editor you're
	// editing (sticky) and the selector you're hovering (temporary). Hover
	// wins while it lasts, then the edited rule's highlight returns — so the
	// outline stays put the whole time you work in a rule.
	function applyHighlight() {
		var sel = state.hoverSelector || state.editSelector;
		if (sel) postToFrame('es-inspector:highlight-selector', { selector: sel });
		else postToFrame('es-inspector:highlight-clear');
	}
	function setHoverHighlight(sel) { state.hoverSelector = sel || null; applyHighlight(); }
	function setEditHighlight(sel) { state.editSelector = sel || null; applyHighlight(); }
	function clearHighlights() { state.hoverSelector = null; state.editSelector = null; applyHighlight(); }

	// Delete a rule: cut its source text out, re-parse, live-update, and
	// re-render the panel for the current selection.
	function handleDeleteRule(rule) {
		var start = rule.start, end = rule.end;
		// Swallow trailing inline whitespace + one newline so we don't leave
		// a blank gap behind.
		while (end < state.css.length && (state.css.charAt(end) === ' ' || state.css.charAt(end) === '\t')) end++;
		if (state.css.charAt(end) === '\r') end++;
		if (state.css.charAt(end) === '\n') end++;
		state.css = state.css.slice(0, start) + state.css.slice(end);
		state.rules = parseStylesheet(state.css);
		// Drop pinned selectors that no longer have a rule.
		state.pinned = state.pinned.filter(function (selname) {
			for (var i = 0; i < state.rules.length; i++) {
				if (state.rules[i].type === 'style' && state.rules[i].selector === selname) return true;
			}
			return false;
		});
		state.dirty = true;
		scheduleLiveCss();
		refreshCssPanel(); // rebuild for the current view (rules changed)
	}

	function handleAddSelector(selector) {
		var prefix = state.css && !/\n\s*$/.test(state.css) ? '\n\n' : '\n';
		state.css = state.css + prefix + selector + ' {\n  \n}\n';
		state.rules = parseStylesheet(state.css);
		if (state.pinned.indexOf(selector) === -1) state.pinned.push(selector);
		state.dirty = true;
		scheduleLiveCss();
		state.focusSelector = selector;
		// Rebuild the panel for the current view; the new rule then shows
		// (matched/pinned in Relevant, or in place in All) and gets focused.
		refreshCssPanel();
	}

	function debounced(fn, ms) {
		var t = null;
		return function () { clearTimeout(t); t = setTimeout(fn, ms); };
	}

	// ── Cascade: cross out overridden declarations (DevTools-style) ──
	var _recrossTimer = null;
	function scheduleRecross() {
		clearTimeout(_recrossTimer);
		_recrossTimer = setTimeout(function () { try { recrossAll(); } catch (e) {} }, 150);
	}

	function recrossAll() {
		if (!state.cards || !state.cards.length) return;

		// No element selected → no cascade context (e.g. All view before a
		// click). Clear any strike-through and stop.
		if (!state.selection) {
			for (var k = 0; k < state.cards.length; k++) {
				if (state.cards[k].cm) clearCrosses(state.cards[k]);
			}
			return;
		}

		// Participating entries: currently-applicable declarations only —
		// resting (non-state) rules that target the element, in CodeMirror
		// editors, whose @media (if any) currently matches.
		var entries = [];
		for (var i = 0; i < state.cards.length; i++) {
			var card = state.cards[i];
			if (card.cm) clearCrosses(card);
			if (!card.cm || card.viaState || card.notMatching || card.dimmed || card.atRule) continue;
			if (card.rule.media && !mediaActive(card.rule.media)) continue;
			entries.push({
				card: card,
				spec: specificity(card.part || card.rule.selector),
				order: card.rule.start,
				decls: parseDeclRanges(card.cm.getValue())
			});
		}

		// Winner per property across all participating declarations.
		var winners = {};
		for (var e = 0; e < entries.length; e++) {
			var en = entries[e];
			for (var d = 0; d < en.decls.length; d++) {
				var cand = { en: en, dec: en.decls[d] };
				var cur = winners[en.decls[d].prop];
				if (!cur || declBeats(cand, cur)) winners[en.decls[d].prop] = cand;
			}
		}

		// Cross every declaration that isn't its property's winner.
		for (var e2 = 0; e2 < entries.length; e2++) {
			var en2 = entries[e2];
			for (var d2 = 0; d2 < en2.decls.length; d2++) {
				var win = winners[en2.decls[d2].prop];
				if (!(win && win.en === en2 && win.dec === en2.decls[d2])) {
					crossDecl(en2.card.cm, en2.decls[d2]);
				}
			}
		}
	}

	// Does declaration `a` win over `b`? Importance, then specificity, then
	// source order (later rule wins; within a rule, later declaration wins).
	function declBeats(a, b) {
		var ai = a.dec.important ? 1 : 0, bi = b.dec.important ? 1 : 0;
		if (ai !== bi) return ai > bi;
		var s = compareSpec(a.en.spec, b.en.spec);
		if (s !== 0) return s > 0;
		if (a.en.order !== b.en.order) return a.en.order > b.en.order;
		return a.dec.start >= b.dec.start;
	}

	function mediaActive(mediaStr) {
		try {
			var cond = String(mediaStr).replace(/^@media\s*/i, '').replace(/^@supports\s*/i, '').trim();
			if (!cond) return true;
			var win = state.iframe && state.iframe.contentWindow;
			if (win && win.matchMedia) return win.matchMedia(cond).matches;
		} catch (e) {}
		return true;
	}

	// Approximate selector specificity → [ids, classes, types].
	function specificity(selector) {
		var a = 0, b = 0, c = 0;
		var s = ' ' + String(selector) + ' ';
		s = s.replace(/::[\w-]+/g, function () { c++; return ' '; });          // pseudo-elements
		s = s.replace(/#[\w-]+/g, function () { a++; return ' '; });           // ids
		s = s.replace(/\.[\w-]+/g, function () { b++; return ' '; });          // classes
		s = s.replace(/\[[^\]]*\]/g, function () { b++; return ' '; });        // attributes
		s = s.replace(/:[\w-]+(\([^)]*\))?/g, function (m) {                   // pseudo-classes
			if (/^:where/i.test(m)) return ' ';
			b++; return ' ';
		});
		s = s.replace(/[a-zA-Z][\w-]*/g, function () { c++; return ' '; });    // type selectors
		return [a, b, c];
	}
	function compareSpec(x, y) {
		for (var i = 0; i < 3; i++) { if (x[i] !== y[i]) return x[i] - y[i]; }
		return 0;
	}

	// Parse a declaration block into { prop, value, important, start, end }
	// with char offsets into the text (for CodeMirror markText).
	function parseDeclRanges(text) {
		var out = [], depth = 0, inStr = null, start = 0;
		function push(end) {
			var seg = text.slice(start, end);
			var trimmed = seg.replace(/^\s+|\s+$/g, '');
			if (trimmed) {
				var lead = seg.length - seg.replace(/^\s+/, '').length;
				var s = start + lead;
				var colon = trimmed.indexOf(':');
				if (colon > 0) {
					var value = trimmed.slice(colon + 1).replace(/^\s+|\s+$/g, '');
					out.push({
						prop: trimmed.slice(0, colon).replace(/^\s+|\s+$/g, '').toLowerCase(),
						value: value,
						important: /!\s*important\s*$/i.test(value),
						start: s,
						end: s + trimmed.length
					});
				}
			}
		}
		for (var i = 0; i < text.length; i++) {
			var ch = text.charAt(i);
			if (inStr) { if (ch === '\\') { i++; continue; } if (ch === inStr) inStr = null; continue; }
			if (ch === '"' || ch === "'") { inStr = ch; continue; }
			if (ch === '/' && text.charAt(i + 1) === '*') { var e = text.indexOf('*/', i + 2); i = (e < 0 ? text.length : e + 1); continue; }
			if (ch === '(' || ch === '[' || ch === '{') { depth++; continue; }
			if (ch === ')' || ch === ']' || ch === '}') { depth--; continue; }
			if (ch === ';' && depth === 0) { push(i); start = i + 1; }
		}
		push(text.length);
		return out;
	}

	function crossDecl(cm, dec) {
		try {
			var mark = cm.markText(cm.posFromIndex(dec.start), cm.posFromIndex(dec.end), {
				className: 'es-inspector-decl-crossed'
			});
			cm._esCrossMarks.push(mark);
		} catch (e) {}
	}
	function clearCrosses(card) {
		if (!card.cm || !card.cm._esCrossMarks) return;
		for (var i = 0; i < card.cm._esCrossMarks.length; i++) {
			try { card.cm._esCrossMarks[i].clear(); } catch (e) {}
		}
		card.cm._esCrossMarks = [];
	}

	// Tidy a rule body for display: one declaration per row, regardless of
	// how the source was wrapped. Splits on real (top-level) semicolons so
	// values containing ';' inside url()/strings stay intact. Read-only,
	// so cosmetic only.
	function formatBody(body) {
		var decls = splitDeclarations(String(body));
		var out = [];
		for (var i = 0; i < decls.length; i++) {
			var t = decls[i].trim();
			if (t) out.push('  ' + t + ';');
		}
		return out.join('\n');
	}

	// Split a declaration block on semicolons that are at brace/paren depth
	// zero and outside strings/comments. Returns the declaration texts
	// (without their trailing ';').
	function splitDeclarations(body) {
		var out = [], buf = '', depth = 0, inStr = null;
		for (var i = 0; i < body.length; i++) {
			var c = body.charAt(i);
			if (inStr) {
				buf += c;
				if (c === '\\') { if (i + 1 < body.length) { buf += body.charAt(i + 1); i++; } continue; }
				if (c === inStr) inStr = null;
				continue;
			}
			if (c === '"' || c === "'") { inStr = c; buf += c; continue; }
			if (c === '/' && body.charAt(i + 1) === '*') {
				var e = body.indexOf('*/', i + 2);
				var end = e < 0 ? body.length : e + 2;
				buf += body.slice(i, end);
				i = end - 1;
				continue;
			}
			if (c === '(' || c === '[' || c === '{') { depth++; buf += c; continue; }
			if (c === ')' || c === ']' || c === '}') { depth--; buf += c; continue; }
			if (c === ';' && depth === 0) { out.push(buf); buf = ''; continue; }
			buf += c;
		}
		if (buf.trim()) out.push(buf);
		return out;
	}

	// ── CSS formatter ─────────────────────────────────────────────
	// Re-indent a stylesheet to standard form: 2-space indentation per
	// nesting level, one declaration per line, a blank line between
	// top-level rules. Brace/string/comment aware so values (url(http://…),
	// content:"}", etc.) are never mangled. Idempotent.
	function formatCss(css) {
		var s = String(css);
		var out = [];
		var depth = 0;
		var line = '';
		var i = 0, n = s.length;
		function ind() { var r = ''; for (var k = 0; k < depth; k++) r += '  '; return r; }
		function emit(text) { out.push(ind() + text); }
		function flushDecl() {
			var t = line.trim();
			line = '';
			if (!t) return;
			if (t.charAt(0) !== '@') { // normalize "prop:value" → "prop: value"
				var ci = t.indexOf(':');
				if (ci > 0) t = t.slice(0, ci).trim() + ': ' + t.slice(ci + 1).trim();
			}
			emit(t + ';');
		}
		while (i < n) {
			var c = s.charAt(i);
			if (c === '/' && s.charAt(i + 1) === '*') {
				var pend = line.trim();
				if (pend) { emit(pend); line = ''; }
				var end = s.indexOf('*/', i + 2);
				end = end < 0 ? n : end + 2;
				var clines = s.slice(i, end).split('\n');
				for (var ci2 = 0; ci2 < clines.length; ci2++) {
					out.push(ind() + (ci2 === 0 ? clines[ci2].replace(/^\s+/, '') : ' ' + clines[ci2].trim()));
				}
				i = end;
				continue;
			}
			if (c === '"' || c === "'") {
				line += c; i++;
				while (i < n) {
					var ch = s.charAt(i);
					if (ch === '\\') { line += ch + (s.charAt(i + 1) || ''); i += 2; continue; }
					line += ch; i++;
					if (ch === c) break;
				}
				continue;
			}
			if (c === '{') { emit(line.trim().replace(/\s+,/g, ',') + ' {'); line = ''; depth++; i++; continue; }
			if (c === '}') {
				flushDecl();
				depth = depth > 0 ? depth - 1 : 0;
				emit('}');
				if (depth === 0) out.push('');
				i++; continue;
			}
			if (c === ';') { flushDecl(); i++; continue; }
			if (c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === '\f') {
				if (line.length && line.charAt(line.length - 1) !== ' ') line += ' ';
				i++; continue;
			}
			line += c; i++;
		}
		var last = line.trim();
		if (last) emit(last);
		return out.join('\n').replace(/\n{3,}/g, '\n\n').replace(/^\n+/, '').replace(/\s+$/, '') + '\n';
	}

	// ── HTML formatter + element-range mapper ─────────────────────
	var HTML_VOID = { area: 1, base: 1, br: 1, col: 1, embed: 1, hr: 1, img: 1, input: 1, link: 1, meta: 1, param: 1, source: 1, track: 1, wbr: 1 };
	var HTML_RAW = { pre: 1, textarea: 1, script: 1, style: 1 };

	// Inline elements whose text must NOT be broken onto its own line.
	//
	// Indenting <span>20% OFF</span> into
	//     <span>
	//       20% OFF
	//     </span>
	// puts whitespace inside the span. Whitespace collapses — it does not vanish
	// — so the span renders as " 20% OFF ", one real space at each end, and its
	// inline box is two spaces wider than its text. Anything sized to that box
	// (a highlight drawn with an absolutely-positioned ::before, say) overshoots
	// the words it was meant to sit behind. Hitting Format was enough to do it.
	//
	// So an inline element holding nothing but text is emitted on ONE line, text
	// collapsed but not padded. Inline elements with child tags still nest —
	// their children are usually laid out with flex, where whitespace is ignored.
	var HTML_INLINE_TEXT = {
		a: 1, abbr: 1, b: 1, cite: 1, code: 1, em: 1, i: 1, kbd: 1, label: 1,
		mark: 1, q: 1, s: 1, samp: 1, small: 1, span: 1, strong: 1, sub: 1,
		sup: 1, time: 1, u: 1, 'var': 1
	};

	// Index just past </name> for the element opening at openGt, or -1 when it
	// nests a same-name tag or holds any child tag (then: let the normal
	// indenting path handle it).
	function simpleInlineClose(s, name, openGt) {
		var lower = s.toLowerCase();
		var close = lower.indexOf('</' + name, openGt + 1);
		if (close < 0) return -1;
		var nested = lower.indexOf('<' + name, openGt + 1);
		if (nested !== -1 && nested < close) return -1;
		if (s.slice(openGt + 1, close).indexOf('<') !== -1) return -1;
		var gt = s.indexOf('>', close);
		return gt < 0 ? -1 : gt + 1;
	}

	function htmlTagEnd(s, i) {
		var q = null;
		for (var k = i + 1; k < s.length; k++) {
			var c = s.charAt(k);
			if (q) { if (c === q) q = null; continue; }
			if (c === '"' || c === "'") { q = c; continue; }
			if (c === '>') return k;
		}
		return -1;
	}
	function htmlTagName(tag) { var m = /^<\/?\s*([a-zA-Z][\w:-]*)/.exec(tag); return m ? m[1].toLowerCase() : ''; }

	// Pretty-print a markup fragment: 2-space nesting, each tag/text on its
	// own line. <pre>/<textarea>/<script>/<style> content is left verbatim.
	function formatHtml(html) {
		var s = String(html);
		var out = [];
		var depth = 0, i = 0, n = s.length;
		function ind() { var r = ''; for (var k = 0; k < depth; k++) r += '  '; return r; }
		function emit(t) { out.push(ind() + t); }
		while (i < n) {
			var c = s.charAt(i);
			if (c === '<') {
				if (s.substr(i, 4) === '<!--') {
					var ce = s.indexOf('-->', i + 4); ce = ce < 0 ? n : ce + 3;
					var cl = s.slice(i, ce).split('\n');
					for (var k = 0; k < cl.length; k++) out.push(ind() + (k === 0 ? cl[k].replace(/^\s+/, '') : ' ' + cl[k].trim()));
					i = ce; continue;
				}
				var gt = htmlTagEnd(s, i);
				if (gt < 0) { var rest = s.slice(i).trim(); if (rest) emit(rest); break; }
				var tag = s.slice(i, gt + 1), name = htmlTagName(tag);
				var isClose = s.charAt(i + 1) === '/', selfClose = tag.charAt(tag.length - 2) === '/';
				if (isClose) { depth = depth > 0 ? depth - 1 : 0; emit(tag.trim()); i = gt + 1; continue; }
				if (HTML_RAW[name] && !selfClose) {
					var close = s.toLowerCase().indexOf('</' + name, gt + 1);
					var contentEnd = close < 0 ? n : close, closeGt = close < 0 ? n : s.indexOf('>', close);
					emit(tag.trim()); depth++;
					var lines = s.slice(gt + 1, contentEnd).replace(/^\n|\s+$/g, '').split('\n');
					for (var k2 = 0; k2 < lines.length; k2++) { if (lines[k2].trim() !== '' || lines.length === 1) out.push(ind() + lines[k2]); }
					depth--;
					if (close >= 0) emit('</' + name + '>');
					i = closeGt < 0 ? n : closeGt + 1; continue;
				}
				// Inline element wrapping plain text stays on one line — see
				// HTML_INLINE_TEXT for why breaking it changes what renders.
				if (HTML_INLINE_TEXT[name] && !selfClose && !HTML_VOID[name]) {
					var iEnd = simpleInlineClose(s, name, gt);
					if (iEnd > 0) {
						var iClose = s.toLowerCase().indexOf("</" + name, gt + 1);
						var inner = s.slice(gt + 1, iClose).replace(/\s+/g, " ").trim();
						emit(tag.trim() + inner + "</" + name + ">");
						i = iEnd; continue;
					}
				}
				emit(tag.trim());
				if (!HTML_VOID[name] && !selfClose) depth++;
				i = gt + 1;
			} else {
				var lt = s.indexOf('<', i); if (lt < 0) lt = n;
				var text = s.slice(i, lt).replace(/\s+/g, ' ').trim();
				if (text) emit(text);
				i = lt;
			}
		}
		return out.join('\n').replace(/\n{3,}/g, '\n\n').replace(/^\n+/, '').replace(/\s+$/, '') + '\n';
	}

	// Map each element (document order, skipping style/script — same scheme
	// the frame uses for uids) to its [start,end] range in the markup text.
	function parseHtmlRanges(html) {
		var ranges = {}, stack = [], seq = 0;
		var re = /<!--[\s\S]*?-->|<([a-zA-Z][\w:-]*)((?:"[^"]*"|'[^']*'|[^>"'])*?)(\/?)>|<\/([a-zA-Z][\w:-]*)\s*>/g;
		var m;
		while ((m = re.exec(html)) !== null) {
			if (m[0].indexOf('<!--') === 0) continue;
			if (m[1]) {
				var tag = m[1].toLowerCase(), start = m.index, end = m.index + m[0].length;
				if (tag === 'style' || tag === 'script') {
					var close = html.toLowerCase().indexOf('</' + tag, end);
					if (close >= 0) { var cg = html.indexOf('>', close); re.lastIndex = cg >= 0 ? cg + 1 : end; }
					continue;
				}
				seq++;
				var uid = 'u' + seq;
				var parentUid = stack.length ? stack[stack.length - 1].uid : null;
				// openEnd = end of the open tag; closeStart = start of the close
				// tag (null when there's no separate close tag); parent = the
				// enclosing element (so we can tell direct text from child tags).
				if (HTML_VOID[tag] || m[3] === '/') ranges[uid] = { start: start, end: end, openEnd: end, closeStart: null, parent: parentUid };
				else stack.push({ uid: uid, tag: tag, start: start, openEnd: end, parent: parentUid });
			} else if (m[4]) {
				var ctag = m[4].toLowerCase();
				if (ctag === 'style' || ctag === 'script') continue;
				var found = -1;
				for (var j = stack.length - 1; j >= 0; j--) { if (stack[j].tag === ctag) { found = j; break; } }
				if (found < 0) continue;
				for (var j2 = stack.length - 1; j2 >= found; j2--) {
					var e = stack[j2];
					if (j2 === found) ranges[e.uid] = { start: e.start, end: m.index + m[0].length, openEnd: e.openEnd, closeStart: m.index, parent: e.parent };
					else ranges[e.uid] = { start: e.start, end: m.index, openEnd: e.openEnd, closeStart: null, parent: e.parent }; // implicitly closed
				}
				stack.length = found;
			}
		}
		for (var s2 = 0; s2 < stack.length; s2++) ranges[stack[s2].uid] = { start: stack[s2].start, end: html.length, openEnd: stack[s2].openEnd, closeStart: null, parent: stack[s2].parent };
		return ranges;
	}

	// ── CSS source parser ─────────────────────────────────────────
	// Splits a stylesheet into rules with their source offsets, so later
	// steps can edit the exact text. Brace/▸string/▸comment aware. Returns:
	//   { type:'style',    selector, body, media, start, end, bodyStart, bodyEnd }
	//   { type:'at-block', selector, body, media, start, end }   // @keyframes etc.
	//   { type:'at',       text, media, start, end }             // @import; etc.
	function parseStylesheet(css) {
		var rules = [];
		try {
			parseBlock(String(css || ''), 0, String(css || '').length, null, rules);
		} catch (e) {
			return [];
		}
		return rules;
	}

	function skipTrivia(css, i, end) {
		while (i < end) {
			var c = css.charAt(i);
			if (c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === '\f') { i++; continue; }
			if (c === '/' && css.charAt(i + 1) === '*') {
				var e = css.indexOf('*/', i + 2);
				i = e < 0 ? end : e + 2;
				continue;
			}
			break;
		}
		return i;
	}

	function findBlockEnd(css, start, end) {
		// `start` points just past the opening brace; returns index of the
		// matching closing brace (or `end`).
		var depth = 1, i = start, inStr = null;
		while (i < end) {
			var c = css.charAt(i);
			if (inStr) {
				if (c === '\\') { i += 2; continue; }
				if (c === inStr) inStr = null;
				i++; continue;
			}
			if (c === '"' || c === "'") { inStr = c; i++; continue; }
			if (c === '/' && css.charAt(i + 1) === '*') {
				var e = css.indexOf('*/', i + 2);
				i = e < 0 ? end : e + 2;
				continue;
			}
			if (c === '{') { depth++; i++; continue; }
			if (c === '}') { depth--; if (depth === 0) return i; i++; continue; }
			i++;
		}
		return end;
	}

	function parseBlock(css, start, end, media, out) {
		var i = start;
		while (i < end) {
			i = skipTrivia(css, i, end);
			if (i >= end) break;
			var ruleStart = i;

			// Read the prelude up to '{' or ';' at this level.
			var j = i, inStr = null;
			while (j < end) {
				var c = css.charAt(j);
				if (inStr) {
					if (c === '\\') { j += 2; continue; }
					if (c === inStr) inStr = null;
					j++; continue;
				}
				if (c === '"' || c === "'") { inStr = c; j++; continue; }
				if (c === '/' && css.charAt(j + 1) === '*') {
					var e = css.indexOf('*/', j + 2);
					j = e < 0 ? end : e + 2;
					continue;
				}
				if (c === '{' || c === ';') break;
				j++;
			}
			if (j >= end) break;

			var prelude = css.slice(i, j).trim();

			if (css.charAt(j) === ';') {
				// Statement at-rule, e.g. @import / @charset.
				out.push({ type: 'at', text: css.slice(ruleStart, j + 1).trim(), media: media, start: ruleStart, end: j + 1 });
				i = j + 1;
				continue;
			}

			// css.charAt(j) === '{'
			var bodyStart = j + 1;
			var bodyEnd = findBlockEnd(css, bodyStart, end);
			var ruleEnd = Math.min(bodyEnd + 1, end);
			var lower = prelude.toLowerCase();

			if (prelude.charAt(0) === '@' && (lower.indexOf('@media') === 0 || lower.indexOf('@supports') === 0)) {
				// Nested rules inherit this condition for display.
				parseBlock(css, bodyStart, bodyEnd, prelude, out);
			} else if (prelude.charAt(0) === '@') {
				// @keyframes / @font-face / @property — not element-matchable,
				// but still editable (body offsets kept for the All-CSS view).
				out.push({ type: 'at-block', selector: prelude, body: css.slice(bodyStart, bodyEnd), media: media, start: ruleStart, end: ruleEnd, bodyStart: bodyStart, bodyEnd: bodyEnd });
			} else {
				out.push({
					type: 'style',
					selector: prelude,
					body: css.slice(bodyStart, bodyEnd),
					media: media,
					start: ruleStart, end: ruleEnd,
					bodyStart: bodyStart, bodyEnd: bodyEnd
				});
			}
			i = ruleEnd;
		}
	}

	// After the tab/window was backgrounded or the laptop went idle,
	// CodeMirror's measurement caches go stale, which makes the first scroll
	// stutter. Re-measure the editors when we regain focus/visibility.
	function onVisibilityRefresh() {
		if (document.visibilityState && document.visibilityState !== 'visible') return;
		setTimeout(function () {
			if (state.allCm) { try { state.allCm.refresh(); } catch (e) {} }
			if (state.htmlLeftCm) { try { state.htmlLeftCm.refresh(); } catch (e) {} }
			for (var i = 0; i < state.cards.length; i++) {
				if (state.cards[i].cm) { try { state.cards[i].cm.refresh(); } catch (e) {} }
			}
		}, 50);
	}

	function onKeydown(e) {
		if (e.key !== 'Escape') return;
		e.preventDefault();
		e.stopPropagation();
		// The discard confirmation captures Escape to back itself out, before
		// it can close the takeover.
		if (state.confirming) { hideConfirm(); return; }
		// An expanded Checks list folds away first, before Escape cancels.
		if (state.checksOpen) { setChecksOpen(false); return; }
		requestCancel();
	}

	// ── Boot ──────────────────────────────────────────────────────
	function init() {
		var btn = document.getElementById('es-inspector-open');
		if (!btn) return; // Button only rendered for in-scope forms.
		if (!document.getElementById(HTML_ID) || !document.getElementById(CSS_ID)) return;
		btn.addEventListener('click', open);
		// Re-open automatically when the page loads with the param present
		// (e.g. the user refreshed while the takeover was open). Deferred so
		// the CodeMirror editors have a chance to mount first.
		if (urlWantsInspector()) setTimeout(open, 250);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
