<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Install {

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::migrate_legacy_tables();

		$prefix          = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();

		$forms = "CREATE TABLE {$prefix}emblemstudio_forms (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			saas_form_id BIGINT(20) UNSIGNED NULL,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			html LONGTEXT NULL,
			css LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'inactive',
			enabled TINYINT(1) NOT NULL DEFAULT 0,
			funnelkit_list_id BIGINT(20) UNSIGNED NULL,
			funnelkit_lists LONGTEXT NULL,
			funnelkit_tags LONGTEXT NULL,
			field_mapping LONGTEXT NULL,
			form_types LONGTEXT NULL,
			success_mode VARCHAR(20) NOT NULL DEFAULT 'inline_state',
			success_message LONGTEXT NULL,
			success_redirect_url VARCHAR(2048) NULL,
			auto_trigger_config LONGTEXT NULL,
			display_rules LONGTEXT NULL,
			use_shadow_dom TINYINT(1) NOT NULL DEFAULT 0,
			use_design_system TINYINT(1) NOT NULL DEFAULT 1,
			popup_max_width INT(11) NOT NULL DEFAULT 720,
			popup_fullscreen TINYINT(1) NOT NULL DEFAULT 0,
			popup_close_theme VARCHAR(10) NOT NULL DEFAULT 'dark',
			track_clicks TINYINT(1) NOT NULL DEFAULT 1,
			track_purchases TINYINT(1) NOT NULL DEFAULT 0,
			funnelkit_enabled TINYINT(1) NOT NULL DEFAULT 0,
			funnelkit_contact_status VARCHAR(12) NOT NULL DEFAULT 'subscribed',
			webhooks LONGTEXT NULL,
			connections LONGTEXT NULL,
			lite_disabled LONGTEXT NULL,
			display_order INT(11) NOT NULL DEFAULT 0,
			active_from DATETIME NULL,
			active_until DATETIME NULL,
			trashed_at DATETIME NULL,
			archived_at DATETIME NULL,
			locked_by BIGINT(20) UNSIGNED NULL,
			locked_at DATETIME NULL,
			locked_token VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY trashed_at (trashed_at),
			KEY archived_at (archived_at),
			KEY saas_form_id (saas_form_id)
		) $charset_collate;";

		$events = "CREATE TABLE {$prefix}emblemstudio_events (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			saas_event_id BIGINT(20) UNSIGNED NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			event_type VARCHAR(20) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'inline',
			click_target VARCHAR(191) NULL,
			variant VARCHAR(8) NULL,
			post_id BIGINT(20) UNSIGNED NULL,
			ua VARCHAR(255) NULL,
			ip VARCHAR(45) NULL,
			visitor VARCHAR(40) NULL,
			lead_id BIGINT(20) UNSIGNED NULL,
			measurement_mode VARCHAR(12) NULL,
			step VARCHAR(64) NULL,
			source VARCHAR(12) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_event_time (form_id, event_type, created_at),
			KEY form_event_step (form_id, event_type, step),
			KEY form_type_event_time (form_id, type, event_type, created_at),
			KEY form_variant_event_time (form_id, variant, event_type, created_at),
			KEY form_visitor_time (form_id, visitor, created_at),
			KEY lead_id (lead_id),
			UNIQUE KEY saas_event_id (saas_event_id)
		) $charset_collate;";

		$leads = "CREATE TABLE {$prefix}emblemstudio_leads (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			saas_lead_id BIGINT(20) UNSIGNED NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			email VARCHAR(191) NOT NULL,
			extra_fields LONGTEXT NULL,
			funnelkit_contact_id BIGINT(20) UNSIGNED NULL,
			funnelkit_pushed TINYINT(1) NOT NULL DEFAULT 0,
			error TEXT NULL,
			is_bot TINYINT(1) NOT NULL DEFAULT 0,
			bot_signals TEXT NULL,
			ip VARCHAR(45) NULL,
			source TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY email (email),
			KEY is_bot (is_bot),
			UNIQUE KEY saas_lead_id (saas_lead_id)
		) $charset_collate;";

		$logs = "CREATE TABLE {$prefix}emblemstudio_logs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY level_time (level, created_at)
		) $charset_collate;";

		$revisions = "CREATE TABLE {$prefix}emblemstudio_form_revisions (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			html LONGTEXT NULL,
			css LONGTEXT NULL,
			note VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id, created_at)
		) $charset_collate;";

		$ds_revisions = "CREATE TABLE {$prefix}emblemstudio_design_system_revisions (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			css LONGTEXT NULL,
			note VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset_collate;";

		$variants = "CREATE TABLE {$prefix}emblemstudio_variants (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(191) NULL,
			slot VARCHAR(2) NULL,
			html LONGTEXT NULL,
			css LONGTEXT NULL,
			traffic_weight TINYINT(3) UNSIGNED NOT NULL DEFAULT 50,
			status VARCHAR(20) NOT NULL DEFAULT 'live',
			created_at DATETIME NOT NULL,
			archived_at DATETIME NULL,
			archive_conv_rate DECIMAL(5,2) NULL,
			PRIMARY KEY  (id),
			KEY form_status (form_id, status),
			KEY form_slot (form_id, slot)
		) $charset_collate;";

		$tests = "CREATE TABLE {$prefix}emblemstudio_tests (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			started_at DATETIME NOT NULL,
			ended_at DATETIME NULL,
			live_seconds BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			live_since DATETIME NULL,
			paused_at DATETIME NULL,
			pause_log LONGTEXT NULL,
			design_changed_at DATETIME NULL,
			winner_slot VARCHAR(2) NULL,
			ended_by VARCHAR(20) NULL,
			auto_declare TINYINT(1) NOT NULL DEFAULT 1,
			primary_metric VARCHAR(20) NOT NULL DEFAULT 'conversions',
			min_runtime_days INT(11) NULL,
			min_conversions_per_variant INT(11) NULL,
			min_cr_gap_percent DECIMAL(5,2) NULL,
			min_confidence_percent DECIMAL(5,2) NULL,
			auto_eval_last_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY form_status (form_id, status)
		) $charset_collate;";

		$presets = "CREATE TABLE {$prefix}emblemstudio_display_rule_presets (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			saas_preset_id BIGINT(20) UNSIGNED NULL,
			name VARCHAR(191) NOT NULL,
			show_mode VARCHAR(16) NOT NULL DEFAULT 'all',
			show_filters LONGTEXT NULL,
			hide_filters LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY saas_preset_id (saas_preset_id)
		) $charset_collate;";

		$consent_texts = "CREATE TABLE {$prefix}emblemstudio_consent_texts (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			hash VARCHAR(64) NOT NULL,
			text LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY hash (hash)
		) $charset_collate;";

		$purchases = "CREATE TABLE {$prefix}emblemstudio_purchases (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			ordered_at DATETIME NOT NULL,
			currency VARCHAR(8) NOT NULL DEFAULT '',
			total DECIMAL(18,2) NOT NULL DEFAULT 0,
			refunded DECIMAL(18,2) NOT NULL DEFAULT 0,
			is_renewal TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT '',
			touches LONGTEXT NULL,
			needs_push TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY needs_push (needs_push)
		) $charset_collate;";

		$lead_consents = "CREATE TABLE {$prefix}emblemstudio_lead_consents (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT(20) UNSIGNED NOT NULL,
			email VARCHAR(191) NULL,
			field_name VARCHAR(191) NOT NULL DEFAULT '',
			consent_text_hash VARCHAR(64) NOT NULL,
			checkbox_state TINYINT(1) NOT NULL,
			page_url VARCHAR(255) NULL,
			consent_version VARCHAR(191) NULL,
			form_revision_id BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lead_field (lead_id, field_name),
			KEY consent_text_hash (consent_text_hash),
			KEY email (email)
		) $charset_collate;";

		dbDelta( $forms );
		dbDelta( $events );
		dbDelta( $leads );
		dbDelta( $logs );
		dbDelta( $revisions );
		self::create_scheduling_tables();
		dbDelta( $ds_revisions );
		dbDelta( $variants );
		dbDelta( $tests );
		dbDelta( $presets );
		dbDelta( $consent_texts );
		dbDelta( $lead_consents );
		dbDelta( $purchases );
		self::create_rate_limits_table();

		update_option( 'emblem_studio_db_version', EMBLEM_STUDIO_DB_VERSION );
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'emblemstudio_' . $name;
	}

	private static function scheduling_tables_sql(): array {
		global $wpdb;
		$prefix          = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();

		$segments = "CREATE TABLE {$prefix}emblemstudio_form_schedule_segments (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			never_ran_reason VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_start (form_id, starts_at)
		) $charset_collate;";

		$scheduled = "CREATE TABLE {$prefix}emblemstudio_form_scheduled_revisions (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			html LONGTEXT NULL,
			css LONGTEXT NULL,
			name VARCHAR(191) NULL,
			apply_at DATETIME NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			applied_at DATETIME NULL,
			reason VARCHAR(500) NULL,
			based_on_revision_id BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY due (status, apply_at),
			KEY form_apply (form_id, apply_at)
		) $charset_collate;";

		return [ $segments, $scheduled ];
	}

	private static function create_scheduling_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		list( $segments, $scheduled ) = self::scheduling_tables_sql();
		if ( ES_Caps::has( 'schedule_window' ) ) {
			dbDelta( $segments );
		}
		if ( ES_Caps::has( 'revisions' ) ) {
			dbDelta( $scheduled );
		}
	}

	private static function backfill_schedule_segments(): void {
		global $wpdb;
		$forms    = self::table( 'forms' );
		$segments = self::table( 'form_schedule_segments' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $segments ) ) !== $segments ) {
			return;
		}
		$now  = current_time( 'mysql' );
		$rows = $wpdb->get_results(
			"SELECT id, active_from, active_until FROM {$forms} WHERE active_from IS NOT NULL OR active_until IS NOT NULL"
		);
		foreach ( (array) $rows as $row ) {
			$already = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$segments} WHERE form_id = %d",
				(int) $row->id
			) );
			if ( $already > 0 ) {
				continue;
			}
			$wpdb->insert( $segments, [
				'form_id'    => (int) $row->id,
				'starts_at'  => $row->active_from,
				'ends_at'    => $row->active_until,
				'position'   => 0,
				'created_at' => $now,
			] );
		}
	}

	public const MIGRATION_ERROR_OPTION = 'emblem_studio_migration_error';

	private static function record_migration_failure( string $step, string $error ): void {
		update_option( self::MIGRATION_ERROR_OPTION, [ 'step' => $step, 'error' => $error, 'at' => time() ], false );
	}

	private static function migration_failed_recently( string $step ): bool {
		$e = get_option( self::MIGRATION_ERROR_OPTION );
		return is_array( $e ) && ( $e['step'] ?? '' ) === $step && time() - (int) ( $e['at'] ?? 0 ) < HOUR_IN_SECONDS;
	}

	public static function migration_notice(): void {
		$e = get_option( self::MIGRATION_ERROR_OPTION );
		if ( ! is_array( $e ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Emblem Studio could not finish a database update.', 'emblem-studio' ) . '</strong> ';
		/* translators: %s: the update step number. */
		echo esc_html( sprintf( __( 'Step %s did not complete, so some stats may be missing. It is tried again every hour. If this stays, ask your host whether the database user may change tables.', 'emblem-studio' ), (string) ( $e['step'] ?? '' ) ) );
		if ( ! empty( $e['error'] ) ) {
			echo ' <code>' . esc_html( (string) $e['error'] ) . '</code>';
		}
		echo '</p></div>';
	}

	public static function maybe_migrate(): void {
		self::migrate_legacy_tables();

		$current = get_option( 'emblem_studio_db_version', '1' );

		if ( version_compare( $current, '2', '<' ) ) {
			global $wpdb;
			$events = self::table( 'events' );
			$wpdb->query( "UPDATE $events SET event_type = 'page_view' WHERE event_type = 'impression'" );
			update_option( 'emblem_studio_db_version', '2' );
		}

		if ( version_compare( $current, '3', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );

			$cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'funnelkit_lists', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN funnelkit_lists LONGTEXT NULL AFTER funnelkit_list_id" );
			}
			if ( ! in_array( 'funnelkit_tags', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN funnelkit_tags LONGTEXT NULL AFTER funnelkit_lists" );
			}

			$wpdb->query(
				"UPDATE $forms SET funnelkit_lists = JSON_ARRAY(funnelkit_list_id)
				 WHERE funnelkit_list_id IS NOT NULL AND funnelkit_list_id > 0
				 AND (funnelkit_lists IS NULL OR funnelkit_lists = '')"
			);

			update_option( 'emblem_studio_db_version', '3' );
		}

		if ( version_compare( $current, '4', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'field_mapping', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN field_mapping LONGTEXT NULL AFTER funnelkit_tags" );
			}
			update_option( 'emblem_studio_db_version', '4' );
		}

		if ( version_compare( $current, '5', '<' ) ) {
			global $wpdb;
			$forms  = self::table( 'forms' );
			$events = self::table( 'events' );

			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'form_types', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN form_types LONGTEXT NULL AFTER field_mapping" );
			}
			$wpdb->query( "UPDATE $forms SET form_types = '[\"inline\"]' WHERE form_types IS NULL OR form_types = ''" );

			$event_cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );
			if ( ! in_array( 'type', $event_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'inline' AFTER event_type" );
				$wpdb->query( "ALTER TABLE $events ADD INDEX form_type_event_time (form_id, type, event_type, created_at)" );
			}

			update_option( 'emblem_studio_db_version', '5' );
		}

		if ( version_compare( $current, '6', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'trashed_at', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN trashed_at DATETIME NULL AFTER success_redirect_url" );
				$wpdb->query( "ALTER TABLE $forms ADD INDEX trashed_at (trashed_at)" );
			}
			update_option( 'emblem_studio_db_version', '6' );
		}

		if ( version_compare( $current, '7', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );
			$wpdb->query( "UPDATE $forms SET status = 'inactive' WHERE status = 'draft'" );
			update_option( 'emblem_studio_db_version', '7' );
		}

		if ( version_compare( $current, '8', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'auto_trigger_config', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN auto_trigger_config LONGTEXT NULL AFTER form_types" );
			}
			update_option( 'emblem_studio_db_version', '8' );
		}

		if ( version_compare( $current, '9', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'display_rules', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN display_rules LONGTEXT NULL AFTER auto_trigger_config" );
			}
			update_option( 'emblem_studio_db_version', '9' );
		}

		if ( version_compare( $current, '10', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'use_shadow_dom', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN use_shadow_dom TINYINT(1) NOT NULL DEFAULT 0 AFTER display_rules" );
			}
			update_option( 'emblem_studio_db_version', '10' );
		}

		if ( version_compare( $current, '11', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'popup_max_width', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN popup_max_width INT(11) NOT NULL DEFAULT 720 AFTER use_shadow_dom" );
			}
			update_option( 'emblem_studio_db_version', '11' );
		}

		if ( version_compare( $current, '12', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'popup_fullscreen', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN popup_fullscreen TINYINT(1) NOT NULL DEFAULT 0 AFTER popup_max_width" );
			}
			update_option( 'emblem_studio_db_version', '12' );
		}

		if ( version_compare( $current, '13', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'active_from', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN active_from DATETIME NULL AFTER popup_fullscreen" );
			}
			if ( ! in_array( 'active_until', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN active_until DATETIME NULL AFTER active_from" );
			}
			update_option( 'emblem_studio_db_version', '13' );
		}

		if ( version_compare( $current, '14', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'popup_close_theme', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN popup_close_theme VARCHAR(10) NOT NULL DEFAULT 'light' AFTER popup_fullscreen" );
			}
			update_option( 'emblem_studio_db_version', '14' );
		}

		if ( version_compare( $current, '15', '<' ) ) {
			global $wpdb;
			$rev   = self::table( 'form_revisions' );
			$cc    = $wpdb->get_charset_collate();
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS $rev (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					form_id BIGINT(20) UNSIGNED NOT NULL,
					html LONGTEXT NULL,
					css LONGTEXT NULL,
					note VARCHAR(191) NULL,
					created_at DATETIME NOT NULL,
					created_by BIGINT(20) UNSIGNED NULL,
					PRIMARY KEY  (id),
					KEY form_id (form_id, created_at)
				) $cc"
			);
			update_option( 'emblem_studio_db_version', '15' );
		}

		if ( version_compare( $current, '16', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );
			$rows = $wpdb->get_results( "SELECT id, html FROM $forms WHERE html LIKE '<?xml%'" );
			foreach ( (array) $rows as $r ) {
				$cleaned = preg_replace( '/^(?:<\?xml[^>]*\??>\s*)+/i', '', (string) $r->html );
				if ( $cleaned !== $r->html ) {
					$wpdb->update( $forms, [ 'html' => $cleaned ], [ 'id' => (int) $r->id ] );
				}
			}
			update_option( 'emblem_studio_db_version', '16' );
		}

		if ( version_compare( $current, '17', '<' ) ) {
			global $wpdb;
			$forms      = self::table( 'forms' );
			$events     = self::table( 'events' );
			$form_cols  = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			$event_cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );
			if ( ! in_array( 'track_clicks', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN track_clicks TINYINT(1) NOT NULL DEFAULT 1 AFTER popup_close_theme" );
			}
			if ( ! in_array( 'click_target', $event_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN click_target VARCHAR(191) NULL AFTER event_type" );
			}
			update_option( 'emblem_studio_db_version', '17' );
		}

		if ( version_compare( $current, '18', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'webhooks', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN webhooks LONGTEXT NULL AFTER track_clicks" );
			}
			update_option( 'emblem_studio_db_version', '18' );
		}

		if ( version_compare( $current, '19', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'funnelkit_enabled', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN funnelkit_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER track_clicks" );
			}
			update_option( 'emblem_studio_db_version', '19' );
		}

		if ( version_compare( $current, '20', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'display_order', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN display_order INT(11) NOT NULL DEFAULT 0 AFTER webhooks" );
				$wpdb->query( "UPDATE $forms SET display_order = id WHERE display_order = 0" );
			}
			update_option( 'emblem_studio_db_version', '20' );
		}

		if ( version_compare( $current, '21', '<' ) ) {
			global $wpdb;
			$cc         = $wpdb->get_charset_collate();
			$events     = self::table( 'events' );
			$variants   = self::table( 'variants' );
			$tests      = self::table( 'tests' );
			$event_cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );

			if ( ! in_array( 'variant', $event_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN variant VARCHAR(8) NULL AFTER click_target" );
			}
			$event_indexes = $wpdb->get_col( "SHOW INDEX FROM $events", 2 );
			if ( ! in_array( 'form_variant_event_time', (array) $event_indexes, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD INDEX form_variant_event_time (form_id, variant, event_type, created_at)" );
			}

			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS $variants (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					form_id BIGINT(20) UNSIGNED NOT NULL,
					name VARCHAR(191) NULL,
					slot VARCHAR(2) NULL,
					html LONGTEXT NULL,
					css LONGTEXT NULL,
					traffic_weight TINYINT(3) UNSIGNED NOT NULL DEFAULT 50,
					status VARCHAR(20) NOT NULL DEFAULT 'live',
					created_at DATETIME NOT NULL,
					archived_at DATETIME NULL,
					archive_conv_rate DECIMAL(5,2) NULL,
					PRIMARY KEY  (id),
					KEY form_status (form_id, status),
					KEY form_slot (form_id, slot)
				) $cc"
			);

			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS $tests (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					form_id BIGINT(20) UNSIGNED NOT NULL,
					status VARCHAR(20) NOT NULL DEFAULT 'running',
					started_at DATETIME NOT NULL,
					ended_at DATETIME NULL,
					winner_slot VARCHAR(2) NULL,
					ended_by VARCHAR(20) NULL,
					auto_declare TINYINT(1) NOT NULL DEFAULT 1,
					min_runtime_days INT(11) NULL,
					min_conversions_per_variant INT(11) NULL,
					min_cr_gap_percent DECIMAL(5,2) NULL,
					min_confidence_percent DECIMAL(5,2) NULL,
					auto_eval_last_at DATETIME NULL,
					PRIMARY KEY  (id),
					KEY form_status (form_id, status)
				) $cc"
			);

			update_option( 'emblem_studio_db_version', '21' );
		}

		if ( version_compare( $current, '22', '<' ) ) {
			global $wpdb;
			$tests = self::table( 'tests' );
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM $tests" );
			if ( ! in_array( 'primary_metric', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $tests ADD COLUMN primary_metric VARCHAR(20) NOT NULL DEFAULT 'conversions' AFTER auto_declare" );
			}
			update_option( 'emblem_studio_db_version', '22' );
		}

		if ( version_compare( $current, '23', '<' ) ) {
			global $wpdb;
			$leads = self::table( 'leads' );
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM $leads" );
			if ( ! in_array( 'is_bot', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $leads ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER error" );
				$wpdb->query( "ALTER TABLE $leads ADD INDEX is_bot (is_bot)" );
			}
			if ( ! in_array( 'ip', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $leads ADD COLUMN ip VARCHAR(45) NULL AFTER is_bot" );
			}
			update_option( 'emblem_studio_db_version', '23' );
		}

		if ( version_compare( $current, '24', '<' ) ) {
			global $wpdb;
			$events     = self::table( 'events' );
			$event_cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );
			if ( ! in_array( 'ua', $event_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN ua VARCHAR(255) NULL AFTER post_id" );
			}
			if ( ! in_array( 'ip', $event_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN ip VARCHAR(45) NULL AFTER ua" );
			}
			update_option( 'emblem_studio_db_version', '24' );
		}

		if ( version_compare( $current, '25', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'locked_by', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN locked_by BIGINT(20) UNSIGNED NULL AFTER trashed_at" );
			}
			if ( ! in_array( 'locked_at', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN locked_at DATETIME NULL AFTER locked_by" );
			}
			update_option( 'emblem_studio_db_version', '25' );
		}

		if ( version_compare( $current, '26', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'connections', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN connections LONGTEXT NULL AFTER webhooks" );
			}
			update_option( 'emblem_studio_db_version', '26' );
		}

		if ( version_compare( $current, '27', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'locked_token', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN locked_token VARCHAR(64) NULL AFTER locked_at" );
			}
			update_option( 'emblem_studio_db_version', '27' );
		}

		if ( version_compare( $current, '28', '<' ) ) {
			global $wpdb;
			$events     = self::table( 'events' );
			$event_cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );
			if ( ! in_array( 'visitor', $event_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN visitor VARCHAR(40) NULL AFTER ip" );
				$wpdb->query( "ALTER TABLE $events ADD INDEX form_visitor_time (form_id, visitor, created_at)" );
			}
			update_option( 'emblem_studio_db_version', '28' );
		}

		if ( version_compare( $current, '29', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'use_design_system', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN use_design_system TINYINT(1) NOT NULL DEFAULT 1 AFTER use_shadow_dom" );
			}
			$ds_rev = self::table( 'design_system_revisions' );
			$charset_collate = $wpdb->get_charset_collate();
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( "CREATE TABLE {$ds_rev} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				css LONGTEXT NULL,
				note VARCHAR(191) NULL,
				created_at DATETIME NOT NULL,
				created_by BIGINT(20) UNSIGNED NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) $charset_collate;" );
			update_option( 'emblem_studio_db_version', '29' );
		}

		if ( version_compare( $current, '30', '<' ) ) {
			global $wpdb;
			$forms  = self::table( 'forms' );
			$events = self::table( 'events' );
			$leads  = self::table( 'leads' );

			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'saas_form_id', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN saas_form_id BIGINT(20) UNSIGNED NULL AFTER id" );
				$wpdb->query( "ALTER TABLE $forms ADD INDEX saas_form_id (saas_form_id)" );
			}

			$event_cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );
			if ( ! in_array( 'saas_event_id', $event_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN saas_event_id BIGINT(20) UNSIGNED NULL AFTER id" );
				$wpdb->query( "ALTER TABLE $events ADD UNIQUE KEY saas_event_id (saas_event_id)" );
			}

			$lead_cols = $wpdb->get_col( "SHOW COLUMNS FROM $leads" );
			if ( ! in_array( 'saas_lead_id', $lead_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $leads ADD COLUMN saas_lead_id BIGINT(20) UNSIGNED NULL AFTER id" );
				$wpdb->query( "ALTER TABLE $leads ADD UNIQUE KEY saas_lead_id (saas_lead_id)" );
			}

			update_option( 'emblem_studio_db_version', '30' );
		}

		if ( version_compare( $current, '31', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'lite_disabled', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN lite_disabled LONGTEXT NULL AFTER connections" );
			}
			update_option( 'emblem_studio_db_version', '31' );
		}

		if ( version_compare( $current, '32', '<' ) ) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			$presets         = self::table( 'display_rule_presets' );
			dbDelta( "CREATE TABLE {$presets} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				saas_preset_id BIGINT(20) UNSIGNED NULL,
				name VARCHAR(191) NOT NULL,
				show_mode VARCHAR(16) NOT NULL DEFAULT 'all',
				show_filters LONGTEXT NULL,
				hide_filters LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY saas_preset_id (saas_preset_id)
			) $charset_collate;" );
			update_option( 'emblem_studio_db_version', '32' );
		}

		if ( version_compare( $current, '33', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'archived_at', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN archived_at DATETIME NULL AFTER trashed_at" );
				$wpdb->query( "ALTER TABLE $forms ADD INDEX archived_at (archived_at)" );
			}
			update_option( 'emblem_studio_db_version', '33' );
		}

		if ( version_compare( $current, '34', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );
			$wpdb->query( "ALTER TABLE $forms MODIFY COLUMN funnelkit_enabled TINYINT(1) NOT NULL DEFAULT 0" );
			update_option( 'emblem_studio_db_version', '34' );
		}

		if ( version_compare( $current, '35', '<' ) ) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			$consent_texts   = self::table( 'consent_texts' );
			$lead_consents   = self::table( 'lead_consents' );
			dbDelta( "CREATE TABLE {$consent_texts} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				hash VARCHAR(64) NOT NULL,
				text LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash)
			) $charset_collate;" );
			dbDelta( "CREATE TABLE {$lead_consents} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				lead_id BIGINT(20) UNSIGNED NOT NULL,
				field_name VARCHAR(191) NOT NULL DEFAULT '',
				consent_text_hash VARCHAR(64) NOT NULL,
				checkbox_state TINYINT(1) NOT NULL,
				page_url VARCHAR(255) NULL,
				consent_version VARCHAR(191) NULL,
				form_revision_id BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY lead_field (lead_id, field_name),
				KEY consent_text_hash (consent_text_hash)
			) $charset_collate;" );
			update_option( 'emblem_studio_db_version', '35' );
		}

		if ( version_compare( $current, '36', '<' ) ) {
			global $wpdb;
			$lead_consents = self::table( 'lead_consents' );

			$has_field = $wpdb->get_var( "SHOW COLUMNS FROM {$lead_consents} LIKE 'field_name'" );
			if ( ! $has_field ) {
				$wpdb->query( "ALTER TABLE {$lead_consents} ADD COLUMN field_name VARCHAR(191) NOT NULL DEFAULT '' AFTER lead_id" );
			}

			$has_old_index = $wpdb->get_var( "SHOW INDEX FROM {$lead_consents} WHERE Key_name = 'lead_id'" );
			if ( $has_old_index ) {
				$wpdb->query( "ALTER TABLE {$lead_consents} DROP INDEX lead_id" );
			}

			$has_new_index = $wpdb->get_var( "SHOW INDEX FROM {$lead_consents} WHERE Key_name = 'lead_field'" );
			if ( ! $has_new_index ) {
				$wpdb->query( "ALTER TABLE {$lead_consents} ADD UNIQUE KEY lead_field (lead_id, field_name)" );
			}

			update_option( 'emblem_studio_db_version', '36' );
		}

		if ( version_compare( $current, '37', '<' ) ) {
			global $wpdb;
			$leads = self::table( 'leads' );

			$has_signals = $wpdb->get_var( "SHOW COLUMNS FROM {$leads} LIKE 'bot_signals'" );
			if ( ! $has_signals ) {
				$wpdb->query( "ALTER TABLE {$leads} ADD COLUMN bot_signals TEXT NULL AFTER is_bot" );
			}

			delete_option( 'es_saas_pull_leads_after' );

			update_option( 'emblem_studio_db_version', '37' );
		}

		if ( version_compare( $current, '38', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );

			$wpdb->query( "UPDATE {$forms} SET status = 'inactive' WHERE trashed_at IS NOT NULL AND status = 'active'" );
			$wpdb->query( "UPDATE {$forms} SET status = 'inactive' WHERE archived_at IS NOT NULL AND status = 'active'" );

			update_option( 'emblem_studio_db_version', '38' );
		}

		if ( version_compare( $current, '39', '<' ) ) {
			global $wpdb;
			$forms = self::table( 'forms' );
			$tests = self::table( 'tests' );

			$has_enabled = $wpdb->get_var( "SHOW COLUMNS FROM {$forms} LIKE 'enabled'" );
			if ( ! $has_enabled ) {
				$wpdb->query( "ALTER TABLE {$forms} ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status" );
				$wpdb->query( "UPDATE {$forms} SET enabled = 1 WHERE status = 'active'" );
			}

			$has_live = $wpdb->get_var( "SHOW COLUMNS FROM {$tests} LIKE 'live_seconds'" );
			if ( ! $has_live ) {
				$wpdb->query( "ALTER TABLE {$tests} ADD COLUMN live_seconds BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER ended_at" );
				$wpdb->query( "ALTER TABLE {$tests} ADD COLUMN live_since DATETIME NULL AFTER live_seconds" );
				$wpdb->query( "ALTER TABLE {$tests} ADD COLUMN paused_at DATETIME NULL AFTER live_since" );
				$wpdb->query( "ALTER TABLE {$tests} ADD COLUMN pause_log LONGTEXT NULL AFTER paused_at" );
				$wpdb->query( "ALTER TABLE {$tests} ADD COLUMN design_changed_at DATETIME NULL AFTER pause_log" );
				$wpdb->query( "UPDATE {$tests} SET live_since = started_at WHERE status = 'running'" );
			}

			if ( class_exists( 'ES_Form_Repo' ) ) {
				ES_Form_Repo::reconcile_statuses();
			}

			update_option( 'emblem_studio_db_version', '39' );
		}

		if ( version_compare( $current, '41', '<' ) ) {
			self::create_scheduling_tables();
			self::backfill_schedule_segments();
			update_option( 'emblem_studio_db_version', '41' );
		}

		if ( version_compare( $current, '42', '<' ) ) {
			self::create_scheduling_tables();
			self::backfill_schedule_segments();
			update_option( 'emblem_studio_db_version', '42' );
		}

		if ( version_compare( $current, '43', '<' ) ) {
			self::create_purchases_table();
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $forms" );
			if ( ! in_array( 'track_purchases', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN track_purchases TINYINT(1) NOT NULL DEFAULT 0 AFTER track_clicks" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			update_option( 'emblem_studio_db_version', '43' );
		}

		if ( version_compare( $current, '44', '<' ) ) {
			global $wpdb;
			$consents = self::table( 'lead_consents' );
			$leads    = self::table( 'leads' );
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$consents} LIKE 'email'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$consents} ADD COLUMN email VARCHAR(191) NULL AFTER lead_id, ADD KEY email (email)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->query( "UPDATE {$consents} c JOIN {$leads} l ON l.id = c.lead_id SET c.email = l.email WHERE c.email IS NULL AND l.email IS NOT NULL AND l.email <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			update_option( 'emblem_studio_db_version', '44' );
		}

		if ( version_compare( $current, '45', '<' ) ) {
			if ( self::migration_failed_recently( '45' ) ) {
				return;
			}
			global $wpdb;
			$events = self::table( 'events' );
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );
			if ( ! in_array( 'lead_id', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN lead_id BIGINT(20) UNSIGNED NULL, ADD KEY lead_id (lead_id)" );
			}
			if ( ! in_array( 'measurement_mode', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN measurement_mode VARCHAR(12) NULL" );
			}
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM $events" );
			if ( in_array( 'lead_id', $cols, true ) && in_array( 'measurement_mode', $cols, true ) ) {
				update_option( 'emblem_studio_db_version', '45' );
				delete_option( self::MIGRATION_ERROR_OPTION );
			} else {
				self::record_migration_failure( '45', (string) $wpdb->last_error );
				return;
			}
		}

		if ( version_compare( $current, '46', '<' ) ) {
			global $wpdb;
			$forms     = self::table( 'forms' );
			$form_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $forms" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! in_array( 'funnelkit_contact_status', $form_cols, true ) ) {
				$wpdb->query( "ALTER TABLE $forms ADD COLUMN funnelkit_contact_status VARCHAR(12) NOT NULL DEFAULT 'subscribed' AFTER funnelkit_enabled" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$form_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $forms" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( in_array( 'funnelkit_contact_status', $form_cols, true ) ) {
				update_option( 'emblem_studio_db_version', '46' );
			}
		}

		if ( version_compare( $current, '47', '<' ) ) {
			global $wpdb;
			$events = self::table( 'events' );
			$cols   = (array) $wpdb->get_col( "SHOW COLUMNS FROM $events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! in_array( 'step', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN step VARCHAR(64) NULL, ADD KEY form_event_step (form_id, event_type, step)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( in_array( 'step', $cols, true ) ) {
				update_option( 'emblem_studio_db_version', '47' );
			}
		}

		if ( version_compare( $current, '48', '<' ) ) {
			global $wpdb;
			self::create_rate_limits_table();
			$t = ES_Rate_Limit::table();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
				foreach ( [ '_transient_esf_rl_', '_transient_timeout_esf_rl_' ] as $prefix ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
				}
				update_option( 'emblem_studio_db_version', '48' );
			}
		}

		if ( version_compare( $current, '49', '<' ) ) {
			global $wpdb;
			$events = self::table( 'events' );
			$leads  = self::table( 'leads' );
			$ecols  = (array) $wpdb->get_col( "SHOW COLUMNS FROM $events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! in_array( 'source', $ecols, true ) ) {
				$wpdb->query( "ALTER TABLE $events ADD COLUMN source VARCHAR(12) NULL AFTER step" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$lcols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $leads" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! in_array( 'source', $lcols, true ) ) {
				$wpdb->query( "ALTER TABLE $leads ADD COLUMN source TEXT NULL AFTER ip" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$ecols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lcols = (array) $wpdb->get_col( "SHOW COLUMNS FROM $leads" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( in_array( 'source', $ecols, true ) && in_array( 'source', $lcols, true ) ) {
				update_option( 'emblem_studio_db_version', '49' );
			}
		}
	}

	private static function create_rate_limits_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = ES_Rate_Limit::table();
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			bucket CHAR(32) NOT NULL,
			window_start INT(10) UNSIGNED NOT NULL,
			hits INT(10) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (bucket),
			KEY window_start (window_start)
		) $charset_collate;" );
	}

	private static function create_purchases_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$prefix          = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$prefix}emblemstudio_purchases (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			ordered_at DATETIME NOT NULL,
			currency VARCHAR(8) NOT NULL DEFAULT '',
			total DECIMAL(18,2) NOT NULL DEFAULT 0,
			refunded DECIMAL(18,2) NOT NULL DEFAULT 0,
			is_renewal TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT '',
			touches LONGTEXT NULL,
			needs_push TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY needs_push (needs_push)
		) $charset_collate"
		);
	}

	private static function migrate_legacy_tables(): void {
		if ( get_option( 'optin_studio_db_version', false ) === false ) {
			return;
		}
		global $wpdb;
		$prefix = $wpdb->prefix;

		foreach ( [ 'forms', 'events', 'leads', 'logs', 'form_revisions', 'variants', 'tests' ] as $name ) {
			$old = $prefix . 'optinstudio_' . $name;
			$new = $prefix . 'emblemstudio_' . $name;
			$has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old;
			$has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) === $new;
			if ( $has_old && ! $has_new ) {
				$wpdb->query( "RENAME TABLE `$old` TO `$new`" );
			}
		}

		$like   = $wpdb->esc_like( 'optin_studio_' ) . '%';
		$legacy = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		foreach ( (array) $legacy as $old_key ) {
			$new_key = 'emblem_studio_' . substr( (string) $old_key, strlen( 'optin_studio_' ) );
			if ( get_option( $new_key, null ) === null ) {
				update_option( $new_key, get_option( (string) $old_key ) );
			}
			delete_option( (string) $old_key );
		}
	}
}
