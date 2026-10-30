<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Logger {

	public static function log( string $level, string $message, array $context = [] ): void {
		global $wpdb;
		$wpdb->insert(
			ES_Install::table( 'logs' ),
			[
				'level'      => $level,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql' ),
			]
		);
	}

	public static function info( string $message, array $context = [] ): void {
		self::log( 'info', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::log( 'error', $message, $context );
	}

	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$t = ES_Install::table( 'logs' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/**
	 * Delete log rows older than $days. Default 30 days.
	 * Logs include email addresses (from FunnelKit push traces) — bounded retention
	 * is a GDPR / data-hygiene requirement.
	 */
	public static function purge_old( int $days = 30 ): int {
		global $wpdb;
		$t = ES_Install::table( 'logs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE created_at < %s", $cutoff ) );
	}
}
