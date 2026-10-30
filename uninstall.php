<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * What gets removed is listed once, in includes/es-owned-data.php (audit S4).
 * This used to name eight tables and two settings by hand, so seven tables,
 * about twenty-five settings and ten scheduled jobs stayed behind, the web
 * app connection key among them.
 *
 * ALWAYS, on every uninstall:
 *  - every scheduled job (its code is gone, so it can only fail);
 *  - the stored credentials, the connection key first (a key left on a site
 *    without the plugin is a loose password to another system).
 *
 * ONLY when the admin opted in beforehand, via Settings → "When the plugin is
 * deleted → Also delete all Emblem Studio data": every table, setting and
 * cached value. WordPress runs uninstall.php non-interactively, so there's no
 * prompt at delete time — this stored preference is the "ask". It guards
 * against an accidental delete (or a delete-then-reinstall) silently
 * destroying every form, lead, and stat.
 *
 * NOTE: keep the option key in sync with admin/class-es-admin.php +
 * admin/views/settings.php (uninstall.php runs without the plugin loaded, so it
 * can't share a class constant).
 */

$es_owned = require __DIR__ . '/includes/es-owned-data.php';

if ( ! function_exists( 'emblem_studio_uninstall_site' ) ) {
	/** Clean one site. On a network, this runs for each site. */
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
			return; // keep tables + settings intact for a possible reinstall
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
