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
			if ( class_exists( 'ES_Preset_Ajax' ) ) {
				new ES_Preset_Ajax();
			}
		}

		// Schedule the daily log purge (retains last 30 days). Idempotent.
		if ( ! wp_next_scheduled( 'emblem_studio_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'emblem_studio_daily_purge' );
		}
		add_action( 'emblem_studio_daily_purge', [ $this, 'run_daily_purge' ] );

		// Move forms in and out of Scheduled and Expired as their start and end
		// times pass (SER-1270). WP-Cron only fires when someone visits the site,
		// so this is best effort and nothing depends on it: the public side
		// resolves the window live on every render, and the admin list
		// reconciles as it loads. This keeps the stored state fresh on a site
		// nobody happens to be looking at.
		add_filter( 'cron_schedules', [ $this, 'add_minute_schedule' ] );
		if ( ! wp_next_scheduled( 'emblem_studio_reconcile_statuses' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'emblem_studio_minute', 'emblem_studio_reconcile_statuses' );
		}
		add_action( 'emblem_studio_reconcile_statuses', [ $this, 'run_status_reconcile' ] );

		// Apply content changes whose scheduled time has arrived (SER-1287).
		// A SEPARATE hook from the one above on purpose: that decides whether a
		// form shows, this decides what it says, and a fault in one must not
		// stop the other.
		//
		// WP-Cron only fires on a page load, so on a quiet site a change WILL
		// run late. It runs late rather than never, which is the deliberate
		// rule: a promo panel that comes down late is untidy, one that never
		// comes down is a lie on a live page. Unlike the reconciler above,
		// nothing else covers this — there is no render-time fallback, because
		// applying a change is a write, not a reading of state.
		if ( ! wp_next_scheduled( 'emblem_studio_apply_scheduled_revisions' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'emblem_studio_minute', 'emblem_studio_apply_scheduled_revisions' );
		}
		add_action( 'emblem_studio_apply_scheduled_revisions', [ $this, 'run_scheduled_revisions' ] );
	}

	/** Apply any content changes that are due. See the note where this is hooked. */
	public function run_scheduled_revisions(): void {
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::apply_due();
		}
	}

	/**
	 * A one-minute interval for the schedule reconciler. WordPress ships five
	 * minutes as its shortest, and a start time five minutes late is a support
	 * ticket waiting to happen.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
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
	}

	public function run_status_reconcile(): void {
		ES_Form_Repo::reconcile_statuses();
	}
}
