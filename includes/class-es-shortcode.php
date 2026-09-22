<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers per-form, per-shell shortcodes:
 *   [es-inline-N]                                              (single tag)
 *   [es-popup-N]click target[/es-popup-N]                       (enclosing)
 *
 * Legacy aliases [emblem-studio-inline-N] and [emblem-studio-popup-N] are
 * registered alongside so existing content keeps working. The admin UI only
 * shows the short `es-*` form.
 *
 * And one by public code, which is what the web app's dashboard offers
 * (SER-1235), because it works before this site has a local copy:
 *   [emblem-studio asset="esf_…"]
 *   [emblem-studio-popup asset="esf_…"]click target[/emblem-studio-popup]
 *
 * The popup-auto shell has NO shortcode — instead it's injected into
 * `wp_footer` on every front-end page for any form that has popup-auto
 * enabled. The JS in frontend.js reads the per-form trigger config from
 * the bootstrap element and opens the modal when a trigger fires.
 *
 * Both shortcodes are registered for every asset. A shell the asset has
 * switched off, or an asset that is inactive, trashed or archived, renders
 * nothing (a click popup keeps its link text) — "strict mode". Registering
 * only the live ones printed the others as raw code (item 1).
 *
 * Forms are mounted into a Shadow DOM by frontend.js (see assets/frontend.js).
 */
class ES_Shortcode {

	private static bool $assets_enqueued = false;

	/**
	 * Cached "does any active form use an auto-injected shell?" flag —
	 * lets inject_auto_popups() skip its per-page form scan entirely on
	 * inline-only sites. Flushed by ES_Form_Repo on every form mutation.
	 */
	private const AUTO_SHELL_TRANSIENT = 'emblem_studio_has_auto_shell';

	public function __construct() {
		add_action( 'init', [ $this, 'register_per_form_shortcodes' ] );
		// After do_shortcode (priority 11): whatever is left belongs to an asset
		// that no longer exists.
		add_filter( 'the_content', [ $this, 'strip_orphan_shortcodes' ], 12 );
		add_filter( 'widget_text_content', [ $this, 'strip_orphan_shortcodes' ], 12 );
		// Auto-injected shells (popup-auto / ribbon / slide-in) are account-gated
		// and live in ES_Auto_Shells, which registers its own wp_footer hook when
		// the capability is on (and is absent entirely from the Lite build).
	}

	public function register_per_form_shortcodes(): void {
		if ( is_admin() ) {
			return;
		}
		global $wpdb;
		$t = ES_Install::table( 'forms' );
		// EVERY asset's shortcodes are registered, trashed, archived and with the
		// shell switched off included. The render methods already show nothing
		// for those (a click popup keeps its link text, on purpose). Registering
		// only the live ones left the rest as raw "[es-inline-12]" on the page,
		// because to WordPress they were not shortcodes at all (item 1 of
		// Catalina's Lite test pass).
		$ids = $wpdb->get_col( "SELECT id FROM $t" );
		// On a connected site, also every id Emblem Studio knows this site by,
		// in case a page carries one whose local copy has not synced down yet.
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
			add_shortcode( 'emblem-studio-inline-' . $form_id, $inline_cb ); // legacy alias

			$popup_cb = function ( $atts, $content = null ) use ( $form_id ) {
				return $this->render_popup_click( $form_id, $content );
			};
			add_shortcode( 'es-popup-' . $form_id, $popup_cb );
			add_shortcode( 'emblem-studio-popup-' . $form_id, $popup_cb ); // legacy alias
		}
	}

	/**
	 * Shortcodes for an asset deleted for good. Its id is gone, so there is
	 * nothing to register and WordPress would print the raw code. An inline
	 * asset leaves nothing; a click popup leaves its link text, the same as an
	 * inactive one.
	 */
	public function strip_orphan_shortcodes( $content ) {
		if ( ! is_string( $content ) || strpos( $content, '[e' ) === false ) {
			return $content;
		}
		// A code whose shortcode still exists was written on purpose, escaped as
		// [[es-inline-5]] to show the code itself. Only a deleted asset's goes.
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
		// Connected to a free account: render the SaaS version (live dashboard
		// edits + SaaS stats/leads). The SaaS decides active/inactive per request.
		// Asked before the local copy, which may not have synced down yet.
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
		// Defensive: ensure the form is still enabled for inline.
		if ( ! in_array( 'inline', ES_Form_Repo::types_for( $form ), true ) ) {
			return '';
		}
		return $this->emit_inline( $form );
	}

	public function render_popup_click( int $id, $content ): string {
		$form = ES_Form_Repo::find( $id );
		// Default trigger text if the author left the shortcode empty.
		$label = ( is_string( $content ) && trim( wp_strip_all_tags( $content ) ) !== '' )
			? $content
			: esc_html__( 'Click here', 'emblem-studio' );

		// Connected: render the SaaS popup (the trigger content becomes its label).
		// Asked before the local copy, which may not have synced down yet.
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

	/**
	 * When the site is connected to a free Emblem Studio account, render the form
	 * from the SaaS instead of locally: emit a [data-es-form] slot that the SaaS
	 * embed.js fills (same host markup, but design + events + leads flow through
	 * the SaaS, so dashboard edits go live). Returns null when not connected or the
	 * form isn't mapped yet — the caller then falls back to local rendering.
	 *
	 * Only present in the Lite build (the connector ships there); the Full plugin
	 * has no connector class, so this is always null and rendering is unchanged.
	 */
	private function maybe_saas_slot( int $wp_id, string $shell, string $label = '' ): ?string {
		if ( ! self::connected() ) {
			return null;
		}
		// The asset's public code, never the SaaS row id (SER-1235). Public
		// pages stopped answering to the row id (SER-1128 stage 4), and they
		// stopped for exactly the sites whose connector reported itself current,
		// so every shortcode on a connected site rendered nothing. The row id is
		// kept only for a SaaS too old to send codes.
		$key = Emblem_Studio_SaaS_Connector::saas_form_key( $wp_id );
		if ( $key === '' ) {
			$saas_id = Emblem_Studio_SaaS_Connector::saas_form_id( $wp_id );
			$key     = $saas_id > 0 ? (string) $saas_id : '';
		}
		if ( $key === '' ) {
			return null; // not mirrored/mapped yet — fall back to local
		}
		return self::saas_slot( $key, $shell, $label );
	}

	private static function connected(): bool {
		return class_exists( 'Emblem_Studio_SaaS_Connector' ) && Emblem_Studio_SaaS_Connector::is_connected();
	}

	/** The placeholder the SaaS embed script fills in, for one asset and shell. */
	private static function saas_slot( string $key, string $shell, string $label = '' ): string {
		if ( $shell === 'popup-click' ) {
			return '<div data-es-form="' . esc_attr( $key ) . '" data-es-shell="popup-click">' . wp_kses_post( $label ) . '</div>';
		}
		return '<div data-es-form="' . esc_attr( $key ) . '" data-es-shell="inline"></div>';
	}

	/**
	 * [emblem-studio asset="esf_…"] and
	 * [emblem-studio-popup asset="esf_…"]Link text[/emblem-studio-popup]
	 *
	 * Keyed by the asset's public code, the one the dashboard shows (SER-1235),
	 * so it works the moment the asset exists in Emblem Studio: no waiting for
	 * this site to sync a local copy and number it. A number instead of a code
	 * means this site's own asset, the same as [es-inline-N].
	 *
	 * Two names, not one with a shell="" switch. WordPress pairs a lone
	 * [emblem-studio] with the next [/emblem-studio] it finds, so an inline one
	 * above a popup one swallowed the popup and everything between them. That
	 * is why the old pair was es-inline and es-popup too.
	 *
	 * Without a connection there is nothing to fetch a code from, so an inline
	 * asset shows nothing and a click popup keeps its link text.
	 *
	 * @param array<string, string>|string $atts
	 */
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

	/**
	 * Emit the host + JSON payload for inline rendering.
	 */
	private function emit_inline( object $form ): string {
		// Connections: an already-subscribed visitor either hides this form or
		// sees its [data-esf-subscribed] block instead.
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

	/**
	 * Emit a click-popup: a trigger element wrapping the author's content, plus a
	 * <template> carrying the popup's host payload. JS attaches a click handler
	 * to the trigger and clones the template into a modal overlay on demand.
	 *
	 * Multiple invocations of the same form on one page each render their own
	 * trigger; the template is rendered each time too (harmless redundancy).
	 */
	private function emit_popup_click( object $form, string $content ): string {
		$conn = $this->connection_decision( $form );
		if ( $conn['mode'] === 'hide' ) {
			return ''; // already subscribed → drop the trigger entirely
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

		// Preserve any allowed HTML inside the wrapped content (links, spans, imgs).
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
		// If a test is running and the visitor is assigned to a live slot,
		// swap in that variant's html/css. Otherwise the form-level snapshot
		// stays the production version — single-version mode.
		$variant = class_exists( 'ES_AB' ) ? ES_AB::assigned_variant_for_form( (int) $form->id ) : null;
		if ( $variant ) {
			if ( is_string( $variant->html ?? null ) ) {
				$html = (string) $variant->html;
			}
			if ( is_string( $variant->css ?? null ) ) {
				$css = (string) $variant->css;
			}
		}
		// Prepend the site Design System (shared CSS), unless the form opts out.
		// This is the single choke point — every shell + the auto-injected shells
		// route their CSS through here. (class_exists guard: absent in Lite.)
		if ( class_exists( 'ES_Design_System' ) ) {
			$css = ES_Design_System::compose( $form, $css );
		}
		$payload = [
			'css'  => $css,
			'html' => $html,
		];
		// Live values (SER-1352): the text of every price or discount this HTML shows
		// that is safe to show now, from the chosen variant's own HTML. Absent for an
		// asset with no markers. frontend.js also asks for fresher text once the page
		// loads, because this page may be served from a cache.
		$live = class_exists( 'ES_Live_Values' ) ? ES_Live_Values::resolve_html( $html ) : null;
		if ( $live !== null ) {
			$payload['live'] = (object) $live;
		}
		return (string) wp_json_encode(
			$payload,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * The variant data attribute fragment to splice into a host element.
	 * Returns ' data-esf-variant="A"' (note leading space) when the visitor
	 * is assigned to slot A or B; empty string otherwise. Concatenated into
	 * the existing inline attribute strings — keeps the markup byte-identical
	 * to today when no test is running.
	 */
	public function variant_attr( int $form_id ): string {
		$slot = class_exists( 'ES_AB' ) ? ES_AB::assigned_slot_for_form( $form_id ) : '';
		return $slot === '' ? '' : ' data-esf-variant="' . esc_attr( $slot ) . '"';
	}

	/**
	 * Does this asset ask for purchases after a click to be measured? (SER-1348)
	 *
	 * This attribute is the only thing that turns it on. Every other asset on the
	 * page is left alone, and a site that has not switched the feature on sends the
	 * attribute for nobody.
	 */
	public function buy_attr( object $form ): string {
		$on = ! empty( $form->track_purchases )
			&& class_exists( 'ES_Purchases' )
			&& ES_Purchases::enabled();

		return $on ? ' data-esf-track-purchases="1"' : '';
	}
	/**
	 * Already-subscribed suppression decision for a form. Delegates to
	 * ES_Connections when present (Full); in the Lite build that module is
	 * absent, so default to "show, no extra attributes" (no suppression).
	 *
	 * @return array{mode:string,attrs:string}
	 */
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

	/**
	 * Ask speed plugins not to delay our front-end script.
	 *
	 * Delay-JavaScript settings hold a script back until the visitor scrolls,
	 * clicks or moves the mouse. That is a sensible default for a chat widget
	 * and a bad one for a form that is already on screen: the visitor stares at
	 * an empty space until they touch something. These attributes are the
	 * opt-out that Cloudflare's Rocket Loader, Autoptimize, WP Rocket and their
	 * relatives read.
	 *
	 * Not a complete fix. Some plugins exclude by URL from their own settings
	 * list and ignore markup entirely, so a site owner may still have to add
	 * ours by hand.
	 *
	 * @param string $tag    The full <script> tag.
	 * @param string $handle The script's registered handle.
	 */
	public function mark_script_no_delay( string $tag, string $handle ): string {
		if ( 'emblem-studio-frontend' !== $handle ) {
			return $tag;
		}

		return str_replace(
			'<script ',
			'<script data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1" ',
			$tag
		);
	}

	/**
	 * True when at least one active, non-trashed form is enabled for an
	 * auto-injected shell (popup-auto / ribbon / slide-in). Cached for an
	 * hour and flushed on every form mutation, so an inline-only site
	 * does zero auto-shell work on the front end — inject_auto_popups()
	 * reads this flag and returns early instead of scanning the table.
	 */
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
