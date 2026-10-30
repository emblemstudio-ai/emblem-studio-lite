<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$es_owned = require __DIR__ . '/includes/es-owned-data.php';

if ( ! function_exists( 'emblem_studio_uninstall_site' ) ) {
	function emblem_studio_uninstall_site( array $owned ): void {
		global $wpdb;

		$starts = static function ( string $name, array $prefixes ): bool {
			foreach ( $prefixes as $p ) {
				if ( strpos( $name, $p ) === 0 ) {
					return true;
				}
			}
			return false;
		};

		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( $starts( (string) $hook, $owned['cron_prefixes'] ) ) {
					wp_clear_scheduled_hook( (string) $hook );
				}
			}
		}

		foreach ( $owned['secrets'] as $name ) {
			delete_option( $name );
		}

		if ( get_option( 'emblem_studio_delete_data_on_uninstall' ) !== '1' ) {
			return;
		}

		$like   = $wpdb->esc_like( $wpdb->prefix . $owned['table_prefix'] ) . '%';
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		foreach ( $tables as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', (string) $t ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		foreach ( $owned['options'] as $name ) {
			delete_option( $name );
		}
		foreach ( $owned['transients'] as $name ) {
			delete_transient( $name );
		}
		$patterns = [];
		foreach ( $owned['option_prefixes'] as $p ) {
			$patterns[] = $wpdb->esc_like( $p ) . '%';
		}
		foreach ( $owned['transient_prefixes'] as $p ) {
			$patterns[] = $wpdb->esc_like( '_transient_' . $p ) . '%';
			$patterns[] = $wpdb->esc_like( '_transient_timeout_' . $p ) . '%';
		}
		foreach ( $patterns as $pattern ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		wp_cache_flush();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $es_site_id ) {
		switch_to_blog( (int) $es_site_id );
		emblem_studio_uninstall_site( $es_owned );
		restore_current_blog();
	}
} else {
	emblem_studio_uninstall_site( $es_owned );
}
