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
		// Auto-injected shells (popup-auto / ribbon / slide-in) are account-gated
		// (Free and up). The Lite build omits ES_Auto_Shells entirely; otherwise it
		// rides on the shortcode's shared render helpers.
		if ( ES_Caps::has( 'auto_shells' ) && class_exists( 'ES_Auto_Shells' ) ) {
			new ES_Auto_Shells( $shortcode );
		}
		new ES_Ajax();
		new ES_Privacy();
		new ES_Preview();
		new ES_REST();
		if ( is_admin() ) {
			new ES_Admin();
		}

		// Schedule the daily log purge (retains last 30 days). Idempotent.
		if ( ! wp_next_scheduled( 'emblem_studio_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'emblem_studio_daily_purge' );
		}
		add_action( 'emblem_studio_daily_purge', [ $this, 'run_daily_purge' ] );
	}

	public function run_daily_purge(): void {
		$days = (int) apply_filters( 'emblem_studio_log_retention_days', 30 );
		ES_Logger::purge_old( $days );
	}
}
