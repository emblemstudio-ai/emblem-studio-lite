<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Plugin {

	private static ?Emblem_Studio_Plugin $instance = null;

	public static function instance(): Emblem_Studio_Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$shortcode = new Emblem_Studio_Shortcode();
		if ( Emblem_Studio_Caps::has( 'auto_shells' ) && class_exists( 'Emblem_Studio_Auto_Shells' ) ) {
			new Emblem_Studio_Auto_Shells( $shortcode );
		}
		new Emblem_Studio_Ajax();
		Emblem_Studio_No_Delay::register();
		Emblem_Studio_Live_Values::init();
		Emblem_Studio_Click_Tag::init();
		Emblem_Studio_Purchases::init();
		Emblem_Studio_Recent_Orders::init();
		new Emblem_Studio_Privacy();
		new Emblem_Studio_Preview();
		new Emblem_Studio_REST();
		if ( is_admin() ) {
			new Emblem_Studio_Admin();
			if ( class_exists( 'Emblem_Studio_Preset_Ajax' ) ) {
				new Emblem_Studio_Preset_Ajax();
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

		$has_scheduled_changes = false;
		if ( $has_scheduled_changes ) {
			if ( ! wp_next_scheduled( 'emblem_studio_apply_scheduled_revisions' ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'emblem_studio_minute', 'emblem_studio_apply_scheduled_revisions' );
			}
			add_action( 'emblem_studio_apply_scheduled_revisions', [ $this, 'run_scheduled_revisions' ] );
		} elseif ( wp_next_scheduled( 'emblem_studio_apply_scheduled_revisions' ) ) {
			wp_clear_scheduled_hook( 'emblem_studio_apply_scheduled_revisions' );
		}
	}

	public function run_scheduled_revisions(): void {
		if ( class_exists( 'Emblem_Studio_Form_Scheduled_Revisions' ) ) {
			Emblem_Studio_Form_Scheduled_Revisions::apply_due();
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
		Emblem_Studio_Logger::purge_old( $days );

		$bot_days = (int) apply_filters( 'emblem_studio_bot_retention_days', 90 );
		if ( $bot_days > 0 ) {
			Emblem_Studio_Event_Recorder::purge_bot_events( $bot_days );
			Emblem_Studio_Leads::purge_bot_leads( $bot_days );
		}

		Emblem_Studio_Rate_Limit::purge_stale();
	}

	public function run_status_reconcile(): void {
		Emblem_Studio_Form_Repo::reconcile_statuses( true );
	}
}
