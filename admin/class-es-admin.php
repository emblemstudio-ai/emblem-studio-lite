<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_filter( 'admin_title', [ $this, 'filter_admin_title' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_head', [ $this, 'menu_icon_css' ] );
		add_action( 'in_admin_header', [ $this, 'maybe_suppress_admin_notices' ], 999 );
		add_action( 'admin_post_es_save_form', [ $this, 'handle_save' ] );
		add_action( 'admin_post_es_trash_form', [ $this, 'handle_trash' ] );
		add_action( 'admin_post_es_restore_form', [ $this, 'handle_restore' ] );
		add_action( 'admin_post_es_delete_form', [ $this, 'handle_delete' ] );
		add_action( 'admin_post_es_archive_form', [ $this, 'handle_archive' ] );
		add_action( 'admin_post_es_unarchive_form', [ $this, 'handle_unarchive' ] );
		add_action( 'admin_post_es_bulk_forms', [ $this, 'handle_bulk' ] );
		add_action( 'admin_post_es_save_settings', [ $this, 'handle_save_settings' ] );
		if ( ES_Caps::has( 'design_system' ) ) {
			add_action( 'admin_post_es_save_design_system', [ $this, 'handle_save_design_system' ] );
		}
		add_action( 'admin_post_es_export_leads', [ $this, 'handle_export_leads' ] );
		add_action( 'admin_post_es_reset_stats', [ $this, 'handle_reset_stats' ] );
		add_action( 'admin_post_es_delete_lead', [ $this, 'handle_delete_lead' ] );
		add_action( 'admin_post_es_move_lead_to_humans', [ $this, 'handle_move_lead_to_humans' ] );
		add_action( 'admin_post_es_retry_lead', [ $this, 'handle_retry_lead' ] );
		add_action( 'admin_post_es_duplicate_form', [ $this, 'handle_duplicate' ] );
		if ( ES_Caps::has( 'revisions' ) ) {
			add_action( 'admin_post_es_restore_revision',        [ $this, 'handle_restore_revision'        ] );
			add_action( 'admin_post_es_revision_note',           [ $this, 'handle_revision_note'           ] );
			add_action( 'admin_post_es_export_revision',         [ $this, 'handle_export_revision'         ] );
			add_action( 'admin_post_es_restore_revision_as_new', [ $this, 'handle_restore_revision_as_new' ] );
			add_action( 'admin_post_es_schedule_change',        [ $this, 'handle_schedule_change'        ] );
			add_action( 'admin_post_es_cancel_scheduled_change', [ $this, 'handle_cancel_scheduled_change' ] );
			add_action( 'admin_post_es_apply_scheduled_change',  [ $this, 'handle_apply_scheduled_change'  ] );
		}
		add_action( 'admin_post_es_export_form',  [ $this, 'handle_export_form'  ] );
		add_action( 'admin_post_es_export_all',   [ $this, 'handle_export_all'   ] );
		add_action( 'admin_post_es_import_forms', [ $this, 'handle_import_forms' ] );
		add_action( 'admin_post_es_install_bundle',          [ $this, 'handle_install_bundle'          ] );
		add_action( 'admin_post_es_install_form',            [ $this, 'handle_install_form'            ] );
		if ( ES_Caps::has( 'ab' ) ) {
			add_action( 'admin_post_es_restore_revision_as_variant', [ $this, 'handle_restore_revision_as_variant' ] );
			add_action( 'admin_post_es_end_test',                [ $this, 'handle_end_test'                ] );
			add_action( 'admin_post_es_restore_variant',         [ $this, 'handle_restore_variant'         ] );
			add_action( 'admin_post_es_delete_variant',          [ $this, 'handle_delete_variant'          ] );
		}
		add_action( 'wp_ajax_es_reorder_forms',              [ $this, 'handle_reorder_forms'          ] );
		add_action( 'wp_ajax_es_lock_beat',                  [ $this, 'handle_lock_beat'              ] );
		add_action( 'wp_ajax_es_lock_takeover',              [ $this, 'handle_lock_takeover'          ] );
		add_action( 'wp_ajax_es_lock_release',               [ $this, 'handle_lock_release'           ] );
		add_action( 'wp_ajax_es_lock_states',                [ $this, 'handle_lock_states'            ] );
		add_action( 'wp_ajax_es_check_asset',                [ $this, 'handle_check_asset'            ] );
		add_action( 'add_meta_boxes', [ $this, 'register_exclusion_metabox' ] );
		add_action( 'save_post',      [ $this, 'save_exclusion_metabox' ], 10, 2 );
	}

	private function exclusion_metabox_post_types(): array {
		return [ 'post', 'page' ];
	}

	private function exclusion_bucket( string $post_type ): string {
		return $post_type === 'page' ? 'pages' : 'posts';
	}

	private function exclusion_forms(): array {
		$out = [];
		foreach ( ES_Form_Repo::all( 'all' ) as $form ) {
			if ( array_intersect( ES_Form_Repo::types_for( $form ), ES_Form_Repo::auto_shells() ) ) {
				$out[] = $form;
			}
		}
		return $out;
	}

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

	private static function leads_back_url(): string {
		$fallback = admin_url( 'admin.php?page=emblem-studio-leads' );
		$ref      = (string) wp_get_referer();
		if ( $ref === '' || strpos( $ref, admin_url( 'admin.php' ) ) !== 0 ) {
			return $fallback;
		}
		parse_str( (string) wp_parse_url( $ref, PHP_URL_QUERY ), $query );
		if ( ( $query['page'] ?? '' ) !== 'emblem-studio-leads' ) {
			return $fallback;
		}
		return remove_query_arg( [ 'lead_deleted', 'lead_moved', 'lead_move_failed', 'lead_retried', 'lead_retry_failed' ], $ref );
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
		$back = self::leads_back_url();
		wp_safe_redirect( add_query_arg( 'lead_deleted', '1', $back ) );
		exit;
	}

	public function handle_move_lead_to_humans(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_move_lead_to_humans_' . $id );
		$push = ! isset( $_GET['push'] ) || (int) $_GET['push'] === 1;
		$result = $id ? ES_Leads::move_to_humans( $id, $push ) : [ 'ok' => false ];
		$back = self::leads_back_url();
		$flag = $result['ok'] ? 'lead_moved' : 'lead_move_failed';
		wp_safe_redirect( add_query_arg( $flag, '1', $back ) );
		exit;
	}

	public function handle_retry_lead(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'es_retry_lead_' . $id );
		$result = $id ? ES_Leads::retry_push( $id ) : [ 'ok' => false ];
		$back = self::leads_back_url();
		$flag = $result['ok'] ? 'lead_retried' : 'lead_retry_failed';
		wp_safe_redirect( add_query_arg( $flag, '1', $back ) );
		exit;
	}

	private function menu_icon(): string {
		$png = (string) file_get_contents( EMBLEM_STUDIO_PATH . 'assets/images/wp-admin-menu-icon-20x20.png' );

		return 'data:image/png;base64,' . base64_encode( $png );
	}

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
			. 'filter:drop-shadow(0 1px 2px rgba(0,0,0,.45)) !important;';

		$font_decl = "font-family:'Titillium Web','Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif !important;";

		echo '<style id="es-menu-icon-css">'
			. esc_html( $selector ) . '{' . $decl . '}'
			. esc_html( "{$sel} .wp-menu-name" ) . '{' . $font_decl . '}'
			. '</style>';
	}

	public function menu(): void {
		$connector = class_exists( 'Emblem_Studio_SaaS_Connector' );
		$connected = $connector && Emblem_Studio_SaaS_Connector::is_connected();

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
		if ( $connected ) {
			add_submenu_page( 'emblem-studio', __( 'Surveys', 'emblem-studio' ), __( 'Surveys', 'emblem-studio' ), 'manage_options', 'emblem-studio-surveys', [ $this, 'route_surveys' ] );
			add_submenu_page( 'emblem-studio', __( 'Sales notifications', 'emblem-studio' ), __( 'Sales notifications', 'emblem-studio' ), 'manage_options', 'emblem-studio-sales-notifications', [ $this, 'route_sales_notifications' ] );
		}
		if ( $connected ) {
			add_submenu_page(
				'emblem-studio',
				__( 'Schedule', 'emblem-studio' ),
				__( 'Schedule', 'emblem-studio' ),
				'manage_options',
				'emblem-studio-schedule',
				[ $this, 'route_schedule' ]
			);
		}
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
		if ( $connected ) {
			add_submenu_page( 'emblem-studio', __( 'Survey results', 'emblem-studio' ), __( 'Survey results', 'emblem-studio' ), 'manage_options', 'emblem-studio-survey-results', [ $this, 'route_survey_results' ] );
		}
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
		if ( $connected ) {
			add_submenu_page(
				'emblem-studio',
				__( 'Support', 'emblem-studio' ),
				__( 'Support', 'emblem-studio' ),
				'manage_options',
				'emblem-studio-tickets',
				[ $this, 'route_tickets' ]
			);
			add_submenu_page( 'emblem-studio', __( 'Team', 'emblem-studio' ), __( 'Team', 'emblem-studio' ), 'manage_options', 'emblem-studio-team', [ $this, 'route_team' ] );
		}
		add_submenu_page(
			'emblem-studio',
			__( 'Settings', 'emblem-studio' ),
			__( 'Settings', 'emblem-studio' ),
			'manage_options',
			'emblem-studio-settings',
			[ $this, 'route_settings' ]
		);
		add_submenu_page(
			'emblem-studio',
			__( 'Privacy & Cookies', 'emblem-studio' ),
			__( 'Privacy & Cookies', 'emblem-studio' ),
			'manage_options',
			'emblem-studio-privacy',
			[ $this, 'route_privacy' ]
		);
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

	public function route_surveys(): void {
		if ( ! $this->maybe_embed( 'emblem-studio-surveys' ) ) {
			$this->route();
		}
	}

	public function route_sales_notifications(): void {
		if ( ! $this->maybe_embed( 'emblem-studio-sales-notifications' ) ) {
			$this->route();
		}
	}

	public function route_survey_results(): void {
		if ( ! $this->maybe_embed( 'emblem-studio-survey-results' ) ) {
			$this->route();
		}
	}

	public function route_team(): void {
		if ( ! $this->maybe_embed( 'emblem-studio-team' ) ) {
			$this->route();
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

	public function handle_check_asset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( 'es_check_asset', 'nonce' );

		$html = isset( $_POST['html'] ) ? (string) wp_unslash( $_POST['html'] ) : '';
		$css  = isset( $_POST['css'] ) ? (string) wp_unslash( $_POST['css'] ) : '';

		wp_send_json_success( [ 'checks' => ES_Asset_Checks::run( $html, $css ) ] );
	}

	private function lock_request(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		if ( ! check_ajax_referer( 'es_lock', 'nonce', false ) ) {
			wp_send_json_error( [ 'reason' => 'nonce' ], 403 );
		}
		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( ! $form_id ) {
			wp_send_json_error( [ 'message' => 'No form id' ], 400 );
		}
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		return [ $form_id, get_current_user_id(), $token ];
	}

	public function handle_lock_beat(): void {
		list( $form_id, $me, $token ) = $this->lock_request();
		$held = ES_Locks::beat( $form_id, $me, $token, ! empty( $_POST['claim'] ) );
		$holder = $held ? 0 : ES_Locks::holder( $form_id );
		wp_send_json_success( array_merge( [
			'held'    => $held,
			'by'      => $held ? '' : ES_Locks::user_label( $holder ),
			'by_is_me' => ( ! $held && $holder === $me ),
		], $this->lock_fresh_nonces() ) );
	}

	private function lock_fresh_nonces(): array {
		return [
			'nonce'      => wp_create_nonce( 'es_lock' ),
			'save_nonce' => wp_create_nonce( 'es_save_form' ),
		];
	}

	public function handle_lock_takeover(): void {
		list( $form_id, $me, $token ) = $this->lock_request();
		ES_Locks::take_over( $form_id, $me, $token );
		wp_send_json_success( $this->lock_fresh_nonces() );
	}

	public function handle_lock_states(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( 'es_lock', 'nonce' );
		$raw = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ids'] ) ) : '';
		$ids = array_slice( array_map( 'intval', explode( ',', $raw ) ), 0, 500 );
		$me  = get_current_user_id();
		$out = [];
		foreach ( ES_Locks::holders( $ids ) as $form_id => $uid ) {
			$out[ $form_id ] = [ 'is_me' => $uid === $me, 'name' => ES_Locks::user_label( $uid ) ];
		}
		wp_send_json_success( (object) $out );
	}

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

	public function route_schedule(): void {
		if ( $this->maybe_embed( 'emblem-studio-schedule' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Schedule', 'emblem-studio' ); ?></h1>
			<p>
				<?php esc_html_e( 'Connect this site to Emblem Studio to see every scheduled asset and queued change on one calendar.', 'emblem-studio' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-account' ) ); ?>">
					<?php esc_html_e( 'Connect', 'emblem-studio' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

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

	public function route_privacy(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/privacy.php';
	}

	public function route_design_system(): void {
		if ( $this->maybe_embed( 'emblem-studio-design-system' ) ) {
			return;
		}
		if ( ! ES_Caps::has( 'design_system' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/design-system.php';
	}

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

	public static function normalize_import_envelope( $decoded ) {
		if ( ! is_array( $decoded ) || ! empty( $decoded['emblem_studio_export_version'] ) ) {
			return $decoded;
		}
		$schema = isset( $decoded['schema'] ) ? (string) $decoded['schema'] : '';
		if ( $schema === 'emblem-studio/forms' && isset( $decoded['forms'] ) && is_array( $decoded['forms'] ) ) {
			return [ 'emblem_studio_export_version' => '1', 'forms' => $decoded['forms'] ];
		}
		if ( $schema === 'emblem-studio/form' && isset( $decoded['html'] ) ) {
			return [ 'emblem_studio_export_version' => '1', 'forms' => [ $decoded ] ];
		}
		return $decoded;
	}

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

		$decoded = self::normalize_import_envelope( json_decode( $contents, true ) );
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
		$payload['slug'] = ( $form->slug ?: 'form' ) . '-rev-' . (int) $rev->id;

		$new_id = ES_Form_Repo::from_import_array( $payload );
		if ( ! $new_id ) {
			wp_die( esc_html__( 'Could not create the new form.', 'emblem-studio' ), '', [ 'response' => 500 ] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $new_id . '&restored_as_new=1' ) );
		exit;
	}

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

	public function handle_schedule_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( ! $form_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_schedule_change_' . $form_id );

		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		if ( class_exists( 'ES_AB' ) && ES_AB::running_test_for_form( $form_id ) ) {
			$this->back_to_scheduled( $form_id, 'ab' );
		}

		$apply_at    = isset( $_POST['apply_at'] ) ? sanitize_text_field( wp_unslash( $_POST['apply_at'] ) ) : '';
		$name        = isset( $_POST['change_name'] ) ? sanitize_text_field( wp_unslash( $_POST['change_name'] ) ) : '';
		$revision_id = isset( $_POST['revision_id'] ) ? (int) $_POST['revision_id'] : 0;

		if ( $revision_id ) {
			$rev = ES_Form_Revisions::find( $revision_id );
			if ( ! $rev || (int) $rev->form_id !== $form_id ) {
				wp_die( esc_html__( 'Revision not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
			}
			$html = (string) $rev->html;
			$css  = (string) $rev->css;
			$name = '' !== $name ? $name : __( 'From a saved version', 'emblem-studio' );
		} else {
			$html = (string) $form->html;
			$css  = (string) $form->css;
		}

		$new_id = ES_Form_Scheduled_Revisions::insert( $form_id, $html, $css, $apply_at, $name );
		$this->back_to_scheduled( $form_id, $new_id ? 'queued' : 'badtime' );
	}

	public function handle_cancel_scheduled_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id   = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		$change_id = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		if ( ! $form_id || ! $change_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_cancel_scheduled_change_' . $form_id . '_' . $change_id );

		$row = ES_Form_Scheduled_Revisions::find( $change_id );
		if ( ! $row || (int) $row->form_id !== $form_id ) {
			wp_die( esc_html__( 'Scheduled change not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		ES_Form_Scheduled_Revisions::cancel( $change_id, __( 'Cancelled from the admin screen.', 'emblem-studio' ) );
		$this->back_to_scheduled( $form_id, 'cancelled' );
	}

	public function handle_apply_scheduled_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'emblem-studio' ), '', [ 'response' => 403 ] );
		}
		$form_id   = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		$change_id = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		if ( ! $form_id || ! $change_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'emblem-studio' ), '', [ 'response' => 400 ] );
		}
		check_admin_referer( 'es_apply_scheduled_change_' . $form_id . '_' . $change_id );

		$row = ES_Form_Scheduled_Revisions::find( $change_id );
		if ( ! $row || (int) $row->form_id !== $form_id ) {
			wp_die( esc_html__( 'Scheduled change not found.', 'emblem-studio' ), '', [ 'response' => 404 ] );
		}

		$status = ES_Form_Scheduled_Revisions::apply( $change_id );
		$this->back_to_scheduled( $form_id, ES_Form_Scheduled_Revisions::APPLIED === $status ? 'applied' : $status );
	}

	private function back_to_scheduled( int $form_id, string $result ): void {
		wp_safe_redirect( admin_url(
			'admin.php?page=emblem-studio&action=edit&id=' . $form_id . '&es_sched=' . rawurlencode( $result ) . '#revisions'
		) );
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
		update_option( ES_Event_Recorder::OPT_DETECT_DATACENTER, ! empty( $_POST['detect_datacenter'] ) ? '1' : '0' );
		update_option( ES_Event_Recorder::OPT_TRUST_PROXY, ! empty( $_POST['trust_proxy'] ) ? '1' : '0' );

		$raw_ips = isset( $_POST['exclude_ips'] ) ? (string) wp_unslash( $_POST['exclude_ips'] ) : '';
		$ips     = [];
		foreach ( preg_split( '/[\r\n,]+/', $raw_ips ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' && filter_var( $line, FILTER_VALIDATE_IP ) ) {
				$ips[] = $line;
			}
		}
		update_option( ES_Event_Recorder::OPT_EXCLUDE_IPS, array_values( array_unique( $ips ) ) );

		update_option( 'emblem_studio_delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) ? '1' : '0' );

		wp_safe_redirect( admin_url( 'admin.php?page=emblem-studio-settings&saved=1' ) );
		exit;
	}

	public function maybe_suppress_admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
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
		wp_enqueue_style( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.css', [], $this->asset_ver( 'assets/admin.css' ) );

		$action = $_GET['action'] ?? '';
		$page   = $_GET['page'] ?? '';
		$is_editor = ( $page === 'emblem-studio-new' || $action === 'edit' );
		if ( $is_editor ) {
			$html_editor = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
			$css_editor  = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
			wp_enqueue_script( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.js', [ 'jquery', 'wp-theme-plugin-editor' ], $this->asset_ver( 'assets/admin.js' ), true );
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
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'presetNonce'   => wp_create_nonce( 'es_preset_ajax' ),
				'checkNonce'    => wp_create_nonce( 'es_check_asset' ),
				'checkLabels'   => [
					'error'   => __( 'Will break', 'emblem-studio' ),
					'warning' => __( 'Worth fixing', 'emblem-studio' ),
					'title'   => __( 'Checks', 'emblem-studio' ),
				],
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
			wp_enqueue_style( 'emblem-studio-inspector', EMBLEM_STUDIO_URL . 'assets/inspector.css', [ 'emblem-studio-admin' ], $this->asset_ver( 'assets/inspector.css' ) );
			wp_enqueue_script( 'emblem-studio-inspector-color', EMBLEM_STUDIO_URL . 'assets/inspector-color.js', [], $this->asset_ver( 'assets/inspector-color.js' ), true );
			wp_enqueue_script( 'emblem-studio-inspector', EMBLEM_STUDIO_URL . 'assets/inspector.js', [ 'emblem-studio-admin', 'emblem-studio-inspector-color' ], $this->asset_ver( 'assets/inspector.js' ), true );
		} elseif ( $page === 'emblem-studio-design-system' && ES_Caps::has( 'design_system' ) ) {
			$css_editor = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
			wp_enqueue_script( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.js', [ 'jquery' ], $this->asset_ver( 'assets/admin.js' ), true );
			wp_localize_script( 'emblem-studio-admin', 'EmblemStudioDS', [ 'cssEditor' => $css_editor ?: null ] );
		} else {
			wp_enqueue_script( 'emblem-studio-admin', EMBLEM_STUDIO_URL . 'assets/admin.js', [ 'jquery', 'jquery-ui-sortable' ], $this->asset_ver( 'assets/admin.js' ), true );
		}
		if ( $page === 'emblem-studio-reports' ) {
			wp_enqueue_script( 'emblem-studio-reports', EMBLEM_STUDIO_URL . 'assets/reports.js', [], $this->asset_ver( 'assets/reports.js' ), true );
		}
		if ( $page === 'emblem-studio' && $action !== 'edit' ) {
			wp_enqueue_script( 'emblem-studio-asset-preview', EMBLEM_STUDIO_URL . 'assets/asset-preview.js', [], $this->asset_ver( 'assets/asset-preview.js' ), true );
			wp_localize_script( 'emblem-studio-asset-preview', 'EmblemStudioPreview', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'es_preview_payload' ),
			] );
		}
	}

	private function asset_ver( string $rel ): string {
		$mtime = @filemtime( EMBLEM_STUDIO_PATH . $rel );
		return $mtime ? (string) $mtime : EMBLEM_STUDIO_VERSION;
	}

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
		$custom = sprintf( 'ES %d - %s', $id, (string) $form->name );
		return str_replace( $title, $custom, $admin_title );
	}

	private function maybe_embed( string $slug ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! class_exists( 'Emblem_Studio_SaaS_Connector' ) ) {
			return false;
		}
		return Emblem_Studio_SaaS_Connector::maybe_render_page( $slug );
	}

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
		if ( ! empty( $_GET['blank'] ) ) {
			$this->render_edit();
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EMBLEM_STUDIO_PATH . 'admin/views/form-picker.php';
	}

	private function render_list(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$valid_filters = [ 'all', 'active', 'inactive', 'scheduled', 'expired', 'archived', 'trash' ];
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
				if ( ! $lock_holder_is_me ) {
					$lock_holder_token = 'held-by-another-user';
				}
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

		$posted_token = isset( $_POST['lock_token'] ) ? sanitize_text_field( wp_unslash( $_POST['lock_token'] ) ) : '';
		if ( $id && ES_Locks::blocks_save( $id, get_current_user_id(), $posted_token ) ) {
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

		$name = ES_Form_Repo::clean_name( wp_unslash( $_POST['name'] ?? '' ) );
		if ( $name === '' ) {
			$name = 'Untitled form';
		}

		$slug = ES_Form_Repo::unique_slug( sanitize_title( $name ), $id );

		$lists = isset( $_POST['funnelkit_lists'] ) && is_array( $_POST['funnelkit_lists'] )
			? array_map( 'intval', $_POST['funnelkit_lists'] )
			: [];
		$tags_csv = wp_unslash( $_POST['funnelkit_tags_csv'] ?? '' );
		$tags     = array_values( array_filter( array_map( 'trim', preg_split( '/,/', $tags_csv ) ?: [] ) ) );

		$types = isset( $_POST['form_types'] ) && is_array( $_POST['form_types'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['form_types'] ) )
			: [];

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
				'exit_window_seconds' => $pick( 'exit_window_seconds', 0 ),
				'exit_window_autofire' => ! empty( $pick( 'exit_window_autofire', null ) ),
			];
			if ( $shell === 'ribbon' ) {
				$out['position'] = sanitize_key( (string) ( $_POST['ribbon_position'] ?? 'top' ) );
				$out['sticky']   = ! empty( $_POST['ribbon_sticky'] );
			}
			if ( $shell === 'slide-in' ) {
				$out['corner'] = sanitize_key( (string) ( $_POST['slide_in_corner'] ?? 'bottom-right' ) );
			}
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

		$mapping = [];
		if ( ! empty( $_POST['field_mapping_json'] ) ) {
			$decoded = json_decode( wp_unslash( (string) $_POST['field_mapping_json'] ), true );
			if ( is_array( $decoded ) ) {
				$mapping = $decoded;
			}
		}

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
			'use_design_system'    => ES_Caps::has( 'design_system' ) ? ( ! empty( $_POST['use_design_system'] ) ? 1 : 0 ) : 1,
			'popup_max_width'      => isset( $_POST['popup_max_width'] ) ? (int) $_POST['popup_max_width'] : 720,
			'popup_fullscreen'     => ! empty( $_POST['popup_fullscreen'] ) ? 1 : 0,
			'popup_close_theme'    => ES_Form_Repo::close_theme( sanitize_key( (string) ( $_POST['popup_close_theme'] ?? 'auto' ) ), 'dark' ),
			'active_from'          => isset( $_POST['active_from'] )  ? wp_unslash( $_POST['active_from'] )  : '',
			'active_until'         => isset( $_POST['active_until'] ) ? wp_unslash( $_POST['active_until'] ) : '',
		];

		if ( $id && $data['status'] === 'active' ) {
			$current = ES_Form_Repo::find( $id );
			if ( $current && ( ! empty( $current->archived_at ) || ! empty( $current->trashed_at ) ) ) {
				$data['status'] = 'inactive';
			}
		}

		if ( $id ) {
			$data['track_clicks'] = ! empty( $_POST['track_clicks'] ) ? 1 : 0;
			delete_transient( 'emblem_studio_buy_assets' );
		}

		$data['funnelkit_enabled'] = ! empty( $_POST['funnelkit_enabled'] ) ? 1 : 0;

		if ( isset( $_POST['funnelkit_contact_status'] ) ) {
			$data['funnelkit_contact_status'] = ES_FunnelKit_Upsert::contact_status( sanitize_key( wp_unslash( (string) $_POST['funnelkit_contact_status'] ) ) );
		}

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
			$data['webhooks'] = [];
		}

		if ( class_exists( 'ES_Connections' ) && isset( $_POST['connections'] ) && is_array( $_POST['connections'] ) ) {
			$conn = wp_unslash( $_POST['connections'] );
			$data['connections'] = [
				'suppress'      => isset( $conn['suppress'] ) && is_array( $conn['suppress'] ) ? array_map( 'intval', $conn['suppress'] ) : [],
				'mutual'        => ! empty( $conn['mutual'] ),
				'duration_days' => isset( $conn['duration_days'] ) ? (int) $conn['duration_days'] : ES_Connections::DEFAULT_DAYS,
				'behavior'      => in_array( ( $conn['behavior'] ?? 'nothing' ), [ 'hide', 'replace', 'nothing' ], true ) ? $conn['behavior'] : 'nothing',
			];
		} else {
			$data['connections'] = [];
		}

		$new_id = ES_Form_Repo::save( $data, $id ?: null );

		if ( $new_id > 0 && class_exists( 'ES_Form_Schedule' ) && ES_Caps::has( 'schedule_window' ) && isset( $_POST['periods'] ) && is_array( $_POST['periods'] ) ) {
			$periods_in = [];
			foreach ( wp_unslash( $_POST['periods'] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$periods_in[] = [
					'starts_at' => sanitize_text_field( (string) ( $row['starts_at'] ?? '' ) ),
					'ends_at'   => sanitize_text_field( (string) ( $row['ends_at'] ?? '' ) ),
				];
			}

			$periods_result = ES_Form_Schedule::replace( $new_id, $periods_in );
			if ( true !== $periods_result ) {
				$periods_error = $periods_result;
			} else {
				ES_Form_Repo::save( [], $new_id );
			}
		}

		$ab_action     = isset( $_POST['ab_action'] ) ? sanitize_key( (string) $_POST['ab_action'] ) : '';
		$started_test  = false;
		if ( $ab_action === 'start' && $new_id > 0 && class_exists( 'ES_AB' ) ) {
			$test_id = ES_AB::start_test( $new_id );
			$started_test = ( $test_id > 0 );
		}
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

		if ( $new_id > 0 && $ab_running && isset( $_POST['test_config'] ) && is_array( $_POST['test_config'] ) ) {
			$tc = $_POST['test_config'];
			$test_data = [
				'auto_declare' => ! empty( $tc['auto_declare'] ),
			];
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
		if ( ! empty( $periods_error ) ) {
			$redirect_args['periods_error'] = rawurlencode( $periods_error );
		}
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
		$winner_slot = ES_AB::normalize_slot( $winner_raw );
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
						if ( ES_Form_Repo::set_enabled( $id, $action === 'activate' ) ) {
							$applied++;
						}
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
