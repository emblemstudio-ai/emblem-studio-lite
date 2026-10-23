(function ($) {
	function relaxedSettings(base) {
		if (!base || !base.codemirror) return base;
		base.codemirror.lint = false;
		if (Array.isArray(base.codemirror.gutters)) {
			base.codemirror.gutters = base.codemirror.gutters.filter(g => g !== 'CodeMirror-lint-markers');
		}
		return base;
	}

	window.EmblemStudioChecks = (function () {
		var cfg = window.EmblemStudioAdmin || {};
		var labels = cfg.checkLabels || { error: 'Will break', warning: 'Worth fixing', title: 'Checks' };

		function request(html, css, cb) {
			if (!cfg.ajaxUrl || !cfg.checkNonce) { cb(null); return; }
			var body = new URLSearchParams();
			body.set('action', 'es_check_asset');
			body.set('nonce', cfg.checkNonce);
			body.set('html', html || '');
			body.set('css', css || '');
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					cb(json && json.success && json.data && Array.isArray(json.data.checks) ? json.data.checks : null);
				})
				.catch(function () { cb(null); });
		}

		function count(items, level) {
			var n = 0;
			for (var i = 0; i < (items || []).length; i++) if (items[i].level === level) n++;
			return n;
		}

		function renderGroups(container, items) {
			container.textContent = '';
			['error', 'warning'].forEach(function (level) {
				var inLevel = (items || []).filter(function (it) { return it.level === level; });
				if (!inLevel.length) return;
				var group = document.createElement('div');
				group.className = 'es-checks-group is-' + level;
				var title = document.createElement('p');
				title.className = 'es-checks-group-title';
				title.textContent = labels[level];
				group.appendChild(title);
				var ul = document.createElement('ul');
				ul.className = 'es-checks-list';
				inLevel.forEach(function (it) {
					var li = document.createElement('li');
					li.className = 'es-checks-item';
					li.setAttribute('data-check-id', it.id || '');
					var msg = document.createElement('span');
					msg.className = 'es-checks-message';
					msg.textContent = it.message || '';
					var fix = document.createElement('span');
					fix.className = 'es-checks-fix';
					fix.textContent = it.fix || '';
					li.appendChild(msg);
					li.appendChild(fix);
					ul.appendChild(li);
				});
				group.appendChild(ul);
				container.appendChild(group);
			});
		}

		return { request: request, renderGroups: renderGroups, count: count, labels: labels };
	})();

	$(function () {
		function initEditorIfExists(id, settings) {
			var el = document.getElementById(id);
			if (!settings || !el || el.hidden) return;
			wp.codeEditor.initialize($('#' + id), relaxedSettings(settings));
		}
		(function mountAllEditors() {
			if (!window.EmblemStudioAdmin || !wp.codeEditor) return;
			initEditorIfExists('es-html',   EmblemStudioAdmin.htmlEditor);
			initEditorIfExists('es-css',    EmblemStudioAdmin.cssEditor);
			initEditorIfExists('es-html-A', EmblemStudioAdmin.htmlEditor);
			initEditorIfExists('es-css-A',  EmblemStudioAdmin.cssEditor);
		})();

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
			}, true);
		})();

		function syncSuccessFields() {
			var mode = $('input[data-es-success-mode]:checked').val();
			$('.es-success-field').removeClass('is-visible');
			if (mode === 'redirect') {
				$('.es-success-field-redirect').addClass('is-visible');
			}
		}
		$(document).on('change', 'input[data-es-success-mode]', syncSuccessFields);
		syncSuccessFields();

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
				hidden.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});

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
				a.dispatchEvent(new Event('change', { bubbles: true }));
			});
		})();

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

		(function syncStickyStripOffset() {
			var header = document.querySelector('.es-form-header');
			var strip  = document.querySelector('.es-ab-strip--running');
			if (!header || !strip) return;
			function update() {
				var wpBar = document.getElementById('wpadminbar');
				var wpBarH = wpBar ? wpBar.offsetHeight : 32;
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
				if (slot === 'B') ensureSlotBEditorsInited();
				setTimeout(function () {
					var cmH = getCM('es-html-' + slot);
					var cmC = getCM('es-css-' + slot);
					try { if (cmH) cmH.refresh(); } catch (e) {}
					try { if (cmC) cmC.refresh(); } catch (e) {}
				}, 0);
				document.dispatchEvent(new CustomEvent('os:variant-changed', { detail: { slot: slot } }));
			}

			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					var slot = tab.getAttribute('data-variant-slot');
					if (slot && slot !== EmblemStudioAB.activeSlot) setActiveSlot(slot);
				});
			});
		})();

		$(document).on('change', '.es-shell-toggle', function () {
			$(this).closest('.es-shell-card').attr('data-enabled', this.checked ? 'true' : 'false');
		});

		$(document).on('change', 'input[data-visitor-logged-in]', function () {
			var $fieldset = $(this).closest('.es-dr-visitor');
			var $roles    = $fieldset.find('.es-dr-roles');
			$roles.toggle($(this).val() === 'in');
		});

		$(document).on('change', '.es-checkbox-pill input[type="checkbox"]', function () {
			$(this).closest('.es-checkbox-pill').toggleClass('is-checked', this.checked);
		});

		(function initInlivePreview() {
			var checkbox = document.getElementById('es-inline-preview-enabled');
			if (!checkbox) return;
			var wrap = document.querySelector('.es-inline-preview-frame-wrap');
			var btn = document.querySelector('.es-preview-btn');
			var url = btn ? btn.getAttribute('data-preview-url') : null;
			if (!wrap || !url) return;

			var STORAGE_KEY = 'es_inline_preview_enabled';
			var refreshTimer = null;

			function render() {
				if (!checkbox.checked) return;
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

			var replayBtn = document.getElementById('es-inline-preview-replay');
			if (replayBtn) {
				replayBtn.addEventListener('click', function () {
					var iframe = document.getElementById('es-inline-preview-iframe');
					if (iframe && iframe.contentWindow) {
						iframe.contentWindow.postMessage({ type: 'es-inspector:replay' }, '*');
					}
				});
				window.addEventListener('message', function (e) {
					var iframe = document.getElementById('es-inline-preview-iframe');
					if (!iframe || e.source !== iframe.contentWindow) return;
					var d = e.data || {};
					if (!d || d.type !== 'es-inspector:animatable') return;
					replayBtn.disabled = !d.animatable;
					replayBtn.setAttribute('data-es-tip', d.animatable ? 'Replay entry animations' : 'This asset has no animation');
				});
			}

			try {
				if (localStorage.getItem(STORAGE_KEY) === '1') {
					checkbox.checked = true;
					setTimeout(function () { applyState(true); }, 400);
				}
			} catch (e) {}

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
			['es-html', 'es-html-A', 'es-html-B'].forEach(hookEditor);
			['es-css', 'es-css-A', 'es-css-B'].forEach(hookEditor);

			document.addEventListener('os:variant-changed', function () {
				hookEditor('es-html-B');
				hookEditor('es-css-B');
				scheduleRender();
			});

			$(document).on('change', 'input[name="success_mode"], #es-success-message', scheduleRender);
		})();

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
				if (shellHint) shellHint.hidden = true;
				if (isEmpty() || !url) {
					if (loader) loader.hidden = true;
					buildPlaceholder();
					ph.hidden = false;
				} else {
					ph.hidden = true;
					if (loader) loader.hidden = false;
					iframe.style.height = '520px';
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

			var validateCb = wrap.querySelector('.es-design-validate');
			var sendCb = wrap.querySelector('.es-design-send');
			var testGroup = wrap.querySelector('.es-design-test');
			var shellHint = wrap.querySelector('.es-design-shell-hint');

			window.addEventListener('message', function (e) {
				if (e.source !== iframe.contentWindow) return;
				var d = e.data || {};
				if (d && d.type === 'es-inspector:animatable') {
					if (replay) {
						replay.disabled = !d.animatable;
						replay.setAttribute('data-es-tip', d.animatable ? 'Replay entry animations' : 'This asset has no animation');
					}
					return;
				}
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
					shellHint.className = 'es-design-shell-hint ' + (atBottom ? 'is-top' : 'is-bottom');
					shellHint.hidden = false;
				} else {
					shellHint.hidden = true;
				}
			});
			function hasFields() {
				var ta = document.getElementById(htmlFieldId());
				if (!ta) return false;
				var html = ta.value;
				if (/<(?:textarea|select)[\s>]/i.test(html)) return true;
				var inputs = html.match(/<input\b[^>]*>/gi);
				if (!inputs) return false;
				var nonFields = ['hidden', 'submit', 'button', 'image', 'reset'];
				return inputs.some(function (tag) {
					var m = tag.match(/\btype\s*=\s*("|'|)([a-zA-Z-]+)\1/i);
					var type = m ? m[2].toLowerCase() : 'text';
					return nonFields.indexOf(type) === -1;
				});
			}
			function syncTestVisibility() {
				if (testGroup) testGroup.hidden = !hasFields();
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
				if (loader && !loader.hidden) requestAnimationFrame(function () { loader.hidden = true; });
			});

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
					iframe.style.width = '';
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

			document.addEventListener('es:editor-closed', refresh);
			document.addEventListener('os:variant-changed', refresh);

			setTimeout(refresh, 150);
		})();

		(function initAssetChecksBox() {
			var box = document.getElementById('es-checks');
			var checks = window.EmblemStudioChecks;
			if (!box || !checks) return;
			var body = box.querySelector('.es-checks-body');
			if (!body) return;

			function ids() {
				var slot = (window.EmblemStudioAB && EmblemStudioAB.testRunning) ? EmblemStudioAB.activeSlot : '';
				return slot ? { html: 'es-html-' + slot, css: 'es-css-' + slot } : { html: 'es-html', css: 'es-css' };
			}
			function read(id) {
				var el = document.getElementById(id);
				if (!el) return '';
				var cm = el.CodeMirror || (el.nextElementSibling && el.nextElementSibling.CodeMirror);
				return cm ? cm.getValue() : (el.value || '');
			}
			function current() {
				var f = ids();
				return { html: read(f.html), css: read(f.css) };
			}

			var start = current();
			var shown = start.html + '\u0000' + start.css;
			var timer = null;
			var seq = 0;

			function run() {
				if (timer) { clearTimeout(timer); timer = null; }
				var now = current();
				var key = now.html + '\u0000' + now.css;
				if (key === shown) return;
				var mine = ++seq;
				checks.request(now.html, now.css, function (items) {
					if (mine !== seq || items === null) return;
					shown = key;
					checks.renderGroups(body, items);
					box.hidden = items.length === 0;
				});
			}
			function schedule() {
				if (timer) clearTimeout(timer);
				timer = setTimeout(run, 600);
			}

			['es-html', 'es-html-A', 'es-html-B', 'es-css', 'es-css-A', 'es-css-B'].forEach(function (id) {
				var el = document.getElementById(id);
				if (!el) return;
				el.addEventListener('input', schedule);
				el.addEventListener('change', schedule);
				var tries = 0;
				var t = setInterval(function () {
					var cm = el.CodeMirror || (el.nextElementSibling && el.nextElementSibling.CodeMirror);
					if (cm) {
						if (!cm._esChecksHooked) { cm._esChecksHooked = true; cm.on('change', schedule); }
						clearInterval(t);
					} else if (++tries > 20) {
						clearInterval(t);
					}
				}, 250);
			});
			document.addEventListener('es:editor-closed', run);
			document.addEventListener('os:variant-changed', run);
		})();

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

		(function initScheduledPreviews() {
			document.querySelectorAll('.es-sched-previews').forEach(function (box) {
				var url = box.getAttribute('data-preview-url');
				var id = box.getAttribute('data-change-id');
				if (!url || !id) return;
				function post(target, html, css) {
					var f = document.createElement('form');
					f.method = 'POST';
					f.action = url;
					f.target = target;
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
				post('es-sched-now-' + id, null, null);
				post('es-sched-after-' + id, box.querySelector('.es-sched-data-html').value, box.querySelector('.es-sched-data-css').value);
			});
		})();

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

			function normalizeForDiff(s) {
				return String(s).replace(/\r\n?/g, '\n').replace(/\n$/, '');
			}

			function commonPrefixLen(a, b) {
				var i = 0, n = Math.min(a.length, b.length);
				while (i < n && a.charCodeAt(i) === b.charCodeAt(i)) i++;
				return i;
			}
			function commonSuffixLen(a, b, capA, capB) {
				var i = 0, la = a.length, lb = b.length;
				while (i < (la - capA) && i < (lb - capB) && a.charCodeAt(la - 1 - i) === b.charCodeAt(lb - 1 - i)) i++;
				return i;
			}
			function linesSimilar(a, b) {
				if (!a.length || !b.length) return false;
				var p = commonPrefixLen(a, b);
				var s = commonSuffixLen(a, b, p, p);
				return ( p + s ) / Math.max(a.length, b.length) >= 0.25;
			}

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

			function pairModified(diff) {
				var out = [];
				var i = 0;
				while (i < diff.length) {
					if (diff[i].type === 'common') { out.push(diff[i]); i++; continue; }
					var removed = [];
					var added = [];
					while (i < diff.length && diff[i].type !== 'common') {
						if (diff[i].type === 'removed') removed.push(diff[i]);
						else added.push(diff[i]);
						i++;
					}
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

				renderDiff(
					diffLines(oldHtml, currentHtml),
					li.querySelector('.es-revision-diff-grid[data-which="html"]')
				);
				renderDiff(
					diffLines(oldCss, currentCss),
					li.querySelector('.es-revision-diff-grid[data-which="css"]')
				);

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
				window.scrollTo({ top: 0 });
			});

			window.addEventListener('hashchange', function () { activate(fromHash()); });

			document.addEventListener('invalid', function (e) {
				var el = e.target;
				if (!el || !el.closest) return;
				var panel = el.closest('.es-tab-panel');
				if (!panel) return;
				activate(panel.getAttribute('data-panel'));
			}, true);
		})();

		document.querySelectorAll('.es-tag-input').forEach(initTagInput);

		document.querySelectorAll('.es-mapper').forEach(initMapper);

		initDirtyTracker();

		initCopyTargets();

		initLockLabels();

		initAutoCloseNotices();

		var selectAll = document.getElementById('es-cb-select-all-1');
		if (selectAll) {
			selectAll.addEventListener('change', function () {
				document.querySelectorAll('input[name="form_ids[]"]').forEach(function (cb) {
					cb.checked = selectAll.checked;
				});
			});
		}

		initShellPanels();

		initShellSettingsContainer();

		initShellConnector();

		initShellExclusivity();

		initCloseConfigVisibility();

		initFormLevelSync();

		initDisplayRulesPanel();

		initContentPickers();

		initPresetPickers();

		initClearAllButtons();
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
					if (isChipped(item.id)) return;
					var li = document.createElement('li');
					li.className = 'es-content-suggestion';
					li.dataset.id = item.id;
					li.textContent = item.title || '(no title)';
					li.addEventListener('mousedown', function (e) {
						e.preventDefault();
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
					if (token !== activeFetch) return;
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
			fetchSuggestions(search.value.trim());
		});

		document.addEventListener('click', function (e) {
			if (!root.contains(e.target)) hideSuggestions();
		});

		root._escpApi = {
			setSelection: function (items) {
				chipsBox.innerHTML = '';
				(items || []).forEach(function (it) { addChip(it.id, it.title); });
			}
		};
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function loadPresetsData() {
		var el = document.getElementById('es-presets-data');
		if (!el) return [];
		try {
			var parsed = JSON.parse(el.textContent || '[]');
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function presetFieldName(key, side, field) {
		return 'display_' + key + '_' + side + '_' + field;
	}

	function presetWhereFieldset(picker) {
		return picker.parentElement ? picker.parentElement.querySelector('.es-dr-where-fields') : null;
	}

	function setCheckboxGroup(scope, name, values) {
		var strValues = (values || []).map(function (v) { return String(v); });
		scope.querySelectorAll('input[type="checkbox"][name="' + name + '[]"]').forEach(function (cb) {
			cb.checked = strValues.indexOf(cb.value) !== -1;
		});
	}

	function setChipPicker(scope, type, name, ids, titleMap) {
		var root = scope.querySelector('.es-content-picker[data-content-type="' + type + '"][data-name="' + name + '"]');
		if (!root || !root._escpApi) return;
		var items = (ids || []).map(function (id) {
			return { id: id, title: (titleMap && titleMap[id]) || ('#' + id) };
		});
		root._escpApi.setSelection(items);
	}

	function setPresetCoupledState(picker, preset) {
		var idField = picker.querySelector('.es-preset-id-field');
		var coupled = picker.querySelector('.es-preset-coupled');
		var uncoupled = picker.querySelector('.es-preset-uncoupled');
		var fieldset = presetWhereFieldset(picker);

		if (preset) {
			idField.value = preset.id;
			var nameEl = picker.querySelector('.es-preset-active-name');
			if (nameEl) nameEl.textContent = preset.name;
			var changeSelect = picker.querySelector('.es-preset-change-select');
			if (changeSelect) changeSelect.value = preset.id;
			coupled.hidden = false;
			uncoupled.hidden = true;
			if (fieldset) { fieldset.setAttribute('disabled', 'disabled'); fieldset.style.opacity = '.6'; }
		} else {
			idField.value = 0;
			coupled.hidden = true;
			uncoupled.hidden = false;
			if (fieldset) { fieldset.removeAttribute('disabled'); fieldset.style.opacity = ''; }
		}
	}

	function applyPresetToPicker(picker, preset) {
		var key = picker.getAttribute('data-shell-key');
		var fieldset = presetWhereFieldset(picker);
		if (!fieldset || !key) return;

		var modeInput = fieldset.querySelector('input[name="display_' + key + '_show_mode"][value="' + preset.show_mode + '"]');
		if (modeInput) modeInput.checked = true;

		['show', 'hide'].forEach(function (side) {
			var filters = preset[side + '_filters'] || {};
			var titles = (preset.titles && preset.titles[side]) || {};

			setCheckboxGroup(fieldset, presetFieldName(key, side, 'specials'), filters.specials);
			setCheckboxGroup(fieldset, presetFieldName(key, side, 'post_types'), filters.post_types);
			setCheckboxGroup(fieldset, presetFieldName(key, side, 'wc_pages'), filters.wc_pages);

			var urlsField = fieldset.querySelector('textarea[name="' + presetFieldName(key, side, 'urls') + '"]');
			if (urlsField) urlsField.value = (filters.urls || []).join('\n');

			setChipPicker(fieldset, 'category', presetFieldName(key, side, 'categories'), filters.categories, titles.cats);
			setChipPicker(fieldset, 'tag', presetFieldName(key, side, 'tags'), filters.tags, titles.tags);
			setChipPicker(fieldset, 'post', presetFieldName(key, side, 'posts'), filters.posts, titles.posts);
			setChipPicker(fieldset, 'page', presetFieldName(key, side, 'pages'), filters.pages, titles.pages);
		});

		fieldset.querySelectorAll('input[name="display_' + key + '_show_mode"]').forEach(function (r) {
			r.dispatchEvent(new Event('change', { bubbles: true }));
		});

		setPresetCoupledState(picker, preset);
	}

	function gatherShellWhere(picker) {
		var key = picker.getAttribute('data-shell-key');
		var fieldset = presetWhereFieldset(picker);
		if (!fieldset || !key) return { show_mode: 'all', show_filters: {}, hide_filters: {} };

		var checkedMode = fieldset.querySelector('input[name="display_' + key + '_show_mode"]:checked');

		function gatherSide(side) {
			function checkedValues(field) {
				var out = [];
				fieldset.querySelectorAll('input[type="checkbox"][name="' + presetFieldName(key, side, field) + '[]"]:checked').forEach(function (cb) { out.push(cb.value); });
				return out;
			}
			function chipIds(type, field) {
				var root = fieldset.querySelector('.es-content-picker[data-content-type="' + type + '"][data-name="' + presetFieldName(key, side, field) + '"]');
				var out = [];
				if (root) root.querySelectorAll('.es-content-chip input[type="hidden"]').forEach(function (inp) { out.push(inp.value); });
				return out;
			}
			var urlsField = fieldset.querySelector('textarea[name="' + presetFieldName(key, side, 'urls') + '"]');
			return {
				specials: checkedValues('specials'),
				post_types: checkedValues('post_types'),
				wc_pages: checkedValues('wc_pages'),
				categories: chipIds('category', 'categories'),
				tags: chipIds('tag', 'tags'),
				posts: chipIds('post', 'posts'),
				pages: chipIds('page', 'pages'),
				urls: urlsField ? urlsField.value.split('\n') : []
			};
		}

		return {
			show_mode: checkedMode ? checkedMode.value : 'all',
			show_filters: gatherSide('show'),
			hide_filters: gatherSide('hide')
		};
	}

	function presetAjax(action, data, cb) {
		var cfg = window.EmblemStudioAdmin || {};
		if (!cfg.ajaxUrl) { cb(null); return; }
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.presetNonce || '');
		Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) { cb(json && json.success ? json.data : null); })
			.catch(function () { cb(null); });
	}

	function addOrUpdatePresetOption(picker, preset) {
		['.es-preset-apply-select', '.es-preset-change-select'].forEach(function (sel) {
			var select = picker.querySelector(sel);
			if (!select) return;
			var opt = select.querySelector('option[value="' + preset.id + '"]');
			if (!opt) {
				opt = document.createElement('option');
				opt.value = preset.id;
				select.appendChild(opt);
			}
			opt.textContent = preset.name;
		});
	}

	var presetsById = {};

	function initPresetPickers() {
		presetsById = {};
		loadPresetsData().forEach(function (p) { presetsById[p.id] = p; });

		document.querySelectorAll('.es-dr-preset-picker').forEach(function (picker) {
			var applySelect = picker.querySelector('.es-preset-apply-select');
			var applyBtn = picker.querySelector('.es-preset-apply-btn');
			var changeSelect = picker.querySelector('.es-preset-change-select');
			var changeBtn = picker.querySelector('.es-preset-change-btn');
			var unlinkBtn = picker.querySelector('.es-preset-unlink-btn');
			var saveAsBtn = picker.querySelector('.es-preset-save-as-btn');
			var saveNameField = picker.querySelector('.es-preset-save-name');
			var manageLink = picker.querySelector('.es-preset-manage-link');

			if (applyBtn) applyBtn.addEventListener('click', function () {
				var id = parseInt(applySelect.value, 10);
				if (id && presetsById[id]) applyPresetToPicker(picker, presetsById[id]);
			});
			if (changeBtn) changeBtn.addEventListener('click', function () {
				var id = parseInt(changeSelect.value, 10);
				if (id && presetsById[id]) applyPresetToPicker(picker, presetsById[id]);
			});
			if (unlinkBtn) unlinkBtn.addEventListener('click', function () {
				setPresetCoupledState(picker, null);
			});
			if (saveAsBtn) saveAsBtn.addEventListener('click', function () {
				var name = (saveNameField.value || '').trim();
				if (!name) { saveNameField.focus(); return; }
				var where = gatherShellWhere(picker);
				saveAsBtn.disabled = true;
				presetAjax('es_preset_save_as', { name: name, rules: JSON.stringify(where) }, function (data) {
					saveAsBtn.disabled = false;
					if (!data || !data.preset) return;
					var preset = data.preset;
					presetsById[preset.id] = preset;
					document.querySelectorAll('.es-dr-preset-picker').forEach(function (p) { addOrUpdatePresetOption(p, preset); });
					setPresetCoupledState(picker, presetsById[preset.id]);
					saveNameField.value = '';
				});
			});
			if (manageLink) manageLink.addEventListener('click', openPresetManageModal);
		});
	}

	function clearAllDisplayRules(picker) {
		var key = picker.getAttribute('data-shell-key');
		var body = picker.closest('.es-display-rules-body');
		var fieldset = presetWhereFieldset(picker);
		if (!body || !fieldset || !key) return;

		var modeAll = fieldset.querySelector('input[name="display_' + key + '_show_mode"][value="all"]');
		if (modeAll) modeAll.checked = true;
		['show', 'hide'].forEach(function (side) {
			setCheckboxGroup(fieldset, presetFieldName(key, side, 'specials'), []);
			setCheckboxGroup(fieldset, presetFieldName(key, side, 'post_types'), []);
			setCheckboxGroup(fieldset, presetFieldName(key, side, 'wc_pages'), []);
			var urlsField = fieldset.querySelector('textarea[name="' + presetFieldName(key, side, 'urls') + '"]');
			if (urlsField) urlsField.value = '';
			setChipPicker(fieldset, 'category', presetFieldName(key, side, 'categories'), [], {});
			setChipPicker(fieldset, 'tag', presetFieldName(key, side, 'tags'), [], {});
			setChipPicker(fieldset, 'post', presetFieldName(key, side, 'posts'), [], {});
			setChipPicker(fieldset, 'page', presetFieldName(key, side, 'pages'), [], {});
		});
		fieldset.querySelectorAll('input[name="display_' + key + '_show_mode"]').forEach(function (r) {
			r.dispatchEvent(new Event('change', { bubbles: true }));
		});
		setPresetCoupledState(picker, null);

		var loggedInAny = body.querySelector('input[name="display_' + key + '_visitor_logged_in"][value="any"]');
		if (loggedInAny) { loggedInAny.checked = true; loggedInAny.dispatchEvent(new Event('change', { bubbles: true })); }
		setCheckboxGroup(body, 'display_' + key + '_visitor_roles', []);
		var stateAny = body.querySelector('input[name="display_' + key + '_visitor_state"][value="any"]');
		if (stateAny) stateAny.checked = true;
		setCheckboxGroup(body, 'display_' + key + '_schedule_days', []);
		var startField = body.querySelector('input[name="display_' + key + '_schedule_start_time"]');
		if (startField) startField.value = '';
		var endField = body.querySelector('input[name="display_' + key + '_schedule_end_time"]');
		if (endField) endField.value = '';
	}

	function initClearAllButtons() {
		document.querySelectorAll('.es-dr-clear-all').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!window.confirm('Clear all display rules for this shell? It goes back to showing everywhere, any time, for everyone.')) return;
				var body = btn.closest('.es-display-rules-body');
				var picker = body ? body.querySelector('.es-dr-preset-picker') : null;
				if (picker) clearAllDisplayRules(picker);
			});
		});
	}

	var presetModalEl = null;

	function ensurePresetModal() {
		if (presetModalEl) return presetModalEl;
		var el = document.createElement('div');
		el.id = 'es-preset-modal';
		el.innerHTML =
			'<div class="es-preset-modal-backdrop"></div>' +
			'<div class="es-preset-modal-box">' +
				'<div class="es-preset-modal-head">' +
					'<h2>' + escapeHtml('Display-rule presets') + '</h2>' +
					'<button type="button" class="es-preset-modal-close" aria-label="Close">&times;</button>' +
				'</div>' +
				'<p class="es-preset-modal-sub">Editing here updates every shell already following a preset. Post types, categories, tags, and specific posts/pages: apply the preset to a shell, adjust it there, then "Save current as preset" under the same name.</p>' +
				'<div class="es-preset-modal-body"><p>Loading…</p></div>' +
			'</div>';
		var wrap = document.querySelector('.emblem-studio-wrap') || document.body;
		wrap.appendChild(el);
		el.querySelector('.es-preset-modal-close').addEventListener('click', closePresetManageModal);
		el.querySelector('.es-preset-modal-backdrop').addEventListener('click', closePresetManageModal);
		presetModalEl = el;
		return el;
	}

	function openPresetManageModal() {
		var el = ensurePresetModal();
		el.classList.add('is-open');
		presetAjax('es_preset_list', {}, function (data) {
			renderPresetModalList(el, (data && data.presets) || []);
		});
	}

	function closePresetManageModal() {
		if (presetModalEl) presetModalEl.classList.remove('is-open');
	}

	function renderPresetModalList(el, presets) {
		var body = el.querySelector('.es-preset-modal-body');
		body.innerHTML = '';
		if (!presets.length) {
			body.innerHTML = '<p>No presets saved for this site yet — save one from any shell’s Display rules panel first.</p>';
			return;
		}
		presets.forEach(function (preset) { body.appendChild(renderPresetModalRow(body, preset)); });
	}

	function renderModalFilterSide(scopeId, side, filters, titles, urlLabel) {
		var cfg = window.EmblemStudioAdmin || {};
		filters = filters || {};
		titles = titles || {};
		var urls = (filters.urls || []).join('\n');

		function checkboxGroup(field, items, keyOf, selected) {
			var sel = (selected || []).map(String);
			return items.map(function (item) {
				var value = String(keyOf(item));
				var checked = sel.indexOf(value) !== -1 ? ' checked' : '';
				return '<label class="es-preset-modal-chk"><input type="checkbox" data-field="' + field + '" value="' + escapeHtml(value) + '"' + checked + '> ' + escapeHtml(item.label) + '</label>';
			}).join(' ');
		}

		function contentPicker(type, field) {
			return '<div class="es-content-picker" data-content-type="' + type + '" data-name="es-preset-modal-' + scopeId + '-' + side + '-' + field + '">' +
				'<div class="es-content-chips"></div>' +
				'<div class="es-content-search-wrap"><input type="text" class="es-content-search" placeholder="Search ' + type + 's…"><ul class="es-content-suggestions" hidden></ul></div>' +
			'</div>';
		}

		var html = '<div class="es-preset-modal-side" data-side="' + side + '">';
		html += '<p class="es-preset-modal-group-label">' + escapeHtml(urlLabel) + '</p>';
		if (cfg.presetSpecials && cfg.presetSpecials.length) {
			html += '<p>' + checkboxGroup('specials', cfg.presetSpecials, function (i) { return i.key; }, filters.specials) + '</p>';
		}
		if (cfg.presetPostTypes && cfg.presetPostTypes.length) {
			html += '<p>' + checkboxGroup('post_types', cfg.presetPostTypes, function (i) { return i.slug; }, filters.post_types) + '</p>';
		}
		if (cfg.presetWcActive && cfg.presetWcPages && cfg.presetWcPages.length) {
			html += '<p>' + checkboxGroup('wc_pages', cfg.presetWcPages, function (i) { return i.key; }, filters.wc_pages) + '</p>';
		}
		html += '<p class="es-preset-modal-group-label">Categories</p>' + contentPicker('category', 'categories');
		html += '<p class="es-preset-modal-group-label">Tags</p>' + contentPicker('tag', 'tags');
		html += '<p class="es-preset-modal-group-label">Specific posts</p>' + contentPicker('post', 'posts');
		html += '<p class="es-preset-modal-group-label">Specific pages</p>' + contentPicker('page', 'pages');
		html += '<textarea class="es-preset-modal-urls" data-field="urls" rows="2" placeholder="/&#10;/pricing">' + escapeHtml(urls) + '</textarea>';
		html += '</div>';
		return html;
	}

	function initModalContentPickers(container, filters, titles) {
		['show', 'hide'].forEach(function (side) {
			var sideFilters = (filters && filters[side + '_filters']) || {};
			var sideTitles = (titles && titles[side]) || {};
			[['category', 'categories', 'cats'], ['tag', 'tags', 'tags'], ['post', 'posts', 'posts'], ['page', 'pages', 'pages']].forEach(function (m) {
				var root = container.querySelector('.es-preset-modal-side[data-side="' + side + '"] .es-content-picker[data-content-type="' + m[0] + '"]');
				if (!root) return;
				initContentPicker(root);
				var ids = sideFilters[m[1]] || [];
				var titleMap = sideTitles[m[2]] || {};
				root._escpApi.setSelection(ids.map(function (id) { return { id: id, title: titleMap[id] || ('#' + id) }; }));
			});
		});
	}

	function gatherModalFilterSide(container, side) {
		var root = container.querySelector('.es-preset-modal-side[data-side="' + side + '"]');
		if (!root) return {};
		function checked(field) {
			var out = [];
			root.querySelectorAll('input[type="checkbox"][data-field="' + field + '"]:checked').forEach(function (cb) { out.push(cb.value); });
			return out;
		}
		function chipIds(type) {
			var picker = root.querySelector('.es-content-picker[data-content-type="' + type + '"]');
			var out = [];
			if (picker) picker.querySelectorAll('.es-content-chip input[type="hidden"]').forEach(function (inp) { out.push(inp.value); });
			return out;
		}
		var urlsField = root.querySelector('.es-preset-modal-urls');
		return {
			specials: checked('specials'),
			post_types: checked('post_types'),
			wc_pages: checked('wc_pages'),
			categories: chipIds('category'),
			tags: chipIds('tag'),
			posts: chipIds('post'),
			pages: chipIds('page'),
			urls: urlsField ? urlsField.value.split('\n') : []
		};
	}

	function renderPresetModalRow(list, preset) {
		var row = document.createElement('div');
		row.className = 'es-preset-modal-row';

		var header = document.createElement('button');
		header.type = 'button';
		header.className = 'es-preset-modal-row-header';
		header.innerHTML =
			'<span class="es-preset-modal-chevron">&rsaquo;</span>' +
			'<span class="es-preset-modal-row-name">' + escapeHtml(preset.name) + '</span>' +
			'<span class="es-preset-modal-row-usage">' + preset.usage_count + ' shell' + (preset.usage_count === 1 ? '' : 's') + '</span>';
		row.appendChild(header);

		var editor = document.createElement('div');
		editor.className = 'es-preset-modal-row-editor';
		editor.hidden = true;
		editor.innerHTML =
			'<p><input type="text" class="es-preset-modal-name" value="' + escapeHtml(preset.name) + '"></p>' +
			'<p>' +
				'<label><input type="radio" name="es-preset-modal-mode-' + preset.id + '" value="all"' + (preset.show_mode !== 'selected' ? ' checked' : '') + '> Everywhere on the site</label><br>' +
				'<label><input type="radio" name="es-preset-modal-mode-' + preset.id + '" value="selected"' + (preset.show_mode === 'selected' ? ' checked' : '') + '> Only on pages matching one or more of these:</label>' +
			'</p>' +
			renderModalFilterSide(preset.id, 'show', preset.show_filters, preset.titles, 'Show on') +
			renderModalFilterSide(preset.id, 'hide', preset.hide_filters, preset.titles, 'Hide on (overrides show)') +
			'<p>' +
				'<button type="button" class="button button-primary es-preset-modal-save">Save changes</button> ' +
				'<button type="button" class="button es-preset-modal-delete">Delete</button>' +
			'</p>';
		row.appendChild(editor);
		initModalContentPickers(editor, preset, preset.titles);

		header.addEventListener('click', function () {
			var opening = editor.hidden;
			list.querySelectorAll('.es-preset-modal-row-editor').forEach(function (e) { e.hidden = true; });
			list.querySelectorAll('.es-preset-modal-row-header').forEach(function (h) { h.classList.remove('is-open'); });
			if (opening) { editor.hidden = false; header.classList.add('is-open'); }
		});

		editor.querySelector('.es-preset-modal-save').addEventListener('click', function () {
			var name = editor.querySelector('.es-preset-modal-name').value.trim();
			if (!name) return;
			var mode = editor.querySelector('input[name="es-preset-modal-mode-' + preset.id + '"]:checked');
			var where = {
				show_mode: mode ? mode.value : 'all',
				show_filters: gatherModalFilterSide(editor, 'show'),
				hide_filters: gatherModalFilterSide(editor, 'hide')
			};
			presetAjax('es_preset_update', { id: preset.id, name: name, rules: JSON.stringify(where) }, function (data) {
				if (!data || !data.preset) return;
				presetsById[preset.id] = data.preset;
				document.querySelectorAll('.es-dr-preset-picker').forEach(function (p) { addOrUpdatePresetOption(p, data.preset); });
				openPresetManageModal();
			});
		});

		editor.querySelector('.es-preset-modal-delete').addEventListener('click', function () {
			var note = preset.usage_count > 0 ? (' ' + preset.usage_count + ' shell' + (preset.usage_count === 1 ? '' : 's') + ' using it will keep their current settings, just un-linked.') : '';
			if (!window.confirm('Delete "' + preset.name + '"?' + note)) return;
			presetAjax('es_preset_delete', { id: preset.id }, function (data) {
				if (!data) return;
				delete presetsById[preset.id];
				document.querySelectorAll('.es-dr-preset-picker').forEach(function (p) {
					var idField = p.querySelector('.es-preset-id-field');
					if (idField && parseInt(idField.value, 10) === preset.id) setPresetCoupledState(p, null);
					['.es-preset-apply-select', '.es-preset-change-select'].forEach(function (sel) {
						var select = p.querySelector(sel);
						var opt = select && select.querySelector('option[value="' + preset.id + '"]');
						if (opt) opt.remove();
					});
				});
				openPresetManageModal();
			});
		});

		return row;
	}

	function initDisplayRulesPanel() {
		document.querySelectorAll('.es-dr-show-filters').forEach(function (showFilters) {
			var body = showFilters.closest('.es-display-rules-body');
			if (!body) return;
			var modeInputs = body.querySelectorAll('input[name$="_show_mode"]');
			if (!modeInputs.length) return;
			function sync() {
				var checked = body.querySelector('input[name$="_show_mode"]:checked');
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
			var key      = shell.replace(/-/g, '_');
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
			if (section.offsetParent === null || wrap.style.display === 'none') { hide(); return; }
			var panel = activePanel();
			if (!panel) { hide(); return; }
			var card = section.querySelector('.es-shell-card[data-shell="' + panel.getAttribute('data-shell') + '"]');
			if (!card) { hide(); return; }

			conn.classList.add('is-visible');
			var shellColor = window.getComputedStyle(panel).getPropertyValue('--shell-color').trim();
			conn.style.color = shellColor || '';
			var base = conn.getBoundingClientRect();
			var g    = grid.getBoundingClientRect();
			var c    = card.getBoundingClientRect();
			var w    = wrap.getBoundingClientRect();

			var entryY = c.top - base.top + c.height / 2;
			var topY   = w.top - base.top;
			if (topY <= entryY) { hide(); return; }

			var stacked = window.matchMedia('(max-width: 1100px)').matches;
			var startX, channelX;
			if (stacked) {
				startX   = c.right - base.left;
				channelX = Math.min(g.right - base.left + 14, base.width - 3);
			} else {
				var gridCenter = g.left - base.left + g.width / 2;
				var cardCenter = c.left - base.left + c.width / 2;
				startX   = cardCenter < gridCenter ? (c.right - base.left) : (c.left - base.left);
				channelX = gridCenter;
			}

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

		section.addEventListener('change', redraw);
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
		setTimeout(draw, 60);
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
			cb.dispatchEvent(new Event('change', { bubbles: true }));
		}

		all.forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (!cb.checked) return;
				var isExclusive = EXCLUSIVE.indexOf(cb.value) !== -1;
				all.forEach(function (other) {
					if (other === cb || !other.checked) return;
					if (isExclusive) {
						other.checked = false;
						fireChange(other);
					} else if (EXCLUSIVE.indexOf(other.value) !== -1) {
						other.checked = false;
						fireChange(other);
					}
				});
			});
		});
	}

	function initAutoCloseNotices() {
		document.querySelectorAll('.emblem-studio-wrap .notice.notice-success:not(.inline)').forEach(function (notice) {
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

		document.querySelectorAll('.es-toast').forEach(function (el, i) {
			el.style.top = (60 + i * 52) + 'px';
			requestAnimationFrame(function () { el.classList.add('is-in'); });
			setTimeout(function () {
				el.classList.remove('is-in');
				el.classList.add('is-out');
				setTimeout(function () { el.remove(); }, 280);
			}, 2000);
		});
	}

	function initLockLabels() {
		var table = document.querySelector('.es-forms-table[data-es-lock-nonce]');
		if (!table || typeof window.ajaxurl !== 'string') return;
		var strings = {};
		try { strings = JSON.parse(table.getAttribute('data-es-lock-strings') || '{}'); } catch (e) {}

		function badge(state) {
			var el = document.createElement('span');
			el.className = 'es-lock-badge' + (state.is_me ? ' is-you' : '');
			el.title = state.is_me ? (strings.youTitle || '') : String(strings.otherTitle || '').replace('%s', state.name);
			var icon = document.createElement('span');
			icon.className = 'dashicons dashicons-edit';
			icon.setAttribute('aria-hidden', 'true');
			el.appendChild(icon);
			el.appendChild(document.createTextNode(' ' + (state.is_me ? (strings.you || '') : String(strings.other || '').replace('%s', state.name))));
			return el;
		}

		function refresh() {
			var groups = table.querySelectorAll('tbody[data-es-asset-id]');
			if (!groups.length) return;
			var ids = [];
			groups.forEach(function (g) { ids.push(g.getAttribute('data-es-asset-id')); });
			var body = new URLSearchParams();
			body.append('action', 'es_lock_states');
			body.append('nonce', table.getAttribute('data-es-lock-nonce'));
			body.append('ids', ids.join(','));
			fetch(window.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (!j || !j.success) return;
					var held = j.data || {};
					groups.forEach(function (g) {
						var slot = g.querySelector('.es-lock-slot');
						if (!slot) return;
						var state = held[g.getAttribute('data-es-asset-id')];
						slot.textContent = '';
						if (state) slot.appendChild(badge(state));
					});
				})
				.catch(function () {});
		}

		setTimeout(refresh, 1500);
		setTimeout(refresh, 4000);
		setInterval(function () { if (document.visibilityState === 'visible') refresh(); }, 15000);
		document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') refresh(); });
		window.addEventListener('pageshow', function (e) { if (e.persisted) refresh(); });
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
		if (el.nextElementSibling && el.nextElementSibling.CodeMirror) {
			return el.nextElementSibling.CodeMirror.getValue();
		}
		return el.value || '';
	}

	function submitPreviewPost(previewUrl, target, extra) {
		if (!previewUrl) return;
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

		var initialHookPhase = true;
		setTimeout(function () { initialHookPhase = false; }, 5000);

		function hookCm(id) {
			var el = document.getElementById(id);
			if (!el) return;
			var tries = 0;
			var t = setInterval(function () {
				var cm = el.CodeMirror || (el.nextElementSibling && el.nextElementSibling.CodeMirror);
				if (cm) {
					if (!cm._osDirtyHooked) {
						cm._osDirtyHooked = true;
						cmInstances[id] = cm;
						cm.on('change', check);
						if (initialHookPhase) {
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

		window.addEventListener('beforeunload', function (e) {
			if (form.classList.contains('is-dirty')) {
				e.preventDefault();
				e.returnValue = '';
			}
		});

		snapshot = currentState();
		['es-html', 'es-html-A', 'es-html-B', 'es-css', 'es-css-A', 'es-css-B'].forEach(hookCm);

		document.addEventListener('os:variant-changed', function () {
			hookCm('es-html-B');
			hookCm('es-css-B');
		});
	}

	function initTagInput(container) {
		var funnelkitTags = [];
		var pendingTags = [];
		var selected = [];
		try { funnelkitTags = JSON.parse(container.dataset.suggestions || '[]'); } catch (e) {}
		try { pendingTags = JSON.parse(container.dataset.pending || '[]'); } catch (e) {}
		try { selected = JSON.parse(container.dataset.value || '[]'); } catch (e) {}

		function tagKey(s) { return String(s).trim().toLowerCase().replace(/\s+/g, ' '); }
		function isPending(name) {
			return pendingTags.some(function (p) { return tagKey(p) === tagKey(name); })
				&& !funnelkitTags.some(function (t) { return tagKey(t) === tagKey(name); });
		}
		var allTags = funnelkitTags.concat(pendingTags.filter(function (p) {
			return !funnelkitTags.some(function (t) { return tagKey(t) === tagKey(p); });
		}));
		function nearMatch(name) {
			return allTags.find(function (t) { return tagKey(t) === tagKey(name) && t !== String(name).trim(); });
		}

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
			var lastGroup = null;
			matches.forEach(function (name, i) {
				var group = isPending(name) ? 'From your assets' : 'In FunnelKit';
				if (group !== lastGroup) {
					lastGroup = group;
					var head = document.createElement('div');
					head.className = 'es-tag-suggestion-group';
					head.textContent = group;
					suggEl.appendChild(head);
				}
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

		function renderWarning() {
			var warn = container.querySelector('.es-tag-warning');
			if (!warn) {
				warn = document.createElement('p');
				warn.className = 'es-tag-warning';
				container.appendChild(warn);
			}
			var typed = input.value.trim();
			var near = typed && allTags.indexOf(typed) === -1 ? nearMatch(typed) : null;
			var picked = selected.map(nearMatch).filter(Boolean);
			warn.innerHTML = '';
			warn.style.display = (near || picked.length) ? '' : 'none';
			if (near) {
				warn.innerHTML = '<strong>' + esc(near) + '</strong> already exists. ';
				var use = document.createElement('button');
				use.type = 'button';
				use.className = 'es-tag-warning-use';
				use.textContent = 'Use that one';
				use.addEventListener('mousedown', function (e) { e.preventDefault(); addTag(near); });
				warn.appendChild(use);
			}
			picked.forEach(function (other, i) {
				var line = document.createElement('span');
				line.textContent = '"' + selected.filter(nearMatch)[i] + '" and "' + other + '" are two different tags. Only the spelling differs.';
				line.style.display = 'block';
				warn.appendChild(line);
			});
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
			renderWarning();
		}

		input.addEventListener('input', function () { highlightIdx = -1; renderSuggestions(); renderWarning(); });
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
		if (!ok) return true;
		return ok.indexOf(formKind) !== -1;
	}

	function parseFormFields(html) {
		var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
		var seen = {};
		var reserved = ['esf_hp', 'esf_tag', 'esf_list', 'form_id', 'post_id', 'action', '_wpnonce', '_wp_http_referer'];

		var checkboxCount = {};

		function add(name, kind) {
			if (!name || reserved.indexOf(name) !== -1) return null;
			if (!seen[name]) seen[name] = { name: name, kind: kind };
			return seen[name];
		}
		function addOption(field, value) {
			if (!field || value === '') return;
			if (!field.options) field.options = [];
			if (field.options.indexOf(value) === -1) field.options.push(value);
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
			var type = (el.getAttribute('type') || '').toLowerCase();
			var field = add(name, inputKind(type, name));
			if (!field) return;
			if (type === 'checkbox' || type === 'radio') {
				addOption(field, el.hasAttribute('value') ? el.getAttribute('value') : 'on');
			}
			if (type === 'checkbox') {
				checkboxCount[name] = (checkboxCount[name] || 0) + 1;
				if (checkboxCount[name] >= 2 && field.kind === 'checkbox-single') field.kind = 'checkbox-multi';
			}
		});
		doc.querySelectorAll('select').forEach(function (el) {
			var name = el.getAttribute('name');
			if (!name) return;
			var field = add(name, el.hasAttribute('multiple') ? 'select-multi' : 'select-single');
			if (!field) return;
			el.querySelectorAll('option').forEach(function (opt) {
				addOption(field, opt.hasAttribute('value') ? opt.getAttribute('value') : opt.textContent.trim());
			});
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
		var selectedForm = null;

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

		function findFieldCi(names) {
			for (var i = 0; i < names.length; i++) {
				var wanted = names[i].toLowerCase();
				for (var j = 0; j < formFields.length; j++) {
					if (formFields[j].name.toLowerCase() === wanted) return formFields[j];
				}
			}
			return null;
		}

		function findFieldByKind(kind) {
			for (var i = 0; i < formFields.length; i++) if (formFields[i].kind === kind) return formFields[i];
			return null;
		}

		var NAME_VARIANTS = {
			'col:email': ['email', 'your-email', 'email_address', 'user_email', 'e-mail'],
			'col:contact_no': ['phone', 'tel', 'phone_number', 'mobile', 'contact_number'],
			'col:f_name': ['first_name', 'fname', 'firstname', 'first-name'],
			'col:l_name': ['last_name', 'lname', 'surname', 'last-name', 'lastname'],
		};
		var KIND_FALLBACK = { 'col:email': 'email', 'col:contact_no': 'tel' };

		function autoDetected(fkKey) {
			var names = NAME_VARIANTS[fkKey];
			if (!names) return null;

			var hit = findFieldCi(names);
			if (hit) return { name: hit.name };

			if (fkKey === 'col:f_name' || fkKey === 'col:l_name') {
				var nameField = formFieldExists('name');
				return nameField ? { name: 'name', split: fkKey === 'col:f_name' ? 'first' : 'last' } : null;
			}

			var kind = KIND_FALLBACK[fkKey];
			var byKind = kind ? findFieldByKind(kind) : null;
			return byKind ? { name: byKind.name } : null;
		}

		function unmatchedOptions(fkField, formField) {
			if (!fkField.options || !fkField.options.length || !formField.options || !formField.options.length) return [];
			var known = fkField.options.map(function (o) { return String(o).toLowerCase(); });
			return formField.options.filter(function (v) {
				return known.indexOf(String(v).toLowerCase()) === -1;
			});
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
					var warning = null;
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
							: (!compat ? 'Type mismatch. Saving will still work, but the value may not be stored correctly.' : '');
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
						var dropped = detected ? unmatchedOptions(f, detected) : [];
						if (dropped.length) {
							warning = document.createElement('div');
							warning.className = 'es-mapper-row-warning';
							warning.setAttribute('role', 'note');
							warning.textContent = 'FunnelKit only keeps values it knows. These will be dropped: ' + dropped.join(', ') + '.';
						}
						if (!warning && detected && detected.kind === 'textarea' && (f.kind || 'text') === 'text') {
							warning = document.createElement('div');
							warning.className = 'es-mapper-row-warning';
							warning.setAttribute('role', 'note');
							warning.textContent = '"' + f.label + '" is a single-line field in FunnelKit, so it keeps only the first 99 characters of a long answer. Make it a Text Area field there to keep the whole thing.';
						}
					} else {
						var auto = autoDetected(f.key);
						if (auto) {
							var label = auto.name;
							if (auto.split === 'first') label = auto.name + ' (first word)';
							else if (auto.split === 'last') label = auto.name + ' (rest)';
							var autoField = formFieldExists(auto.name);
							var autoCompat = autoField ? isCompatible(f.kind, autoField.kind) : true;
							var autoPill = document.createElement('span');
							autoPill.className = 'es-mapper-pill is-auto' + (!autoCompat ? ' is-warn' : '');
							autoPill.innerHTML = '<span>' + esc(label) + '</span><span class="es-mapper-auto-tag">auto</span>';
							autoPill.title = !autoCompat
								? 'Type mismatch. Auto-detected by name, but the input type doesn\'t match. Saving will still work, but the value may not be stored correctly.'
								: 'Auto-detected from input name — pushed to FunnelKit on submit. To override, pick a form input on the right then click this slot.';
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
					if (warning) row.appendChild(warning);
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

			function tryHook() {
				var cm = textarea.nextElementSibling && textarea.nextElementSibling.CodeMirror;
				if (cm) {
					cm.on('change', refresh);
					return true;
				}
				return false;
			}
			if (!tryHook()) {
				var tries = 0;
				var t = setInterval(function () {
					if (tryHook() || ++tries > 20) clearInterval(t);
				}, 100);
			}
			textarea.addEventListener('input', refresh);
		}

		render();
		watchHtmlEditor();
	}

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
					.catch(function () { });
			}
		});
	});

	$(function () {
		document.querySelectorAll('.es-destination-toggle').forEach(function (toggle) {
			const card = toggle.closest('.es-connect-card');
			if (!card) return;
			toggle.addEventListener('change', function () {
				card.classList.toggle('is-destination-off', !toggle.checked);
			});
		});
	});

	$(function () {
		const list = document.querySelector('.es-webhook-list');
		if (!list) return;
		const template = document.querySelector('.es-webhook-template');
		const addBtn = document.querySelector('.es-webhook-add');
		const testNonce = list.getAttribute('data-test-nonce') || '';
		const ajaxUrl = (window.ajaxurl || '/wp-admin/admin-ajax.php');

		function nextIndex() {
			let max = -1;
			list.querySelectorAll('input[name^="webhooks["]').forEach(function (input) {
				const m = input.getAttribute('name').match(/^webhooks\[(\d+)\]/);
				if (m) max = Math.max(max, parseInt(m[1], 10));
			});
			return max + 1;
		}

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

		list.addEventListener('click', function (e) {
			const btn = e.target.closest('.es-webhook-remove');
			if (!btn) return;
			const row = btn.closest('.es-webhook-row');
			if (row && confirm('Remove this webhook?')) {
				row.remove();
			}
		});

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

	$(function () {
		const picker = document.querySelector('.es-picker-collections');
		const filters = document.querySelectorAll('.es-picker-filter');
		if (!picker && !filters.length) return;

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
			iframes.forEach(function (f) {
				const src = f.getAttribute('data-thumb-src');
				if (src) { f.src = src; f.removeAttribute('data-thumb-src'); }
			});
		}

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
					document.querySelectorAll('.es-picker-collection').forEach(function (col) {
						const visible = col.querySelectorAll('.es-picker-card:not(.is-filtered-out)').length;
						col.classList.toggle('is-empty', visible === 0);
					});
				});
			});
		}
	});

})(jQuery);

(function () {
	var L = window.EmblemStudioLock;
	if (!L || !L.formId) return;

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

	var bc = ('BroadcastChannel' in window) ? new BroadcastChannel('es-lock-' + L.formId) : null;
	if (bc) bc.onmessage = function (ev) {
		var d = (ev && ev.data) || {};
		if (d.type === 'taken' && held && d.token !== myToken) bootedBy('', true);
	};

	var held = false;
	var submitting = false;
	var beatTimer = null;
	var retryTimer = null;
	var bannerMode = 'takeover';

	var BEAT_MS = 20000;
	var RETRY_MS = 5000;

	function post(action, cb, claimIt) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('form_id', L.formId);
		body.append('nonce', L.nonce);
		body.append('token', myToken);
		if (claimIt) body.append('claim', '1');
		fetch(L.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) {
				return r.text().then(function (t) {
					var j = null;
					try { j = JSON.parse(t); } catch (e) {}
					var expired = (j && j.data && j.data.reason === 'nonce') || t === '-1' || t === '0' ||
						(!j && (r.status === 403 || r.status === 400));
					if (j && j.success) {
						freshNonces(j.data);
						if (cb) cb(j, '');
					} else if (cb) {
						cb(j, expired ? 'nonce' : 'network');
					}
				});
			})
			.catch(function () { if (cb) cb(null, 'network'); });
	}

	function freshNonces(d) {
		if (!d) return;
		if (d.nonce) L.nonce = d.nonce;
		if (d.save_nonce) {
			var f = document.querySelector('.es-form-editor input[name="_wpnonce"]');
			if (f) f.value = d.save_nonce;
		}
	}

	function setSaveDisabled(on) {
		var btns = document.querySelectorAll('.es-form-editor button[type="submit"], .es-form-editor input[type="submit"]');
		for (var i = 0; i < btns.length; i++) { btns[i].disabled = on; }
	}
	function startHeartbeat() {
		if (beatTimer) clearInterval(beatTimer);
		beatTimer = null;
		if (document.hidden) return;
		beatTimer = setInterval(beat, BEAT_MS);
	}
	function stopHeartbeat() {
		if (beatTimer) { clearInterval(beatTimer); beatTimer = null; }
		if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
	}
	function onVisibility() {
		if (!held && bannerMode !== 'retry') return;
		if (document.hidden) { stopHeartbeat(); return; }
		beat();
		startHeartbeat();
	}
	document.addEventListener('visibilitychange', onVisibility);
	function hideBanner() {
		var b = document.getElementById('es-lock-banner');
		if (b) b.hidden = true;
	}
	function claim() {
		held = true;
		bannerMode = 'takeover';
		setSaveDisabled(false);
		hideBanner();
		var bm = document.getElementById('es-lock-boot-modal');
		if (bm) bm.hidden = true;
		if (bc) { try { bc.postMessage({ type: 'taken', token: myToken }); } catch (e) {} }
		startHeartbeat();
	}
	function beat() {
		if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
		post('es_lock_beat', function (j, failure) {
			if (failure) { lostContact(failure); return; }
			if (j.data && j.data.held === false) { bootedBy(j.data.by, j.data.by_is_me); return; }
			if (!held || bannerMode === 'retry') claim();
		});
	}

	function lostContact(failure) {
		held = false;
		bannerMode = 'retry';
		setSaveDisabled(true);
		var banner = document.getElementById('es-lock-banner');
		if (banner) {
			banner.querySelector('.es-lock-banner-text').textContent = failure === 'nonce'
				? 'This page has expired, or you were logged out. Saving is paused. Copy anything you need, then reload the page.'
				: 'Lost contact with the server, so this asset may no longer be locked to you. Saving is paused until it is back.';
			var tb = banner.querySelector('.es-lock-takeback');
			if (tb) { tb.textContent = 'Try again'; tb.hidden = failure === 'nonce'; }
			banner.hidden = false;
		}
		if (failure !== 'nonce' && !document.hidden) {
			if (retryTimer) clearTimeout(retryTimer);
			retryTimer = setTimeout(beat, RETRY_MS);
		}
	}
	function bootedBy(name, isMe) {
		held = false;
		bannerMode = 'takeover';
		stopHeartbeat();
		setSaveDisabled(true);
		var msg = isMe
			? 'You took over editing this asset in another tab or window'
			: (name || 'Someone') + ' has taken over editing this asset';
		var banner = document.getElementById('es-lock-banner');
		if (banner) {
			banner.querySelector('.es-lock-banner-text').textContent = msg + ' — editing is paused here, your changes can no longer be saved.';
			var tb = banner.querySelector('.es-lock-takeback');
			if (tb) { tb.textContent = 'Take editing back'; tb.hidden = false; }
			banner.hidden = false;
		}
		var bootModal = document.getElementById('es-lock-boot-modal');
		if (bootModal) {
			var bt = bootModal.querySelector('.es-lock-boot-text');
			if (bt) bt.textContent = msg + ', so your changes can no longer be saved. Copy anything you need, then take it back or continue in read-only.';
			bootModal.hidden = false;
		}
	}
	function enterViewOnly(name, isMe) {
		held = false;
		bannerMode = 'takeover';
		stopHeartbeat();
		setSaveDisabled(true);
		var banner = document.getElementById('es-lock-banner');
		if (banner) {
			var who = isMe ? 'You have' : (name || 'Someone') + ' has';
			banner.querySelector('.es-lock-banner-text').textContent = 'View only — ' + who + ' this asset open for editing, so changes here cannot be saved.';
			var tb = banner.querySelector('.es-lock-takeback');
			if (tb) { tb.textContent = 'Take over'; tb.hidden = false; }
			banner.hidden = false;
		}
	}

	var reloadBtn = document.querySelector('.es-lock-reload');
	if (reloadBtn) reloadBtn.addEventListener('click', function () { location.reload(); });
	function takeOver(after) {
		post('es_lock_takeover', function (j, failure) {
			if (failure) { lostContact(failure); return; }
			if (after) after();
			claim();
		});
	}
	var takeBackBtn = document.querySelector('.es-lock-takeback');
	if (takeBackBtn) takeBackBtn.addEventListener('click', function () {
		if (bannerMode === 'retry') { claimOnOpen(); return; }
		takeOver();
	});
	function hideBootModal() {
		var m = document.getElementById('es-lock-boot-modal');
		if (m) m.hidden = true;
	}
	var bootTakeBack = document.querySelector('.es-lock-boot-takeback');
	if (bootTakeBack) bootTakeBack.addEventListener('click', function () {
		hideBootModal();
		takeOver();
	});
	var bootDismiss = document.querySelector('.es-lock-boot-dismiss');
	if (bootDismiss) bootDismiss.addEventListener('click', hideBootModal);

	var formEl = document.querySelector('.es-form-editor');
	if (formEl) formEl.addEventListener('submit', function () { submitting = true; });

	var lockedByOther = !!L.holderToken && L.holderToken !== myToken;
	if (lockedByOther) {
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
				takeOver(function () { modal.hidden = true; });
			});
		}
	} else {
		claimOnOpen();
	}
	function claimOnOpen() {
		post('es_lock_beat', function (j, failure) {
			if (failure) lostContact(failure);
			else if (j.data && j.data.held === false) bootedBy(j.data.by, j.data.by_is_me);
			else claim();
		}, true);
	}

	var heldWhenLeft = false;
	window.addEventListener('pagehide', function () {
		if (!held || submitting) return;
		heldWhenLeft = true;
		held = false;
		stopHeartbeat();
		try {
			var body = new URLSearchParams();
			body.append('action', 'es_lock_release');
			body.append('form_id', L.formId);
			body.append('nonce', L.nonce);
			body.append('token', myToken);
			navigator.sendBeacon(L.ajaxUrl, body);
		} catch (e) {}
	});
	window.addEventListener('pageshow', function (e) {
		if (e.persisted && heldWhenLeft) { heldWhenLeft = false; claimOnOpen(); }
	});
})();

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

(function () {
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('.es-lead-toggle') : null;
		if (!btn) { return; }
		e.preventDefault();

		var panel = document.getElementById(btn.getAttribute('aria-controls'));
		if (!panel) { return; }

		var open = btn.getAttribute('aria-expanded') === 'true';
		btn.setAttribute('aria-expanded', open ? 'false' : 'true');
		panel.hidden = open;
	});
})();

(function () {
	var forms = document.querySelectorAll('form[data-es-leave-guard]');
	if (!forms.length) return;

	function state(form) {
		form.querySelectorAll('.CodeMirror').forEach(function (el) {
			if (el.CodeMirror) { try { el.CodeMirror.save(); } catch (e) {} }
		});
		var parts = [];
		new FormData(form).forEach(function (value, key) {
			if (key === '_wpnonce' || key === '_wp_http_referer') return;
			parts.push(key + '=' + (typeof value === 'string' ? value : ''));
		});
		return parts.sort().join('&');
	}

	var watched = [];
	function arm() {
		forms.forEach(function (form) {
			var entry = { form: form, baseline: state(form), submitting: false };
			form.addEventListener('submit', function () { entry.submitting = true; });
			watched.push(entry);
		});
	}
	if (document.readyState === 'complete') arm(); else window.addEventListener('load', arm);

	window.addEventListener('beforeunload', function (e) {
		for (var i = 0; i < watched.length; i++) {
			var w = watched[i];
			if (!w.submitting && state(w.form) !== w.baseline) {
				e.preventDefault();
				e.returnValue = '';
				return;
			}
		}
	});
})();
