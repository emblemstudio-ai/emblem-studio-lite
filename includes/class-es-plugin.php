<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Plugin {

	private static ?ES_Plugin $instance = null;

	public static function instance(): ES_Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$shortcode = new ES_Shortcode();
		if ( ES_Caps::has( 'auto_shells' ) && class_exists( 'ES_Auto_Shells' ) ) {
			new ES_Auto_Shells( $shortcode );
		}
		new ES_Ajax();
		ES_No_Delay::register();
		ES_Live_Values::init();
		ES_Click_Tag::init();
		ES_Purchases::init();
		ES_Recent_Orders::init();
		new ES_Privacy();
		new ES_Preview();
		new ES_REST();
		if ( is_admin() ) {
			new ES_Admin();
			if ( class_exists( 'ES_Preset_Ajax' ) ) {
				new ES_Preset_Ajax();
			}
		}

		if ( ! wp_next_scheduled( 'emblem_studio_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'emblem_studio_daily_purge' );
		}
		add_action( 'emblem_studio_daily_purge', [ $this, 'run_daily_purge' ] );

		add_filter( 'cron_schedules', [ $this, 'add_minute_schedule' ] );
		if ( ! wp_next_scheduled( 'emblem_studio_reconcile_statuses' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'emblem_studio_minute', 'emblem_studio_reconcile_statuses' );
		}
		add_action( 'emblem_studio_reconcile_statuses', [ $this, 'run_status_reconcile' ] );

		if ( ! wp_next_scheduled( 'emblem_studio_apply_scheduled_revisions' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'emblem_studio_minute', 'emblem_studio_apply_scheduled_revisions' );
		}
		add_action( 'emblem_studio_apply_scheduled_revisions', [ $this, 'run_scheduled_revisions' ] );
	}

	public function run_scheduled_revisions(): void {
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::apply_due();
		}
	}

	public function add_minute_schedule( array $schedules ): array {
		if ( ! isset( $schedules['emblem_studio_minute'] ) ) {
			$schedules['emblem_studio_minute'] = [
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute (Emblem Studio)', 'emblem-studio' ),
			];
		}
		return $schedules;
	}

	public function run_daily_purge(): void {
		$days = (int) apply_filters( 'emblem_studio_log_retention_days', 30 );
		ES_Logger::purge_old( $days );

		$bot_days = (int) apply_filters( 'emblem_studio_bot_retention_days', 90 );
		if ( $bot_days > 0 ) {
			ES_Event_Recorder::purge_bot_events( $bot_days );
			ES_Leads::purge_bot_leads( $bot_days );
		}

		ES_Rate_Limit::purge_stale();
	}

	public function run_status_reconcile(): void {
		ES_Form_Repo::reconcile_statuses();
	}
}
