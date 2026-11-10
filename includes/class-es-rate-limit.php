<?php
final class Emblem_Studio_Rate_Limit {

	private const CACHE_GROUP = 'emblem_studio_rate_limit';

	private const STALE_AFTER = 10 * MINUTE_IN_SECONDS;

	private const SWEEP_ONE_IN = 1000;

	public static function table(): string {
		return Emblem_Studio_Install::table( 'rate_limits' );
	}

	public static function exceeded( string $key, int $window, int $max ): bool {
		$window = max( 1, $window );
		$start  = (int) ( floor( time() / $window ) * $window );
		$bucket = md5( $key );

		if ( wp_using_ext_object_cache() ) {
			$cache_key = $bucket . ':' . $start;
			wp_cache_add( $cache_key, 0, self::CACHE_GROUP, $window );
			$hits = wp_cache_incr( $cache_key, 1, self::CACHE_GROUP );
			if ( $hits !== false ) {
				return (int) $hits > $max;
			}
		}

		$hits = self::hit_table( $bucket, $start );
		if ( $hits === null ) {
			return false;
		}
		if ( wp_rand( 1, self::SWEEP_ONE_IN ) === 1 ) {
			self::purge_stale();
		}
		return $hits > $max;
	}

	private static function hit_table( string $bucket, int $start ): ?int {
		global $wpdb;
		$t  = self::table();
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT INTO $t (bucket, window_start, hits) VALUES (%s, %d, 1)
			 ON DUPLICATE KEY UPDATE hits = IF(window_start = %d, hits + 1, 1), window_start = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$bucket,
			$start,
			$start,
			$start
		) );
		if ( $ok === false ) {
			return null;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM $t WHERE bucket = %s", $bucket ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function claim_once( string $key, int $ttl ): bool {
		$bucket = md5( 'once:' . $key );
		$now    = time();

		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $bucket, 1, self::CACHE_GROUP, max( 1, $ttl ) );
		}

		global $wpdb;
		$t  = self::table();
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT INTO $t (bucket, window_start, hits) VALUES (%s, %d, 1)
			 ON DUPLICATE KEY UPDATE hits = IF(window_start < %d, 1, hits + 1), window_start = IF(window_start < %d, %d, window_start)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$bucket,
			$now + $ttl,
			$now,
			$now,
			$now + $ttl
		) );
		if ( $ok === false ) {
			return true;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM $t WHERE bucket = %s", $bucket ) ) === 1; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function purge_stale(): int {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE window_start < %d", time() - self::STALE_AFTER ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
