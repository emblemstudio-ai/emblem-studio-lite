<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Preview {

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_render' ] );
	}

	public function maybe_render(): void {
		if ( ! empty( $_GET['es_template_thumb'] ) ) {
			$this->maybe_render_template_thumb();
			return;
		}
		if ( ! empty( $_GET['es_template_preview'] ) ) {
			$this->maybe_render_template_preview();
			return;
		}
		if ( empty( $_GET['es_preview'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview forms.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id = (int) $_GET['es_preview'];
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'es_preview_' . $form_id ) ) {
			wp_die( esc_html__( 'Invalid or expired preview link.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		if ( isset( $_POST['preview_html'] ) ) {
			$form->html = ES_HTML_Sanitizer::sanitize( (string) wp_unslash( $_POST['preview_html'] ) );
		}
		if ( isset( $_POST['preview_css'] ) ) {
			$form->css = ES_HTML_Sanitizer::sanitize_css( (string) wp_unslash( $_POST['preview_css'] ) );
		}
		if ( isset( $_POST['preview_success_mode'] ) ) {
			$mode = sanitize_text_field( wp_unslash( $_POST['preview_success_mode'] ) );
			if ( in_array( $mode, [ 'inline_state', 'redirect' ], true ) ) {
				$form->success_mode = $mode;
			}
		}
		if ( isset( $_POST['preview_success_message'] ) ) {
			$form->success_message = wp_kses_post( wp_unslash( $_POST['preview_success_message'] ) );
		}

		if ( ! empty( $_POST['inspector'] ) ) {
			$this->render_inspector_frame( $form );
			exit;
		}

		if ( ! empty( $_POST['preview_frame'] ) ) {
			$this->render_inspector_frame( $form, true );
			exit;
		}

		$types = ES_Form_Repo::types_for( $form );
		$shell = ! empty( $types ) ? (string) $types[0] : 'inline';
		$auto_trigger = [];
		if ( ! empty( $form->auto_trigger_config ) && is_string( $form->auto_trigger_config ) ) {
			$decoded = json_decode( $form->auto_trigger_config, true );
			if ( is_array( $decoded ) ) {
				$auto_trigger = $decoded;
			}
		}
		$position = self::resolve_preview_position( $shell, $auto_trigger );

		$this->render( $form, $position, $shell );
		exit;
	}

	public static function resolve_preview_position( string $shell, array $auto_trigger = [] ): string {
		switch ( $shell ) {
			case 'ribbon':
				$pos = $auto_trigger['ribbon']['position'] ?? 'top';
				return $pos === 'bottom' ? 'bottom' : 'top';

			case 'slide-in':
				$corner = $auto_trigger['slide-in']['corner'] ?? 'bottom-right';
				return $corner === 'bottom-left' ? 'bottom-left' : 'bottom-right';

			case 'inline':
			case 'popup-click':
			case 'popup-auto':
			default:
				return 'center';
		}
	}

	public static function preview_font_head(): string {
		return '';
	}

	public static function position_css( string $position ): string {
		switch ( $position ) {
			case 'top':
				return 'body{display:block;}.esf-host{width:100%;}';
			case 'bottom':
				return 'body{display:flex;align-items:safe flex-end;justify-content:stretch;min-height:100vh;}.esf-host{width:100%;}';
			case 'bottom-right':
				return 'body{display:flex;align-items:safe flex-end;justify-content:flex-end;padding:20px;min-height:100vh;}';
			case 'bottom-left':
				return 'body{display:flex;align-items:safe flex-end;justify-content:flex-start;padding:20px;min-height:100vh;}';
			case 'center':
			default:
				return 'body{display:flex;align-items:safe center;justify-content:center;padding:24px;min-height:100vh;}.esf-host{max-width:100%;}';
		}
	}

	private function render( object $form, string $position = 'center', string $shell = 'inline' ): void {
		$form_id      = (int) $form->id;
		$form_name    = (string) $form->name;
		$success_mode = (string) $form->success_mode;
		$use_shadow   = ! empty( $form->use_shadow_dom ) ? '1' : '0';
		$close_theme  = ES_Form_Repo::close_theme( $form->popup_close_theme ?? 'light' );

		$close_html = '';
		$close_css  = '';
		if ( $shell === 'ribbon' ) {
			list( $close_html, $close_css ) = self::ribbon_close_assets( $close_theme );
		}

		$preview_css = class_exists( 'ES_Design_System' )
			? ES_Design_System::compose( $form, (string) $form->css )
			: (string) $form->css;
		$payload = wp_json_encode(
			[
				'css'  => $preview_css,
				'html' => (string) $form->html,
				'live' => class_exists( 'ES_Live_Values' ) ? ES_Live_Values::resolve_html( (string) $form->html ) : null,
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);

		$frontend_src = esc_url( EMBLEM_STUDIO_URL . 'assets/frontend.js?v=' . EMBLEM_STUDIO_VERSION );
		$ajax_url     = esc_url( admin_url( 'admin-ajax.php' ) );

		$position_css = self::position_css( $position );

		$font_head = self::preview_font_head();

		ob_start();
		?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php echo $font_head; ?>
<style>html,body{margin:0;padding:0;background:#fff;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}<?php echo $position_css; ?><?php echo $close_css; ?></style>
</head>
<body>
<script>window.EmblemStudio = { ajaxUrl: <?php echo wp_json_encode( $ajax_url ); ?>, postId: 0, preview: true };</script>
<?php if ( $close_html !== '' ) : ?>
<div class="esf-preview-ribbon">
<div class="esf-host" data-esf-id="<?php echo $form_id; ?>" data-esf-success-mode="<?php echo esc_attr( $success_mode ); ?>" data-esf-shadow="<?php echo $use_shadow; ?>">
<script type="application/json" class="esf-data"><?php echo $payload; ?></script>
</div>
<?php echo $close_html; ?>
</div>
<?php else : ?>
<div class="esf-host" data-esf-id="<?php echo $form_id; ?>" data-esf-success-mode="<?php echo esc_attr( $success_mode ); ?>" data-esf-shadow="<?php echo $use_shadow; ?>">
<script type="application/json" class="esf-data"><?php echo $payload; ?></script>
</div>
<?php endif; ?>
<script src="<?php echo $frontend_src; ?>"></script>
</body>
</html>
		<?php
		$iframe_doc = trim( ob_get_clean() );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $form_name ); ?> — <?php esc_html_e( 'Preview', 'emblem-studio' ); ?></title>
	<style>
		html, body { margin: 0; padding: 0; background: #f0f0f1; color: #1d2327; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		.osp-bar { position: sticky; top: 0; z-index: 100; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; padding: 10px 14px; background: #f6f7f7; border-bottom: 1px solid #dcdcde; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
		.osp-bar strong { font-size: 13px; margin-right: auto; color: #1d2327; max-width: 40%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.osp-bar button { padding: 5px 11px; border: 1px solid #c3c4c7; background: #fff; cursor: pointer; border-radius: 3px; font-size: 12px; color: #1d2327; font-family: inherit; }
		.osp-bar button:hover { border-color: #2271b1; }
		.osp-bar button.is-active { background: #2271b1; color: #fff; border-color: #2271b1; }
		.osp-bar input[type="range"] { width: 180px; }
		.osp-bar .osp-w { font-variant-numeric: tabular-nums; min-width: 60px; font-size: 12px; color: #50575e; }
		.osp-stage { padding: 24px 16px 80px; display: flex; justify-content: center; min-height: 80vh; }
		#osp-frame { width: 645px; height: calc(100vh - 120px); border: 1px solid #c3c4c7; background: #fff; transition: width 0.18s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
		#osp-frame.is-fluid { width: 100%; max-width: none; }
		@media (max-width: 600px) {
			.osp-bar strong { display: none; }
			.osp-bar { gap: 0.4rem; padding: 8px 10px; }
		}
	</style>
</head>
<body>
	<div class="osp-bar">
		<strong><?php echo esc_html( $form_name ); ?> · #<?php echo (int) $form_id; ?></strong>
		<button data-w="320" type="button">320</button>
		<button data-w="480" type="button">480</button>
		<button data-w="645" type="button" class="is-active">645</button>
		<button data-w="768" type="button">768</button>
		<button data-w="1024" type="button">1024</button>
		<button data-w="fluid" type="button"><?php esc_html_e( 'Fluid', 'emblem-studio' ); ?></button>
		<input type="range" id="osp-slider" min="280" max="1400" value="645" step="10">
		<span class="osp-w" id="osp-w">645px</span>
	</div>

	<div class="osp-stage">
		<iframe id="osp-frame" title="<?php echo esc_attr( $form_name ); ?>" srcdoc="<?php echo esc_attr( $iframe_doc ); ?>"></iframe>
	</div>

	<script>
	(function () {
		var frame  = document.getElementById('osp-frame');
		var slider = document.getElementById('osp-slider');
		var label  = document.getElementById('osp-w');
		var btns   = document.querySelectorAll('.osp-bar button[data-w]');

		function clearActive() { btns.forEach(function (b) { b.classList.remove('is-active'); }); }
		function setWidth(w, source) {
			if (w === 'fluid') {
				frame.style.width = '';
				frame.classList.add('is-fluid');
				label.textContent = 'Fluid';
				return;
			}
			frame.classList.remove('is-fluid');
			var n = parseInt(w, 10) || 645;
			frame.style.width = n + 'px';
			label.textContent = n + 'px';
			if (source !== 'slider') slider.value = n;
		}

		btns.forEach(function (b) {
			b.addEventListener('click', function () {
				clearActive();
				b.classList.add('is-active');
				setWidth(b.dataset.w, 'btn');
			});
		});
		slider.addEventListener('input', function () {
			clearActive();
			setWidth(slider.value, 'slider');
		});
	})();
	</script>
</body>
</html>
		<?php
	}

	private function render_inspector_frame( object $form, bool $preview = false ): void {
		$form_id      = (int) $form->id;
		$success_mode = (string) $form->success_mode;
		$use_shadow   = ! empty( $form->use_shadow_dom ) ? '1' : '0';
		$close_theme  = ES_Form_Repo::close_theme( $form->popup_close_theme ?? 'light' );

		$types  = ES_Form_Repo::types_for( $form );
		if ( empty( $types ) ) {
			$types = [ 'inline' ];
		}
		$labels = [
			'inline'      => __( 'Inline', 'emblem-studio' ),
			'popup-click' => __( 'Click popup', 'emblem-studio' ),
			'popup-auto'  => __( 'Auto popup', 'emblem-studio' ),
			'ribbon'      => __( 'Ribbon', 'emblem-studio' ),
			'slide-in'    => __( 'Slide-in', 'emblem-studio' ),
		];
		$shells_for_js = [];
		foreach ( $types as $t ) {
			$shells_for_js[] = [ 'key' => $t, 'label' => $labels[ $t ] ?? $t ];
		}

		$requested = isset( $_POST['inspector_shell'] ) ? sanitize_key( (string) wp_unslash( $_POST['inspector_shell'] ) ) : '';
		$shell     = in_array( $requested, $types, true ) ? $requested : (string) $types[0];

		$cfg       = ES_Form_Repo::get_shell_trigger_config( $form, $shell );
		$position  = self::resolve_preview_position( $shell, [ $shell => $cfg ] );
		$corner    = $cfg['corner'] ?? 'bottom-right';
		$is_popup  = in_array( $shell, [ 'popup-click', 'popup-auto' ], true );
		$is_fullscreen = $is_popup && ! empty( $form->popup_fullscreen );
		$has_close = $shell !== 'inline' && ! empty( $cfg['close_button'] );

		$body_bg = ( $is_popup && ! $is_fullscreen && ! $preview ) ? '#0f172a' : ( $preview ? '#ffffff' : '#f1f5f9' );

		$preview_popup_dim = ( $preview && $is_popup && ! $is_fullscreen )
			? 'body{background:rgba(15,17,21,.55);-webkit-backdrop-filter:blur(8px) saturate(120%);backdrop-filter:blur(8px) saturate(120%);}'
			: '';

		$position_css = $is_fullscreen
			? 'body{display:block;}.esf-host{display:block;width:100%;}'
			: self::position_css( $position );

		$close_html = '';
		$close_css  = '';
		if ( $has_close ) {
			list( $close_html, $close_css ) = self::inspector_close_assets( $shell, $close_theme, $corner );
		}

		$entry_css = self::inspector_entry_css( $shell, $position, $corner, $is_fullscreen );

		$max_width = max( 100, (int) ( $form->popup_max_width ?? 720 ) );
		$wrap_css  = self::inspector_shell_wrap_css( $shell, $is_fullscreen, $max_width );

		$inspector_render_css = class_exists( 'ES_Design_System' )
			? ES_Design_System::compose( $form, (string) $form->css )
			: (string) $form->css;
		$payload = wp_json_encode(
			[
				'css'  => $inspector_render_css,
				'html' => (string) $form->html,
				'live' => class_exists( 'ES_Live_Values' ) ? ES_Live_Values::resolve_html( (string) $form->html ) : null,
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);
		$ajax_url      = admin_url( 'admin-ajax.php' );
		$frontend_src  = EMBLEM_STUDIO_URL . 'assets/frontend.js?v=' . EMBLEM_STUDIO_VERSION;
		$inspector_src = EMBLEM_STUDIO_URL . 'assets/inspector-frame.js?v=' . EMBLEM_STUDIO_VERSION;
		$font_head     = self::preview_font_head();

		$host = '<div class="esf-host" data-esf-id="' . $form_id . '" data-esf-type="' . esc_attr( $shell )
			. '" data-esf-success-mode="' . esc_attr( $success_mode ) . '" data-esf-shadow="' . $use_shadow
			. '" data-esf-track-clicks="0">'
			. '<script type="application/json" class="esf-data">' . $payload . '</script>'
			. '</div>';
		?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php echo $font_head; ?>
<style>
html, body { margin: 0; padding: 0; background: <?php echo esc_html( $body_bg ); ?>; color: #1d2327; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; min-height: 100%; box-sizing: border-box; }
<?php echo $position_css; ?><?php echo $wrap_css; ?><?php echo $close_css; ?><?php echo $entry_css; ?><?php echo $preview_popup_dim; ?>
</style>
</head>
<body>
<script>window.EmblemStudio = { ajaxUrl: <?php echo wp_json_encode( $ajax_url ); ?>, postId: 0, preview: true };
window.__esInspectorCss = <?php echo wp_json_encode( (string) $form->css ); ?>;
window.__esInspectorDs = <?php echo wp_json_encode( ( class_exists( 'ES_Design_System' ) && ! empty( $form->use_design_system ) ) ? ES_Design_System::get_css() : '' ); ?>;
window.__esInspectorShells = <?php echo wp_json_encode( $shells_for_js ); ?>;
window.__esInspectorShell = <?php echo wp_json_encode( $shell ); ?>;</script>
<div class="esf-inspect-shell"><?php echo $host; ?><?php echo $close_html; ?></div>
<script src="<?php echo esc_url( $frontend_src ); ?>"></script>
<?php if ( $is_fullscreen && $has_close ) : ?><script><?php echo self::inspector_fullscreen_close_js(); // phpcs:ignore WordPress.Security.EscapeOutput ?></script><?php endif; ?>
<?php if ( $preview ) : ?>
<script><?php echo self::preview_replay_js(); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
<script>
// Tell the parent which shell/position rendered, so it can hint where an
// easily-missed shell (e.g. a ribbon pinned to the bottom) appears.
try { window.parent.postMessage({ type: 'es-inspector:shell-info', shell: <?php echo wp_json_encode( $shell ); ?>, position: <?php echo wp_json_encode( $position ); ?> }, '*'); } catch ( e ) {}
</script>
<?php if ( $shell === 'inline' ) : ?>
<style>body{padding-top:50px;padding-bottom:50px;}</style>
<?php endif; ?>
<script>
// Report the asset height so the Design tab can grow the preview iframe (520px
// floor + ~150px above/below a taller asset). Ribbon is a full-width edge bar
// that never needs more room, and fullscreen popups fill the frame — both report
// 0 and the parent keeps the minimum. Slide-in DOES grow: its .esf-inspect-shell
// wrapper is position:relative + sized to the form here (unlike the live front
// end's position:fixed corner card), so it measures and grows exactly like a
// popup. Popups cap at 90vh, so measure scrollHeight (natural content height).
(function () {
	var SHELL = <?php echo wp_json_encode( $shell ); ?>;
	var noGrow = <?php echo ( $is_fullscreen || $shell === 'ribbon' ) ? 'true' : 'false'; ?>;
	var ro = null, target = null, lastH = -1;
	function findTarget() { return document.querySelector('.esf-inspect-shell') || document.querySelector('.esf-host[data-esf-id]'); }
	function report() {
		if (noGrow) { if (lastH !== 0) { lastH = 0; try { window.parent.postMessage({ type: 'es-inspector:size', shell: SHELL, height: 0 }, '*'); } catch (e) {} } return; }
		var el = findTarget(); if (!el) return;
		if (el !== target) { target = el; if (ro) ro.disconnect(); if (window.ResizeObserver) { ro = new ResizeObserver(report); ro.observe(el); } }
		var h = Math.ceil(Math.max(el.getBoundingClientRect().height, el.scrollHeight || 0));
		if (h > 0 && h !== lastH) { lastH = h; try { window.parent.postMessage({ type: 'es-inspector:size', shell: SHELL, height: h }, '*'); } catch (e) {} }
	}
	try { new MutationObserver(report).observe(document.documentElement, { childList: true, subtree: true }); } catch (e) {}
	report();
	window.addEventListener('load', report);
	setTimeout(report, 200);
	setTimeout(report, 800);
})();
</script>
<script>
// The preview's close (×) is inert chrome — clicking it shouldn't dismiss the
// asset (that would leave an empty previewer). Flash a quick hint instead.
(function () {
	function hint(anchorEl) {
		var id = 'esf-preview-close-hint', el = document.getElementById(id);
		if (!el) {
			el = document.createElement('div');
			el.id = id;
			el.textContent = 'Closes this asset on your live page';
			el.style.cssText = 'position:fixed;z-index:2147483647;background:rgba(15,23,42,.92);color:#fff;'
				+ 'font:600 12px/1.4 Montserrat,system-ui,sans-serif;padding:6px 10px;border-radius:8px;'
				+ 'box-shadow:0 6px 20px rgba(0,0,0,.25);pointer-events:none;opacity:0;transition:opacity .12s;white-space:nowrap;';
			document.body.appendChild(el);
		}
		// Float just below the close button, right-aligned to it.
		var r = anchorEl && anchorEl.getBoundingClientRect ? anchorEl.getBoundingClientRect() : null;
		if (r && r.width) {
			el.style.top = (r.bottom + 8) + 'px';
			el.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
			el.style.left = 'auto'; el.style.bottom = 'auto'; el.style.transform = 'none';
		} else {
			el.style.top = 'auto'; el.style.bottom = '18px'; el.style.left = '50%'; el.style.right = 'auto'; el.style.transform = 'translateX(-50%)';
		}
		clearTimeout(el._t);
		el.style.opacity = '1';
		el._t = setTimeout(function () { el.style.opacity = '0'; }, 1400);
	}
	document.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target.closest('.esf-inspect-close, .esf-ribbon-close') : null;
		if (!t) return;
		e.preventDefault();
		hint(t);
	}, true);
})();
</script>
<?php else : ?>
<script src="<?php echo esc_url( $inspector_src ); ?>"></script>
<?php endif; ?>
</body>
</html>
		<?php
	}

	private static function preview_replay_js(): string {
		return <<<'JS'
(function () {
	function host() { return document.querySelector('.esf-host[data-esf-id]'); }
	function findForm() {
		var h = host();
		if (!h) return null;
		return (h.shadowRoot || h).querySelector('form');
	}
	// Undo a sandbox-submit success state so Replay returns to the initial form
	// (un-hide the form + any branches showInlineThankYou hid, re-hide the
	// thank-you). The honeypot stays off-screen via its own positioning.
	function resetSuccess() {
		var h = host();
		if (!h) return;
		var root = h.shadowRoot || h;
		var form = root.querySelector('form');
		if (form) {
			form.style.display = '';
			for (var i = 0; i < form.children.length; i++) {
				var c = form.children[i];
				if (c.style && c.style.display === 'none') c.style.display = '';
			}
		}
		var ty = root.querySelector('[data-esf-thankyou], [data-esf-success], [data-osf-thankyou], [data-osf-success]');
		if (ty) { ty.hidden = true; ty.style.display = ''; }
	}
	// Restart animations by briefly killing them then forcing a reflow. With
	// hostOnly we restart just the shell's entrance on .esf-host (used on load,
	// once the form has mounted); otherwise the asset's own CSS animations too.
	function restart(hostOnly) {
		var h = host();
		if (!h) return;
		var nodes = [];
		// The shell entrance lives on the wrapper — reset that first.
		var shell = document.querySelector('.esf-inspect-shell');
		if (shell) nodes.push(shell);
		nodes.push(h);
		if (!hostOnly) {
			var els = (h.shadowRoot || h).querySelectorAll('*');
			for (var i = 0; i < els.length; i++) nodes.push(els[i]);
		}
		for (var j = 0; j < nodes.length; j++) nodes[j].style.animation = 'none';
		void document.body.offsetWidth;
		for (var k = 0; k < nodes.length; k++) nodes[k].style.animation = '';
	}
	// Tell the parent whether the asset declares any CSS animation, so it can
	// disable Replay (with an explanatory tooltip) when there's nothing to run.
	function reportAnimatable() {
		var h = host();
		var nodes = [];
		var shell = document.querySelector('.esf-inspect-shell');
		if (shell) nodes.push(shell);
		if (h) {
			nodes.push(h);
			var els = (h.shadowRoot || h).querySelectorAll('*');
			for (var i = 0; i < els.length; i++) nodes.push(els[i]);
		}
		var animatable = false;
		for (var j = 0; j < nodes.length; j++) {
			var name = getComputedStyle(nodes[j]).animationName;
			if (name && name !== 'none') { animatable = true; break; }
		}
		try { window.parent.postMessage({ type: 'es-inspector:animatable', animatable: animatable }, '*'); } catch (e) {}
	}
	window.addEventListener('message', function (e) {
		if (e.source !== window.parent) return;
		var d = e.data || {};
		// Preview testing toggles: enforce required fields / submit for real.
		// We stash the flags on window.EmblemStudio so frontend.js's submit
		// handler (and any later re-mount) reads the current mode, and flip the
		// form's native validation on/off to match.
		if (d && d.type === 'es-inspector:preview-config') {
			var es = window.EmblemStudio || (window.EmblemStudio = {});
			es.previewValidate = !!d.validate;
			es.previewSend = !!d.send;
			var form = findForm();
			if (form) form.noValidate = !(es.previewValidate || es.previewSend);
			return;
		}
		if (!d || d.type !== 'es-inspector:replay') return;
		resetSuccess();
		restart(false);
	});
	// The shell's entrance CSS animation runs on the .esf-host as soon as the
	// page paints — which is BEFORE frontend.js mounts the form into it, so the
	// empty host animates and the visible content just pops in. Re-trigger the
	// entrance once the form is in place so the user actually sees it animate.
	window.addEventListener('load', function () {
		requestAnimationFrame(function () { restart(true); reportAnimatable(); });
	});
})();
JS;
	}

	private static function inspector_entry_css( string $shell, string $position, string $corner, bool $is_fullscreen ): string {
		if ( $shell === 'ribbon' ) {
			$from = $position === 'bottom' ? '110%' : '-110%';
			return '@keyframes esf-inspect-in{from{transform:translateY(' . $from . ');opacity:0}to{transform:none;opacity:1}}'
				. '.esf-inspect-shell{animation:esf-inspect-in .45s cubic-bezier(.2,.7,.2,1) both;}body{overflow:hidden;}';
		}
		if ( $shell === 'slide-in' ) {
			$from = $corner === 'bottom-left' ? '-110%' : '110%';
			return '@keyframes esf-inspect-in{from{transform:translateX(' . $from . ');opacity:0}to{transform:none;opacity:1}}'
				. '.esf-inspect-shell{animation:esf-inspect-in .5s cubic-bezier(.2,.7,.2,1) both;}body{overflow-x:hidden;}';
		}
		if ( in_array( $shell, [ 'popup-click', 'popup-auto' ], true ) && ! $is_fullscreen ) {
			return '@keyframes esf-inspect-in{from{opacity:0;transform:scale(.96) translateY(8px)}to{opacity:1;transform:none}}'
				. '.esf-inspect-shell{animation:esf-inspect-in .35s ease-out both;}';
		}
		return '';
	}

	private static function inspector_shell_wrap_css( string $shell, bool $is_fullscreen, int $max_width = 720 ): string {
		if ( $is_fullscreen ) {
			return '.esf-inspect-shell{position:relative!important;display:flex!important;flex-direction:column!important;justify-content:safe center!important;width:100%!important;min-height:100vh!important;}';
		}
		if ( $shell === 'ribbon' || $shell === 'inline' ) {
			return '.esf-inspect-shell{position:relative!important;width:100%!important;}';
		}
		$mw = max( 100, $max_width );
		if ( $shell === 'slide-in' ) {
			return '.esf-inspect-shell{position:relative!important;display:inline-block!important;max-width:min(92%,' . $mw . 'px)!important;}';
		}
		return '.esf-inspect-shell{position:relative!important;display:block!important;max-width:min(92%,' . $mw . 'px)!important;min-width:min(320px,' . $mw . 'px,92%)!important;}';
	}

	private static function auto_close_css( string $sel ): string {
		$cross  = "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 26 26'><path d='M9.5 9.5l7 7M16.5 9.5l-7 7' stroke='rgba(0,0,0,0.7)' stroke-width='1.6' stroke-linecap='round' fill='none'/></svg>\")";
		$ring   = 'radial-gradient(circle at 50% 50%,transparent 0 11.6px,rgba(0,0,0,.15) 11.9px 12.5px,transparent 12.8px)';
		$fill   = 'radial-gradient(circle at 50% 50%,rgba(0,0,0,.10) 0 11.8px,transparent 12.1px)';
		$mask   = $cross . ',' . $ring . ',' . $fill;
		$sizes  = '26px 26px,26px 26px,26px 26px';
		$filter = static function ( int $c ): string {
			return 'blur(3px) grayscale(1) invert(1) contrast(' . $c . ')';
		};
		return '@supports (((-webkit-mask-image:none) or (mask-image:none)) and ((-webkit-backdrop-filter:invert(1)) or (backdrop-filter:invert(1)))){'
			. $sel . '{background:transparent!important;border:0!important;box-shadow:none!important;'
			. '-webkit-backdrop-filter:' . $filter( 3 ) . '!important;backdrop-filter:' . $filter( 3 ) . '!important;'
			. '-webkit-mask-image:' . $mask . '!important;mask-image:' . $mask . '!important;'
			. '-webkit-mask-size:' . $sizes . '!important;mask-size:' . $sizes . '!important;'
			. '-webkit-mask-repeat:no-repeat!important;mask-repeat:no-repeat!important;'
			. '-webkit-mask-position:center,center,center!important;mask-position:center,center,center!important;}'
			. $sel . ' svg{display:none!important;}'
			. $sel . ':hover{background:transparent!important;'
			. '-webkit-backdrop-filter:' . $filter( 5 ) . '!important;backdrop-filter:' . $filter( 5 ) . '!important;}}';
	}

	private static function inspector_fullscreen_close_js(): string {
		return '(function(){'
			. 'var shell=document.querySelector(".esf-inspect-shell");'
			. 'var btn=document.querySelector(".esf-inspect-close");'
			. 'if(!shell||!btn||!window.ResizeObserver)return;'
			. 'function els(){var h=shell.querySelector(".esf-host");if(!h)return[];var r=h.shadowRoot||h;'
			. 'return Array.prototype.filter.call(r.children,function(e){return e.tagName!=="STYLE"&&e.tagName!=="SCRIPT"&&e.tagName!=="TEMPLATE";});}'
			. 'var ro=new ResizeObserver(place);'
			. 'function place(){var box=null;els().forEach(function(e){ro.observe(e);var r=e.getBoundingClientRect();'
			. 'if(!r.width&&!r.height)return;'
			. 'box=box?{top:Math.min(box.top,r.top),right:Math.max(box.right,r.right)}:{top:r.top,right:r.right};});'
			. 'if(!box)return;var s=shell.getBoundingClientRect();'
			. 'btn.style.setProperty("top",(box.top-s.top+12)+"px","important");'
			. 'btn.style.setProperty("left",(box.right-s.left-12-btn.offsetWidth)+"px","important");'
			. 'btn.style.setProperty("right","auto","important");}'
			. 'ro.observe(shell);'
			. 'new MutationObserver(place).observe(shell,{childList:true,subtree:true});'
			. 'place();})();';
	}

	private static function inspector_close_assets( string $shell, string $close_theme, string $corner = 'bottom-right' ): array {
		$html = '<button type="button" class="esf-inspect-close" aria-label="Close" tabindex="-1">'
			. '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"/></svg>'
			. '</button>';
		$color_vars = $close_theme === 'dark'
			? '--esf-close-fg:rgba(0,0,0,0.55);--esf-close-bg:rgba(0,0,0,0.06);--esf-close-border:rgba(0,0,0,0.12);--esf-close-hover-fg:#1d2327;--esf-close-hover-bg:rgba(0,0,0,0.10);'
			: '--esf-close-fg:rgba(255,255,255,0.7);--esf-close-bg:rgba(255,255,255,0.10);--esf-close-border:rgba(255,255,255,0.15);--esf-close-hover-fg:#fff;--esf-close-hover-bg:rgba(255,255,255,0.18);';

		if ( $shell === 'slide-in' ) {
			$pos = $corner === 'bottom-left'
				? 'top:12px!important;right:12px!important;left:auto!important;'
				: 'top:12px!important;left:12px!important;right:auto!important;';
		} else {
			$pos = 'top:12px!important;right:12px!important;';
		}

		$css = ':root{' . $color_vars . '}'
			. '.esf-inspect-close{all:revert!important;position:absolute!important;z-index:99999!important;' . $pos
			. 'width:26px!important;height:26px!important;min-width:0!important;min-height:0!important;padding:0!important;margin:0!important;'
			. 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
			. 'border:1px solid var(--esf-close-border)!important;border-radius:50%!important;'
			. 'background:var(--esf-close-bg)!important;color:var(--esf-close-fg)!important;cursor:pointer!important;'
			. '-webkit-backdrop-filter:blur(6px)!important;backdrop-filter:blur(6px)!important;'
			. 'text-shadow:none!important;text-decoration:none!important;letter-spacing:0!important;opacity:1!important;'
			. 'transition:background .15s, color .15s, transform .25s!important;}'
			. '.esf-inspect-close:hover{background:var(--esf-close-hover-bg)!important;color:var(--esf-close-hover-fg)!important;transform:rotate(90deg)!important;}';
		if ( $close_theme === 'auto' ) {
			$css .= self::auto_close_css( '.esf-inspect-close' );
		}
		return [ $html, $css ];
	}

	private static function ribbon_close_assets( string $close_theme ): array {
		$html = '<button type="button" class="esf-ribbon-close" aria-label="Close" tabindex="-1">'
			. '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"/></svg>'
			. '</button>';
		$color_vars = $close_theme === 'dark'
			? '--esf-close-fg:rgba(0,0,0,0.55);--esf-close-bg:rgba(0,0,0,0.06);--esf-close-border:rgba(0,0,0,0.12);--esf-close-hover-fg:#1d2327;--esf-close-hover-bg:rgba(0,0,0,0.10);'
			: '--esf-close-fg:rgba(255,255,255,0.7);--esf-close-bg:rgba(255,255,255,0.10);--esf-close-border:rgba(255,255,255,0.15);--esf-close-hover-fg:#fff;--esf-close-hover-bg:rgba(255,255,255,0.18);';
		$css = ':root{' . $color_vars . '}'
			. '.esf-preview-ribbon{position:relative!important;width:100%!important;}'
			. '.esf-ribbon-close{all:revert!important;position:absolute!important;top:12px!important;right:12px!important;z-index:99999!important;'
			. 'width:26px!important;height:26px!important;min-width:0!important;min-height:0!important;padding:0!important;margin:0!important;'
			. 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
			. 'border:1px solid var(--esf-close-border)!important;border-radius:50%!important;'
			. 'background:var(--esf-close-bg)!important;color:var(--esf-close-fg)!important;cursor:pointer!important;'
			. '-webkit-backdrop-filter:blur(6px)!important;backdrop-filter:blur(6px)!important;'
			. 'text-shadow:none!important;text-decoration:none!important;letter-spacing:0!important;opacity:1!important;'
			. 'transition:background .15s, color .15s, transform .25s!important;}'
			. '.esf-ribbon-close:hover{background:var(--esf-close-hover-bg)!important;color:var(--esf-close-hover-fg)!important;transform:rotate(90deg)!important;}';
		if ( $close_theme === 'auto' ) {
			$css .= self::auto_close_css( '.esf-ribbon-close' );
		}
		return [ $html, $css ];
	}

	public static function url_for( int $form_id ): string {
		return wp_nonce_url(
			add_query_arg( 'es_preview', $form_id, home_url( '/' ) ),
			'es_preview_' . $form_id
		);
	}

	public static function template_thumb_url( string $bundle_slug, string $form_slug ): string {
		$pair = sanitize_key( $bundle_slug ) . '/' . sanitize_key( $form_slug );
		return wp_nonce_url(
			add_query_arg( 'es_template_thumb', $pair, home_url( '/' ) ),
			'es_template_thumb_' . $pair
		);
	}

	public static function template_preview_url( string $bundle_slug, string $form_slug ): string {
		$pair = sanitize_key( $bundle_slug ) . '/' . sanitize_key( $form_slug );
		return wp_nonce_url(
			add_query_arg( 'es_template_preview', $pair, home_url( '/' ) ),
			'es_template_preview_' . $pair
		);
	}

	private function maybe_render_template_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview templates.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$pair  = isset( $_GET['es_template_preview'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['es_template_preview'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( $pair === '' || ! wp_verify_nonce( $nonce, 'es_template_preview_' . $pair ) ) {
			wp_die( esc_html__( 'Invalid or expired template-preview link.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$parts = explode( '/', $pair, 2 );
		if ( count( $parts ) !== 2 ) {
			wp_die( esc_html__( 'Malformed template id.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		$template = ES_Templates::load_form( $parts[0], $parts[1] );
		$bundle   = ES_Templates::bundle( $parts[0] );
		if ( ! $template ) {
			wp_die( esc_html__( 'Template not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		$display_name = ! empty( $bundle['name'] )
			? $bundle['name'] . ' — ' . $template['name']
			: $template['name'];
		$form = (object) [
			'id'              => 0,
			'name'            => (string) $display_name,
			'html'            => (string) $template['html'],
			'css'             => (string) $template['css'],
			'success_mode'    => (string) ( $template['success_mode'] ?? 'inline_state' ),
			'success_message' => (string) ( $template['success_message'] ?? '' ),
			'use_shadow_dom'  => ! empty( $template['use_shadow_dom'] ) ? 1 : 0,
		];
		$shell        = (string) ( $template['shell'] ?? 'inline' );
		$auto_trigger = is_array( $template['auto_trigger_config'] ?? null ) ? $template['auto_trigger_config'] : [];
		$position     = self::resolve_preview_position( $shell, $auto_trigger );
		$this->render( $form, $position, $shell );
		exit;
	}

	private function maybe_render_template_thumb(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview templates.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$pair  = isset( $_GET['es_template_thumb'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['es_template_thumb'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( $pair === '' || ! wp_verify_nonce( $nonce, 'es_template_thumb_' . $pair ) ) {
			wp_die( esc_html__( 'Invalid or expired template-thumbnail link.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$parts = explode( '/', $pair, 2 );
		if ( count( $parts ) !== 2 ) {
			wp_die( esc_html__( 'Malformed template id.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		$form = ES_Templates::load_form( $parts[0], $parts[1] );
		if ( ! $form ) {
			wp_die( esc_html__( 'Template not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		$payload = wp_json_encode(
			[ 'css' => (string) $form['css'], 'html' => (string) $form['html'] ],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);
		$ajax_url     = admin_url( 'admin-ajax.php' );
		$frontend_src = EMBLEM_STUDIO_URL . 'assets/frontend.js?v=' . EMBLEM_STUDIO_VERSION;
		$use_shadow   = ! empty( $form['use_shadow_dom'] ) ? '1' : '0';
		$shell        = isset( $form['shell'] ) ? (string) $form['shell'] : 'inline';
		$auto_trigger = is_array( $form['auto_trigger_config'] ?? null ) ? $form['auto_trigger_config'] : [];
		$position     = self::resolve_preview_position( $shell, $auto_trigger );
		$position_css = self::position_css( $position );
		$font_head = self::preview_font_head();
		?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php echo $font_head; ?>
<style>
html, body { margin: 0; padding: 0; background: #f1f5f9; color: #1d2327; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; min-height: 100%; box-sizing: border-box; }
<?php echo $position_css; ?>
</style>
</head>
<body>
<script>window.EmblemStudio = { ajaxUrl: <?php echo wp_json_encode( $ajax_url ); ?>, postId: 0, preview: true };</script>
<div class="esf-host" data-esf-id="0" data-esf-type="<?php echo esc_attr( $shell ); ?>" data-esf-success-mode="<?php echo esc_attr( $form['success_mode'] ?? 'inline_state' ); ?>" data-esf-shadow="<?php echo $use_shadow; ?>" data-esf-track-clicks="0">
<script type="application/json" class="esf-data"><?php echo $payload; ?></script>
</div>
<script src="<?php echo esc_url( $frontend_src ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}
}
