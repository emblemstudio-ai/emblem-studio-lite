<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Shortcode {

	private static bool $assets_enqueued = false;

	private const AUTO_SHELL_TRANSIENT = 'emblem_studio_has_auto_shell';

	public function __construct() {
		add_action( 'init', [ $this, 'register_per_form_shortcodes' ] );
		add_filter( 'the_content', [ $this, 'strip_orphan_shortcodes' ], 12 );
		add_filter( 'widget_text_content', [ $this, 'strip_orphan_shortcodes' ], 12 );
	}

	public function register_per_form_shortcodes(): void {
		if ( is_admin() ) {
			return;
		}
		global $wpdb;
		$t = ES_Install::table( 'forms' );
		$ids = $wpdb->get_col( "SELECT id FROM $t" );
		if ( self::connected() ) {
			$ids = array_merge( (array) $ids, Emblem_Studio_SaaS_Connector::mapped_wp_ids() );
		}
		add_shortcode( 'emblem-studio', [ $this, 'render_asset_shortcode' ] );
		add_shortcode( 'emblem-studio-popup', [ $this, 'render_asset_shortcode' ] );
		foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $raw_id ) {
			$form_id = (int) $raw_id;
			if ( $form_id <= 0 ) {
				continue;
			}
			$inline_cb = function () use ( $form_id ) {
				return $this->render_inline( $form_id );
			};
			add_shortcode( 'es-inline-' . $form_id, $inline_cb );
			add_shortcode( 'emblem-studio-inline-' . $form_id, $inline_cb );

			$popup_cb = function ( $atts, $content = null ) use ( $form_id ) {
				return $this->render_popup_click( $form_id, $content );
			};
			add_shortcode( 'es-popup-' . $form_id, $popup_cb );
			add_shortcode( 'emblem-studio-popup-' . $form_id, $popup_cb );
		}
	}

	public function strip_orphan_shortcodes( $content ) {
		if ( ! is_string( $content ) || strpos( $content, '[e' ) === false ) {
			return $content;
		}
		$content = preg_replace_callback(
			'/\[(es|emblem-studio)-popup-(\d+)\](.*?)\[\/\1-popup-\2\]/s',
			static fn ( $m ) => shortcode_exists( $m[1] . '-popup-' . $m[2] ) ? $m[0] : $m[3],
			$content
		) ?? $content;
		return preg_replace_callback(
			'/\[(es|emblem-studio)-(inline|popup)-(\d+)\s*\/?\]/',
			static fn ( $m ) => shortcode_exists( $m[1] . '-' . $m[2] . '-' . $m[3] ) ? $m[0] : '',
			$content
		) ?? $content;
	}

	public function render_inline( int $id ): string {
		$saas = $this->maybe_saas_slot( $id, 'inline' );
		if ( $saas !== null ) {
			return $saas;
		}
		$form = ES_Form_Repo::find( $id );
		if ( ! $form ) {
			return '';
		}
		if ( ! ES_Form_Repo::is_currently_active( $form ) ) {
			return '';
		}
		if ( ! in_array( 'inline', ES_Form_Repo::types_for( $form ), true ) ) {
			return '';
		}
		return $this->emit_inline( $form );
	}

	public function render_popup_click( int $id, $content ): string {
		$form = ES_Form_Repo::find( $id );
		$label = ( is_string( $content ) && trim( wp_strip_all_tags( $content ) ) !== '' )
			? $content
			: esc_html__( 'Click here', 'emblem-studio' );

		$saas = $this->maybe_saas_slot( $id, 'popup-click', $label );
		if ( $saas !== null ) {
			return $saas;
		}
		if ( ! $form ) {
			return is_string( $content ) ? $content : '';
		}
		if ( ! ES_Form_Repo::is_currently_active( $form ) ) {
			return is_string( $content ) ? $content : '';
		}
		if ( ! in_array( 'popup-click', ES_Form_Repo::types_for( $form ), true ) ) {
			return is_string( $content ) ? $content : '';
		}
		return $this->emit_popup_click( $form, $label );
	}

	private function maybe_saas_slot( int $wp_id, string $shell, string $label = '' ): ?string {
		if ( ! self::connected() ) {
			return null;
		}
		$key = Emblem_Studio_SaaS_Connector::saas_form_key( $wp_id );
		if ( $key === '' ) {
			$saas_id = Emblem_Studio_SaaS_Connector::saas_form_id( $wp_id );
			$key     = $saas_id > 0 ? (string) $saas_id : '';
		}
		if ( $key === '' ) {
			return null;
		}
		return self::saas_slot( $key, $shell, $label );
	}

	private static function connected(): bool {
		return class_exists( 'Emblem_Studio_SaaS_Connector' ) && Emblem_Studio_SaaS_Connector::is_connected();
	}

	private static function saas_slot( string $key, string $shell, string $label = '' ): string {
		if ( $shell === 'popup-click' ) {
			return '<div data-es-form="' . esc_attr( $key ) . '" data-es-shell="popup-click">' . wp_kses_post( $label ) . '</div>';
		}
		return '<div data-es-form="' . esc_attr( $key ) . '" data-es-shell="inline"></div>';
	}

	public function render_asset_shortcode( $atts, $content = null, $tag = 'emblem-studio' ): string {
		$atts  = shortcode_atts( [ 'asset' => '' ], is_array( $atts ) ? $atts : [], (string) $tag );
		$key   = trim( (string) $atts['asset'] );
		$shell = $tag === 'emblem-studio-popup' ? 'popup-click' : 'inline';
		$text  = is_string( $content ) ? $content : '';

		if ( ctype_digit( $key ) ) {
			return $shell === 'popup-click' ? $this->render_popup_click( (int) $key, $content ) : $this->render_inline( (int) $key );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $key ) || ! self::connected() ) {
			return $shell === 'popup-click' ? $text : '';
		}
		$label = trim( wp_strip_all_tags( $text ) ) !== '' ? $text : esc_html__( 'Click here', 'emblem-studio' );
		return self::saas_slot( $key, $shell, $label );
	}

	private function emit_inline( object $form ): string {
		$conn = $this->connection_decision( $form );
		if ( $conn['mode'] === 'hide' ) {
			return '';
		}
		$this->enqueue_assets();
		$id      = (int) $form->id;
		$payload = $this->build_payload( $form );
		$shadow  = ! empty( $form->use_shadow_dom ) ? '1' : '0';
		$track   = ! empty( $form->track_clicks ) ? '1' : '0';
		$buy     = $this->buy_attr( $form );

		$variant = $this->variant_attr( $id );

		return '<div class="esf-host" data-esf-id="' . $id . '" data-esf-type="inline" data-esf-success-mode="' . esc_attr( $form->success_mode ) . '" data-esf-shadow="' . $shadow . '" data-esf-track-clicks="' . $track . '"' . $buy . $variant . $conn['attrs'] . '>'
			. '<script type="application/json" class="esf-data">' . $payload . '</script>'
			. '</div>';
	}

	private function emit_popup_click( object $form, string $content ): string {
		$conn = $this->connection_decision( $form );
		if ( $conn['mode'] === 'hide' ) {
			return '';
		}
		$this->enqueue_assets();
		$id         = (int) $form->id;
		$payload    = $this->build_payload( $form );
		$shadow     = ! empty( $form->use_shadow_dom ) ? '1' : '0';
		$max_width  = max( 100, (int) ( $form->popup_max_width ?? 720 ) );
		$fullscreen = ! empty( $form->popup_fullscreen ) ? '1' : '0';
		$close_theme = ES_Form_Repo::close_theme( $form->popup_close_theme ?? 'light' );
		$track      = ! empty( $form->track_clicks ) ? '1' : '0';
		$buy        = $this->buy_attr( $form );

		$trigger_inner = wp_kses_post( $content );

		$variant = $this->variant_attr( $id );

		$out  = '<a href="#" class="esf-popup-trigger" data-esf-popup-id="' . $id . '" data-esf-id="' . $id . '" data-esf-type="popup-click" role="button"' . $variant . '>';
		$out .= $trigger_inner;
		$out .= '</a>';

		$out .= '<template class="esf-popup-data" data-esf-popup-id="' . $id . '">';
		$out .= '<div class="esf-host esf-popup-host" data-esf-id="' . $id . '" data-esf-type="popup-click" data-esf-success-mode="' . esc_attr( $form->success_mode ) . '" data-esf-shadow="' . $shadow . '" data-esf-max-width="' . $max_width . '" data-esf-fullscreen="' . $fullscreen . '" data-esf-close-theme="' . $close_theme . '" data-esf-track-clicks="' . $track . '"' . $buy . $variant . $conn['attrs'] . '>';
		$out .= '<script type="application/json" class="esf-data">' . $payload . '</script>';
		$out .= '</div>';
		$out .= '</template>';

		return $out;
	}

	public function build_payload( object $form ): string {
		$html = (string) $form->html;
		$css  = (string) $form->css;
		$variant = class_exists( 'ES_AB' ) ? ES_AB::assigned_variant_for_form( (int) $form->id ) : null;
		if ( $variant ) {
			if ( is_string( $variant->html ?? null ) ) {
				$html = (string) $variant->html;
			}
			if ( is_string( $variant->css ?? null ) ) {
				$css = (string) $variant->css;
			}
		}
		if ( class_exists( 'ES_Design_System' ) ) {
			$css = ES_Design_System::compose( $form, $css );
		}
		$payload = [
			'css'  => $css,
			'html' => $html,
		];
		$live = class_exists( 'ES_Live_Values' ) ? ES_Live_Values::resolve_html( $html ) : null;
		if ( $live !== null ) {
			$payload['live'] = (object) $live;
		}
		return (string) wp_json_encode(
			$payload,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);
	}

	public function variant_attr( int $form_id ): string {
		$slot = class_exists( 'ES_AB' ) ? ES_AB::assigned_slot_for_form( $form_id ) : '';
		return $slot === '' ? '' : ' data-esf-variant="' . esc_attr( $slot ) . '"';
	}

	public function buy_attr( object $form ): string {
		$on = ! empty( $form->track_purchases )
			&& class_exists( 'ES_Purchases' )
			&& ES_Purchases::enabled();

		return $on ? ' data-esf-track-purchases="1"' : '';
	}
	public function connection_decision( object $form ): array {
		if ( class_exists( 'ES_Connections' ) ) {
			return ES_Connections::render_decision( $form );
		}

		return [ 'mode' => 'show', 'attrs' => '' ];
	}

	public function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		wp_enqueue_script( 'emblem-studio-frontend', EMBLEM_STUDIO_URL . 'assets/frontend.js', [], EMBLEM_STUDIO_VERSION, true );
		wp_localize_script( 'emblem-studio-frontend', 'EmblemStudio', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'postId'  => get_queried_object_id(),
		] );
		add_filter( 'script_loader_tag', [ $this, 'mark_script_no_delay' ], 10, 2 );
	}

	public function mark_script_no_delay( string $tag, string $handle ): string {
		if ( 'emblem-studio-frontend' !== $handle ) {
			return $tag;
		}

		return str_replace(
			'<script ',
			'<script ' . ES_No_Delay::ATTRS . ' ',
			$tag
		);
	}

	public static function any_form_uses_auto_shell(): bool {
		$cached = get_transient( self::AUTO_SHELL_TRANSIENT );
		if ( $cached !== false ) {
			return $cached === '1';
		}
		global $wpdb;
		$t    = ES_Install::table( 'forms' );
		$rows = $wpdb->get_col(
			"SELECT form_types FROM $t WHERE trashed_at IS NULL AND archived_at IS NULL AND status = 'active' AND form_types IS NOT NULL AND form_types <> ''"
		);
		$auto = ES_Form_Repo::auto_shells();
		$any  = false;
		foreach ( (array) $rows as $json ) {
			$types = json_decode( (string) $json, true );
			if ( is_array( $types ) && array_intersect( $types, $auto ) !== [] ) {
				$any = true;
				break;
			}
		}
		set_transient( self::AUTO_SHELL_TRANSIENT, $any ? '1' : '0', HOUR_IN_SECONDS );
		return $any;
	}

	public static function flush_auto_shell_cache(): void {
		delete_transient( self::AUTO_SHELL_TRANSIENT );
	}
}
