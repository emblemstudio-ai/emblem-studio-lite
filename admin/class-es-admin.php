<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_filter( 'admin_title', [ $this, 'filter_admin_title' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		// Menu icon needs styling on EVERY admin page (the sidebar shows
		// everywhere), so this can't ride on the page-scoped asset enqueue.
		add_action( 'admin_head', [ $this, 'menu_icon_css' ] );
		// Suppress third-party admin notices on Emblem Studio screens.
		// Priority 999 so it runs after every other plugin's add_action()
		// has fired but before WP renders them via do_action('admin_notices').
		add_action( 'in_admin_header', [ $this, 'maybe_suppress_admin_notices' ], 999 );
		add_action( 'admin_post_es_save_form', [ $this, 'handle_save' ] );
		add_action( 'admin_post_es_trash_form', [ $this, 'handle_trash' ] );
		add_action( 'admin_post_es_restore_form', [ $this, 'handle_restore' ] );
		add_action( 'admin_post_es_delete_form', [ $this, 'handle_delete' ] );
		add_action( 'admin_post_es_archive_form', [ $this, 'handle_archive' ] );
		add_action( 'admin_post_es_unarchive_form', [ $this, 'handle_unarchive' ] );
		add_action( 'admin_post_es_bulk_forms', [ $this, 'handle_bulk' ] );
		add_action( 'admin_post_es_save_settings', [ $this, 'handle_save_settings' ] );
		// Design System (Pro/full only) — shared per-install CSS.
		if ( ES_Caps::has( 'design_system' ) ) {
			add_action( 'admin_post_es_save_design_system', [ $this, 'handle_save_design_system' ] );
		}
		add_action( 'admin_post_es_export_leads', [ $this, 'handle_export_leads' ] );
		add_action( 'admin_post_es_reset_stats', [ $this, 'handle_reset_stats' ] );
		add_action( 'admin_post_es_delete_lead', [ $this, 'handle_delete_lead' ] );
		add_action( 'admin_post_es_move_lead_to_humans', [ $this, 'handle_move_lead_to_humans' ] );
		add_action( 'admin_post_es_retry_lead', [ $this, 'handle_retry_lead' ] );
		add_action( 'admin_post_es_duplicate_form', [ $this, 'handle_duplicate' ] );
		// Revisions are account-gated (Free and up) — register handlers only
		// when the module is present (absent in the Lite build).
		if ( ES_Caps::has( 'revisions' ) ) {
			add_action( 'admin_post_es_restore_revision',        [ $this, 'handle_restore_revision'        ] );
			add_action( 'admin_post_es_revision_note',           [ $this, 'handle_revision_note'           ] );
			add_action( 'admin_post_es_export_revision',         [ $this, 'handle_export_revision'         ] );
			add_action( 'admin_post_es_restore_revision_as_new', [ $this, 'handle_restore_revision_as_new' ] );
		}
		add_action( 'admin_post_es_export_form',  [ $this, 'handle_export_form'  ] );
		add_action( 'admin_post_es_export_all',   [ $this, 'handle_export_all'   ] );
		add_action( 'admin_post_es_import_forms', [ $this, 'handle_import_forms' ] );
		add_action( 'admin_post_es_install_bundle',          [ $this, 'handle_install_bundle'          ] );
		add_action( 'admin_post_es_install_form',            [ $this, 'handle_install_form'            ] );
		// A/B testing is account-gated (Paid). restore_revision_as_variant
		// bridges a revision into an A/B variant, so it belongs here too.
		if ( ES_Caps::has( 'ab' ) ) {
			add_action( 'admin_post_es_restore_revision_as_variant', [ $this, 'handle_restore_revision_as_variant' ] );
			add_action( 'admin_post_es_end_test',                [ $this, 'handle_end_test'                ] );
			add_action( 'admin_post_es_restore_variant',         [ $this, 'handle_restore_variant'         ] );
			add_action( 'admin_post_es_delete_variant',          [ $this, 'handle_delete_variant'          ] );
		}
		// Admin-only AJAX for drag-reorder on the Forms list.
		add_action( 'wp_ajax_es_reorder_forms',              [ $this, 'handle_reorder_forms'          ] );
		add_action( 'wp_ajax_es_lock_beat',                  [ $this, 'handle_lock_beat'              ] );
		add_action( 'wp_ajax_es_lock_takeover',              [ $this, 'handle_lock_takeover'          ] );
		add_action( 'wp_ajax_es_lock_release',               [ $this, 'handle_lock_release'           ] );
		// Per-post form-exclusion metabox on page / post edit screens.
		add_action( 'add_meta_boxes', [ $this, 'register_exclusion_metabox' ] );
		add_action( 'save_post',      [ $this, 'save_exclusion_metabox' ], 10, 2 );
	}

	// ================================================================
	// Per-post form-exclusion metabox.
	//
	// A panel on page / post edit screens for hiding a form from THAT
	// content item. It is a second doorway into the form's own Display
	// Rules hide list — single source of truth, no separate per-post
	// store — so an exclusion made here shows up under the form's
	// Display Rules automatically, and vice versa.
	// ================================================================

	/**
	 * Post types the exclusion metabox supports. post + page only: those
	 * are the display-rules hide buckets (posts / pages) that exist and
	 * whose matcher works. Custom post types need a generalized bucket —
	 * deferred (see docs/ARCHITECTURE.md section 9).
	 */
	private function exclusion_metabox_post_types(): array {
		return [ 'post', 'page' ];
	}

	/** Which hide_filters bucket a post type's IDs live in. */
	private function exclusion_bucket( string $post_type ): string {
		return $post_type === 'page' ? 'pages' : 'posts';
	}

	/**
	 * Forms eligible for per-post exclusion: non-trashed forms using at
	 * least one auto-injected shell (popup-auto / ribbon / slide-in).
	 * Inline + click-popup forms render only where their shortcode sits,
	 * so there is nothing to exclude.
	 */
	private function exclusion_forms(): array {
		$out = [];
		foreach ( ES_Form_Repo::all( 'all' ) as $form ) {
			if ( array_intersect( ES_Form_Repo::types_for( $form ), ES_Form_Repo::auto_shells() ) ) {
				$out[] = $form;
			}
		}
		return $out;
	}

	/** True when $post_id sits in the form's hide list for EVERY auto shell. */
	private function form_excludes_post( object $form, int $post_id, string $bucket ): bool {
		$rules = ES_Display_Rules::get_all_for_form( $form );
		foreach ( ES_Form_Repo::auto_shells() as $shell ) {
			$hide = array_map( 'intval', (array) ( $rules[ $shell ]['hide_filters'][ $bucket ] ?? [] ) );
			if ( ! in_array( $post_id, $hide, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Add or remove $post_id from a form's hide list across ALL auto
	 * shells — the "whole form" exclusion model. Adding to every shell
	 * (not just the ones currently enabled) keeps the exclusion durable
	 * if the form gains another shell later.
	 */
	private function set_form_exclusion( object $form, int $post_id, string $bucket, bool $exclude ): void {
		$rules = ES_Display_Rules::get_all_for_form( $form );
		foreach ( ES_Form_Repo::auto_shells() as $shell ) {
			$list = array_map( 'intval', (array) ( $rules[ $shell ]['hide_filters'][ $bucket ] ?? [] ) );
			if ( $exclude ) {
				if ( ! in_array( $post_id, $list, true ) ) {
					$list[] = $post_id;
				}
			} else {
				$list = array_values( array_filter( $list, static function ( $id ) use ( $post_id ) {
					return (int) $id !== $post_id;
				} ) );
			}
			$rules[ $shell ]['hide_filters'][ $bucket ] = $list;
		}
		ES_Form_Repo::update_display_rules( (int) $form->id, $rules );
	}

	/** Register the metabox on every supported post type's edit screen. */
	public function register_exclusion_metabox(): void {
		foreach ( $this->exclusion_metabox_post_types() as $post_type ) {
			add_meta_box(
				'emblem-studio-exclusions',
				__( 'Emblem Studio', 'emblem-studio' ),
				[ $this, 'render_exclusion_metabox' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/** Render the metabox — one checkbox per auto-displaying form. */
	public function render_exclusion_metabox( WP_Post $post ): void {
		$forms   = $this->exclusion_forms();
		$bucket  = $this->exclusion_bucket( (string) $post->post_type );
		$post_id = (int) $post->ID;

		wp_nonce_field( 'es_exclusion_metabox', 'es_exclusion_nonce' );

		if ( empty( $forms ) ) {
			echo '<p>' . esc_html__( 'No auto-displaying forms yet. Popup, ribbon, and slide-in forms will appear here once you create one.', 'emblem-studio' ) . '</p>';
			return;
		}

		echo '<p style="margin-top:0;">' . esc_html__( 'Tick a form to stop it showing on this page:', 'emblem-studio' ) . '</p>';
		foreach ( $forms as $form ) {
			$fid      = (int) $form->id;
			$excluded = $this->form_excludes_post( $form, $post_id, $bucket );
			$label    = ( isset( $form->name ) && (string) $form->name !== '' )
				? (string) $form->name
				: sprintf(
					/* translators: %d: form ID */
					__( 'Form #%d', 'emblem-studio' ),
					$fid
				);
			printf(
				'<p style="margin:6px 0;">'
					. '<input type="hidden" name="es_exclude_original[%1$d]" value="%2$d">'
					. '<label><input type="checkbox" name="es_exclude_forms[%1$d]" value="1"%3$s> %4$s</label>'
				. '</p>',
				$fid,
				$excluded ? 1 : 0,
				checked( $excluded, true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'A ticked form is hidden here. This is the same exclusion list shown under the form\'s Display Rules.', 'emblem-studio' ) . '</p>';
	}

	/**
	 * Persist metabox changes on post save. Non-destructive: a form's
	 * hide list is only touched when the checkbox state actually changed
	 * from what was rendered. So a plain post save — or a granular
	 * per-shell exclusion set from the form's own Display Rules — is
	 * never clobbered by an untouched metabox.
	 */
	public function save_exclusion_metabox( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->exclusion_metabox_post_types(), true ) ) {
			return;
		}
		// No nonce → this save isn't the metabox form (REST content save,
		// quick edit, programmatic save). Leave exclusions untouched.
		if ( empty( $_POST['es_exclusion_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['es_exclusion_nonce'] ) ), 'es_exclusion_metabox' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$bucket   = $this->exclusion_bucket( (string) $post->post_type );
		$checked  = ( isset( $_POST['es_exclude_forms'] ) && is_array( $_POST['es_exclude_forms'] ) )
			? array_map( 'intval', array_keys( $_POST['es_exclude_forms'] ) )
			: [];
		$original = ( isset( $_POST['es_exclude_original'] ) && is_array( $_POST['es_exclude_original'] ) )
			? wp_unslash( $_POST['es_exclude_original'] )
			: [];

		foreach ( $this->exclusion_forms() as $form ) {
			$fid = (int) $form->id;
			$now = in_array( $fid, $checked, true );
			$was = ! empty( $original[ $fid ] );
			if ( $now !== $was ) {
				$this->set_form_exclusion( $form, (int) $post_id, $bucket, $now );
			}
		}
	}

	public function handle_duplicate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_duplicate_form_' . $id );
		if ( ! $id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio' ) );
			exit;
		}
		$new_id = ES_Form_Repo::duplicate( $id );
		if ( ! $new_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&duplicate_failed=1' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $new_id . '&duplicated=1' ) );
		exit;
	}

	public function handle_delete_lead(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_delete_lead_' . $id );
		if ( $id ) {
			ES_Leads::delete( $id );
		}
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=emblem-studio-leads' );
		wp_safe_redirect( add_query_arg( 'lead_deleted', '1', $back ) );
		exit;
	}

	public function handle_move_lead_to_humans(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_move_lead_to_humans_' . $id );
		// Push toggle comes through as ?push=1|0. Default to push so the
		// freebie use case (the whole reason the button exists) just works.
		$push = ! isset( $_GET['push'] ) || (int) $_GET['push'] === 1;
		$result = $id ? ES_Leads::move_to_humans( $id, $push ) : [ 'ok' => false ];
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=emblem-studio-leads' );
		$flag = $result['ok'] ? 'lead_moved' : 'lead_move_failed';
		wp_safe_redirect( add_query_arg( $flag, '1', $back ) );
		exit;
	}

	/**
	 * A human asking, right now, for one more try on a confirmed-human
	 * lead whose FunnelKit push never landed — see ES_Leads::retry_push.
	 */
	public function handle_retry_lead(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_retry_lead_' . $id );
		$result = $id ? ES_Leads::retry_push( $id ) : [ 'ok' => false ];
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=emblem-studio-leads' );
		$flag = $result['ok'] ? 'lead_retried' : 'lead_retry_failed';
		wp_safe_redirect( add_query_arg( $flag, '1', $back ) );
		exit;
	}

	/**
	 * Brand emblem for the admin menu: the approved sunset logo, at the size WP
	 * expects for `add_menu_page()`'s icon. Returned as a base64 PNG data URI so
	 * it ships in the plugin (no extra HTTP request) and needs no enqueue.
	 */
	private function menu_icon(): string {
		$png = (string) file_get_contents( EMBLEM_STUDIO_PATH . 'assets/images/wp-admin-menu-icon-20x20.png' );

		return 'data:image/png;base64,' . base64_encode( $png );
	}

	/**
	 * Force the menu emblem to full opacity (WP dims icons to ~60%) on every
	 * admin page, and swap in the retina (40x40@2x) export so it stays crisp on
	 * HiDPI screens — WP's own background-image (set from menu_icon() above)
	 * only ever uses the 20x20 file. Also sets the sidebar label itself
	 * ("Emblem Studio") to the brand display font — it sits right next to the
	 * logo, so it needs the same styling everywhere the icon does, not just on
	 * our own screens (`.emblem-studio-wrap h1` in admin.css only covers our
	 * own pages' headings, never the sidebar). Tiny inline style — no enqueue.
	 */
	public function menu_icon_css(): void {
		$png = (string) file_get_contents( EMBLEM_STUDIO_PATH . 'assets/images/wp-admin-menu-icon-40x40@2x.png' );
		$icon_uri = 'data:image/png;base64,' . base64_encode( $png );

		$sel    = '#adminmenu #toplevel_page_emblem-studio';
		$states = [ '', '.opensub', ':hover', '.wp-has-current-submenu', '.current' ];
		$rules  = [];
		foreach ( $states as $s ) {
			$rules[] = "{$sel}{$s} div.wp-menu-image";
		}
		$selector = implode( ',', $rules );

		$decl = 'opacity:1 !important;'
			. "background-image:url('{$icon_uri}') !important;"
			. 'background-repeat:no-repeat !important;'
			. 'background-position:center !important;'
			. 'background-size:20px auto !important;'
			// Faint lift on both the dark sidebar and the blue active bar.
			. 'filter:drop-shadow(0 1px 2px rgba(0,0,0,.45)) !important;';

		$font_decl = "font-family:'Titillium Web','Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif !important;";

		echo '<style id="es-menu-icon-css">'
			. esc_html( $selector ) . '{' . $decl . '}'
			. esc_html( "{$sel} .wp-menu-name" ) . '{' . $font_decl . '}'
			. '</style>';
	}

	public function menu(): void {
		// P5 thin client: when connected to a free Emblem Studio account, the
		// sidebar mirrors the SaaS site sections (each page embeds its SaaS twin),
		// including ones the standalone Lite plugin doesn't have (Overview, A/B,
		// Integrations). Inert in the Full plugin (no connector class).
		$connector = class_exists( 'Emblem_Studio_SaaS_Connector' );
		$connected = $connector && Emblem_Studio_SaaS_Connector::is_connected();

		// Sidebar name reflects the edition/tier: Lite (standalone) → Free → Pro
		// (connected). The Full plugin has no connector, so it stays "Emblem Studio".
		$title = $connector
			? Emblem_Studio_SaaS_Connector::edition_label()
			: __( 'Emblem Studio', 'emblem-studio' );

		add_menu_page(
			$title,
			$title,
			'manage_options',
			'emblem-studio',
			[ $this, 'route' ],
			$this->menu_icon(),
			30
		);
		add_submenu_page(
			'emblem-studio',
			__( 'Forms & Assets', 'emblem-studio' ),
			__( 'Forms & Assets', 'emblem-studio' ),
			'manage_options',
			'emblem-studio',
			[ $this, 'route' ]
		);
		add_submenu_page(
			'emblem-studio',
			__( 'Add Form', 'emblem-studio' ),
			__( 'Add New', 'emblem-studio' ),
			'manage_options',
			'emblem-studio-new',
			[ $this, 'route_new' ]
		);
		// Design System — the full edition has the local CSS editor; a connected
		// Lite site embeds the account's (SaaS) Design System page. Mirrors how A/B
		// Tests shows when the cap is on OR the site is connected.
		if ( ES_Caps::has( 'design_system' ) || $connected ) {
			add_submenu_page(
				'emblem-studio',
				__( 'Design System', 'emblem-studio' ),
				__( 'Design System', 'emblem-studio' ),
				'manage_options',
				'emblem-studio-design-system',
				[ $this, 'route_design_system' ]
			);
		}
		// A/B Tests: the standalone Lite build omits it (Paid, gated off); when
		// connected the account's tier decides, so the SaaS page is embedded and
		// the menu always shows (the SaaS handles any upsell).
		if ( ES_Caps::has( 'ab' ) || $connected ) {
			add_submenu_page(
				'emblem-studio',
				__( 'A/B Tests', 'emblem-studio' ),
				__( 'A/B Tests', 'emblem-studio' ),
				'manage_options',
				'emblem-studio-tests',
				[ $this, 'route_tests' ]
			);
		}
		add_submenu_page(
			'emblem-studio',
			__( 'Leads', 'emblem-studio' ),
			__( 'Leads', 'emblem-studio' ),
			'manage_options',
			'emblem-studio-leads',
			[ $this, 'route_leads' ]
		);
		add_submenu_page(
			'emblem-studio',
			__( 'Reports', 'emblem-studio' ),
			__( 'Reports', 'emblem-studio' ),
			'manage_options',
			'emblem-studio-reports',
			[ $this, 'route_reports' ]
		);
		if ( $connected ) {
			add_submenu_page(
				'emblem-studio',
				__( 'Integrations', 'emblem-studio' ),
				__( 'Integrations', 'emblem-studio' ),
				'manage_options',
				'emblem-studio-integrations',
				[ $this, 'route_integrations' ]
			);
		}
		// Support & feedback (SER-1049) — SaaS-only, account-scoped (not
		// per-site), so it only appears once the site is actually paired; a
		// standalone Lite install has no local ticket data to show at all.
		if ( $connected ) {
			add_submenu_page(
				'emblem-studio',
				__( 'Support', 'emblem-studio' ),
				__( 'Support', 'emblem-studio' ),
				'manage_options',
				'emblem-studio-tickets',
				[ $this, 'route_tickets' ]
			);
		}
		add_submenu_page(
			'emblem-studio',
			__( 'Settings', 'emblem-studio' ),
			__( 'Settings', 'emblem-studio' ),
			'manage_options',
			'emblem-studio-settings',
			[ $this, 'route_settings' ]
		);
		// Plugin-local explainer, deliberately NEVER routed through maybe_embed()
		// (see route_privacy()) — it has no SaaS twin, so it renders identically
		// whether the site is standalone, Lite, or connected.
		add_submenu_page(
			'emblem-studio',
			__( 'Privacy & Cookies', 'emblem-studio' ),
			__( 'Privacy & Cookies', 'emblem-studio' ),
			'manage_options',
			'emblem-studio-privacy',
			[ $this, 'route_privacy' ]
		);
		// The Connect / Account screen (Lite + connector only). Label reflects
		// state — "Connect" when not yet paired, "Account" once connected.
		if ( $connector ) {
			add_submenu_page(
				'emblem-studio',
				$connected ? __( 'Account', 'emblem-studio' ) : __( 'Connect', 'emblem-studio' ),
				$connected ? __( 'Account', 'emblem-studio' ) : __( 'Connect', 'emblem-studio' ),
				'manage_options',
				'emblem-studio-account',
				[ $this, 'route_account' ]
			);
		}
	}

	public function route_integrations(): void {
		if ( $this->maybe_embed( 'emblem-studio-integrations' ) ) {
			return;
		}
		$this->route_settings();
	}

	public function route_reports(): void {
		if ( $this->maybe_embed( 'emblem-studio-reports' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/reports.php';
	}

	public function route_tests(): void {
		if ( $this->maybe_embed( 'emblem-studio-tests' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/tests-list.php';
	}

	/**
	 * Install one template from a bundle as a single new form. Used by
	 * each thumbnail card in the picker. Redirects to the new form's
	 * editor with a `from_template=1` query so the editor can show a
	 * one-line "you came from a template" callout (future polish).
	 */
	public function handle_install_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$bundle = isset( $_GET['bundle'] ) ? sanitize_key( wp_unslash( (string) $_GET['bundle'] ) ) : '';
		$form   = isset( $_GET['form'] )   ? sanitize_key( wp_unslash( (string) $_GET['form'] ) )   : '';
		if ( $bundle === '' || $form === '' ) {
			wp_die( esc_html__( 'Missing bundle or form slug.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_install_form_' . $bundle . '_' . $form );

		$id = ES_Templates::install_form( $bundle, $form );
		if ( ! $id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio-new&install_failed=1' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $id . '&from_template=1' ) );
		exit;
	}

	/**
	 * Install one industry bundle as N new forms. Each form lands as
	 * status='inactive' via the existing ES_Form_Repo::from_import_array
	 * pipeline, so all the import safety (slug collisions, sanitizer,
	 * status forcing) runs untouched. Redirects to the gallery with a
	 * success notice; if the bundle had zero installable forms, falls
	 * back to a warning.
	 */
	/**
	 * Admin-only AJAX — apply a new manual order to the Forms list.
	 * jQuery UI Sortable on the tbody fires this after every drop.
	 * Expects POST { nonce, ids: [int,int,...] } in top-to-bottom order.
	 */
	public function handle_reorder_forms(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( 'es_reorder_forms', 'nonce' );

		$raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? $_POST['ids'] : [];
		$ids = array_values( array_filter( array_map( 'intval', $raw ), function ( $v ) {
			return $v > 0;
		} ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => 'No form IDs provided' ], 400 );
		}

		$updated = ES_Form_Repo::reorder( $ids );
		wp_send_json_success( [ 'updated' => $updated ] );
	}

	// ── Concurrent-edit lock (AJAX) ───────────────────────────────
	private function lock_request(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( 'es_lock', 'nonce' );
		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( ! $form_id ) {
			wp_send_json_error( [ 'message' => 'No form id' ], 400 );
		}
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		return [ $form_id, get_current_user_id(), $token ];
	}

	/** Heartbeat: refresh my lock, or report who took it over (by name). */
	public function handle_lock_beat(): void {
		list( $form_id, $me, $token ) = $this->lock_request();
		$held = ES_Locks::beat( $form_id, $me, $token );
		$holder = $held ? 0 : ES_Locks::holder( $form_id );
		wp_send_json_success( [
			'held'    => $held,
			'by'      => $held ? '' : ES_Locks::user_label( $holder ),
			// Same WP user, different tab — so the booted tab can say "you" not a name.
			'by_is_me' => ( ! $held && $holder === $me ),
		] );
	}

	/** Take over editing — become the lock holder for this session token. */
	public function handle_lock_takeover(): void {
		list( $form_id, $me, $token ) = $this->lock_request();
		ES_Locks::take_over( $form_id, $me, $token );
		wp_send_json_success();
	}

	/** Release the lock on leaving (fired from beforeunload / sendBeacon). */
	public function handle_lock_release(): void {
		list( $form_id, $me, $token ) = $this->lock_request();
		ES_Locks::release( $form_id, $token );
		wp_send_json_success();
	}

	public function handle_install_bundle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$slug = isset( $_GET['bundle'] ) ? sanitize_key( wp_unslash( (string) $_GET['bundle'] ) ) : '';
		if ( $slug === '' ) {
			wp_die( esc_html__( 'Missing bundle.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_install_bundle_' . $slug );

		$ids = ES_Templates::install_bundle( $slug );
		if ( empty( $ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio-templates&install_failed=1' ) );
			exit;
		}
		// Land back on the gallery with a count notice. The user can then
		// click any of the newly-created forms from the main Forms list.
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio-templates&installed=' . count( $ids ) ) );
		exit;
	}

	public function route_leads(): void {
		if ( $this->maybe_embed( 'emblem-studio-leads' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/leads-list.php';
	}

	public function route_settings(): void {
		if ( $this->maybe_embed( 'emblem-studio-settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/settings.php';
	}

	/** Support & feedback (SER-1049) — SaaS-only, no local Lite equivalent.
	 *  The submenu only registers when connected, so reaching this unconnected
	 *  is a rare direct-URL edge case, not the normal path. */
	public function route_tickets(): void {
		if ( $this->maybe_embed( 'emblem-studio-tickets' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Support', 'emblem-studio' ); ?></h1>
			<p>
				<?php esc_html_e( 'Connect this site to Emblem Studio to report a problem or request a feature.', 'emblem-studio' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-account' ) ); ?>">
					<?php esc_html_e( 'Connect', 'emblem-studio' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/** Plugin-local — deliberately skips maybe_embed(). This is static explainer
	 *  copy with no SaaS twin; an unmapped slug through maybe_embed() would
	 *  silently fall back to embedding the Assets page on a connected site. */
	public function route_privacy(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/privacy.php';
	}

	/** Design System editor: connected Lite embeds the account's (SaaS) page; the
	 *  full edition renders the local CSS editor. */
	public function route_design_system(): void {
		if ( $this->maybe_embed( 'emblem-studio-design-system' ) ) {
			return;
		}
		if ( ! ES_Caps::has( 'design_system' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/design-system.php';
	}

	/** Save the shared Design System CSS (snapshots a revision on change). */
	public function handle_save_design_system(): void {
		if ( ! ES_Caps::has( 'design_system' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'es_save_design_system' );
		$css = isset( $_POST['design_system_css'] ) ? (string) wp_unslash( $_POST['design_system_css'] ) : '';
		if ( class_exists( 'ES_Design_System' ) ) {
			ES_Design_System::save_css( $css );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio-design-system&saved=1' ) );
		exit;
	}

	public function handle_export_leads(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'es_export_leads' );
		// Hand the full filter set through to the CSV so the export
		// matches what's on screen. Same parse path the list view uses.
		$filters = ES_Leads::parse_filters( $_GET );
		ES_Leads::stream_csv( $filters );
	}

	public function handle_reset_stats(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id = isset( $_GET['form_id'] ) && (int) $_GET['form_id'] > 0 ? (int) $_GET['form_id'] : 0;
		$shell   = isset( $_GET['shell'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['shell'] ) ) : '';
		if ( $shell && ! in_array( $shell, ES_Form_Repo::valid_types(), true ) ) {
			$shell = '';
		}
		$nonce_action = 'es_reset_stats_' . $form_id . ( $shell ? '_' . $shell : '' );
		check_admin_referer( $nonce_action );

		ES_Reports::reset_stats( $form_id ?: null, $shell ?: null );

		$redirect_to = isset( $_GET['return'] ) && $_GET['return'] === 'list'
			? admin_url( 'admin.php?page=emblem-studio&stats_reset=1' )
			: ( $form_id
				? admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $form_id . '&stats_reset=1' )
				: admin_url( 'admin.php?page=emblem-studio&stats_reset=1' ) );
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Single-form export — streams a JSON file as a download.
	 * URL: admin-post.php?action=es_export_form&id=N&_wpnonce=...
	 */
	public function handle_export_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! $id ) {
			wp_die( esc_html__( 'Missing form id.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_export_form_' . $id );

		$form = ES_Form_Repo::find( $id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		$payload = [
			'emblem_studio_export_version' => '1',
			'exported_at'                 => current_time( 'mysql' ),
			'exported_from'               => home_url(),
			'plugin_version'              => defined( 'EMBLEM_STUDIO_VERSION' ) ? EMBLEM_STUDIO_VERSION : '',
			'forms'                       => [ ES_Form_Repo::to_export_array( $form ) ],
		];

		$filename = 'emblem-studio-' . sanitize_title( $form->name ?: 'form' ) . '-' . gmdate( 'Y-m-d' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Bulk export — every non-trashed form, packaged in one JSON file.
	 * URL: admin-post.php?action=es_export_all&_wpnonce=...
	 */
	public function handle_export_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'es_export_all' );

		$forms = ES_Form_Repo::all( 'all' );
		$out_forms = [];
		foreach ( (array) $forms as $f ) {
			$out_forms[] = ES_Form_Repo::to_export_array( $f );
		}

		$payload = [
			'emblem_studio_export_version' => '1',
			'exported_at'                 => current_time( 'mysql' ),
			'exported_from'               => home_url(),
			'plugin_version'              => defined( 'EMBLEM_STUDIO_VERSION' ) ? EMBLEM_STUDIO_VERSION : '',
			'forms'                       => $out_forms,
		];

		$filename = 'emblem-studio-all-forms-' . gmdate( 'Y-m-d' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Multi-form import from an uploaded JSON file. Decodes the file,
	 * validates the export-version envelope, runs each form through
	 * Form_Repo::from_import_array, redirects back with a count.
	 */
	public function handle_import_forms(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'es_import_forms' );

		if ( empty( $_FILES['import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&import_error=missing_file' ) );
			exit;
		}

		$contents = file_get_contents( $_FILES['import_file']['tmp_name'] );
		if ( $contents === false || $contents === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&import_error=unreadable' ) );
			exit;
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) || empty( $decoded['emblem_studio_export_version'] ) || empty( $decoded['forms'] ) || ! is_array( $decoded['forms'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&import_error=invalid_format' ) );
			exit;
		}

		$created = 0;
		$failed  = 0;
		foreach ( $decoded['forms'] as $form_data ) {
			if ( ! is_array( $form_data ) ) {
				$failed++;
				continue;
			}
			$new_id = ES_Form_Repo::from_import_array( $form_data );
			if ( $new_id ) {
				$created++;
			} else {
				$failed++;
			}
		}

		$args = [ 'page' => 'emblem-studio', 'imported' => $created ];
		if ( $failed > 0 ) {
			$args['import_failed'] = $failed;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Export one revision as a JSON file. Hybrid payload: the revision's
	 * stored HTML/CSS plus the form's CURRENT settings for everything else
	 * (shells, FunnelKit, display rules, schedule, etc.) — revisions don't
	 * snapshot those, so "now" is the only honest source. Name gets a
	 * "(<timestamp>)" suffix so the user can tell which revision the file
	 * came from when they look at it later.
	 */
	public function handle_export_revision(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id     = isset( $_GET['form_id'] )     ? (int) $_GET['form_id']     : 0;
		$revision_id = isset( $_GET['revision_id'] ) ? (int) $_GET['revision_id'] : 0;
		if ( ! $form_id || ! $revision_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_export_revision_' . $form_id . '_' . $revision_id );

		$form = ES_Form_Repo::find( $form_id );
		$rev  = ES_Form_Revisions::find( $revision_id );
		if ( ! $form || ! $rev || (int) $rev->form_id !== $form_id ) {
			wp_die( esc_html__( 'Revision not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		$timestamp = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rev->created_at );
		$export = ES_Form_Repo::to_export_array( $form );
		$export['html'] = (string) $rev->html;
		$export['css']  = (string) $rev->css;
		$export['name'] = $export['name'] . ' (' . $timestamp . ')';
		// Override slug so the import doesn't derive it from the timestamped name (which produces ugly date-slug strings).
		$export['slug'] = ( $form->slug ?: 'form' ) . '-rev-' . (int) $rev->id;

		$payload = [
			'emblem_studio_export_version' => '1',
			'exported_at'                 => current_time( 'mysql' ),
			'exported_from'               => home_url(),
			'plugin_version'              => defined( 'EMBLEM_STUDIO_VERSION' ) ? EMBLEM_STUDIO_VERSION : '',
			'forms'                       => [ $export ],
		];

		$filename = 'emblem-studio-' . sanitize_title( $form->name ?: 'form' ) . '-rev-' . gmdate( 'Y-m-d-His', strtotime( $rev->created_at ) ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Restore a revision as a NEW form (in addition to keeping the
	 * original). The new form gets the revision's HTML/CSS plus the
	 * source form's current settings for everything else. Status forced
	 * to 'inactive' on the new form so the user can review before going
	 * live. Redirects to the new form's edit page.
	 */
	public function handle_restore_revision_as_new(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id     = isset( $_GET['form_id'] )     ? (int) $_GET['form_id']     : 0;
		$revision_id = isset( $_GET['revision_id'] ) ? (int) $_GET['revision_id'] : 0;
		if ( ! $form_id || ! $revision_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_restore_revision_as_new_' . $form_id . '_' . $revision_id );

		$form = ES_Form_Repo::find( $form_id );
		$rev  = ES_Form_Revisions::find( $revision_id );
		if ( ! $form || ! $rev || (int) $rev->form_id !== $form_id ) {
			wp_die( esc_html__( 'Revision not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		$timestamp = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rev->created_at );
		$payload = ES_Form_Repo::to_export_array( $form );
		$payload['html'] = (string) $rev->html;
		$payload['css']  = (string) $rev->css;
		$payload['name'] = $payload['name'] . ' (' . $timestamp . ')';
		// Override slug so the import doesn't derive it from the timestamped name (which produces ugly date-slug strings).
		$payload['slug'] = ( $form->slug ?: 'form' ) . '-rev-' . (int) $rev->id;

		$new_id = ES_Form_Repo::from_import_array( $payload );
		if ( ! $new_id ) {
			wp_die( esc_html__( 'Could not create the new form.', 'emblem-studio' ), '', [ 'response' => 500 ] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $new_id . '&restored_as_new=1' ) );
		exit;
	}

	/**
	 * Start a new A/B test seeded with a revision's html/css in slot B
	 * (current form design becomes slot A). Refuses if a test is already
	 * running on the form — the variant strip's archive lookup gates
	 * this in the UI too, but the server check is the actual contract.
	 */
	public function handle_restore_revision_as_variant(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id     = isset( $_GET['form_id'] )     ? (int) $_GET['form_id']     : 0;
		$revision_id = isset( $_GET['revision_id'] ) ? (int) $_GET['revision_id'] : 0;
		if ( ! $form_id || ! $revision_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_restore_revision_as_variant_' . $form_id . '_' . $revision_id );

		$rev = ES_Form_Revisions::find( $revision_id );
		if ( ! $rev || (int) $rev->form_id !== $form_id ) {
			wp_die( esc_html__( 'Revision not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}
		$new_test_id = ES_AB::start_test( $form_id, [
			'B' => [
				'html' => (string) $rev->html,
				'css'  => (string) $rev->css,
			],
		] );
		$args = [ 'page' => 'emblem-studio', 'action' => 'edit', 'id' => $form_id ];
		$args[ $new_test_id ? 'variant_restored' : 'variant_restore_failed' ] = 1;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_restore_revision(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$revision_id = isset( $_GET['revision_id'] ) ? (int) $_GET['revision_id'] : 0;
		$form_id     = isset( $_GET['form_id'] )     ? (int) $_GET['form_id'] : 0;
		if ( ! $revision_id || ! $form_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_restore_revision_' . $form_id . '_' . $revision_id );

		$rev = ES_Form_Revisions::find( $revision_id );
		if ( ! $rev || (int) $rev->form_id !== $form_id ) {
			wp_die( esc_html__( 'Revision not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		// Save with ONLY html/css present — opt-in column update pattern
		// means no other settings get clobbered. The pre-save state (i.e.
		// the version we're overwriting) is captured as a new revision
		// with a note explaining what triggered the overwrite, so the user
		// can undo the restore one click later.
		$timestamp = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rev->created_at );
		ES_Form_Repo::save(
			[
				'html'          => (string) $rev->html,
				'css'           => (string) $rev->css,
				'revision_note' => sprintf( __( 'Saved before restoring from %s', 'emblem-studio' ), $timestamp ),
			],
			$form_id
		);

		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $form_id . '&restored=1#revisions' ) );
		exit;
	}

	public function handle_revision_note(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		$revision_id = isset( $_POST['revision_id'] ) ? (int) $_POST['revision_id'] : 0;
		if ( ! $revision_id ) {
			wp_send_json_error( [ 'message' => 'bad request' ], 400 );
		}
		check_admin_referer( 'es_revision_note_' . $revision_id );

		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '';
		ES_Form_Revisions::set_note( $revision_id, $note );
		wp_send_json_success( [ 'note' => $note ] );
	}

	public function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'es_save_settings' );
		update_option( ES_FunnelKit::OPT_API_KEY,    sanitize_text_field( wp_unslash( $_POST['fk_api_key'] ?? '' ) ) );
		$fk_secret = sanitize_text_field( wp_unslash( $_POST['fk_api_secret'] ?? '' ) );
		if ( $fk_secret !== '' ) {
			update_option( ES_FunnelKit::OPT_API_SECRET, $fk_secret );
		}
		update_option( ES_FunnelKit::OPT_API_BASE,   esc_url_raw( wp_unslash( $_POST['fk_api_base'] ?? home_url() ) ) );
		// Bot filtering is always on (no toggle) — known bots are always counted
		// separately so they never pollute real visitor metrics. Checkbox arrives
		// only when ticked; absence means "off."
		update_option( ES_Event_Recorder::OPT_DETECT_DATACENTER, ! empty( $_POST['detect_datacenter'] ) ? '1' : '0' );
		update_option( ES_Event_Recorder::OPT_TRUST_PROXY, ! empty( $_POST['trust_proxy'] ) ? '1' : '0' );

		// IP exclusion list: one per line, keep only valid IPs.
		$raw_ips = isset( $_POST['exclude_ips'] ) ? (string) wp_unslash( $_POST['exclude_ips'] ) : '';
		$ips     = [];
		foreach ( preg_split( '/[\r\n,]+/', $raw_ips ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' && filter_var( $line, FILTER_VALIDATE_IP ) ) {
				$ips[] = $line;
			}
		}
		update_option( ES_Event_Recorder::OPT_EXCLUDE_IPS, array_values( array_unique( $ips ) ) );

		// Uninstall data policy. Default OFF (keep data) — checkbox arrives only
		// when ticked. Read in uninstall.php (kept in sync there by key name).
		update_option( 'emblem_studio_delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) ? '1' : '0' );

		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio-settings&saved=1' ) );
		exit;
	}

	/**
	 * Strip every third-party notice from Emblem Studio screens so the
	 * admin doesn't read as "shared WordPress backend." Our own success/
	 * error notices are rendered inline inside the views (not via the
	 * admin_notices hook), so they survive untouched. Critical WP
	 * messages stay too — only the action-hook driven notices that
	 * other plugins use to advertise / nag / upsell are dropped.
	 */
	public function maybe_suppress_admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		// Only suppress on screens whose ID is namespaced to our menu.
		// Covers: toplevel_page_emblem-studio + every submenu_page_*.
		if ( strpos( $screen->id, 'emblem-studio' ) === false ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'emblem-studio' ) ) {
			return;
		}
		// Brand fonts (Inter + Titillium Web) are self-hosted in
		// assets/fonts/ and declared via @font-face at the top of admin.css
		// — no Google Fonts dependency, so they render even offline and
		// the admin never phones a third party on load.
		wp_enqueue_style( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.css', [], $this->asset_ver( 'assets/admin.css' ) );

		$action = $_GET['action'] ?? '';
		$page   = $_GET['page'] ?? '';
		$is_editor = ( $page === 'emblem-studio-new' || $action === 'edit' );
		if ( $is_editor ) {
			$html_editor = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
			$css_editor  = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
			wp_enqueue_script( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.js', [ 'jquery', 'wp-theme-plugin-editor' ], $this->asset_ver( 'assets/admin.js' ), true );
			// Display-rule presets (SER-899 phase 2) — the site's real post types
			// + special-page labels + WooCommerce state, so the Manage Presets
			// modal can render the SAME filter checkboxes/chip pickers the
			// per-shell panel does, not a cut-down editor. Mirrors form-edit.php's
			// $dr_post_types / $dr_special_labels.
			$preset_post_types = [];
			if ( class_exists( 'ES_Preset_Repo' ) ) {
				$wp_post_types = get_post_types( [ 'public' => true ], 'objects' );
				unset( $wp_post_types['attachment'] );
				foreach ( $wp_post_types as $slug => $pt ) {
					$preset_post_types[] = [ 'slug' => $slug, 'label' => $pt->labels->singular_name ?? $slug ];
				}
			}
			wp_localize_script( 'emblem-studio-admin', 'EmblemStudioAdmin', [
				'htmlEditor' => $html_editor,
				'cssEditor'  => $css_editor,
				'restUrl'    => esc_url_raw( rest_url() ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				// Display-rule presets (SER-899 phase 2) — the "Manage presets"
				// modal + "Save current as preset" are the only preset actions
				// that hit the server immediately; applying/changing/un-linking
				// a shell's coupling is client-side only until the page's own Save.
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'presetNonce'   => wp_create_nonce( 'es_preset_ajax' ),
				'presetPostTypes'   => $preset_post_types,
				'presetSpecials'    => class_exists( 'ES_Display_Rules' ) ? array_map(
					static function ( $key ) {
						$labels = [
							'front_page' => __( 'Front page', 'emblem-studio' ),
							'blog'       => __( 'Blog index', 'emblem-studio' ),
							'404'        => __( '404 page', 'emblem-studio' ),
							'search'     => __( 'Search results', 'emblem-studio' ),
							'archive'    => __( 'Archives', 'emblem-studio' ),
						];
						return [ 'key' => $key, 'label' => $labels[ $key ] ];
					},
					ES_Display_Rules::SPECIALS
				) : [],
				'presetWcActive'    => class_exists( 'ES_Display_Rules' ) && ES_Display_Rules::wc_active(),
				'presetWcPages'     => class_exists( 'ES_Display_Rules' ) ? array_map(
					static function ( $key ) {
						return [ 'key' => $key, 'label' => ES_Display_Rules::wc_page_labels()[ $key ] ?? $key ];
					},
					ES_Display_Rules::WC_PAGES
				) : [],
			] );
			// Inspector (semi-builder) takeover — its own JS/CSS so it can
			// grow without bloating admin.js. Loads after admin.js so the
			// CodeMirror editors it bridges to are already mounted.
			wp_enqueue_style( 'emblem-studio-inspector', EMBLEM_STUDIO_URL . 'assets/inspector.css', [ 'emblem-studio-admin' ], $this->asset_ver( 'assets/inspector.css' ) );
			wp_enqueue_script( 'emblem-studio-inspector-color', EMBLEM_STUDIO_URL . 'assets/inspector-color.js', [], $this->asset_ver( 'assets/inspector-color.js' ), true );
			wp_enqueue_script( 'emblem-studio-inspector', EMBLEM_STUDIO_URL . 'assets/inspector.js', [ 'emblem-studio-admin', 'emblem-studio-inspector-color' ], $this->asset_ver( 'assets/inspector.js' ), true );
		} elseif ( $page === 'emblem-studio-design-system' && ES_Caps::has( 'design_system' ) ) {
			// Design System page — just a CSS code editor (mounted inline by the view).
			$css_editor = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
			wp_enqueue_script( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.js', [ 'jquery' ], $this->asset_ver( 'assets/admin.js' ), true );
			wp_localize_script( 'emblem-studio-admin', 'EmblemStudioDS', [ 'cssEditor' => $css_editor ?: null ] );
		} else {
			// All other Emblem Studio admin pages still need admin.js for shared
			// helpers (e.g., click-to-copy on shortcodes in the Forms list).
			// Forms list also needs jQuery UI Sortable for drag-reorder.
			wp_enqueue_script( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.js', [ 'jquery', 'jquery-ui-sortable' ], $this->asset_ver( 'assets/admin.js' ), true );
		}
		if ( $page === 'emblem-studio-reports' ) {
			wp_enqueue_script( 'emblem-studio-reports', EMBLEM_STUDIO_URL . 'assets/reports.js', [], $this->asset_ver( 'assets/reports.js' ), true );
		}
		// Forms & Assets list — live hover thumbnail of each asset.
		if ( $page === 'emblem-studio' && $action !== 'edit' ) {
			wp_enqueue_script( 'emblem-studio-asset-preview', EMBLEM_STUDIO_URL . 'assets/asset-preview.js', [], $this->asset_ver( 'assets/asset-preview.js' ), true );
			wp_localize_script( 'emblem-studio-asset-preview', 'EmblemStudioPreview', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'es_preview_payload' ),
			] );
		}
	}

	/**
	 * Cache-busting version for a bundled asset: the file's modified time,
	 * so a saved CSS/JS edit busts the browser cache without bumping the
	 * plugin version. Falls back to EMBLEM_STUDIO_VERSION if the file
	 * can't be stat'd. (filemtime is a standard, production-safe strategy.)
	 */
	private function asset_ver( string $rel ): string {
		$mtime = @filemtime( EMBLEM_STUDIO_PATH . $rel );
		return $mtime ? (string) $mtime : EMBLEM_STUDIO_VERSION;
	}

	/**
	 * Browser-tab title for the asset editor. WordPress otherwise shows the
	 * menu label ("Forms & Assets") for every screen under this page, so open
	 * editor tabs are indistinguishable. On the edit screen we swap in
	 * "ES <id> - <name>" while keeping WP's " ‹ Site — WordPress" suffix.
	 */
	public function filter_admin_title( string $admin_title, string $title ): string {
		if ( ( $_GET['page'] ?? '' ) !== 'emblem-studio' || ( $_GET['action'] ?? '' ) !== 'edit' ) {
			return $admin_title;
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( $id <= 0 ) {
			return $admin_title;
		}
		$form = ES_Form_Repo::find( $id );
		if ( ! $form || ! isset( $form->name ) ) {
			return $admin_title;
		}
		$custom = sprintf( 'ES %d - %s', $id, wp_strip_all_tags( (string) $form->name ) );
		return str_replace( $title, $custom, $admin_title );
	}

	/**
	 * P5 thin client: when the site is connected to a free Emblem Studio account
	 * (Lite + the bundled connector), each sidebar page shows the embedded SaaS
	 * dashboard instead of the built-in Lite screen. Returns true if the connector
	 * handled output for this page (connected → embed, or "disconnected" screen),
	 * so the caller skips its own view. Inert in the Full plugin (no connector).
	 */
	private function maybe_embed( string $slug ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! class_exists( 'Emblem_Studio_SaaS_Connector' ) ) {
			return false;
		}
		return Emblem_Studio_SaaS_Connector::maybe_render_page( $slug );
	}

	/** The Connect / Account screen (Lite-only submenu). */
	public function route_account(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( class_exists( 'Emblem_Studio_SaaS_Connector' ) ) {
			Emblem_Studio_SaaS_Connector::render_account_page();
		}
	}

	public function route(): void {
		if ( $this->maybe_embed( 'emblem-studio' ) ) {
			return;
		}
		$action = $_GET['action'] ?? '';
		if ( $action === 'edit' ) {
			$this->render_edit();
			return;
		}
		$this->render_list();
	}

	public function route_new(): void {
		if ( $this->maybe_embed( 'emblem-studio-new' ) ) {
			return;
		}
		// ?blank=1 routes through the picker's "Blank form" card directly
		// to the editor without showing the picker. Power-user shortcut.
		if ( ! empty( $_GET['blank'] ) ) {
			$this->render_edit();
			return;
		}
		// Default: show the picker (blank card + template thumbnails).
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/form-picker.php';
	}

	private function render_list(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$valid_filters = [ 'all', 'active', 'inactive', 'archived', 'trash' ];
		$filter        = isset( $_GET['filter'] ) ? sanitize_key( (string) $_GET['filter'] ) : 'all';
		if ( ! in_array( $filter, $valid_filters, true ) ) {
			$filter = 'all';
		}
		$forms  = ES_Form_Repo::all( $filter );
		$counts = ES_Form_Repo::counts_by_filter();
		include EMBLEM_STUDIO_PATH . 'admin/views/forms-list.php';
	}

	private function render_edit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$form = $id ? ES_Form_Repo::find( $id ) : null;

		// Concurrent-edit lock. Identity is the per-tab session token, which is
		// only known client-side, so we don't claim here — we hand the view the
		// current holder (token + name) and the browser decides: claim if free
		// or mine, otherwise show the take-over modal. (See admin.js + ES_Locks.)
		$lock_nonce        = wp_create_nonce( 'es_lock' );
		$lock_me           = get_current_user_id();
		$lock_holder_token = '';
		$lock_holder_name  = '';
		$lock_holder_is_me = false;
		if ( $id ) {
			$lock_holder_token = ES_Locks::holder_token( $id );
			if ( $lock_holder_token !== '' ) {
				$holder            = ES_Locks::holder( $id );
				$lock_holder_name  = ES_Locks::user_label( $holder );
				$lock_holder_is_me = ( $holder === $lock_me );
			}
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/form-edit.php';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'es_save_form' );

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		// Concurrent-edit guard (hard lock): refuse to save an existing asset
		// that another SESSION has taken over, so a booted editor (even another
		// tab of the same admin) can't clobber the current one. The editor posts
		// its session token; new assets (id = 0) have no lock.
		$posted_token = isset( $_POST['lock_token'] ) ? sanitize_text_field( wp_unslash( $_POST['lock_token'] ) ) : '';
		if ( $id && ES_Locks::is_locked_by_other_session( $id, $posted_token ) ) {
			$who = ES_Locks::user_label( ES_Locks::holder( $id ) );
			wp_die(
				esc_html( sprintf(
					/* translators: %s: the other editor's name */
					__( '%s took over editing this asset, so your changes were not saved. Copy anything you need, then reload.', 'emblem-studio' ),
					$who
				) ),
				esc_html__( 'Not saved — someone else is editing', 'emblem-studio' ),
				[ 'response' => 409, 'back_link' => true ]
			);
		}

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( $name === '' ) {
			$name = 'Untitled form';
		}

		// Slug is internal-only since v0.9.0 (no UI). Auto-derived from name,
		// deduped via unique_slug — purely for the DB column's UNIQUE constraint.
		$slug = ES_Form_Repo::unique_slug( sanitize_title( $name ), $id );

		$lists = isset( $_POST['funnelkit_lists'] ) && is_array( $_POST['funnelkit_lists'] )
			? array_map( 'intval', $_POST['funnelkit_lists'] )
			: [];
		$tags_csv = wp_unslash( $_POST['funnelkit_tags_csv'] ?? '' );
		$tags     = array_values( array_filter( array_map( 'trim', preg_split( '/,/', $tags_csv ) ?: [] ) ) );

		// Shell types the form is enabled in.
		$types = isset( $_POST['form_types'] ) && is_array( $_POST['form_types'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['form_types'] ) )
			: [];

		// ---- Per-shell trigger config + display rules ----
		// Field-name convention:  <shell-key>_trigger_type, <shell-key>_delay_seconds, ...
		//                         display_<shell-key>_show_mode, display_<shell-key>_show_specials[]
		// where <shell-key> is the shell slug with hyphens → underscores
		// (e.g. popup-auto → popup_auto, slide-in → slide_in).
		//
		// Backwards-compat: the pre-v0.17 form-edit emitted unprefixed field
		// names (auto_trigger_type, display_show_specials[], etc.) — those are
		// still accepted and routed to popup-auto. Both reads to keep PR 1
		// migrations smooth.
		$shell_to_key = function ( string $shell ): string {
			return str_replace( '-', '_', $shell );
		};
		$pick_array = function ( string $name, string $cast ) {
			if ( ! isset( $_POST[ $name ] ) || ! is_array( $_POST[ $name ] ) ) {
				return [];
			}
			$values = wp_unslash( $_POST[ $name ] );
			return $cast === 'int'
				? array_map( 'intval', $values )
				: array_map( 'sanitize_key', $values );
		};
		$collect_shell_trigger = function ( string $shell ) use ( $shell_to_key ) {
			$k = $shell_to_key( $shell );
			$legacy_prefix = ( $shell === 'popup-auto' ) ? 'auto' : null;
			$pick = function ( string $base, $default ) use ( $k, $legacy_prefix ) {
				if ( isset( $_POST[ $k . '_' . $base ] ) ) {
					return $_POST[ $k . '_' . $base ];
				}
				if ( $legacy_prefix !== null && isset( $_POST[ $legacy_prefix . '_' . $base ] ) ) {
					return $_POST[ $legacy_prefix . '_' . $base ];
				}
				return $default;
			};
			$out = [
				'trigger'             => sanitize_key( (string) $pick( 'trigger_type', '' ) ),
				'delay_seconds'       => $pick( 'delay_seconds', 5 ),
				'scroll_percent'      => $pick( 'scroll_percent', 50 ),
				'inactivity_seconds'  => $pick( 'inactivity_seconds', 30 ),
				'exit_include_hidden' => ! empty( $pick( 'exit_include_hidden', null ) ),
				'exit_buffer_seconds' => $pick( 'exit_buffer_seconds', 0 ),
			];
			if ( $shell === 'ribbon' ) {
				$out['position'] = sanitize_key( (string) ( $_POST['ribbon_position'] ?? 'top' ) );
				$out['sticky']   = ! empty( $_POST['ribbon_sticky'] );
			}
			if ( $shell === 'slide-in' ) {
				$out['corner'] = sanitize_key( (string) ( $_POST['slide_in_corner'] ?? 'bottom-right' ) );
			}
			// Close + dismiss config — applies to every auto shell uniformly.
			$out['close_button']       = ! empty( $pick( 'close_button', null ) );
			$out['dismiss_behavior']   = sanitize_key( (string) $pick( 'dismiss_behavior', 'show_again' ) );
			$out['dismiss_hide_hours'] = $pick( 'dismiss_hide_hours', 24 );
			return $out;
		};
		$collect_shell_rules = function ( string $shell ) use ( $shell_to_key, $pick_array ) {
			$k = $shell_to_key( $shell );
			$legacy_keyless = ( $shell === 'popup-auto' );
			$name = function ( string $side, string $key ) use ( $k, $legacy_keyless ) {
				$candidate = 'display_' . $k . '_' . $side . '_' . $key;
				if ( isset( $_POST[ $candidate ] ) ) {
					return $candidate;
				}
				if ( $legacy_keyless && isset( $_POST[ 'display_' . $side . '_' . $key ] ) ) {
					return 'display_' . $side . '_' . $key;
				}
				return $candidate;
			};
			$show_mode = isset( $_POST[ 'display_' . $k . '_show_mode' ] )
				? sanitize_key( (string) $_POST[ 'display_' . $k . '_show_mode' ] )
				: ( $legacy_keyless ? sanitize_key( (string) ( $_POST['display_show_mode'] ?? 'all' ) ) : 'all' );
			$visitor_logged_in_raw = $_POST[ 'display_' . $k . '_visitor_logged_in' ] ?? 'any';
			$visitor_logged_in     = sanitize_key( (string) $visitor_logged_in_raw );
			$visitor_roles         = $pick_array( 'display_' . $k . '_visitor_roles', 'key' );
			$visitor_state_raw     = $_POST[ 'display_' . $k . '_visitor_state' ] ?? 'any';
			$visitor_state         = sanitize_key( (string) $visitor_state_raw );
			$schedule_days         = $pick_array( 'display_' . $k . '_schedule_days', 'int' );
			$schedule_start        = isset( $_POST[ 'display_' . $k . '_schedule_start_time' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'display_' . $k . '_schedule_start_time' ] ) ) : '';
			$schedule_end          = isset( $_POST[ 'display_' . $k . '_schedule_end_time' ] )   ? sanitize_text_field( wp_unslash( $_POST[ 'display_' . $k . '_schedule_end_time' ] ) )   : '';
			// URL patterns arrive as one textarea string per side ("\n"-separated).
			$urls_for = function ( string $side ) use ( $name ) {
				$field = $name( $side, 'urls' );
				if ( ! isset( $_POST[ $field ] ) ) {
					return [];
				}
				$raw = (string) wp_unslash( $_POST[ $field ] );
				$lines = preg_split( '/\r\n|\r|\n/', $raw );
				return array_values( array_filter( array_map( 'trim', (array) $lines ), function ( $s ) { return $s !== ''; } ) );
			};
			return [
				'show_mode'         => $show_mode,
				'show_filters'      => [
					'specials'   => $pick_array( $name( 'show', 'specials'   ), 'key' ),
					'post_types' => $pick_array( $name( 'show', 'post_types' ), 'key' ),
					'categories' => $pick_array( $name( 'show', 'categories' ), 'int' ),
					'tags'       => $pick_array( $name( 'show', 'tags'       ), 'int' ),
					'posts'      => $pick_array( $name( 'show', 'posts'      ), 'int' ),
					'pages'      => $pick_array( $name( 'show', 'pages'      ), 'int' ),
					'urls'       => $urls_for( 'show' ),
					'wc_pages'   => $pick_array( $name( 'show', 'wc_pages'   ), 'key' ),
				],
				'hide_filters'      => [
					'specials'   => $pick_array( $name( 'hide', 'specials'   ), 'key' ),
					'post_types' => $pick_array( $name( 'hide', 'post_types' ), 'key' ),
					'categories' => $pick_array( $name( 'hide', 'categories' ), 'int' ),
					'tags'       => $pick_array( $name( 'hide', 'tags'       ), 'int' ),
					'posts'      => $pick_array( $name( 'hide', 'posts'      ), 'int' ),
					'pages'      => $pick_array( $name( 'hide', 'pages'      ), 'int' ),
					'urls'       => $urls_for( 'hide' ),
					'wc_pages'   => $pick_array( $name( 'hide', 'wc_pages'   ), 'key' ),
				],
				'visitor_logged_in' => $visitor_logged_in,
				'visitor_roles'     => $visitor_roles,
				'visitor_state'     => $visitor_state,
				'schedule'          => [
					'days'       => $schedule_days,
					'start_time' => $schedule_start,
					'end_time'   => $schedule_end,
				],
			];
		};

		/**
		 * Preserve a shell's display-rule preset coupling across a normal page
		 * save (SER-899 phase 2). Applying/changing/un-linking/saving-as-preset
		 * happen instantly over AJAX (ES_Preset_Ajax) the moment the picker is
		 * used — same interaction model as the SaaS's picker, not a separate
		 * "click Save to take effect" step. So by the time this runs, a coupled
		 * shell's `preset_id` hidden field already reflects the true state; this
		 * only needs to re-resolve show_mode/filters from that preset's CURRENT
		 * values, because the WHERE fields render `disabled` while coupled and
		 * so never post anything for $collect_shell_rules to have read.
		 */
		$resync_preset_coupling = function ( string $shell, array $rules ) use ( $shell_to_key ) {
			if ( ! class_exists( 'ES_Preset_Repo' ) ) {
				return $rules;
			}
			$key         = $shell_to_key( $shell );
			$preset_id   = isset( $_POST[ 'display_' . $key . '_preset_id' ] ) ? (int) $_POST[ 'display_' . $key . '_preset_id' ] : 0;
			$preset      = $preset_id ? ES_Preset_Repo::find( $preset_id ) : null;
			if ( $preset ) {
				$where                 = ES_Preset_Repo::to_export_array( $preset );
				$rules['show_mode']    = $where['show_mode'];
				$rules['show_filters'] = (array) $where['show_filters'];
				$rules['hide_filters'] = (array) $where['hide_filters'];
				$rules['preset_id']    = $preset_id;
			}
			return $rules;
		};

		$auto_trigger_config = [];
		$display_rules       = [];
		foreach ( ES_Form_Repo::auto_shells() as $shell ) {
			$auto_trigger_config[ $shell ] = $collect_shell_trigger( $shell );
			$display_rules[ $shell ]       = $resync_preset_coupling( $shell, $collect_shell_rules( $shell ) );
		}

		// Field mapping arrives as a JSON-encoded object in `field_mapping_json`.
		$mapping = [];
		if ( ! empty( $_POST['field_mapping_json'] ) ) {
			$decoded = json_decode( wp_unslash( (string) $_POST['field_mapping_json'] ), true );
			if ( is_array( $decoded ) ) {
				$mapping = $decoded;
			}
		}

		// When an A/B test is running, the form-level html/css stay frozen
		// at the pre-test snapshot. The visible editor only ever shows the
		// active variant (its name="html"/"css" attrs are stripped), and
		// the variants[A|B][html|css] hidden stores carry the real data.
		// We pin html/css to the existing DB values so ES_Form_Repo::save
		// updates them to themselves (no spurious revisions either).
		$ab_running   = ! empty( $_POST['ab_test_running'] );
		$frozen_html  = null;
		$frozen_css   = null;
		if ( $ab_running && $id ) {
			$existing = ES_Form_Repo::find( $id );
			if ( $existing ) {
				$frozen_html = (string) $existing->html;
				$frozen_css  = (string) $existing->css;
			}
		}

		$data = [
			'name'                 => $name,
			'slug'                 => $slug,
			'html'                 => $ab_running ? ( $frozen_html ?? '' ) : wp_unslash( $_POST['html'] ?? '' ),
			'css'                  => $ab_running ? ( $frozen_css ?? '' )  : wp_unslash( $_POST['css'] ?? '' ),
			'status'               => sanitize_text_field( $_POST['status'] ?? 'inactive' ),
			'success_mode'         => sanitize_text_field( $_POST['success_mode'] ?? 'inline_state' ),
			'success_message'      => wp_kses_post( wp_unslash( $_POST['success_message'] ?? '' ) ),
			'success_redirect_url' => esc_url_raw( wp_unslash( $_POST['success_redirect_url'] ?? '' ) ),
			'funnelkit_lists'      => $lists,
			'funnelkit_tags'       => $tags,
			'field_mapping'        => $mapping,
			'form_types'           => $types,
			'auto_trigger_config'  => $auto_trigger_config,
			'display_rules'        => $display_rules,
			'use_shadow_dom'       => ! empty( $_POST['use_shadow_dom'] ) ? 1 : 0,
			// Design System opt-out (Pro/full only). Absent the toggle (Lite), keep
			// the column at its inherit-by-default value so it's a no-op there.
			'use_design_system'    => ES_Caps::has( 'design_system' ) ? ( ! empty( $_POST['use_design_system'] ) ? 1 : 0 ) : 1,
			'popup_max_width'      => isset( $_POST['popup_max_width'] ) ? (int) $_POST['popup_max_width'] : 720,
			'popup_fullscreen'     => ! empty( $_POST['popup_fullscreen'] ) ? 1 : 0,
			'popup_close_theme'    => sanitize_key( (string) ( $_POST['popup_close_theme'] ?? 'dark' ) ),
			'active_from'          => isset( $_POST['active_from'] )  ? wp_unslash( $_POST['active_from'] )  : '',
			'active_until'         => isset( $_POST['active_until'] ) ? wp_unslash( $_POST['active_until'] ) : '',
		];

		// Archived and Trash are states the form is IN, so the status field on
		// the edit screen is not a way out of either. Unarchive or restore the
		// form first. Without this, an archived form saved as Active reads
		// Active and Archived at once, which is the state SER-1234 ended.
		if ( $id && $data['status'] === 'active' ) {
			$current = ES_Form_Repo::find( $id );
			if ( $current && ( ! empty( $current->archived_at ) || ! empty( $current->trashed_at ) ) ) {
				$data['status'] = 'inactive';
			}
		}

		// Click tracking toggle only lives on the Stats tab, which is hidden
		// on brand-new forms. Only propagate when we know the form was the one
		// that rendered the toggle (i.e. we're editing an existing form);
		// otherwise let the DB default (1) win on initial create.
		if ( $id ) {
			$data['track_clicks'] = ! empty( $_POST['track_clicks'] ) ? 1 : 0;
		}

		// FunnelKit destination toggle (same per-form pattern as webhooks).
		// Always computed as an explicit 0/1 — an unchecked box sends no
		// `funnelkit_enabled` key at all (standard HTML checkbox behavior),
		// so `! empty($_POST[…])` alone would leave the column untouched on
		// an UPDATE and fall through to the DB column default on an INSERT.
		// Sending it explicitly every save is what makes "uncheck and save"
		// actually turn it off, and is why the column's own default (now 0,
		// see class-es-install.php's v34 migration) never actually matters.
		$data['funnelkit_enabled'] = ! empty( $_POST['funnelkit_enabled'] ) ? 1 : 0;

		// Webhooks — array of rows from the Connect tab repeater. Empty
		// rows (no URL) are stripped in ES_Webhooks::sanitize_array.
		if ( isset( $_POST['webhooks'] ) && is_array( $_POST['webhooks'] ) ) {
			$raw_hooks = [];
			foreach ( $_POST['webhooks'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$raw_hooks[] = [
					'url'     => isset( $row['url'] )     ? wp_unslash( (string) $row['url'] )     : '',
					'secret'  => isset( $row['secret'] )  ? wp_unslash( (string) $row['secret'] )  : '',
					'headers' => isset( $row['headers'] ) ? wp_unslash( (string) $row['headers'] ) : '',
					'active'  => ! empty( $row['active'] ),
				];
			}
			$data['webhooks'] = $raw_hooks;
		} else {
			$data['webhooks'] = []; // explicit empty so save() persists "no webhooks"
		}

		// Connections — "already subscribed" suppression. Sanitized in
		// ES_Connections::sanitize via ES_Form_Repo::save.
		if ( class_exists( 'ES_Connections' ) && isset( $_POST['connections'] ) && is_array( $_POST['connections'] ) ) {
			$conn = wp_unslash( $_POST['connections'] );
			$data['connections'] = [
				'suppress'      => isset( $conn['suppress'] ) && is_array( $conn['suppress'] ) ? array_map( 'intval', $conn['suppress'] ) : [],
				'mutual'        => ! empty( $conn['mutual'] ),
				'duration_days' => isset( $conn['duration_days'] ) ? (int) $conn['duration_days'] : ES_Connections::DEFAULT_DAYS,
				'behavior'      => in_array( ( $conn['behavior'] ?? 'nothing' ), [ 'hide', 'replace', 'nothing' ], true ) ? $conn['behavior'] : 'nothing',
			];
		} else {
			$data['connections'] = []; // explicit empty → stored as NULL (no-op)
		}

		$new_id = ES_Form_Repo::save( $data, $id ?: null );

		// A/B testing — per-form variant + test-lifecycle actions.
		$ab_action     = isset( $_POST['ab_action'] ) ? sanitize_key( (string) $_POST['ab_action'] ) : '';
		$started_test  = false;
		if ( $ab_action === 'start' && $new_id > 0 && class_exists( 'ES_AB' ) ) {
			$test_id = ES_AB::start_test( $new_id );
			$started_test = ( $test_id > 0 );
		}
		// Persist edits to the live variants when a test was already
		// running before this save. We deliberately skip this on the
		// same request that STARTED the test — start_test just seeded
		// both slots from the form's html/css snapshot, no point
		// overwriting them with the same POST values.
		if ( $new_id > 0 && ! $started_test && $ab_running && isset( $_POST['variants'] ) && is_array( $_POST['variants'] ) ) {
			$variants_in = [];
			foreach ( [ 'A', 'B' ] as $slot ) {
				$src = $_POST['variants'][ $slot ] ?? null;
				if ( ! is_array( $src ) ) {
					continue;
				}
				$variants_in[ $slot ] = [
					'html' => array_key_exists( 'html', $src ) ? wp_unslash( (string) $src['html'] ) : null,
					'css'  => array_key_exists( 'css',  $src ) ? wp_unslash( (string) $src['css']  ) : null,
				];
			}
			if ( $variants_in ) {
				ES_AB::save_variants( $new_id, $variants_in );
			}
		}

		// Test config (auto-winner toggle + per-condition thresholds +
		// traffic split). Runs on the request that started the test too
		// — so if the user customized thresholds before clicking Start,
		// the customizations stick. NULL-ing a threshold disables the
		// condition entirely in auto_winner_check.
		if ( $new_id > 0 && $ab_running && isset( $_POST['test_config'] ) && is_array( $_POST['test_config'] ) ) {
			$tc = $_POST['test_config'];
			$test_data = [
				'auto_declare' => ! empty( $tc['auto_declare'] ),
			];
			// Each enable_<name> checkbox gates its threshold. Checked +
			// non-empty value = active. Unchecked / empty = NULL (off).
			$conditions = [
				'min_runtime_days',
				'min_conversions_per_variant',
				'min_cr_gap_percent',
				'min_confidence_percent',
			];
			foreach ( $conditions as $col ) {
				$enabled = ! empty( $tc[ 'enable_' . $col ] );
				$raw     = isset( $tc[ $col ] ) ? wp_unslash( (string) $tc[ $col ] ) : '';
				$test_data[ $col ] = ( $enabled && $raw !== '' ) ? $raw : null;
			}
			if ( isset( $tc['traffic_a'] ) && $tc['traffic_a'] !== '' ) {
				$test_data['traffic_a'] = (int) wp_unslash( (string) $tc['traffic_a'] );
			}
			ES_AB::save_test_config( $new_id, $test_data );
		}

		$redirect_args = [
			'page'   => 'emblem-studio',
			'action' => 'edit',
			'id'     => $new_id,
			'saved'  => 1,
		];
		if ( $started_test ) {
			$redirect_args['test_started'] = 1;
		}
		// "Open editor" on a brand-new asset saves first, then re-opens into
		// the takeover editor on the reloaded (now-saved) edit screen.
		if ( ! empty( $_POST['es_open_editor'] ) ) {
			$redirect_args['es-inspect'] = 'open';
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_trash(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_trash_form_' . $id );
		if ( $id ) {
			ES_Form_Repo::trash( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&trashed=1' ) );
		exit;
	}

	/**
	 * End a running A/B test on a form. Two flavors via GET param:
	 *   ?winner=A or ?winner=B → declare that variant the winner. Its
	 *     html/css is copied into the form-level snapshot; both
	 *     variants are archived; the test row is marked completed.
	 *   ?winner=  (empty) → end without a winner. Form-level html/css
	 *     stays at the pre-test snapshot (so the form reverts cleanly);
	 *     both variants are archived; test row is marked completed
	 *     with winner_slot=NULL.
	 * Nonce scoped per-form so a stale link can't end someone else's test.
	 */
	public function handle_end_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		check_admin_referer( 'es_end_test_' . $form_id );
		if ( $form_id <= 0 ) {
			wp_die( esc_html__( 'Missing form_id.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		$winner_raw  = isset( $_GET['winner'] ) ? sanitize_key( (string) wp_unslash( $_GET['winner'] ) ) : '';
		$winner_slot = in_array( $winner_raw, [ 'A', 'B' ], true ) ? $winner_raw : null;
		$ok = ES_AB::end_test( $form_id, $winner_slot, 'manual' );

		$args = [
			'page'   => 'emblem-studio',
			'action' => 'edit',
			'id'     => $form_id,
		];
		if ( $ok ) {
			$args['test_ended'] = $winner_slot ?: 'cancelled';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Restore an archived A/B variant — starts a new test with the
	 * archived content seeded into slot B and the current form design
	 * in slot A. Per-variant nonce so links don't survive being copied.
	 */
	public function handle_restore_variant(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$variant_id = isset( $_GET['variant_id'] ) ? (int) $_GET['variant_id'] : 0;
		$form_id    = isset( $_GET['form_id'] )    ? (int) $_GET['form_id'] : 0;
		check_admin_referer( 'es_restore_variant_' . $variant_id );
		$new_test_id = ES_AB::restore_variant( $variant_id );
		$args = [ 'page' => 'emblem-studio', 'action' => 'edit', 'id' => $form_id ];
		$args[ $new_test_id ? 'variant_restored' : 'variant_restore_failed' ] = 1;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Hard-delete an archived A/B variant. Live variants can't be
	 * deleted via this — the underlying repo method enforces that.
	 */
	public function handle_delete_variant(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$variant_id = isset( $_GET['variant_id'] ) ? (int) $_GET['variant_id'] : 0;
		$form_id    = isset( $_GET['form_id'] )    ? (int) $_GET['form_id'] : 0;
		check_admin_referer( 'es_delete_variant_' . $variant_id );
		$ok = ES_AB::delete_variant( $variant_id );
		$args = [ 'page' => 'emblem-studio', 'action' => 'edit', 'id' => $form_id ];
		if ( $ok ) {
			$args['variant_deleted'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_restore(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_restore_form_' . $id );
		if ( $id ) {
			ES_Form_Repo::restore( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&filter=trash&restored=1' ) );
		exit;
	}

	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_delete_form_' . $id );
		ES_Form_Repo::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&filter=trash&deleted=1' ) );
		exit;
	}

	/**
	 * Archive an asset — hides it from the normal lists and stops it
	 * rendering, without deleting it (kept for history/comparison against
	 * newer assets; never auto-deleted). Not to be confused with the
	 * separate A/B-test "variant archive" (ES_AB) — this archives the
	 * whole asset.
	 */
	public function handle_archive(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_archive_form_' . $id );
		if ( $id ) {
			ES_Form_Repo::archive( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&archived=1' ) );
		exit;
	}

	public function handle_unarchive(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_unarchive_form_' . $id );
		if ( $id ) {
			ES_Form_Repo::restore_from_archive( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&filter=archived&restored=1' ) );
		exit;
	}

	public function handle_bulk(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'es_bulk_forms' );

		$action = sanitize_key( (string) ( $_POST['bulk_action'] ?? '' ) );
		$ids    = isset( $_POST['form_ids'] ) && is_array( $_POST['form_ids'] )
			? array_values( array_filter( array_map( 'intval', $_POST['form_ids'] ) ) )
			: [];
		$filter = isset( $_POST['filter'] ) ? sanitize_key( (string) $_POST['filter'] ) : 'all';

		$applied = 0;
		if ( $ids && $action !== '' ) {
			foreach ( $ids as $id ) {
				switch ( $action ) {
					case 'activate':
					case 'inactive':
						global $wpdb;
						// Bulk Activate skips archived and trashed forms rather
						// than switching them on behind their own state. Selecting
						// a whole page and pressing Activate is the likeliest way
						// to create a pile of Active-and-Archived forms at once.
						if ( $action === 'activate' ) {
							$current = ES_Form_Repo::find( $id );
							if ( ! $current || ! empty( $current->archived_at ) || ! empty( $current->trashed_at ) ) {
								break;
							}
						}
						$wpdb->update(
							ES_Form_Repo::table(),
							[ 'status' => $action === 'activate' ? 'active' : 'inactive', 'updated_at' => current_time( 'mysql' ) ],
							[ 'id' => $id ]
						);
						$applied++;
						break;
					case 'archive':
						ES_Form_Repo::archive( $id );
						$applied++;
						break;
					case 'unarchive':
						ES_Form_Repo::restore_from_archive( $id );
						$applied++;
						break;
					case 'trash':
						ES_Form_Repo::trash( $id );
						$applied++;
						break;
					case 'restore':
						ES_Form_Repo::restore( $id );
						$applied++;
						break;
					case 'delete_permanent':
						ES_Form_Repo::delete( $id );
						$applied++;
						break;
				}
			}
		}

		$redirect_args = [ 'page' => 'emblem-studio' ];
		if ( $filter !== 'all' ) {
			$redirect_args['filter'] = $filter;
		}
		if ( $applied > 0 ) {
			$redirect_args['bulk_applied'] = $applied;
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
