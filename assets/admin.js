(function ($) {
	function relaxedSettings(base) {
		// Disable lint markers for hand-authored form HTML/CSS — the linter raises
		// false positives on legitimate SVG attributes (viewBox), and on indentation
		// style, which aren't real errors for this use case.
		if (!base || !base.codemirror) return base;
		base.codemirror.lint = false;
		if (Array.isArray(base.codemirror.gutters)) {
			base.codemirror.gutters = base.codemirror.gutters.filter(g => g !== 'CodeMirror-lint-markers');
		}
		return base;
	}

	$(function () {
		// Mount CodeMirror on editor textareas. Single-version forms get
		// #es-html / #es-css. A/B test forms get #es-html-A / #es-css-A
		// IMMEDIATELY (slot A pane is visible from page load), but slot
		// B's editors are LAZY-INITIALIZED on the first click of the B
		// tab — CodeMirror's auto-save submit handler doesn't reliably
		// attach when init runs in a display:none container, which would
		// silently swallow the user's B edits on form submit.
		function initEditorIfExists(id, settings) {
			var el = document.getElementById(id);
			// Skip hidden fields — the preview-first Design tab keeps es-html /
			// es-css as hidden inputs (edited only via the takeover editor), so
			// mounting CodeMirror on them would surface a stray editor.
			if (!settings || !el || el.hidden) return;
			wp.codeEditor.initialize($('#' + id), relaxedSettings(settings));
		}
		(function mountAllEditors() {
			if (!window.EmblemStudioAdmin || !wp.codeEditor) return;
			initEditorIfExists('es-html',   EmblemStudioAdmin.htmlEditor);
			initEditorIfExists('es-css',    EmblemStudioAdmin.cssEditor);
			initEditorIfExists('es-html-A', EmblemStudioAdmin.htmlEditor);
			initEditorIfExists('es-css-A',  EmblemStudioAdmin.cssEditor);
			// Slot B is intentionally NOT initialized here — see
			// ensureSlotBEditorsInited inside the variant strip IIFE.
		})();

		// Belt-and-suspenders form-submit flush: CodeMirror.fromTextArea
		// normally adds its own submit handler that calls cm.save() (which
		// copies CM's internal value back to the underlying textarea so
		// the form POST carries it). With editors mounted in panes that
		// may have been hidden at some point, the auto-save attachment
		// can flake — so we also explicitly walk every possible editor
		// id and force cm.save() before the form submits.
		(function flushEditorsOnSubmit() {
			var form = document.querySelector('form.es-form-editor');
			if (!form) return;
			form.addEventListener('submit', function () {
				['es-html', 'es-html-A', 'es-html-B', 'es-css', 'es-css-A', 'es-css-B'].forEach(function (id) {
					var el = document.getElementById(id);
					if (!el) return;
					var cm = el.CodeMirror || (el.nextElementSibling && el.nextElementSibling.CodeMirror);
					if (cm) { try { cm.save(); } catch (e) {} }
				});
			}, true); // capture-phase so we run before any cancel listeners
		})();

		function syncSuccessFields() {
			var mode = $('input[data-es-success-mode]:checked').val();
			$('.es-success-field').removeClass('is-visible');
			if (mode === 'custom_message') {
				$('.es-success-field-custom_message').addClass('is-visible');
			} else if (mode === 'redirect') {
				$('.es-success-field-redirect').addClass('is-visible');
			}
		}
		$(document).on('change', 'input[data-es-success-mode]', syncSuccessFields);
		syncSuccessFields();

		// Full-screen toggle now appears once per popup panel (click + auto).
		// Keep every copy in lock-step, and disable the max-width input wherever
		// full-screen is on (it's meaningless then).
		function syncPopupFullscreen(on) {
			var $fs = $('input[name="popup_fullscreen"]');
			if (!$fs.length) return;
			if (typeof on !== 'boolean') { on = $fs.is(':checked'); }
			$fs.prop('checked', on);
			$('input[name="popup_max_width"]').prop('disabled', on);
		}
		$(document).on('change', 'input[name="popup_fullscreen"]', function () {
			syncPopupFullscreen(this.checked);
		});
		syncPopupFullscreen();

		// Status toggle: flip the hidden status value + label live, and keep
		// the header's data-status in sync (drives styling elsewhere). The
		// hidden input changing is what the dirty-tracker picks up.
		$(document).on('click', '.es-status-switch', function () {
			var $sw     = $(this);
			var active  = $sw.attr('aria-checked') !== 'true';
			var value   = active ? 'active' : 'inactive';
			$sw.attr('aria-checked', active ? 'true' : 'false');
			$('.es-status-text').text(active ? 'Active' : 'Inactive');
			$('.es-form-header').attr('data-status', value);
			var hidden = document.getElementById('es-status');
			if (hidden) {
				hidden.value = value;
				// Native bubbling event so the dirty-tracker (addEventListener) sees it.
				hidden.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});

		// Start A/B test — open the confirmation modal (graphic + irreversible
		// reset warning). The modal's confirm button submits the form with
		// ab_action=start; cancel / backdrop / Escape dismiss it.
		var $abStartModal = $('#es-modal-ab-start');
		function openAbStartModal() {
			$abStartModal.prop('hidden', false);
			$('body').css('overflow', 'hidden');
		}
		function closeAbStartModal() {
			$abStartModal.prop('hidden', true);
			$('body').css('overflow', '');
		}
		$(document).on('click', '.es-ab-start-open', openAbStartModal);
		$(document).on('click', '.es-ab-start-dismiss', closeAbStartModal);
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && $abStartModal.length && ! $abStartModal.prop('hidden')) closeAbStartModal();
		});

		// A/B archive panel toggle. Button has aria-controls pointing at
		// the panel id, aria-expanded flips on click. Multiple toggles
		// can target the same panel (idle + running strips both show it).
		$(document).on('click', '.es-ab-archive-toggle', function () {
			var panelId = this.getAttribute('aria-controls');
			var panel = panelId ? document.getElementById(panelId) : null;
			if (!panel) return;
			var open = panel.hasAttribute('hidden');
			if (open) panel.removeAttribute('hidden');
			else panel.setAttribute('hidden', '');
			document.querySelectorAll('[aria-controls="' + panelId + '"]').forEach(function (t) {
				t.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		});
		$(document).on('click', '.es-ab-archive-restore', function (e) {
			if (!confirm('Restore this archived variant?\n\nA new A/B test will start with the current design in slot A and this variant in slot B.\nStats will reset.')) {
				e.preventDefault();
			}
		});
		$(document).on('click', '.es-ab-archive-delete', function (e) {
			if (!confirm('Delete this archived variant permanently?\n\nThis cannot be undone.')) {
				e.preventDefault();
			}
		});
		$(document).on('click', '.es-revision-restore-as-variant', function (e) {
			if (!confirm('Start a new A/B test with this revision as variant B?\n\nThe current design becomes variant A. Stats will reset.')) {
				e.preventDefault();
			}
		});

		// A/B traffic split: A and B inputs are locked to sum 100. User
		// edits A → B auto-derives. B is editable too — when user types
		// in B, we mirror back to A. The B input is not submitted (no
		// name attr); A is the single source of truth.
		(function initABTrafficSplit() {
			var a = document.getElementById('es-ab-traffic-a');
			var b = document.getElementById('es-ab-traffic-b');
			if (!a || !b) return;
			function clamp(n) { return Math.max(1, Math.min(99, n)); }
			a.addEventListener('input', function () {
				var v = clamp(parseInt(a.value, 10) || 50);
				a.value = v;
				b.value = 100 - v;
			});
			b.addEventListener('input', function () {
				var v = clamp(parseInt(b.value, 10) || 50);
				b.value = v;
				a.value = 100 - v;
				// Bubble change on A so the dirty tracker sees it (B has
				// no name, won't trigger FormData diff on its own).
				a.dispatchEvent(new Event('change', { bubbles: true }));
			});
		})();

		// Declare-winner / end-test confirmation. data-winner is "A", "B",
		// or "" (end without winner). Each carries different consequences
		// so the message is tailored per case. The href already carries
		// a per-form nonce — JS just gates the click.
		$(document).on('click', '.es-ab-declare-winner', function (e) {
			var slot = this.getAttribute('data-winner') || '';
			var msg;
			if (slot === 'A' || slot === 'B') {
				msg = 'Declare variant ' + slot + ' the winner?\n\n'
					+ slot + ' becomes the live design.\nThe other variant is archived.\nThis cannot be undone.';
			} else {
				msg = 'End the A/B test without declaring a winner?\n\n'
					+ 'Both variants are archived.\nThe form reverts to the pre-test design.';
			}
			if (!confirm(msg)) e.preventDefault();
		});

		// A/B variant strip — only present on the Design tab of a form
		// with a running test. The form now has TWO complete editor
		// panes (one per slot), each with its own mounted CodeMirror.
		// Tab clicks just toggle which pane is visible — no value
		// shuffling, no hidden fields, no browser-cache fights. The
		// textareas submit directly as variants[A|B][html|css].
		// `window.EmblemStudioAB.activeSlot` reflects the user's current
		// pick — read by the inline-preview helper to render the right
		// variant. Stays 'A' for single-version forms.
		// Variant strip sticks below the form header on scroll. Form
		// header height varies with viewport width, font loading
		// (Montserrat reflows once it's ready), and any admin-bar
		// state — so a CSS-hardcoded offset misjudges in either
		// direction (gap when header is short, overlap when tall).
		// Measure both at mount + on resize + after fonts load and
		// stamp the result as inline top:Npx on the strip.
		(function syncStickyStripOffset() {
			var header = document.querySelector('.es-form-header');
			var strip  = document.querySelector('.es-ab-strip--running');
			if (!header || !strip) return;
			function update() {
				var wpBar = document.getElementById('wpadminbar');
				var wpBarH = wpBar ? wpBar.offsetHeight : 32;
				// -1 to overlap the form-header's bottom border by a hair
				// so the strip sits flush instead of leaving a 1px seam.
				strip.style.top = (wpBarH + header.offsetHeight - 1) + 'px';
			}
			update();
			window.addEventListener('resize', update);
			if (document.fonts && document.fonts.ready) {
				document.fonts.ready.then(update);
			}
		})();

		window.EmblemStudioAB = window.EmblemStudioAB || { activeSlot: 'A', testRunning: false };
		(function initABVariantSwitch() {
			var strip = document.querySelector('.es-ab-strip--running');
			if (!strip) return;
			EmblemStudioAB.testRunning = true;
			var tabs = strip.querySelectorAll('.es-ab-tab');
			var activeLabel = strip.querySelector('.es-ab-strip-active-slot');
			var panes = {
				A: document.querySelector('.es-ab-pane[data-es-variant-pane="A"]'),
				B: document.querySelector('.es-ab-pane[data-es-variant-pane="B"]')
			};
			var slotBInited = false;

			function getCM(id) {
				var el = document.getElementById(id);
				if (!el) return null;
				return el.CodeMirror || (el.nextElementSibling && el.nextElementSibling.CodeMirror) || null;
			}

			// Initialize slot B's editors the FIRST time the user clicks
			// the B tab — at that moment the pane is visible (we've just
			// added .is-active), so CodeMirror can lay out correctly and
			// its auto-save submit handler attaches reliably. Idempotent.
			function ensureSlotBEditorsInited() {
				if (slotBInited) return;
				if (!window.EmblemStudioAdmin || !wp.codeEditor) return;
				slotBInited = true;
				initEditorIfExists('es-html-B', EmblemStudioAdmin.htmlEditor);
				initEditorIfExists('es-css-B',  EmblemStudioAdmin.cssEditor);
			}

			function setActiveSlot(slot) {
				if (!slot || !panes[slot]) return;
				EmblemStudioAB.activeSlot = slot;
				['A', 'B'].forEach(function (s) {
					if (panes[s]) panes[s].classList.toggle('is-active', s === slot);
				});
				tabs.forEach(function (t) {
					var on = t.getAttribute('data-variant-slot') === slot;
					t.classList.toggle('is-active', on);
					t.setAttribute('aria-selected', on ? 'true' : 'false');
				});
				if (activeLabel) activeLabel.textContent = slot;
				// First time we reveal slot B, mount its CodeMirrors. After
				// that, just refresh on every reveal so the gutter renders
				// (CM can lose layout when its container goes display:none).
				if (slot === 'B') ensureSlotBEditorsInited();
				setTimeout(function () {
					var cmH = getCM('es-html-' + slot);
					var cmC = getCM('es-css-' + slot);
					try { if (cmH) cmH.refresh(); } catch (e) {}
					try { if (cmC) cmC.refresh(); } catch (e) {}
				}, 0);
				// Notify other components (inline preview, future hooks)
				// that the user is now editing a different variant. Tab
				// clicks don't fire CodeMirror change events on their own
				// in the two-pane architecture, so without this the
				// preview iframe would stay stuck on the prior slot.
				document.dispatchEvent(new CustomEvent('os:variant-changed', { detail: { slot: slot } }));
			}

			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					var slot = tab.getAttribute('data-variant-slot');
					if (slot && slot !== EmblemStudioAB.activeSlot) setActiveSlot(slot);
				});
			});
		})();

		// (Popup options — max-width, full-screen — and close-button style now
		// live inside each shell's settings panel, shown/hidden with the panel.)

		// Shell card: toggle data-enabled when checkbox flips so CSS can
		// show/hide the body and tint the border.
		$(document).on('change', '.es-shell-toggle', function () {
			$(this).closest('.es-shell-card').attr('data-enabled', this.checked ? 'true' : 'false');
		});

		// Display-rules Visitor block: user-role checkboxes only make sense
		// when "Only logged-in" is selected — toggle visibility live so the
		// admin doesn't see options that won't apply.
		$(document).on('change', 'input[data-visitor-logged-in]', function () {
			var $fieldset = $(this).closest('.es-dr-visitor');
			var $roles    = $fieldset.find('.es-dr-roles');
			$roles.toggle($(this).val() === 'in');
		});

		// Checkbox-pill group: keep is-checked class in sync with the actual
		// checkbox state so the pill background reflects current selection.
		$(document).on('change', '.es-checkbox-pill input[type="checkbox"]', function () {
			$(this).closest('.es-checkbox-pill').toggleClass('is-checked', this.checked);
		});

		// Inline live preview under the HTML/CSS editors.
		// User-toggleable via a checkbox; persisted in localStorage so the
		// choice sticks across reloads. Refreshes the iframe (debounced)
		// after CodeMirror change events, and re-renders if the success-mode
		// radios or success-message textarea change. Reuses the same POST
		// mechanism as the "Preview in new tab" button — just targets the
		// iframe instead of _blank.
		(function initInlivePreview() {
			var checkbox = document.getElementById('es-inline-preview-enabled');
			if (!checkbox) return; // No inline preview block on this page (new form etc.)
			var wrap = document.querySelector('.es-inline-preview-frame-wrap');
			var btn = document.querySelector('.es-preview-btn');
			var url = btn ? btn.getAttribute('data-preview-url') : null;
			if (!wrap || !url) return;

			var STORAGE_KEY = 'es_inline_preview_enabled';
			var refreshTimer = null;

			function render() {
				if (!checkbox.checked) return;
				// preview_frame=1 → the positioned, uninstrumented frame
				// (correct shell placement + entrance animation + Replay),
				// the same render the editor's live stage uses.
				submitPreviewPost(url, 'es-inline-preview-iframe', { preview_frame: '1' });
			}

			function scheduleRender() {
				if (!checkbox.checked) return;
				clearTimeout(refreshTimer);
				refreshTimer = setTimeout(render, 600);
			}

			function applyState(enabled) {
				wrap.hidden = !enabled;
				if (enabled) {
					try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
					render();
				} else {
					try { localStorage.setItem(STORAGE_KEY, '0'); } catch (e) {}
				}
			}

			checkbox.addEventListener('change', function () { applyState(checkbox.checked); });

			// Replay entry animations in the preview frame.
			var replayBtn = document.getElementById('es-inline-preview-replay');
			if (replayBtn) {
				replayBtn.addEventListener('click', function () {
					var iframe = document.getElementById('es-inline-preview-iframe');
					if (iframe && iframe.contentWindow) {
						iframe.contentWindow.postMessage({ type: 'es-inspector:replay' }, '*');
					}
				});
				// Disable Replay when the frame reports the asset has no animation.
				window.addEventListener('message', function (e) {
					var iframe = document.getElementById('es-inline-preview-iframe');
					if (!iframe || e.source !== iframe.contentWindow) return;
					var d = e.data || {};
					if (!d || d.type !== 'es-inspector:animatable') return;
					replayBtn.disabled = !d.animatable;
					replayBtn.setAttribute('data-es-tip', d.animatable ? 'Replay entry animations' : 'This asset has no animation');
				});
			}

			// Restore previous preference.
			try {
				if (localStorage.getItem(STORAGE_KEY) === '1') {
					checkbox.checked = true;
					// Wait briefly for CodeMirror to mount its editors before
					// the first render — otherwise we'd POST empty values.
					setTimeout(function () { applyState(true); }, 400);
				}
			} catch (e) {}

			// Hook CodeMirror change events for debounced refresh.
			// Dedup via a flag on the CM instance so we can safely
			// re-call hookEditor when slot B's editors lazy-init.
			function hookEditor(id) {
				var el = document.getElementById(id);
				if (!el) return;
				var tries = 0;
				var t = setInterval(function () {
					var cm = el.CodeMirror || (el.nextElementSibling && el.nextElementSibling.CodeMirror);
					if (cm) {
						if (!cm._osPreviewHooked) {
							cm._osPreviewHooked = true;
							cm.on('change', scheduleRender);
						}
						clearInterval(t);
					}
					else if (++tries > 80) clearInterval(t);
				}, 100);
			}
			// Hook either the single-version editors OR the per-slot
			// A/B editors — whichever the page rendered.
			['es-html', 'es-html-A', 'es-html-B'].forEach(hookEditor);
			['es-css', 'es-css-A', 'es-css-B'].forEach(hookEditor);

			// Variant tab switches don't fire CodeMirror change events,
			// so without an explicit hook the preview would stay stuck
			// on the previously-edited slot. Listen for the synthetic
			// event from the variant strip + re-hook B's editors in case
			// they were lazy-initialized AFTER our initial polling expired.
			document.addEventListener('os:variant-changed', function () {
				hookEditor('es-html-B');
				hookEditor('es-css-B');
				scheduleRender();
			});

			// Success-mode + message changes should also trigger a re-render
			// since the preview reflects what shows after submit.
			$(document).on('change', 'input[name="success_mode"], #es-success-message', scheduleRender);
		})();

		// Preview-first Design tab (single-version). The HTML/CSS fields are
		// hidden; this shows the positioned preview when there's markup, a
		// shell-aware placeholder when it's empty, switches between enabled
		// shells, replays entry animations, and refreshes after the editor.
		(function initDesignPreview() {
			var wrap = document.querySelector('.es-design-preview');
			if (!wrap) return;
			var url = wrap.getAttribute('data-preview-url');
			var isNew = wrap.getAttribute('data-is-new') === '1';
			var iframe = document.getElementById('es-inline-preview-iframe');
			var ph = wrap.querySelector('.es-design-placeholder');
			if (!iframe || !ph) return;

			var shellBtns = wrap.querySelectorAll('.es-design-shell');
			function activeShell() {
				var a = wrap.querySelector('.es-design-shell.is-active') || shellBtns[0];
				return a ? a.getAttribute('data-shell') : 'inline';
			}
			function htmlFieldId() {
				// A/B: the preview tracks whichever variant the tabs have active.
				if (window.EmblemStudioAB && window.EmblemStudioAB.testRunning) {
					return 'es-html-' + window.EmblemStudioAB.activeSlot;
				}
				return 'es-html';
			}
			function isEmpty() {
				var ta = document.getElementById(htmlFieldId());
				return !ta || ta.value.trim() === '';
			}
			function buildPlaceholder() {
				var shell = activeShell();
				var shape = (shell === 'ribbon') ? 'bar' : (shell === 'slide-in') ? 'corner' : 'card';
				ph.className = 'es-design-placeholder is-' + shape;
				ph.innerHTML =
					'<div class="es-design-ph-box">' +
						'<div class="es-design-ph-icon" aria-hidden="true">+</div>' +
						'<div class="es-design-ph-copy">' +
							'<p class="es-design-ph-title">' + (isNew ? 'Build your asset' : 'Nothing to show yet') + '</p>' +
							'<p class="es-design-ph-text">Open the editor to paste your HTML &amp; CSS and build this asset.</p>' +
						'</div>' +
					'</div>';
			}
			var loader = wrap.querySelector('.es-design-loader');
			function refresh() {
				syncTestVisibility();
				if (shellHint) shellHint.hidden = true; // re-shown when the new frame reports its shell
				if (isEmpty() || !url) {
					if (loader) loader.hidden = true; // nothing to load — show the placeholder instead
					buildPlaceholder();
					ph.hidden = false;
				} else {
					ph.hidden = true;
					if (loader) loader.hidden = false; // spinner until the iframe finishes loading
					iframe.style.height = '520px'; // reset to the floor; the frame re-reports its size
					submitPreviewPost(url, 'es-inline-preview-iframe', { preview_frame: '1', inspector_shell: activeShell() });
				}
			}

			for (var i = 0; i < shellBtns.length; i++) {
				shellBtns[i].addEventListener('click', function (e) {
					for (var j = 0; j < shellBtns.length; j++) shellBtns[j].classList.remove('is-active');
					e.currentTarget.classList.add('is-active');
					refresh();
				});
			}

			var replay = document.getElementById('es-design-replay');
			if (replay) replay.addEventListener('click', function () {
				if (iframe.contentWindow) iframe.contentWindow.postMessage({ type: 'es-inspector:replay' }, '*');
			});

			// Preview testing toggles. Default = sandbox (no validation, no send).
			// "Submit for real" implies "Enforce required", so we tick + lock that
			// box when it's on. We push the current state to the frame on every
			// (re)load so a fresh preview document picks it up.
			var validateCb = wrap.querySelector('.es-design-validate');
			var sendCb = wrap.querySelector('.es-design-send');
			var testGroup = wrap.querySelector('.es-design-test');
			var shellHint = wrap.querySelector('.es-design-shell-hint');

			// The preview frame reports which shell/position it rendered; flag the
			// ones that are easy to miss (a ribbon pinned to the top/bottom edge).
			window.addEventListener('message', function (e) {
				if (e.source !== iframe.contentWindow) return;
				var d = e.data || {};
				// Disable Replay when the asset has no animation to run.
				if (d && d.type === 'es-inspector:animatable') {
					if (replay) {
						replay.disabled = !d.animatable;
						replay.setAttribute('data-es-tip', d.animatable ? 'Replay entry animations' : 'This asset has no animation');
					}
					return;
				}
				// Grow the preview to fit a taller asset (520px floor + ~150px
				// above/below). Ribbon / slide-in / fullscreen report 0 → stay min.
				if (d && d.type === 'es-inspector:size') {
					var sh = (typeof d.height === 'number') ? d.height : 0;
					iframe.style.height = sh > 0 ? Math.max(520, sh + 100) + 'px' : '520px';
					return;
				}
				if (!shellHint || !d || d.type !== 'es-inspector:shell-info') return;
				if (d.shell === 'ribbon') {
					var atBottom = d.position !== 'top';
					shellHint.textContent = atBottom
						? '↓ This ribbon shows at the bottom of the page'
						: '↑ This ribbon shows at the top of the page';
					// Pin the hint to the OPPOSITE edge so it never overlaps the ribbon.
					shellHint.className = 'es-design-shell-hint ' + (atBottom ? 'is-top' : 'is-bottom');
					shellHint.hidden = false;
				} else {
					shellHint.hidden = true;
				}
			});
			function hasForm() {
				var ta = document.getElementById(htmlFieldId());
				return !!ta && /<form[\s>]/i.test(ta.value);
			}
			function syncTestVisibility() {
				// Only relevant when the asset has a <form> to submit.
				if (testGroup) testGroup.hidden = !hasForm();
			}
			function sendPreviewConfig() {
				if (!iframe.contentWindow) return;
				var send = !!(sendCb && sendCb.checked);
				var validate = send || !!(validateCb && validateCb.checked);
				iframe.contentWindow.postMessage({ type: 'es-inspector:preview-config', validate: validate, send: send }, '*');
			}
			if (sendCb) sendCb.addEventListener('change', function () {
				if (validateCb) {
					if (sendCb.checked) validateCb.checked = true;
					validateCb.disabled = sendCb.checked;
				}
				sendPreviewConfig();
			});
			if (validateCb) validateCb.addEventListener('change', sendPreviewConfig);
			iframe.addEventListener('load', function () {
				sendPreviewConfig();
				// Asset has rendered — drop the spinner (a frame later so its
				// entrance animation starts under the veil, not after a flash).
				if (loader && !loader.hidden) requestAnimationFrame(function () { loader.hidden = true; });
			});

			// Width controls — resize the preview iframe (centered in the stage).
			var widthBtns = wrap.querySelectorAll('.es-design-width button[data-w]');
			var slider = wrap.querySelector('.es-design-width-slider');
			var widthLabel = wrap.querySelector('.es-design-width-label');
			function markActiveWidth(w) {
				for (var k = 0; k < widthBtns.length; k++) {
					widthBtns[k].classList.toggle('is-active', widthBtns[k].getAttribute('data-w') === w);
				}
			}
			function setWidth(w) {
				if (w === 'fluid') {
					iframe.style.width = ''; // falls back to 100% via CSS
					if (widthLabel) widthLabel.textContent = 'Fluid';
				} else {
					var px = parseInt(w, 10) || 0;
					iframe.style.width = px + 'px';
					if (slider) slider.value = String(px);
					if (widthLabel) widthLabel.textContent = px + 'px';
				}
				markActiveWidth(w);
			}
			for (var wb = 0; wb < widthBtns.length; wb++) {
				widthBtns[wb].addEventListener('click', function (e) { setWidth(e.currentTarget.getAttribute('data-w')); });
			}
			if (slider) slider.addEventListener('input', function () {
				iframe.style.width = slider.value + 'px';
				if (widthLabel) widthLabel.textContent = slider.value + 'px';
				markActiveWidth(null);
			});

			// The editor writes its edits back to the hidden fields on Done.
			document.addEventListener('es:editor-closed', refresh);
			// A/B: re-render when the variant tabs switch the active variant.
			document.addEventListener('os:variant-changed', refresh);

			setTimeout(refresh, 150);
		})();

		// New-asset "Open editor": there's no saved row yet, so save first
		// (flagging the redirect) and the editor auto-opens on the reload.
		(function initCreateAndEdit() {
			var btn = document.querySelector('.es-design-create-edit');
			if (!btn) return;
			btn.addEventListener('click', function () {
				var form = document.querySelector('form.es-form-editor');
				if (!form) return;
				var name = document.getElementById('es-name');
				if (name && !name.value.trim()) { name.focus(); return; }
				var flag = document.createElement('input');
				flag.type = 'hidden';
				flag.name = 'es_open_editor';
				flag.value = '1';
				form.appendChild(flag);
				if (form.requestSubmit) form.requestSubmit(); else form.submit();
			});
		})();

		// Revisions tab — chronological list with side-by-side diff + preview.
		// Click a row to expand. The body holds:
		//   1. HTML diff (old left, current right) — red lines = removed, green = added
		//   2. CSS diff  (same shape)
		//   3. Previews (old left, current right) — iframes named in PHP so the
		//      POST-to-iframe form submission resolves a named browsing context
		//      on the very first try (no race with .name set via JS).
		(function initRevisions() {
			var list = document.querySelector('.es-revisions-list');
			if (!list) return;
			var previewUrl = list.getAttribute('data-preview-url');
			if (!previewUrl) return;
			var wrap = list.parentElement;
			var currentHtmlEl = wrap.querySelector('.es-revisions-current-html');
			var currentCssEl  = wrap.querySelector('.es-revisions-current-css');
			var currentHtml = currentHtmlEl ? currentHtmlEl.value : '';
			var currentCss  = currentCssEl  ? currentCssEl.value  : '';

			function escapeHtml(s) {
				return String(s)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#39;');
			}

			// Strip CR (CRLF/CR → LF) and drop a trailing newline so split('\n')
			// doesn't yield phantom empty trailing lines or per-line \r mismatches
			// that make every line look "different."
			function normalizeForDiff(s) {
				return String(s).replace(/\r\n?/g, '\n').replace(/\n$/, '');
			}

			// Longest common prefix and suffix of two strings — used to highlight
			// only the changed middle within a modified line.
			function commonPrefixLen(a, b) {
				var i = 0, n = Math.min(a.length, b.length);
				while (i < n && a.charCodeAt(i) === b.charCodeAt(i)) i++;
				return i;
			}
			function commonSuffixLen(a, b, capA, capB) {
				var i = 0, la = a.length, lb = b.length;
				// Don't let the suffix overlap with the already-counted prefix.
				while (i < (la - capA) && i < (lb - capB) && a.charCodeAt(la - 1 - i) === b.charCodeAt(lb - 1 - i)) i++;
				return i;
			}
			// Heuristic: are two changed lines similar enough to pair up as a
			// "modified" row (with intra-line highlights) instead of two separate
			// red/green rows? Yes if the matching prefix+suffix covers at least
			// 25% of the longer line. Otherwise they're effectively unrelated.
			function linesSimilar(a, b) {
				if (!a.length || !b.length) return false;
				var p = commonPrefixLen(a, b);
				var s = commonSuffixLen(a, b, p, p);
				return ( p + s ) / Math.max(a.length, b.length) >= 0.25;
			}

			// Line-level LCS diff. Returns {type, oldLine, newLine} entries.
			function diffLines(oldText, newText) {
				var a = normalizeForDiff(oldText).split('\n');
				var b = normalizeForDiff(newText).split('\n');
				var m = a.length, n = b.length;
				var L = new Array(m + 1);
				for (var i = 0; i <= m; i++) {
					L[i] = new Int32Array(n + 1);
				}
				for (var i = 1; i <= m; i++) {
					for (var j = 1; j <= n; j++) {
						L[i][j] = a[i - 1] === b[j - 1] ? L[i - 1][j - 1] + 1 : Math.max(L[i - 1][j], L[i][j - 1]);
					}
				}
				var out = [];
				var i = m, j = n;
				while (i > 0 && j > 0) {
					if (a[i - 1] === b[j - 1]) {
						out.push({ type: 'common', oldLine: a[i - 1], newLine: b[j - 1] });
						i--; j--;
					} else if (L[i - 1][j] >= L[i][j - 1]) {
						out.push({ type: 'removed', oldLine: a[i - 1] });
						i--;
					} else {
						out.push({ type: 'added', newLine: b[j - 1] });
						j--;
					}
				}
				while (i > 0) { out.push({ type: 'removed', oldLine: a[i - 1] }); i--; }
				while (j > 0) { out.push({ type: 'added',   newLine: b[j - 1] }); j--; }
				out.reverse();
				return out;
			}

			// Post-process: pair adjacent removed+added lines as "modified" when
			// they're similar enough that the intra-line highlight is meaningful.
			// Handles either LCS walk-back order (removed-then-added or
			// added-then-removed) by collecting any contiguous block of
			// non-common entries and re-pairing them positionally. Unrelated
			// lines stay separate.
			function pairModified(diff) {
				var out = [];
				var i = 0;
				while (i < diff.length) {
					if (diff[i].type === 'common') { out.push(diff[i]); i++; continue; }
					// Collect a contiguous run of non-common entries — any
					// interleaving of removed and added.
					var removed = [];
					var added = [];
					while (i < diff.length && diff[i].type !== 'common') {
						if (diff[i].type === 'removed') removed.push(diff[i]);
						else added.push(diff[i]);
						i++;
					}
					// Pair them up positionally; only fuse when "similar."
					var pairs = Math.min(removed.length, added.length);
					var k = 0;
					for (; k < pairs; k++) {
						var oldLine = removed[k].oldLine;
						var newLine = added[k].newLine;
						if (linesSimilar(oldLine, newLine)) {
							out.push({ type: 'modified', oldLine: oldLine, newLine: newLine });
						} else {
							out.push({ type: 'removed', oldLine: oldLine });
							out.push({ type: 'added',   newLine: newLine });
						}
					}
					for (; k < removed.length; k++) out.push(removed[k]);
					for (k = pairs; k < added.length; k++) out.push(added[k]);
				}
				return out;
			}

			// Build the inner HTML for one modified line — prefix/suffix render
			// plain, middle wrapped in <span class="is-changed"> for highlight.
			function inlineHighlight(oldLine, newLine) {
				var p = commonPrefixLen(oldLine, newLine);
				var s = commonSuffixLen(oldLine, newLine, p, p);
				var oldMid = oldLine.slice(p, oldLine.length - s);
				var newMid = newLine.slice(p, newLine.length - s);
				var prefix = escapeHtml(oldLine.slice(0, p));
				var suffix = escapeHtml(oldLine.slice(oldLine.length - s));
				return {
					oldHtml: prefix + (oldMid ? '<span class="is-changed">' + escapeHtml(oldMid) + '</span>' : '') + suffix,
					newHtml: prefix + (newMid ? '<span class="is-changed">' + escapeHtml(newMid) + '</span>' : '') + suffix,
				};
			}

			// Render the diff into a CSS grid: each diff entry becomes one
			// grid row with two cells (old left, new right). The grid
			// auto-equalizes row heights so wrapping in one column doesn't
			// desynchronize the alignment with the other.
			function renderDiff(diff, gridEl) {
				diff = pairModified(diff);
				var cells = [];
				diff.forEach(function (d) {
					if (d.type === 'common') {
						cells.push('<div class="es-diff-cell is-common">' + escapeHtml(d.oldLine) + '</div>');
						cells.push('<div class="es-diff-cell is-common">' + escapeHtml(d.newLine) + '</div>');
					} else if (d.type === 'modified') {
						var h = inlineHighlight(d.oldLine, d.newLine);
						cells.push('<div class="es-diff-cell is-modified-old">' + h.oldHtml + '</div>');
						cells.push('<div class="es-diff-cell is-modified-new">' + h.newHtml + '</div>');
					} else if (d.type === 'removed') {
						cells.push('<div class="es-diff-cell is-removed">' + escapeHtml(d.oldLine) + '</div>');
						cells.push('<div class="es-diff-cell is-spacer">&nbsp;</div>');
					} else {
						cells.push('<div class="es-diff-cell is-spacer">&nbsp;</div>');
						cells.push('<div class="es-diff-cell is-added">' + escapeHtml(d.newLine) + '</div>');
					}
				});
				gridEl.innerHTML = cells.join('');
			}

			// Render a revision into a named iframe through the positioned
			// preview frame (preview_frame=1 → correct shell placement). Pass
			// html/css for the old revision; pass null for the current version
			// so the server uses the saved DB values.
			function postToIframe(iframeName, html, css) {
				var f = document.createElement('form');
				f.method = 'POST';
				f.action = previewUrl;
				f.target = iframeName;
				f.style.display = 'none';
				function add(n, v) {
					var i = document.createElement('input');
					i.type = 'hidden';
					i.name = n;
					i.value = v;
					f.appendChild(i);
				}
				add('preview_frame', '1');
				if (html != null) add('preview_html', html);
				if (css != null) add('preview_css', css);
				document.body.appendChild(f);
				f.submit();
				setTimeout(function () { try { document.body.removeChild(f); } catch (e) {} }, 200);
			}

			function initRow(li) {
				if (li.getAttribute('data-es-loaded') === '1') return;
				li.setAttribute('data-es-loaded', '1');
				var revisionId = li.getAttribute('data-revision-id');
				var oldHtml = li.querySelector('.es-revision-data-html').value;
				var oldCss  = li.querySelector('.es-revision-data-css').value;

				// Diff panes: HTML + CSS
				renderDiff(
					diffLines(oldHtml, currentHtml),
					li.querySelector('.es-revision-diff-grid[data-which="html"]')
				);
				renderDiff(
					diffLines(oldCss, currentCss),
					li.querySelector('.es-revision-diff-grid[data-which="css"]')
				);

				// Previews — both rendered through the positioned preview frame.
				// Iframes have name= set in PHP so the POST resolves the named
				// target on the first try. LEFT = old revision (its HTML/CSS),
				// RIGHT = current saved version (DB values).
				postToIframe('es-rev-cur-' + revisionId, null, null);
				postToIframe('es-rev-old-' + revisionId, oldHtml, oldCss);
			}

			$(list).on('click', '.es-revision-summary', function () {
				var li = this.closest('.es-revision');
				var body = li.querySelector('.es-revision-body');
				var willOpen = body.hidden;
				list.querySelectorAll('.es-revision-body').forEach(function (b) { b.hidden = true; });
				list.querySelectorAll('.es-revision').forEach(function (l) { l.classList.remove('is-open'); });
				if (willOpen) {
					body.hidden = false;
					li.classList.add('is-open');
					initRow(li);
				}
			});

			$(list).on('click', '.es-revision-copy', function () {
				var revisionId = this.getAttribute('data-revision-id');
				var source = this.getAttribute('data-copy-source');
				var ta = list.querySelector('.es-revision-data-' + source + '[data-revision-id="' + revisionId + '"]');
				if (!ta) return;
				var original = this.textContent;
				var done = source === 'html' ? 'HTML copied!' : 'CSS copied!';
				var self = this;
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(ta.value).then(function () {
						self.textContent = done;
						setTimeout(function () { self.textContent = original; }, 1500);
					});
				} else {
					ta.hidden = false;
					ta.select();
					try { document.execCommand('copy'); } catch (e) {}
					ta.hidden = true;
					self.textContent = done;
					setTimeout(function () { self.textContent = original; }, 1500);
				}
			});

			$(list).on('change', '.es-revision-note-input', function () {
				var fd = new FormData();
				fd.append('action', 'es_revision_note');
				fd.append('revision_id', this.getAttribute('data-revision-id'));
				fd.append('note', this.value);
				fd.append('_wpnonce', this.getAttribute('data-nonce'));
				fetch('/wp-admin/admin-post.php', { method: 'POST', body: fd, credentials: 'same-origin' });
			});
			$(list).on('keydown', '.es-revision-note-input', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
			});
		})();

		// Danger zone modal: open on "Reset stats…" click, close on backdrop /
		// close button / Escape. Lock body scroll while open.
		var $dangerModal = $('#es-modal-danger');
		function openDangerModal() {
			$dangerModal.prop('hidden', false);
			$('body').css('overflow', 'hidden');
			$dangerModal.find('.es-modal-close').trigger('focus');
		}
		function closeDangerModal() {
			$dangerModal.prop('hidden', true);
			$('body').css('overflow', '');
		}
		$(document).on('click', '.es-danger-open', openDangerModal);
		$(document).on('click', '[data-es-modal-close]', closeDangerModal);
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && !$dangerModal.prop('hidden')) closeDangerModal();
		});

		// Form-edit tabs. URL hash remembers which tab is open across reloads.
		// If form submit hits an invalid field on a hidden tab, we switch to
		// that tab first so the browser's :invalid focus actually lands somewhere
		// the user can see.
		(function initFormEditTabs() {
			var $tabs   = $('.es-tabs .es-tab');
			var $panels = $('.es-tab-panel');
			if (!$tabs.length) return;
			var slugs = $tabs.map(function () { return $(this).data('tab'); }).get();

			function activate(slug) {
				if (slugs.indexOf(slug) < 0) slug = slugs[0];
				$tabs.removeClass('is-active');
				$tabs.filter('[data-tab="' + slug + '"]').addClass('is-active');
				$panels.removeClass('is-active');
				$panels.filter('[data-panel="' + slug + '"]').addClass('is-active');
			}

			function fromHash() {
				var h = (window.location.hash || '').replace(/^#/, '');
				return slugs.indexOf(h) >= 0 ? h : slugs[0];
			}

			activate(fromHash());

			$tabs.on('click', function (e) {
				e.preventDefault();
				var slug = $(this).data('tab');
				if (history.replaceState) {
					history.replaceState(null, '', '#' + slug);
				} else {
					window.location.hash = slug;
				}
				activate(slug);
				// Each tab should open at its top, not inherit the previous
				// tab's scroll position (panels share one scroll container).
				window.scrollTo({ top: 0 });
			});

			window.addEventListener('hashchange', function () { activate(fromHash()); });

			// Capture-phase 'invalid' listener: switch to the tab holding the
			// offending field before the browser scrolls to it.
			document.addEventListener('invalid', function (e) {
				var el = e.target;
				if (!el || !el.closest) return;
				var panel = el.closest('.es-tab-panel');
				if (!panel) return;
				activate(panel.getAttribute('data-panel'));
			}, true);
		})();

		// Initialize chip-style tag inputs.
		document.querySelectorAll('.es-tag-input').forEach(initTagInput);

		// Initialize field mapper(s).
		document.querySelectorAll('.es-mapper').forEach(initMapper);

		// Dirty-state tracker for the form editor (highlights both Save buttons).
		initDirtyTracker();

		// Click-to-copy on any element marked .es-copy / [data-es-copy].
		initCopyTargets();

		// Success notices float in as auto-dismissing toasts (like "Asset saved").
		initAutoCloseNotices();

		// Forms-list "select all" checkbox in the thead toggles every
		// per-form checkbox in the table body.
		var selectAll = document.getElementById('es-cb-select-all-1');
		if (selectAll) {
			selectAll.addEventListener('change', function () {
				document.querySelectorAll('input[name="form_ids[]"]').forEach(function (cb) {
					cb.checked = selectAll.checked;
				});
			});
		}

		// Form edit — every auto-injected shell panel: only visible when its
		// checkbox is ticked, and the unselected trigger rows are dimmed so
		// you can see at a glance which one will fire.
		initShellPanels();

		// Reveal the shared settings container when an auto shell is selected.
		initShellSettingsContainer();

		// Draw the connector from the selected shell card to its rules below.
		initShellConnector();

		// Ribbon and slide-in are fully exclusive with all other shells —
		// checking one auto-unchecks the others. Server-side enforces too.
		initShellExclusivity();

		// Per-shell "after close" config is meaningless when there's no close
		// button — hide it whenever the close-button checkbox is unticked.
		initCloseConfigVisibility();

		// Keep form-level fields shown in multiple panels in sync (close style,
		// popup max width).
		initFormLevelSync();

		// Form edit — Display rules: when "All front-end pages" is picked, dim the
		// show-filters grid so it's clear the per-rule pickers are ignored.
		initDisplayRulesPanel();

		// Display rules — post / page chip pickers. Live title-search via REST,
		// chips show currently selected items, hidden inputs carry the IDs.
		initContentPickers();
	});

	function initContentPickers() {
		document.querySelectorAll('.es-content-picker').forEach(initContentPicker);
	}

	function initContentPicker(root) {
		if (root._osCpHooked) return;
		root._osCpHooked = true;

		var type        = root.getAttribute('data-content-type');
		var inputName   = root.getAttribute('data-name');
		var chipsBox    = root.querySelector('.es-content-chips');
		var search      = root.querySelector('.es-content-search');
		var suggestions = root.querySelector('.es-content-suggestions');
		if (!type || !inputName || !chipsBox || !search || !suggestions) return;

		var cfg       = window.EmblemStudioAdmin || {};
		var restUrl   = cfg.restUrl   || '';
		var restNonce = cfg.restNonce || '';

		var debounceTimer = null;
		var activeFetch   = 0;

		// Wire any chips rendered server-side.
		chipsBox.querySelectorAll('.es-content-chip-remove').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var chip = btn.closest('.es-content-chip');
				if (chip) chip.remove();
			});
		});

		function hideSuggestions() {
			suggestions.hidden = true;
			suggestions.innerHTML = '';
		}

		function isChipped(id) {
			return !!chipsBox.querySelector('.es-content-chip[data-id="' + id + '"]');
		}

		function addChip(id, title) {
			if (isChipped(id)) return;
			var chip = document.createElement('span');
			chip.className = 'es-content-chip';
			chip.dataset.id = id;

			var label = document.createElement('span');
			label.className = 'es-content-chip-label';
			label.textContent = title;
			chip.appendChild(label);

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'es-content-chip-remove';
			btn.setAttribute('aria-label', 'Remove');
			btn.innerHTML = '&times;';
			btn.addEventListener('click', function () { chip.remove(); });
			chip.appendChild(btn);

			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = inputName + '[]';
			hidden.value = id;
			chip.appendChild(hidden);

			chipsBox.appendChild(chip);
		}

		function renderSuggestions(items) {
			suggestions.innerHTML = '';
			if (!items || !items.length) {
				var li = document.createElement('li');
				li.className = 'es-content-suggestion is-empty';
				li.textContent = 'No matches.';
				suggestions.appendChild(li);
			} else {
				items.forEach(function (item) {
					if (isChipped(item.id)) return; // hide already-selected
					var li = document.createElement('li');
					li.className = 'es-content-suggestion';
					li.dataset.id = item.id;
					li.textContent = item.title || '(no title)';
					li.addEventListener('mousedown', function (e) {
						e.preventDefault(); // keep focus in input
						addChip(item.id, item.title || '(no title)');
						search.value = '';
						hideSuggestions();
						search.focus();
					});
					suggestions.appendChild(li);
				});
				if (!suggestions.children.length) {
					var done = document.createElement('li');
					done.className = 'es-content-suggestion is-empty';
					done.textContent = 'All matches already selected.';
					suggestions.appendChild(done);
				}
			}
			suggestions.hidden = false;
		}

		function fetchSuggestions(q) {
			if (!restUrl) return;
			var token = ++activeFetch;
			var loadingLi = document.createElement('li');
			loadingLi.className = 'es-content-suggestion is-loading';
			loadingLi.textContent = 'Searching…';
			suggestions.innerHTML = '';
			suggestions.appendChild(loadingLi);
			suggestions.hidden = false;

			var url = restUrl + 'emblem-studio/v1/search-content?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q);
			fetch(url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': restNonce }
			})
				.then(function (r) { return r.ok ? r.json() : []; })
				.then(function (items) {
					if (token !== activeFetch) return; // stale
					renderSuggestions(items);
				})
				.catch(function () {
					if (token !== activeFetch) return;
					hideSuggestions();
				});
		}

		search.addEventListener('input', function () {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(function () {
				fetchSuggestions(search.value.trim());
			}, 200);
		});

		search.addEventListener('focus', function () {
			fetchSuggestions(search.value.trim()); // show recent posts on first focus
		});

		document.addEventListener('click', function (e) {
			if (!root.contains(e.target)) hideSuggestions();
		});
	}

	function initDisplayRulesPanel() {
		// One Display-rules block per auto shell (popup-auto / ribbon / slide-in),
		// each with its own `display_<key>_show_mode` radios + show-filters grid.
		// Scope to each shell's body so multiple shells don't cross-wire, and
		// match the real per-shell field name (ends in `_show_mode`).
		document.querySelectorAll('.es-dr-show-filters').forEach(function (showFilters) {
			var body = showFilters.closest('.es-display-rules-body');
			if (!body) return;
			var modeInputs = body.querySelectorAll('input[name$="_show_mode"]');
			if (!modeInputs.length) return;
			function sync() {
				var checked = body.querySelector('input[name$="_show_mode"]:checked');
				// "All front-end pages" → the per-rule pickers are ignored, so dim
				// them and make them non-clickable (CSS: opacity + pointer-events).
				showFilters.classList.toggle('is-disabled', !checked || checked.value === 'all');
			}
			modeInputs.forEach(function (r) { r.addEventListener('change', sync); });
			sync();
		});
	}

	function initShellPanels() {
		document.querySelectorAll('.es-shell-panel[data-shell]').forEach(function (panel) {
			var shell = panel.getAttribute('data-shell');
			if (!shell) return;
			var key      = shell.replace(/-/g, '_'); // popup-auto → popup_auto
			var checkbox = document.getElementById('es-form-type-' + shell);
			if (!checkbox) return;

			function syncVisibility() {
				panel.style.display = checkbox.checked ? '' : 'none';
			}
			function syncSelectedRow() {
				var checked  = panel.querySelector('input[name="' + key + '_trigger_type"]:checked');
				var selected = checked ? checked.value : '';
				panel.querySelectorAll('.es-trigger-row').forEach(function (row) {
					row.classList.toggle('is-disabled', row.getAttribute('data-trigger') !== selected);
				});
			}

			checkbox.addEventListener('change', syncVisibility);
			panel.querySelectorAll('input[name="' + key + '_trigger_type"]').forEach(function (r) {
				r.addEventListener('change', syncSelectedRow);
			});
			syncVisibility();
			syncSelectedRow();
		});
	}

	// Show the shared settings container only when one of the three settings-
	// bearing shells (auto popup / ribbon / slide-in) is selected. Each panel
	// inside still shows/hides by its own checkbox (initShellPanels); this just
	// hides the whole card when none of them is active.
	function initShellSettingsContainer() {
		var wrap = document.getElementById('es-shell-settings-wrap');
		if (!wrap) return;
		var boxes = ['popup-auto', 'ribbon', 'slide-in']
			.map(function (s) { return document.getElementById('es-form-type-' + s); })
			.filter(Boolean);
		if (!boxes.length) { wrap.style.display = 'none'; return; }
		function sync() {
			var any = boxes.some(function (b) { return b.checked; });
			wrap.style.display = any ? '' : 'none';
		}
		boxes.forEach(function (b) { b.addEventListener('change', sync); });
		sync();
	}

	// Draw a connector line from the selected shell card down into its
	// display-rules container, so the link between "what I picked above" and
	// "the rules below" is visually explicit. Positions are computed live
	// (relative to the Shells tab panel) and redrawn on selection / resize /
	// tab change. Pure decoration — pointer-events:none, aria-hidden.
	function initShellConnector() {
		var section = document.getElementById('es-tab-shells');
		var conn    = document.getElementById('es-shell-connector');
		var wrap    = document.getElementById('es-shell-settings-wrap');
		var grid    = section ? section.querySelector('.es-shells-list') : null;
		if (!section || !conn || !wrap || !grid) return;
		var path  = conn.querySelector('.es-connector-path');
		var dot   = conn.querySelector('.es-connector-dot');
		var arrow = conn.querySelector('.es-connector-arrow');
		if (!path || !dot || !arrow) return;

		function activePanel() {
			var panels = wrap.querySelectorAll('.es-shell-settings-panel');
			for (var i = 0; i < panels.length; i++) {
				if (panels[i].offsetParent !== null) return panels[i];
			}
			return null;
		}

		function hide() { conn.classList.remove('is-visible'); }

		function draw() {
			// Bail when the Shells tab is hidden or no auto shell is selected.
			if (section.offsetParent === null || wrap.style.display === 'none') { hide(); return; }
			var panel = activePanel();
			if (!panel) { hide(); return; }
			var card = section.querySelector('.es-shell-card[data-shell="' + panel.getAttribute('data-shell') + '"]');
			if (!card) { hide(); return; }

			conn.classList.add('is-visible'); // make the overlay measurable first
			// Adopt the selected shell's identity colour (the path/dot/arrow use
			// currentColor). Read it from the panel so CSS stays the single source.
			var shellColor = window.getComputedStyle(panel).getPropertyValue('--shell-color').trim();
			conn.style.color = shellColor || '';
			var base = conn.getBoundingClientRect();
			var g    = grid.getBoundingClientRect();
			var c    = card.getBoundingClientRect();
			var w    = wrap.getBoundingClientRect();

			var entryY = c.top - base.top + c.height / 2; // attach at the card's mid-height
			var topY   = w.top - base.top;                // container top edge
			if (topY <= entryY) { hide(); return; }

			// 2-col shells grid stacks to 1 column at 1100px (see admin.css).
			var stacked = window.matchMedia('(max-width: 1100px)').matches;
			var startX, channelX;
			if (stacked) {
				// Single column: run down the right side, around the stacked cards.
				startX   = c.right - base.left;
				channelX = Math.min(g.right - base.left + 14, base.width - 3);
			} else {
				// Two columns: attach to the card's INNER edge and run straight
				// down the empty gap between the columns — never over a card.
				var gridCenter = g.left - base.left + g.width / 2;
				var cardCenter = c.left - base.left + c.width / 2;
				startX   = cardCenter < gridCenter ? (c.right - base.left) : (c.left - base.left);
				channelX = gridCenter;
			}

			// Elbow: out the card's side, a rounded corner, then down to the box.
			var dir = channelX >= startX ? 1 : -1;
			var r   = Math.max(0, Math.min(8, Math.abs(channelX - startX), topY - entryY));
			path.setAttribute('d',
				'M ' + startX + ' ' + entryY +
				' L ' + (channelX - dir * r) + ' ' + entryY +
				' Q ' + channelX + ' ' + entryY + ' ' + channelX + ' ' + (entryY + r) +
				' L ' + channelX + ' ' + topY);
			dot.setAttribute('cx', startX);
			dot.setAttribute('cy', entryY);
			arrow.setAttribute('points',
				(channelX - 5) + ',' + (topY - 7) + ' ' +
				(channelX + 5) + ',' + (topY - 7) + ' ' +
				channelX + ',' + topY);
		}

		function redraw() { window.requestAnimationFrame(draw); }

		// Redraw on ANY option change inside the Shells tab — enabling a
		// different shell, toggling a trigger, picking an appearance option,
		// etc. can all move the active panel / change the container height,
		// and the arrow must follow. (Delegated, so it covers controls added
		// or revealed later too.)
		section.addEventListener('change', redraw);
		// Catch layout shifts that don't fire 'change' — expanding a
		// disclosure, content reflow, fonts settling — by observing the
		// settings container and the shells grid for size/position changes.
		if (window.ResizeObserver) {
			var ro = new ResizeObserver(redraw);
			ro.observe(wrap);
			ro.observe(grid);
		}
		window.addEventListener('resize', redraw);
		document.querySelectorAll('.es-tabs .es-tab').forEach(function (t) {
			t.addEventListener('click', function () { setTimeout(draw, 30); });
		});
		window.addEventListener('hashchange', function () { setTimeout(draw, 30); });
		setTimeout(draw, 60); // initial, after layout settles
	}

	function initCloseConfigVisibility() {
		document.querySelectorAll('.es-close-button-toggle[data-shell]').forEach(function (toggle) {
			var shell = toggle.getAttribute('data-shell');
			var cfg   = document.querySelector('.es-dismiss-config[data-shell="' + shell + '"]');
			var theme = document.querySelector('.es-close-theme-row[data-shell="' + shell + '"]');
			function sync() {
				var show = toggle.checked ? '' : 'none';
				if (cfg)   cfg.style.display = show;
				if (theme) theme.style.display = show;
			}
			toggle.addEventListener('change', sync);
			sync();
		});
	}

	// Form-level fields that appear in more than one shell panel (close-button
	// style, popup max width) are kept in lock-step so the form submits one
	// consistent value no matter which panel the user edited.
	function initFormLevelSync() {
		var groups = {};
		document.querySelectorAll('.es-formlevel-sync').forEach(function (el) {
			var name = el.getAttribute('name');
			if (!name) { return; }
			(groups[name] = groups[name] || []).push(el);
		});
		Object.keys(groups).forEach(function (name) {
			var els = groups[name];
			if (els.length < 2) { return; }
			els.forEach(function (el) {
				el.addEventListener('change', function () {
					els.forEach(function (other) {
						if (other === el) { return; }
						if (el.type === 'checkbox') { other.checked = el.checked; }
						else { other.value = el.value; }
					});
				});
			});
		});
	}

	function initShellExclusivity() {
		var EXCLUSIVE = ['ribbon', 'slide-in'];
		var all       = document.querySelectorAll('input[name="form_types[]"]');
		if (!all.length) return;

		function fireChange(cb) {
			// Dispatch a BUBBLING change event so both the direct panel-visibility
			// listeners AND the document-delegated handlers (the one that flips
			// data-enabled to hide a card's body / shortcode) pick up the uncheck.
			cb.dispatchEvent(new Event('change', { bubbles: true }));
		}

		all.forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (!cb.checked) return;
				var isExclusive = EXCLUSIVE.indexOf(cb.value) !== -1;
				all.forEach(function (other) {
					if (other === cb || !other.checked) return;
					if (isExclusive) {
						// Just checked ribbon/slide-in → uncheck every other shell.
						other.checked = false;
						fireChange(other);
					} else if (EXCLUSIVE.indexOf(other.value) !== -1) {
						// Just checked a non-exclusive shell → uncheck any exclusive ones.
						other.checked = false;
						fireChange(other);
					}
				});
			});
		});
	}

	function initAutoCloseNotices() {
		// Convert our success admin-notices (the old "Settings saved." /
		// "Form restored." green dismissible boxes) into the same floating
		// toast used for "Asset saved": over the content, no close button,
		// smooth in/out, auto-dismiss. Errors/warnings keep WP's persistent
		// notice so they don't vanish before they're read.
		document.querySelectorAll('.emblem-studio-wrap .notice.notice-success').forEach(function (notice) {
			var p = notice.querySelector('p');
			var text = ((p ? p.textContent : notice.textContent) || '').trim();
			if (!text) { return; }
			var toast = document.createElement('div');
			toast.className = 'es-toast';
			toast.setAttribute('role', 'status');
			toast.setAttribute('aria-live', 'polite');
			toast.textContent = text;
			document.body.appendChild(toast);
			if (notice.parentNode) { notice.parentNode.removeChild(notice); }
		});

		// Animate every toast (PHP-rendered or just-converted). Stack multiples
		// so they don't sit on top of each other.
		document.querySelectorAll('.es-toast').forEach(function (el, i) {
			el.style.top = (60 + i * 52) + 'px';
			// Slide+fade in on the next frame so the CSS transition fires.
			requestAnimationFrame(function () { el.classList.add('is-in'); });
			// Hold ~2s, then slide+fade out and remove from the DOM.
			setTimeout(function () {
				el.classList.remove('is-in');
				el.classList.add('is-out');
				setTimeout(function () { el.remove(); }, 280);
			}, 2000);
		});
	}

	function initCopyTargets() {
		document.querySelectorAll('.es-copy[data-es-copy]').forEach(function (el) {
			if (el.__osCopyBound) return;
			el.__osCopyBound = true;
			el.style.cursor = 'pointer';
			el.addEventListener('click', function (e) {
				e.preventDefault();
				var text = el.getAttribute('data-es-copy') || el.textContent || '';
				copyToClipboard(text).then(function () {
					flashCopied(el, 'Copied!');
				}).catch(function () {
					flashCopied(el, 'Copy failed', true);
				});
			});
		});
	}

	function copyToClipboard(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			try {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.left = '-9999px';
				document.body.appendChild(ta);
				ta.select();
				var ok = document.execCommand('copy');
				document.body.removeChild(ta);
				ok ? resolve() : reject(new Error('execCommand failed'));
			} catch (err) { reject(err); }
		});
	}

	function flashCopied(el, msg, isError) {
		var tip = document.createElement('span');
		tip.className = 'es-copy-tip' + (isError ? ' is-error' : '');
		tip.textContent = msg;
		var rect = el.getBoundingClientRect();
		tip.style.position = 'fixed';
		tip.style.left = (rect.left + rect.width / 2) + 'px';
		tip.style.top = (rect.top - 8) + 'px';
		tip.style.transform = 'translate(-50%, -100%)';
		document.body.appendChild(tip);
		// Force reflow so the transition kicks in.
		void tip.offsetWidth;
		tip.classList.add('is-visible');
		setTimeout(function () {
			tip.classList.remove('is-visible');
			setTimeout(function () { tip.remove(); }, 250);
		}, 1100);
	}

	function getCodeMirrorValue(textareaId) {
		var el = document.getElementById(textareaId);
		if (!el) return '';
		if (el.CodeMirror) return el.CodeMirror.getValue();
		// WP's wp.codeEditor mounts CodeMirror as a sibling .CodeMirror div.
		if (el.nextElementSibling && el.nextElementSibling.CodeMirror) {
			return el.nextElementSibling.CodeMirror.getValue();
		}
		return el.value || '';
	}

	function submitPreviewPost(previewUrl, target, extra) {
		if (!previewUrl) return;
		// When an A/B test is running, preview shows whichever variant
		// the user is currently editing — the active pane's editors,
		// not a stale "es-html" that doesn't exist. Single-version
		// forms fall through to the original ids.
		var slot = (window.EmblemStudioAB && EmblemStudioAB.testRunning) ? EmblemStudioAB.activeSlot : '';
		var htmlId = slot ? ('es-html-' + slot) : 'es-html';
		var cssId  = slot ? ('es-css-'  + slot) : 'es-css';
		var html = getCodeMirrorValue(htmlId);
		var css  = getCodeMirrorValue(cssId);
		var smodeEl = document.querySelector('input[name="success_mode"]:checked');
		var smode = smodeEl ? smodeEl.value : 'inline_state';
		var smsgEl = document.getElementById('es-success-message');
		var smsg = smsgEl ? smsgEl.value : '';

		var form = document.createElement('form');
		form.method = 'POST';
		form.action = previewUrl;
		form.target = target || '_blank';
		form.rel = 'noopener';
		form.style.display = 'none';

		function add(name, value) {
			var i = document.createElement('input');
			i.type = 'hidden';
			i.name = name;
			i.value = value;
			form.appendChild(i);
		}
		add('preview_html', html);
		add('preview_css', css);
		add('preview_success_mode', smode);
		add('preview_success_message', smsg);
		if (extra) { for (var key in extra) { if (extra.hasOwnProperty(key)) add(key, extra[key]); } }

		document.body.appendChild(form);
		form.submit();
		setTimeout(function () { try { document.body.removeChild(form); } catch (e) {} }, 200);
	}

	function initDirtyTracker() {
		var form = document.querySelector('form.es-form-editor');
		if (!form) return;
		var cmInstances = {};
		var snapshot = null;

		function currentState() {
			// Make sure CodeMirror values are flushed into their textareas so
			// FormData picks them up.
			Object.keys(cmInstances).forEach(function (id) {
				try { cmInstances[id].save(); } catch (e) {}
			});
			var fd = new FormData(form);
			var entries = [];
			fd.forEach(function (value, key) {
				entries.push(key + '=' + encodeURIComponent(typeof value === 'string' ? value : ''));
			});
			entries.sort();
			return entries.join('&');
		}

		function check() {
			if (snapshot === null) return;
			var dirty = currentState() !== snapshot;
			form.classList.toggle('is-dirty', dirty);
		}

		// Re-snapshot is only safe during the initial page-load phase —
		// before the user has touched anything. If we re-snapshot AFTER
		// edits exist, those edits get baked into the new baseline and
		// the dirty indicator falsely clears.
		var initialHookPhase = true;
		setTimeout(function () { initialHookPhase = false; }, 5000);

		function hookCm(id) {
			var el = document.getElementById(id);
			if (!el) return;
			var tries = 0;
			var t = setInterval(function () {
				var cm = el.CodeMirror || (el.nextElementSibling && el.nextElementSibling.CodeMirror);
				if (cm) {
					// Dedup: a CM instance only ever gets one change
					// listener wired here, even if hookCm is called
					// again later (e.g. on os:variant-changed for
					// lazy-initialized slot B editors).
					if (!cm._osDirtyHooked) {
						cm._osDirtyHooked = true;
						cmInstances[id] = cm;
						cm.on('change', check);
						if (initialHookPhase) {
							// Re-snapshot only during initial mount, when
							// the textarea value may have differed from
							// what CM actually loaded.
							snapshot = currentState();
						}
					}
					clearInterval(t);
				} else if (++tries > 30) {
					clearInterval(t);
				}
			}, 100);
		}

		form.addEventListener('input',  check);
		form.addEventListener('change', check);
		form.addEventListener('submit', function () { form.classList.remove('is-dirty'); });

		// Warn before leaving (reload / navigate away / close) with unsaved
		// edits. The submit handler above clears is-dirty first, so the form's
		// own save never triggers this prompt.
		window.addEventListener('beforeunload', function (e) {
			if (form.classList.contains('is-dirty')) {
				e.preventDefault();
				e.returnValue = '';
			}
		});

		// Initial snapshot — covers regular inputs immediately. CodeMirror hooks
		// re-snapshot once mounted, replacing the early snapshot.
		snapshot = currentState();
		// Register every editor that's actually on the page — single-version
		// has es-html / es-css; A/B running adds the per-slot pairs.
		['es-html', 'es-html-A', 'es-html-B', 'es-css', 'es-css-A', 'es-css-B'].forEach(hookCm);

		// When slot B's CodeMirrors are lazy-initialized after the
		// user first clicks the B tab, re-run the hooks so edits
		// inside B start triggering the dirty indicator. Dedup flag
		// makes this idempotent.
		document.addEventListener('os:variant-changed', function () {
			hookCm('es-html-B');
			hookCm('es-css-B');
		});
	}

	function initTagInput(container) {
		var allTags = [];
		var selected = [];
		try { allTags = JSON.parse(container.dataset.suggestions || '[]'); } catch (e) {}
		try { selected = JSON.parse(container.dataset.value || '[]'); } catch (e) {}

		var hidden = container.querySelector('input[type="hidden"]');
		var chipsEl = container.querySelector('.es-tag-chips');
		var input = container.querySelector('.es-tag-typer');
		var suggEl = container.querySelector('.es-tag-suggestions');
		var highlightIdx = -1;

		function esc(s) { return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		}); }

		function syncHidden() {
			hidden.value = selected.join(',');
			// Hidden inputs don't fire 'change' on their own; dispatch one so
			// the dirty tracker on the parent form sees the update.
			hidden.dispatchEvent(new Event('change', { bubbles: true }));
		}

		function getMatches() {
			var q = input.value.trim().toLowerCase();
			if (!q) return [];
			return allTags
				.filter(function (n) { return selected.indexOf(n) === -1 && n.toLowerCase().indexOf(q) !== -1; })
				.slice(0, 8);
		}

		function renderChips() {
			chipsEl.innerHTML = '';
			selected.forEach(function (name, i) {
				var chip = document.createElement('span');
				chip.className = 'es-tag-chip';
				chip.innerHTML = '<span>' + esc(name) + '</span>';
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'es-tag-chip-remove';
				btn.setAttribute('aria-label', 'Remove ' + name);
				btn.textContent = '×';
				btn.addEventListener('click', function () {
					selected.splice(i, 1);
					renderAll();
					input.focus();
				});
				chip.appendChild(btn);
				chipsEl.appendChild(chip);
			});
		}

		function renderSuggestions() {
			var matches = getMatches();
			var q = input.value.trim();
			var showCreate = q && matches.indexOf(q) === -1 && selected.indexOf(q) === -1;
			if (matches.length === 0 && !showCreate) {
				suggEl.style.display = 'none';
				return;
			}
			suggEl.style.display = '';
			suggEl.innerHTML = '';
			matches.forEach(function (name, i) {
				var item = document.createElement('div');
				item.className = 'es-tag-suggestion' + (i === highlightIdx ? ' is-active' : '');
				item.textContent = name;
				item.addEventListener('mousedown', function (e) {
					e.preventDefault();
					addTag(name);
				});
				suggEl.appendChild(item);
			});
			if (showCreate) {
				var newItem = document.createElement('div');
				newItem.className = 'es-tag-suggestion es-tag-suggestion-new' + (matches.length === highlightIdx ? ' is-active' : '');
				newItem.innerHTML = 'Create new tag: <strong>' + esc(q) + '</strong>';
				newItem.addEventListener('mousedown', function (e) {
					e.preventDefault();
					addTag(q);
				});
				suggEl.appendChild(newItem);
			}
		}

		function addTag(name) {
			name = String(name).trim();
			if (!name || selected.indexOf(name) !== -1) {
				input.value = '';
				renderAll();
				return;
			}
			selected.push(name);
			input.value = '';
			highlightIdx = -1;
			renderAll();
		}

		function renderAll() {
			renderChips();
			renderSuggestions();
			syncHidden();
		}

		input.addEventListener('input', function () { highlightIdx = -1; renderSuggestions(); });
		input.addEventListener('keydown', function (e) {
			var matches = getMatches();
			var q = input.value.trim();
			var total = matches.length + (q && matches.indexOf(q) === -1 && selected.indexOf(q) === -1 ? 1 : 0);

			if (e.key === 'ArrowDown') {
				e.preventDefault();
				highlightIdx = Math.min(highlightIdx + 1, total - 1);
				renderSuggestions();
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				highlightIdx = Math.max(highlightIdx - 1, -1);
				renderSuggestions();
			} else if (e.key === 'Enter') {
				if (q || highlightIdx >= 0) {
					e.preventDefault();
					if (highlightIdx >= 0 && highlightIdx < matches.length) {
						addTag(matches[highlightIdx]);
					} else if (q) {
						addTag(q);
					}
				}
			} else if (e.key === ',' || e.key === 'Tab') {
				if (q) {
					e.preventDefault();
					addTag(q);
				}
			} else if (e.key === 'Backspace' && input.value === '' && selected.length) {
				selected.pop();
				renderAll();
			} else if (e.key === 'Escape') {
				suggEl.style.display = 'none';
				highlightIdx = -1;
			}
		});
		input.addEventListener('focus', renderSuggestions);
		input.addEventListener('blur', function () {
			setTimeout(function () { suggEl.style.display = 'none'; highlightIdx = -1; renderSuggestions(); }, 150);
		});
		container.addEventListener('click', function (e) {
			if (e.target === container || e.target === chipsEl) input.focus();
		});

		renderAll();
	}
	// ---------- Field mapper ------------------------------------------------

	// Compatibility matrix: which form-input kinds are a "clean" match for each FK field kind.
	// Anything not listed is a soft warning (still allowed). Empty / unset → text-like fallback.
	var MAPPER_COMPATIBILITY = {
		text:           ['text', 'email', 'tel', 'url', 'hidden', 'select-single', 'radio', 'checkbox-single', 'textarea'],
		email:          ['email', 'text', 'hidden'],
		tel:            ['tel', 'text', 'number', 'hidden'],
		url:            ['url', 'text', 'hidden'],
		number:         ['number', 'text', 'hidden'],
		textarea:       ['textarea', 'text', 'hidden'],
		select:         ['select-single', 'radio', 'text', 'hidden'],
		radio:          ['select-single', 'radio', 'text', 'hidden'],
		'checkbox-multi': ['checkbox-multi', 'select-multi', 'hidden'],
		date:           ['date', 'text', 'hidden'],
		datetime:       ['datetime', 'text', 'hidden'],
		time:           ['time', 'text', 'hidden']
	};

	function kindBadge(kind) {
		var labels = {
			text: 'text', email: 'email', tel: 'tel', url: 'url', number: 'number',
			date: 'date', time: 'time', datetime: 'datetime', textarea: 'textarea',
			'select-single': 'select', 'select-multi': 'multi-select',
			radio: 'radio', 'checkbox-single': 'checkbox', 'checkbox-multi': 'multi-check',
			hidden: 'hidden', file: 'file', checkbox: 'checkbox'
		};
		return labels[kind] || kind;
	}

	function isCompatible(fkKind, formKind) {
		var ok = MAPPER_COMPATIBILITY[fkKind];
		if (!ok) return true; // unknown → permissive
		return ok.indexOf(formKind) !== -1;
	}

	// Parse an HTML string in the browser (no PHP) into the same shape as
	// the server-side extractor. Used to refresh the form-fields panel live
	// when the user edits the HTML in CodeMirror.
	function parseFormFields(html) {
		var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
		var seen = {};
		var reserved = ['esf_hp', 'esf_tag', 'esf_list', 'form_id', 'post_id', 'action', '_wpnonce', '_wp_http_referer'];

		function add(name, kind) {
			if (!name || reserved.indexOf(name) !== -1) return;
			if (!seen[name]) seen[name] = { name: name, kind: kind };
		}
		function inputKind(type, name) {
			var isMulti = /\[\]$/.test(name);
			switch (type) {
				case 'email': return 'email';
				case 'tel': return 'tel';
				case 'url': return 'url';
				case 'number': case 'range': return 'number';
				case 'date': return 'date';
				case 'time': return 'time';
				case 'datetime-local': return 'datetime';
				case 'hidden': return 'hidden';
				case 'file': return 'file';
				case 'checkbox': return isMulti ? 'checkbox-multi' : 'checkbox-single';
				case 'radio': return 'radio';
				default: return 'text';
			}
		}
		doc.querySelectorAll('input').forEach(function (el) {
			var name = el.getAttribute('name');
			if (!name) return;
			add(name, inputKind((el.getAttribute('type') || '').toLowerCase(), name));
		});
		doc.querySelectorAll('select').forEach(function (el) {
			var name = el.getAttribute('name');
			if (!name) return;
			add(name, el.hasAttribute('multiple') ? 'select-multi' : 'select-single');
		});
		doc.querySelectorAll('textarea').forEach(function (el) {
			var name = el.getAttribute('name');
			if (!name) return;
			add(name, 'textarea');
		});
		return Object.keys(seen).map(function (k) { return seen[k]; });
	}

	function initMapper(root) {
		var groups = [];
		var formFields = [];
		var mapping = {};
		try { groups = JSON.parse(root.dataset.fkGroups || '[]'); } catch (e) {}
		try { formFields = JSON.parse(root.dataset.initialFields || '[]'); } catch (e) {}
		try { mapping = JSON.parse(root.dataset.mapping || '{}'); } catch (e) {}

		var fkCol = root.querySelector('.es-mapper-fk');
		var formCol = root.querySelector('.es-mapper-form');
		var hidden = root.querySelector('input[type="hidden"][name="field_mapping_json"]');
		var selectedForm = null; // currently selected form-input name

		function esc(s) {
			return String(s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		function sync() {
			hidden.value = JSON.stringify(mapping);
			hidden.dispatchEvent(new Event('change', { bubbles: true }));
		}

		function formFieldExists(name) {
			for (var i = 0; i < formFields.length; i++) if (formFields[i].name === name) return formFields[i];
			return null;
		}

		// Server-side auto-detection rules (mirrors class-es-ajax.php):
		// when no explicit mapping is set, certain FK columns automatically
		// pick up form inputs by conventional name.
		function autoDetected(fkKey) {
			if (fkKey === 'col:email')      return formFieldExists('email')      ? { name: 'email' }      : null;
			if (fkKey === 'col:contact_no') return formFieldExists('phone')      ? { name: 'phone' }      : null;
			if (fkKey === 'col:f_name') {
				if (formFieldExists('first_name')) return { name: 'first_name' };
				if (formFieldExists('name'))       return { name: 'name', split: 'first' };
				return null;
			}
			if (fkKey === 'col:l_name') {
				if (formFieldExists('last_name')) return { name: 'last_name' };
				if (formFieldExists('name'))      return { name: 'name', split: 'last' };
				return null;
			}
			return null;
		}

		function render() {
			renderFk();
			renderForm();
			sync();
		}

		function renderFk() {
			fkCol.innerHTML = '<h3>' + esc('FunnelKit fields') + '</h3>';
			groups.forEach(function (g) {
				var section = document.createElement('div');
				section.className = 'es-mapper-group';
				section.innerHTML = '<div class="es-mapper-group-label">' + esc(g.label) + '</div>';
				g.fields.forEach(function (f) {
					var row = document.createElement('div');
					row.className = 'es-mapper-row';
					row.dataset.fkKey = f.key;
					row.dataset.fkKind = f.kind || 'text';

					var label = document.createElement('div');
					label.className = 'es-mapper-row-label';
					label.innerHTML = esc(f.label) +
						(f.required ? ' <span class="es-mapper-required">*</span>' : '') +
						' <span class="es-mapper-kind">' + esc(kindBadge(f.kind)) + '</span>';
					row.appendChild(label);

					var slot = document.createElement('div');
					slot.className = 'es-mapper-slot';
					var mappedName = mapping[f.key];
					if (mappedName) {
						var detected = formFieldExists(mappedName);
						var orphan = !detected;
						var compat = detected ? isCompatible(f.kind, detected.kind) : true;
						var pill = document.createElement('span');
						pill.className = 'es-mapper-pill' + (orphan ? ' is-orphan' : '') + (!compat && !orphan ? ' is-warn' : '');
						pill.innerHTML = '<span>' + esc(mappedName) + '</span>';
						pill.title = orphan
							? 'This form input no longer exists in your HTML.'
							: (!compat ? 'Type mismatch — saving will still work, but value may not be stored correctly.' : '');
						var remove = document.createElement('button');
						remove.type = 'button';
						remove.className = 'es-mapper-pill-remove';
						remove.textContent = '×';
						remove.addEventListener('click', function (e) {
							e.stopPropagation();
							delete mapping[f.key];
							render();
						});
						pill.appendChild(remove);
						slot.appendChild(pill);
					} else {
						var auto = autoDetected(f.key);
						if (auto) {
							var label = auto.name;
							if (auto.split === 'first') label = auto.name + ' (first word)';
							else if (auto.split === 'last') label = auto.name + ' (rest)';
							var autoPill = document.createElement('span');
							autoPill.className = 'es-mapper-pill is-auto';
							autoPill.innerHTML = '<span>' + esc(label) + '</span><span class="es-mapper-auto-tag">auto</span>';
							autoPill.title = 'Auto-detected from input name — pushed to FunnelKit on submit. To override, pick a form input on the right then click this slot.';
							slot.appendChild(autoPill);
						} else {
							slot.classList.add('is-empty');
							slot.textContent = selectedForm ? 'Click to bind "' + selectedForm + '"' : 'Empty';
						}
					}
					slot.addEventListener('click', function () {
						if (!selectedForm) return;
						mapping[f.key] = selectedForm;
						selectedForm = null;
						render();
					});
					row.appendChild(slot);
					section.appendChild(row);
				});
				fkCol.appendChild(section);
			});
		}

		function renderForm() {
			formCol.innerHTML = '<h3>' + esc('Form inputs') + ' <small>(' + formFields.length + ')</small></h3>';
			if (formFields.length === 0) {
				var empty = document.createElement('p');
				empty.className = 'description';
				empty.textContent = 'No named inputs detected in your form HTML yet.';
				formCol.appendChild(empty);
				return;
			}
			formFields.forEach(function (f) {
				var pill = document.createElement('button');
				pill.type = 'button';
				pill.className = 'es-mapper-form-pill' + (selectedForm === f.name ? ' is-selected' : '');
				pill.innerHTML = '<span class="es-mapper-form-name">' + esc(f.name) + '</span>' +
					'<span class="es-mapper-kind">' + esc(kindBadge(f.kind)) + '</span>';
				pill.addEventListener('click', function () {
					selectedForm = (selectedForm === f.name) ? null : f.name;
					render();
				});
				formCol.appendChild(pill);
			});
		}

		// Live refresh from CodeMirror — re-parse the HTML editor's contents
		// and update the right column whenever the user changes the markup.
		function watchHtmlEditor() {
			var sourceSel = root.dataset.htmlSource;
			if (!sourceSel) return;
			var textarea = document.querySelector(sourceSel);
			if (!textarea) return;

			var debounce = null;
			function refresh() {
				clearTimeout(debounce);
				debounce = setTimeout(function () {
					var html = '';
					if (textarea.CodeMirror) {
						html = textarea.CodeMirror.getValue();
					} else if (textarea.nextElementSibling && textarea.nextElementSibling.CodeMirror) {
						html = textarea.nextElementSibling.CodeMirror.getValue();
					} else {
						html = textarea.value;
					}
					formFields = parseFormFields(html);
					render();
				}, 250);
			}

			// CodeMirror hook
			function tryHook() {
				var cm = textarea.nextElementSibling && textarea.nextElementSibling.CodeMirror;
				if (cm) {
					cm.on('change', refresh);
					return true;
				}
				return false;
			}
			if (!tryHook()) {
				// CodeMirror may not be initialized yet; retry briefly.
				var tries = 0;
				var t = setInterval(function () {
					if (tryHook() || ++tries > 20) clearInterval(t);
				}, 100);
			}
			// Plain textarea fallback (in case CodeMirror is disabled)
			textarea.addEventListener('input', refresh);
		}

		render();
		watchHtmlEditor();
	}

	// ============================================================
	// Forms list — drag-and-drop reorder
	// ============================================================
	// Only enabled on the "All" filter view. Each form is a <tbody>
	// with data-form-id; jQuery UI Sortable rearranges them; on drop
	// we POST the new id order to wp-admin/admin-ajax.php?action=
	// es_reorder_forms.
	$(function () {
		const table = document.querySelector('.es-forms-reorderable');
		if (!table || typeof $.fn.sortable !== 'function') return;
		const nonce = table.getAttribute('data-reorder-nonce') || '';
		const ajaxUrl = (window.ajaxurl || '/wp-admin/admin-ajax.php');

		$(table).sortable({
			items: 'tbody.es-form-group[data-form-id]',
			handle: '.es-form-drag-handle',
			placeholder: 'es-form-group es-form-group-placeholder',
			helper: function (e, item) {
				// Preserve cell widths during drag so the placeholder doesn't
				// collapse to content-width on detach.
				item.children().each(function () {
					$(this).width($(this).width());
				});
				return item;
			},
			forcePlaceholderSize: true,
			axis: 'y',
			cursor: 'grabbing',
			tolerance: 'pointer',
			update: function () {
				const ids = $(table).find('tbody.es-form-group[data-form-id]')
					.map(function () { return parseInt(this.getAttribute('data-form-id'), 10); })
					.get()
					.filter(function (n) { return n > 0; });
				if (!ids.length) return;
				const body = new URLSearchParams();
				body.append('action', 'es_reorder_forms');
				body.append('nonce', nonce);
				ids.forEach(function (id) { body.append('ids[]', String(id)); });
				fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.catch(function () { /* swallow — order will re-sync on next page load */ });
			}
		});
	});

	// ============================================================
	// Connect tab — live-toggle the disabled state on destination cards
	// when the user flips the "active" toggle in the card header
	// ============================================================
	$(function () {
		document.querySelectorAll('.es-destination-toggle').forEach(function (toggle) {
			const card = toggle.closest('.es-connect-card');
			if (!card) return;
			toggle.addEventListener('change', function () {
				card.classList.toggle('is-destination-off', !toggle.checked);
			});
		});
	});

	// ============================================================
	// Webhooks repeater on the Connect tab — add / remove / send-test
	// ============================================================
	$(function () {
		const list = document.querySelector('.es-webhook-list');
		if (!list) return;
		const template = document.querySelector('.es-webhook-template');
		const addBtn = document.querySelector('.es-webhook-add');
		const testNonce = list.getAttribute('data-test-nonce') || '';
		const ajaxUrl = (window.ajaxurl || '/wp-admin/admin-ajax.php');

		function nextIndex() {
			// Find max numeric index currently used in name="webhooks[X][..]"
			let max = -1;
			list.querySelectorAll('input[name^="webhooks["]').forEach(function (input) {
				const m = input.getAttribute('name').match(/^webhooks\[(\d+)\]/);
				if (m) max = Math.max(max, parseInt(m[1], 10));
			});
			return max + 1;
		}

		// Add — clone template, swap __INDEX__ for the next free integer
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				if (!template) return;
				const idx = nextIndex();
				const html = template.innerHTML.replace(/__INDEX__/g, String(idx));
				const wrap = document.createElement('div');
				wrap.innerHTML = html.trim();
				const row = wrap.firstElementChild;
				if (row) {
					list.appendChild(row);
					const firstUrl = row.querySelector('input[type="url"]');
					if (firstUrl) firstUrl.focus();
				}
			});
		}

		// Remove — delegate clicks on .es-webhook-remove
		list.addEventListener('click', function (e) {
			const btn = e.target.closest('.es-webhook-remove');
			if (!btn) return;
			const row = btn.closest('.es-webhook-row');
			if (row && confirm('Remove this webhook?')) {
				row.remove();
			}
		});

		// Send test — POST current row's URL/secret/headers to admin-ajax,
		// show the result inline in the row's .es-webhook-test-result span
		list.addEventListener('click', function (e) {
			const btn = e.target.closest('.es-webhook-test');
			if (!btn) return;
			const row = btn.closest('.es-webhook-row');
			if (!row) return;
			const urlIn = row.querySelector('input[type="url"]');
			const secretIn = row.querySelector('input[name*="[secret]"]');
			const headersIn = row.querySelector('textarea[name*="[headers]"]');
			const resultEl = row.querySelector('.es-webhook-test-result');
			const url = urlIn ? urlIn.value.trim() : '';
			if (!url) {
				if (resultEl) {
					resultEl.textContent = 'Enter a URL first.';
					resultEl.className = 'es-webhook-test-result is-error';
				}
				return;
			}
			btn.disabled = true;
			if (resultEl) {
				resultEl.textContent = 'Sending…';
				resultEl.className = 'es-webhook-test-result is-pending';
			}
			const body = new URLSearchParams();
			body.append('action', 'es_test_webhook');
			body.append('nonce', testNonce);
			body.append('url', url);
			body.append('secret', secretIn ? secretIn.value : '');
			body.append('headers', headersIn ? headersIn.value : '');
			fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					btn.disabled = false;
					if (!resultEl) return;
					if (!j || !j.success) {
						resultEl.textContent = (j && j.data && j.data.message) || 'Test failed.';
						resultEl.className = 'es-webhook-test-result is-error';
						return;
					}
					const d = j.data || {};
					if (d.ok) {
						resultEl.textContent = '✓ ' + d.status + ' OK';
						resultEl.className = 'es-webhook-test-result is-success';
					} else if (d.error) {
						resultEl.textContent = '✗ ' + d.error;
						resultEl.className = 'es-webhook-test-result is-error';
					} else {
						resultEl.textContent = '⚠ ' + d.status + (d.body ? ' — ' + d.body.slice(0, 80) : '');
						resultEl.className = 'es-webhook-test-result is-warning';
					}
				})
				.catch(function () {
					btn.disabled = false;
					if (resultEl) {
						resultEl.textContent = 'Network error.';
						resultEl.className = 'es-webhook-test-result is-error';
					}
				});
		});
	});

	// ============================================================
	// Form picker (Add New) — filter chips + lazy thumbnail iframes
	// ============================================================
	// Filter chips toggle which cards are visible by data-shell. The
	// thumbnail iframes lazy-load via IntersectionObserver — each card's
	// real src lives in data-thumb-src and only gets swapped in when the
	// card scrolls into view. Keeps the initial render fast even when we
	// eventually have 60+ templates.
	$(function () {
		const picker = document.querySelector('.es-picker-collections');
		const filters = document.querySelectorAll('.es-picker-filter');
		if (!picker && !filters.length) return;

		// Lazy-load thumbnails when they hit the viewport.
		const iframes = document.querySelectorAll('.es-picker-thumb-iframe[data-thumb-src]');
		if (iframes.length && 'IntersectionObserver' in window) {
			const io = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) return;
					const f = entry.target;
					const src = f.getAttribute('data-thumb-src');
					if (src && f.src !== src) {
						f.src = src;
						f.removeAttribute('data-thumb-src');
					}
					io.unobserve(f);
				});
			}, { rootMargin: '300px 0px' });
			iframes.forEach(function (f) { io.observe(f); });
		} else {
			// Fallback: no IntersectionObserver, eager-load all.
			iframes.forEach(function (f) {
				const src = f.getAttribute('data-thumb-src');
				if (src) { f.src = src; f.removeAttribute('data-thumb-src'); }
			});
		}

		// Filter chips: show/hide cards by data-shell. "all" shows everything.
		// Collections with zero visible cards collapse via .is-empty class.
		if (filters.length) {
			filters.forEach(function (chip) {
				chip.addEventListener('click', function () {
					const shell = chip.getAttribute('data-shell');
					filters.forEach(function (c) { c.classList.remove('is-active'); });
					chip.classList.add('is-active');
					document.querySelectorAll('.es-picker-card').forEach(function (card) {
						const matches = (shell === 'all') || (card.getAttribute('data-shell') === shell);
						card.classList.toggle('is-filtered-out', !matches);
					});
					// Hide collections whose visible-card count is now zero.
					document.querySelectorAll('.es-picker-collection').forEach(function (col) {
						const visible = col.querySelectorAll('.es-picker-card:not(.is-filtered-out)').length;
						col.classList.toggle('is-empty', visible === 0);
					});
				});
			});
		}
	});

})(jQuery);

/* ──────────────────────────────────────────────────────────────────────
 * Concurrent-edit lock (asset editor). Identity is a per-TAB session token
 * (sessionStorage), so even a second tab of the same admin is a distinct
 * session: opening a locked asset offers Take over / View only / Cancel,
 * the editor heartbeats to hold the lock, and a booted tab is locked out of
 * saving with a named alert + "Take editing back".
 * ────────────────────────────────────────────────────────────────────── */
(function () {
	var L = window.EmblemStudioLock;
	if (!L || !L.formId) return;

	// Per-tab token, stable across reloads of THIS tab (so a reload re-claims
	// its own lock instead of prompting to take over from itself).
	var KEY = 'esLockToken:' + L.formId;
	var myToken = '';
	try { myToken = sessionStorage.getItem(KEY) || ''; } catch (e) {}
	if (!myToken) {
		myToken = (window.crypto && crypto.randomUUID)
			? crypto.randomUUID()
			: (Math.random().toString(36).slice(2) + Date.now().toString(36));
		try { sessionStorage.setItem(KEY, myToken); } catch (e) {}
	}
	var tokenField = document.getElementById('es-lock-token');
	if (tokenField) tokenField.value = myToken;

	// Instant same-browser hand-off: other tabs/windows of this browser hear a
	// take-over the moment it happens, so the booted tab pops immediately rather
	// than on its next heartbeat. (Cross-machine still relies on the heartbeat.)
	var bc = ('BroadcastChannel' in window) ? new BroadcastChannel('es-lock-' + L.formId) : null;
	if (bc) bc.onmessage = function (ev) {
		var d = (ev && ev.data) || {};
		if (d.type === 'taken' && held && d.token !== myToken) bootedBy('', true);
	};

	var held = false;       // true once/while this session owns the lock
	var submitting = false; // skip release during our own save round-trip
	var beatTimer = null;

	function post(action, cb) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('form_id', L.formId);
		body.append('nonce', L.nonce);
		body.append('token', myToken);
		fetch(L.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (cb) cb(j); })
			.catch(function () { if (cb) cb(null); });
	}

	function setSaveDisabled(on) {
		var btns = document.querySelectorAll('.es-form-editor button[type="submit"], .es-form-editor input[type="submit"]');
		for (var i = 0; i < btns.length; i++) { btns[i].disabled = on; }
	}
	function startHeartbeat() {
		if (beatTimer) clearInterval(beatTimer);
		// Short interval so a take-over / hand-off is detected almost immediately
		// (the booted tab pops its modal within a few seconds, reliably).
		beatTimer = setInterval(beat, 3000);
	}
	function stopHeartbeat() {
		if (beatTimer) { clearInterval(beatTimer); beatTimer = null; }
	}
	function hideBanner() {
		var b = document.getElementById('es-lock-banner');
		if (b) b.hidden = true;
	}
	function claim() {
		held = true;
		setSaveDisabled(false);
		hideBanner();
		var bm = document.getElementById('es-lock-boot-modal');
		if (bm) bm.hidden = true;
		// Tell other tabs of THIS browser instantly (the cross-machine case still
		// relies on the heartbeat). Same browser = same WP user, so it's "you".
		if (bc) { try { bc.postMessage({ type: 'taken', token: myToken }); } catch (e) {} }
		startHeartbeat();
	}
	function beat() {
		post('es_lock_beat', function (j) {
			if (!j || !j.success) return; // transient — retry next tick
			if (j.data && j.data.held === false) bootedBy(j.data.by, j.data.by_is_me);
		});
	}
	function bootedBy(name, isMe) {
		held = false;
		stopHeartbeat();
		setSaveDisabled(true);
		var msg = isMe
			? 'You took over editing this asset in another tab or window'
			: (name || 'Someone') + ' has taken over editing this asset';
		var banner = document.getElementById('es-lock-banner');
		if (banner) {
			banner.querySelector('.es-lock-banner-text').textContent = msg + ' — editing is paused here, your changes can no longer be saved.';
			var tb = banner.querySelector('.es-lock-takeback');
			if (tb) tb.textContent = 'Take editing back';
			banner.hidden = false;
		}
		// On-brand popup (matches the SaaS) instead of a native alert — grabs
		// attention when you return to a backgrounded tab.
		var bootModal = document.getElementById('es-lock-boot-modal');
		if (bootModal) {
			var bt = bootModal.querySelector('.es-lock-boot-text');
			if (bt) bt.textContent = msg + ', so your changes can no longer be saved. Copy anything you need, then take it back or continue in read-only.';
			bootModal.hidden = false;
		}
	}
	// View-only: chose not to take over. We hold nothing; saving stays disabled
	// and a banner offers "Take over" (same handler as take-back).
	function enterViewOnly(name, isMe) {
		held = false;
		stopHeartbeat();
		setSaveDisabled(true);
		var banner = document.getElementById('es-lock-banner');
		if (banner) {
			var who = isMe ? 'You have' : (name || 'Someone') + ' has';
			banner.querySelector('.es-lock-banner-text').textContent = 'View only — ' + who + ' this asset open for editing, so changes here cannot be saved.';
			var tb = banner.querySelector('.es-lock-takeback');
			if (tb) tb.textContent = 'Take over';
			banner.hidden = false;
		}
	}

	var reloadBtn = document.querySelector('.es-lock-reload');
	if (reloadBtn) reloadBtn.addEventListener('click', function () { location.reload(); });
	var takeBackBtn = document.querySelector('.es-lock-takeback');
	if (takeBackBtn) takeBackBtn.addEventListener('click', function () {
		post('es_lock_takeover', function () { claim(); });
	});
	function hideBootModal() {
		var m = document.getElementById('es-lock-boot-modal');
		if (m) m.hidden = true;
	}
	var bootTakeBack = document.querySelector('.es-lock-boot-takeback');
	if (bootTakeBack) bootTakeBack.addEventListener('click', function () {
		hideBootModal();
		post('es_lock_takeover', function () { claim(); });
	});
	var bootDismiss = document.querySelector('.es-lock-boot-dismiss');
	if (bootDismiss) bootDismiss.addEventListener('click', hideBootModal); // keep read-only; banner stays

	var formEl = document.querySelector('.es-form-editor');
	if (formEl) formEl.addEventListener('submit', function () { submitting = true; });

	var lockedByOther = !!L.holderToken && L.holderToken !== myToken;
	if (lockedByOther) {
		// Another session holds it — offer Take over / View only / Cancel.
		setSaveDisabled(true);
		var modal = document.getElementById('es-lock-modal');
		if (modal) {
			var who = modal.querySelector('.es-lock-who');
			var rest = modal.querySelector('.es-lock-text-rest');
			if (who) who.textContent = L.holderIsMe ? 'You' : (L.holderName || 'Someone');
			if (L.holderIsMe && rest) {
				rest.textContent = 'have this asset open in another tab or window. If you take over here, that session is locked out and loses unsaved changes.';
			}
			modal.hidden = false;
			modal.querySelector('.es-lock-cancel').addEventListener('click', function () { window.location.href = L.formsUrl; });
			var viewBtn = modal.querySelector('.es-lock-viewonly');
			if (viewBtn) viewBtn.addEventListener('click', function () {
				modal.hidden = true;
				enterViewOnly(L.holderIsMe ? 'You' : L.holderName, L.holderIsMe);
			});
			modal.querySelector('.es-lock-takeover').addEventListener('click', function () {
				post('es_lock_takeover', function () { modal.hidden = true; claim(); });
			});
		}
	} else {
		// Free or mine — claim it. beat() acquires if free/mine, or reports a
		// race where someone grabbed it between page render and now.
		post('es_lock_beat', function (j) {
			if (j && j.success && j.data && j.data.held === false) bootedBy(j.data.by, j.data.by_is_me);
			else claim();
		});
	}

	// Release on leave so others don't wait out the stale timeout — but not
	// during our own save submit (the reload re-claims it).
	window.addEventListener('beforeunload', function () {
		if (!held || submitting) return;
		try {
			var body = new URLSearchParams();
			body.append('action', 'es_lock_release');
			body.append('form_id', L.formId);
			body.append('nonce', L.nonce);
			body.append('token', myToken);
			navigator.sendBeacon(L.ajaxUrl, body);
		} catch (e) {}
	});
})();

/* ============================================================
   Cursor-following instant tooltip for [data-es-tip].
   A single fixed-position bubble at <body> level, so it escapes any
   overflow:hidden/auto ancestor (e.g. scrollable stats tables) and never
   clips. Shows immediately on hover, to the right of the cursor, and tracks it.
   ============================================================ */
(function () {
	if ( window.__esTipReady ) { return; }
	window.__esTipReady = true;
	var bubble = null, active = null;
	function ensure() {
		if ( bubble ) { return bubble; }
		bubble = document.createElement('div');
		bubble.setAttribute('role', 'tooltip');
		var s = bubble.style;
		s.position = 'fixed'; s.zIndex = '99999'; s.maxWidth = '240px'; s.padding = '6px 9px';
		s.borderRadius = '8px'; s.background = '#22273f'; s.color = '#fff'; s.fontSize = '11px';
		s.fontWeight = '500'; s.lineHeight = '1.35'; s.boxShadow = '0 6px 16px rgba(15,23,42,0.22)';
		s.pointerEvents = 'none'; s.opacity = '0'; s.transition = 'opacity 80ms ease'; s.left = '0'; s.top = '0';
		document.body.appendChild(bubble);
		return bubble;
	}
	function place(x, y) {
		var b = ensure(), pad = 14, r = b.getBoundingClientRect();
		var left = x + pad, top = y + pad;
		if ( left + r.width > window.innerWidth - 8 ) { left = x - pad - r.width; }
		if ( top + r.height > window.innerHeight - 8 ) { top = y - pad - r.height; }
		b.style.left = Math.max(8, left) + 'px';
		b.style.top = Math.max(8, top) + 'px';
	}
	document.addEventListener('mouseover', function (e) {
		var el = e.target && e.target.closest ? e.target.closest('[data-es-tip]') : null;
		if ( ! el ) { return; }
		active = el;
		var b = ensure();
		b.textContent = el.getAttribute('data-es-tip') || '';
		b.style.opacity = '1';
		place(e.clientX, e.clientY);
	});
	document.addEventListener('mousemove', function (e) {
		if ( active && bubble && bubble.style.opacity === '1' ) { place(e.clientX, e.clientY); }
	});
	document.addEventListener('mouseout', function (e) {
		var el = e.target && e.target.closest ? e.target.closest('[data-es-tip]') : null;
		if ( el && el === active && bubble ) { active = null; bubble.style.opacity = '0'; }
	});
	window.addEventListener('scroll', function () { if ( bubble ) { bubble.style.opacity = '0'; } active = null; }, true);
})();
