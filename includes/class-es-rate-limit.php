<?php
/**
 * Per-visitor hit counters for the public endpoints' rate limits.
 *
 * These used to be WordPress transients, which on a site without a persistent
 * object cache are rows in wp_options: every tracking ping wrote one (plus its
 * timeout row), so a busy site filled the table WordPress reads its settings
 * from with thousands of throwaway rows. The expiry also slid forward on every
 * hit, so a steady visitor's counter never reset.
 *
 * Now the count lives in the object cache when the site has a real one, and in
 * a small table of our own when it doesn't. Windows are fixed: a counter only
 * ever covers the window it was made in, and the next window starts at zero.
 */
final class ES_Rate_Limit {

	private const CACHE_GROUP = 'emblem_studio_rate_limit';

	/** Rows older than this are of no use to any window we use (the longest is a minute). */
	private const STALE_AFTER = 10 * MINUTE_IN_SECONDS;

	/** One hit in this many also clears stale rows, so the table stays tiny between daily purges. */
	private const SWEEP_ONE_IN = 1000;

	public static function table(): string {
		return ES_Install::table( 'rate_limits' );
	}

	/**
	 * Count one hit on $key and say whether it went over $max in the current
	 * $window seconds. The caller builds $key from the scope and the visitor.
	 */
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
			// Table missing (an update that hasn't run its migration yet).
			// Fail open: a rate limit that blocks real visitors is worse.
			return false;
		}
		if ( wp_rand( 1, self::SWEEP_ONE_IN ) === 1 ) {
			self::purge_stale();
		}
		return $hits > $max;
	}

	/**
	 * One atomic upsert: a new window resets the count to 1, the same window
	 * adds one. MySQL applies the SET list left to right, so `hits` is worked
	 * out against the old `window_start` before that is moved on.
	 */
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

	/**
	 * True the first time $key is claimed within $ttl seconds, false after.
	 * Used to spend a form stamp once (audit S6). The row's window_start holds
	 * the expiry, so purge_stale() clears it ten minutes after it lapses.
	 * Fails open (true) when the table is missing, like exceeded().
	 */
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

	/** Drop counters from windows that have closed. Safe to call any time. */
	public static function purge_stale(): int {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE window_start < %d", time() - self::STALE_AFTER ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
