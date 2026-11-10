<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Reports {

	private static function pad_dt( string $s, string $end ): string {
		if ( strlen( $s ) <= 10 ) {
			return $s . ( $end === 'end' ? ' 23:59:59' : ' 00:00:00' );
		}
		return $s;
	}

	public static function kpis( int $form_id, string $from, string $to, ?string $shell = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = 'WHERE form_id = %d AND created_at >= %s AND created_at <= %s';
		$params = [ $form_id, self::pad_dt( $from, 'start' ), self::pad_dt( $to, 'end' ) ];
		if ( $shell ) {
			$where .= ' AND type = %s';
			$params[] = $shell;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN event_type = 'page_view'        THEN 1 ELSE 0 END) AS page_views,
				SUM(CASE WHEN event_type = 'trigger_view'     THEN 1 ELSE 0 END) AS trigger_views,
				SUM(CASE WHEN event_type = 'view'             THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN event_type = 'engage'           THEN 1 ELSE 0 END) AS engagements,
				SUM(CASE WHEN event_type = 'conversion'       THEN 1 ELSE 0 END) AS conversions,
				SUM(CASE WHEN event_type = 'bot_filtered'     THEN 1 ELSE 0 END) AS bot_filtered,
				SUM(CASE WHEN event_type = 'honeypot_blocked' THEN 1 ELSE 0 END) AS honeypot_blocked,
				SUM(CASE WHEN event_type = 'click' AND click_target IN ('__close__','__backdrop__') THEN 1 ELSE 0 END) AS dismissals
			FROM $events
			$where",
			$params
		) );

		$pv  = (int) ( $row->page_views ?? 0 );
		$tv  = (int) ( $row->trigger_views ?? 0 );
		$v   = (int) ( $row->views ?? 0 );
		$eng = (int) ( $row->engagements ?? 0 );
		$c   = (int) ( $row->conversions ?? 0 );
		$bf  = (int) ( $row->bot_filtered ?? 0 );
		$hp  = (int) ( $row->honeypot_blocked ?? 0 );
		$dis = (int) ( $row->dismissals ?? 0 );

		return [
			'page_views'       => $pv,
			'trigger_views'    => $tv,
			'views'            => $v,
			'engagements'      => $eng,
			'conversions'      => $c,
			'bot_filtered'     => $bf,
			'honeypot_blocked' => $hp,
			'dismissals'    => $dis,
			'close_rate'    => $v  > 0 ? round( $dis / $v * 100, 2 ) : 0.0,
			'view_rate'     => $pv > 0 ? round( $v / $pv * 100, 2 ) : 0.0,
			'conv_rate'     => $v  > 0 ? round( $c / $v  * 100, 2 ) : 0.0,
			'conv_rate_pv'  => $pv > 0 ? round( $c / $pv * 100, 2 ) : 0.0,
			'ctr'           => ( $shell === 'popup-click' && $tv > 0 ) ? round( $v / $tv * 100, 2 ) : null,
		];
	}

	public static function timeseries( ?int $form_id, string $from, string $to, string $granularity = 'day', ?string $shell = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$from_full = self::pad_dt( $from, 'start' );
		$to_full   = self::pad_dt( $to, 'end' );

		$bucket = $granularity === 'hour'
			? "DATE_FORMAT(created_at, '%%Y-%%m-%%dT%%H:00')"
			: "DATE(created_at)";

		$where  = '';
		$params = [];
		if ( $form_id ) {
			$where .= ' AND form_id = %d';
			$params[] = $form_id;
		}
		if ( $shell ) {
			$where .= ' AND type = %s';
			$params[] = $shell;
		}

		array_unshift( $params, $from_full, $to_full );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT $bucket AS d,
				SUM(CASE WHEN event_type = 'page_view'    THEN 1 ELSE 0 END) AS page_views,
				SUM(CASE WHEN event_type = 'trigger_view' THEN 1 ELSE 0 END) AS trigger_views,
				SUM(CASE WHEN event_type = 'view'         THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN event_type = 'conversion'   THEN 1 ELSE 0 END) AS conversions
			FROM $events
			WHERE created_at >= %s AND created_at <= %s $where
			GROUP BY d ORDER BY d ASC",
			$params
		) );

		$by_key = [];
		foreach ( (array) $rows as $r ) {
			$by_key[ $r->d ] = [
				'page_views'    => (int) $r->page_views,
				'trigger_views' => (int) $r->trigger_views,
				'views'         => (int) $r->views,
				'conversions'   => (int) $r->conversions,
			];
		}

		$tz  = wp_timezone();
		$out = [];

		if ( $granularity === 'hour' ) {
			$cur = ( new DateTimeImmutable( $from_full, $tz ) )->setTime( (int) substr( $from_full, 11, 2 ), 0, 0 );
			$end = new DateTimeImmutable( $to_full, $tz );
			while ( $cur <= $end ) {
				$key = $cur->format( 'Y-m-d\TH:00' );
				$out[] = [
					'date'          => $key,
					'page_views'    => $by_key[ $key ]['page_views']    ?? 0,
					'trigger_views' => $by_key[ $key ]['trigger_views'] ?? 0,
					'views'         => $by_key[ $key ]['views']         ?? 0,
					'conversions'   => $by_key[ $key ]['conversions']   ?? 0,
				];
				$cur = $cur->modify( '+1 hour' );
			}
		} else {
			$cur = new DateTimeImmutable( substr( $from_full, 0, 10 ), $tz );
			$end = new DateTimeImmutable( substr( $to_full, 0, 10 ), $tz );
			while ( $cur <= $end ) {
				$key = $cur->format( 'Y-m-d' );
				$out[] = [
					'date'          => $key,
					'page_views'    => $by_key[ $key ]['page_views']    ?? 0,
					'trigger_views' => $by_key[ $key ]['trigger_views'] ?? 0,
					'views'         => $by_key[ $key ]['views']         ?? 0,
					'conversions'   => $by_key[ $key ]['conversions']   ?? 0,
				];
				$cur = $cur->modify( '+1 day' );
			}
		}

		return $out;
	}

	public static function last_n_days( int $form_id, int $days = 7 ): array {
		$to   = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$from = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
		return self::kpis( $form_id, $from, $to );
	}

	public static function all_time( int $form_id, ?string $shell = null, ?string $variant = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = 'WHERE form_id = %d';
		$params = [ $form_id ];
		if ( $shell ) {
			$where .= ' AND type = %s';
			$params[] = $shell;
		}
		if ( $variant !== null && in_array( $variant, [ 'A', 'B' ], true ) ) {
			$where .= ' AND variant = %s';
			$params[] = $variant;
		}
		$until = class_exists( 'Emblem_Studio_Stats_Summary' ) ? Emblem_Studio_Stats_Summary::cover_until( $form_id ) : null;
		if ( $until !== null ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $until;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN event_type = 'page_view'        THEN 1 ELSE 0 END) AS page_views,
				SUM(CASE WHEN event_type = 'trigger_view'     THEN 1 ELSE 0 END) AS trigger_views,
				SUM(CASE WHEN event_type = 'view'             THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN event_type = 'conversion'       THEN 1 ELSE 0 END) AS conversions,
				SUM(CASE WHEN event_type = 'engage'           THEN 1 ELSE 0 END) AS engagements,
				SUM(CASE WHEN event_type = 'dropoff'          THEN 1 ELSE 0 END) AS dropoffs,
				SUM(CASE WHEN event_type = 'bot_filtered'     THEN 1 ELSE 0 END) AS bot_filtered,
				SUM(CASE WHEN event_type = 'honeypot_blocked' THEN 1 ELSE 0 END) AS honeypot_blocked,
				SUM(CASE WHEN event_type = 'click'            THEN 1 ELSE 0 END) AS clicks_all,
				SUM(CASE WHEN event_type = 'click' AND (click_target IS NULL OR click_target NOT IN ('__close__','__backdrop__')) THEN 1 ELSE 0 END) AS clicks_meaningful,
				SUM(CASE WHEN event_type = 'click' AND click_target IN ('__close__','__backdrop__') THEN 1 ELSE 0 END) AS dismissals,
				COUNT(DISTINCT CASE WHEN event_type = 'view' AND visitor IS NOT NULL AND visitor <> '' THEN visitor END) AS unique_visitors,
				COUNT(DISTINCT CASE WHEN event_type = 'conversion' AND visitor IS NOT NULL AND visitor <> '' THEN visitor END) AS unique_conversions,
				COUNT(DISTINCT CASE WHEN event_type = 'click' AND (click_target IS NULL OR click_target NOT IN ('__close__','__backdrop__')) AND visitor IS NOT NULL AND visitor <> '' THEN visitor END) AS unique_clickers,
				COUNT(DISTINCT CASE WHEN event_type = 'dropoff' AND visitor IS NOT NULL AND visitor <> '' THEN visitor END) AS unique_droppers,
				SUM(CASE WHEN event_type = 'conversion' AND visitor IS NOT NULL AND visitor <> '' THEN 1 ELSE 0 END) AS labelled_conversions,
				SUM(CASE WHEN event_type = 'view' AND (visitor IS NULL OR visitor = '') THEN 1 ELSE 0 END) AS unknown_views
			FROM $events $where",
			$params
		) );
		if ( $until !== null ) {
			$row = self::all_time_with_summary( (object) ( $row ?: [] ), $form_id, $shell, $variant, $until, $where, $params );
		}

		$pv  = (int) ( $row->page_views ?? 0 );
		$tv  = (int) ( $row->trigger_views ?? 0 );
		$v   = (int) ( $row->views ?? 0 );
		$c   = (int) ( $row->conversions ?? 0 );
		$eng = (int) ( $row->engagements ?? 0 );
		$drop = (int) ( $row->dropoffs ?? 0 );
		$bf  = (int) ( $row->bot_filtered ?? 0 );
		$hp  = (int) ( $row->honeypot_blocked ?? 0 );
		$cl_all  = (int) ( $row->clicks_all ?? 0 );
		$cl_mean = (int) ( $row->clicks_meaningful ?? 0 );
		$dis = (int) ( $row->dismissals ?? 0 );
		$uv  = (int) ( $row->unique_visitors ?? 0 );
		$uc  = (int) ( $row->unique_conversions ?? 0 );
		$ucl = (int) ( $row->unique_clickers ?? 0 );
		$udr = (int) ( $row->unique_droppers ?? 0 );
		$lc  = (int) ( $row->labelled_conversions ?? 0 );

		return [
			'page_views'       => $pv,
			'trigger_views'    => $tv,
			'views'            => $v,
			'conversions'      => $c,
			'engagements'      => $eng,
			'dropoffs'         => $drop,
			'dropoff_rate'     => $eng > 0 ? round( $drop / $eng * 100, 2 ) : 0.0,
			'bot_filtered'     => $bf,
			'honeypot_blocked' => $hp,
			'clicks'              => $cl_all,
			'meaningful_clicks'   => $cl_mean,
			'dismissals'        => $dis,
			'close_rate'        => $v > 0 ? round( $dis / $v * 100, 2 ) : 0.0,
			'unique_visitors'   => $uv,
			'unique_conversions' => $uc,
			'unknown_views'     => (int) ( $row->unknown_views ?? 0 ),
			'view_rate'     => $pv > 0 ? round( $v / $pv * 100, 2 ) : 0.0,
			'conv_rate'          => $v  > 0 ? round( $c / $v  * 100, 2 ) : 0.0,
			'unique_conv_rate'   => $uv > 0 ? round( $uc / $uv * 100, 2 ) : 0.0,
			'conversions_per_person' => $uc > 0 ? round( $lc / $uc, 2 ) : 0.0,
			'ctr'                => ( $shell === 'popup-click' && $tv > 0 ) ? round( $v / $tv * 100, 2 ) : null,
			'click_rate'          => $v  > 0 ? round( $cl_mean / $v * 100, 2 ) : 0.0,
			'click_rate_unique'   => $uv > 0 ? round( $ucl / $uv * 100, 2 ) : 0.0,
			'dropoff_rate_views'  => $v  > 0 ? round( $drop / $v * 100, 2 ) : 0.0,
			'dropoff_rate_unique' => $uv > 0 ? round( $udr / $uv * 100, 2 ) : 0.0,
		];
	}

	private static function all_time_with_summary( object $row, int $form_id, ?string $shell, ?string $variant, string $until, string $where, array $params ): object {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );
		$map    = [
			'page_view' => 'page_views', 'trigger_view' => 'trigger_views', 'view' => 'views',
			'conversion' => 'conversions', 'engage' => 'engagements', 'dropoff' => 'dropoffs',
			'bot_filtered' => 'bot_filtered', 'honeypot_blocked' => 'honeypot_blocked', 'click' => 'clicks_all',
		];
		$out = [];
		foreach ( array_merge( array_values( $map ), [ 'clicks_meaningful', 'dismissals', 'labelled_conversions', 'unknown_views' ] ) as $k ) {
			$out[ $k ] = (int) ( $row->$k ?? 0 );
		}
		$summary_clicks = 0;
		foreach ( Emblem_Studio_Stats_Summary::sums( $form_id, 'base', $until, $shell, $variant, [ 'labelled' ] ) as $s ) {
			$n = (int) $s->n;
			if ( isset( $map[ $s->event_type ] ) ) {
				$out[ $map[ $s->event_type ] ] += $n;
			}
			if ( $s->event_type === 'click' ) {
				$summary_clicks += $n;
			}
			if ( $s->event_type === 'conversion' && (int) $s->labelled ) {
				$out['labelled_conversions'] += $n;
			}
			if ( $s->event_type === 'view' && ! (int) $s->labelled ) {
				$out['unknown_views'] += $n;
			}
		}
		$dismissed = 0;
		foreach ( Emblem_Studio_Stats_Summary::sums( $form_id, 'item', $until, $shell, $variant, [ 'click_target' ] ) as $s ) {
			if ( $s->event_type === 'click' && in_array( $s->click_target, [ '__close__', '__backdrop__' ], true ) ) {
				$dismissed += (int) $s->n;
			}
		}
		$out['dismissals']        += $dismissed;
		$out['clicks_meaningful'] += $summary_clicks - $dismissed;

		$raw = static function ( string $extra ) use ( $wpdb, $events, $where, $params ): array {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT visitor FROM $events $where AND visitor IS NOT NULL AND visitor <> '' AND $extra", $params ) );
		};
		$people = [
			'unique_visitors'    => [ 'viewers', "event_type = 'view'" ],
			'unique_conversions' => [ 'signups', "event_type = 'conversion'" ],
			'unique_clickers'    => [ 'clickers', "event_type = 'click' AND (click_target IS NULL OR click_target NOT IN ('__close__','__backdrop__'))" ],
			'unique_droppers'    => [ 'leavers', "event_type = 'dropoff'" ],
		];
		foreach ( $people as $key => [ $kind, $extra ] ) {
			$out[ $key ] = Emblem_Studio_Stats_Summary::people( $form_id, $kind, $until, $shell, $variant, $raw( $extra ) )->count();
		}
		return (object) $out;
	}

	public static function clicks_for_form( int $form_id, ?string $shell = null, ?string $from = null, ?string $to = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = "WHERE form_id = %d AND event_type = 'click'";
		$params = [ $form_id ];
		if ( $shell ) {
			$where   .= ' AND type = %s';
			$params[] = $shell;
		}
		if ( $from ) {
			$where   .= ' AND created_at >= %s';
			$params[] = self::pad_dt( $from, 'start' );
		}
		if ( $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = self::pad_dt( $to, 'end' );
		}
		$until = ( ! $from && ! $to && class_exists( 'Emblem_Studio_Stats_Summary' ) ) ? Emblem_Studio_Stats_Summary::cover_until( $form_id ) : null;
		if ( $until !== null ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $until;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				COALESCE(NULLIF(click_target,''), '__untagged__') AS target,
				type AS shell,
				COUNT(*) AS clicks,
				COALESCE(SUM(CASE WHEN variant = 'A' THEN 1 ELSE 0 END), 0) AS clicks_a,
				COALESCE(SUM(CASE WHEN variant = 'B' THEN 1 ELSE 0 END), 0) AS clicks_b,
				MAX(created_at) AS last_at
			FROM $events
			$where
			GROUP BY target, shell
			ORDER BY clicks DESC, last_at DESC",
			$params
		) );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ $r->target . '|' . $r->shell ] = [
				'target'   => (string) $r->target,
				'shell'    => (string) $r->shell,
				'clicks'   => (int) $r->clicks,
				'clicks_a' => (int) $r->clicks_a,
				'clicks_b' => (int) $r->clicks_b,
				'last_at'  => (string) $r->last_at,
			];
		}
		if ( $until !== null ) {
			$out = self::clicks_with_summary( $out, $form_id, $shell, $until );
			usort( $out, static function ( array $a, array $b ): int {
				return ( $b['clicks'] <=> $a['clicks'] ) ?: strcmp( $b['last_at'], $a['last_at'] );
			} );
		}
		return array_values( $out );
	}

	private static function clicks_with_summary( array $out, int $form_id, ?string $shell, string $until ): array {
		global $wpdb;
		$t      = Emblem_Studio_Install::table( 'daily_stats' );
		$where  = "form_id = %d AND day < %s AND event_type = 'click'";
		$params = [ $form_id, substr( $until, 0, 10 ) ];
		if ( $shell ) {
			$where   .= ' AND shell = %s';
			$params[] = $shell;
		}
		$add = static function ( string $target, string $sh, string $variant, int $n, string $day ) use ( &$out ): void {
			$key = $target . '|' . $sh;
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = [ 'target' => $target, 'shell' => $sh, 'clicks' => 0, 'clicks_a' => 0, 'clicks_b' => 0, 'last_at' => '' ];
			}
			$out[ $key ]['clicks'] += $n;
			if ( $variant === 'A' ) {
				$out[ $key ]['clicks_a'] += $n;
			} elseif ( $variant === 'B' ) {
				$out[ $key ]['clicks_b'] += $n;
			}
			if ( $out[ $key ]['last_at'] === '' || strcmp( $day, $out[ $key ]['last_at'] ) > 0 ) {
				$out[ $key ]['last_at'] = strlen( $out[ $key ]['last_at'] ) > 10 ? $out[ $key ]['last_at'] : $day;
			}
		};
		$labelled = [];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT click_target, shell, variant, SUM(events) AS n, MAX(day) AS last_day FROM $t WHERE $where AND breakdown = 'item' GROUP BY click_target, shell, variant", $params ) ) as $r ) {
			$add( (string) $r->click_target, (string) $r->shell, (string) $r->variant, (int) $r->n, (string) $r->last_day );
			$k              = $r->shell . '|' . $r->variant;
			$labelled[ $k ] = ( $labelled[ $k ] ?? 0 ) + (int) $r->n;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT shell, variant, SUM(events) AS n, MAX(day) AS last_day FROM $t WHERE $where AND breakdown = 'base' GROUP BY shell, variant", $params ) ) as $r ) {
			$untagged = (int) $r->n - ( $labelled[ $r->shell . '|' . $r->variant ] ?? 0 );
			if ( $untagged > 0 ) {
				$add( '__untagged__', (string) $r->shell, (string) $r->variant, $untagged, (string) $r->last_day );
			}
		}
		return $out;
	}

	public static function dropoff_steps( object $form, ?string $shell = null, ?string $from = null, ?string $to = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = "WHERE form_id = %d AND event_type = 'dropoff'";
		$params = [ (int) $form->id ];
		if ( $shell ) {
			$where   .= ' AND type = %s';
			$params[] = $shell;
		}
		if ( $from ) {
			$where   .= ' AND created_at >= %s';
			$params[] = self::pad_dt( $from, 'start' );
		}
		if ( $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = self::pad_dt( $to, 'end' );
		}

		$until = ( ! $from && ! $to && class_exists( 'Emblem_Studio_Stats_Summary' ) ) ? Emblem_Studio_Stats_Summary::cover_until( (int) $form->id ) : null;
		if ( $until !== null ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $until;
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT
				step,
				COUNT(*) AS n,
				COALESCE(SUM(CASE WHEN variant = 'A' THEN 1 ELSE 0 END), 0) AS n_a,
				COALESCE(SUM(CASE WHEN variant = 'B' THEN 1 ELSE 0 END), 0) AS n_b
			FROM $events
			$where
			GROUP BY step",
			$params
		) );
		if ( $until !== null ) {
			$by = [];
			foreach ( $rows as $r ) {
				$k = (string) ( $r->step ?? '' );
				$by[ $k ] = (object) [ 'step' => $k, 'n' => (int) $r->n, 'n_a' => (int) $r->n_a, 'n_b' => (int) $r->n_b ];
			}
			foreach ( Emblem_Studio_Stats_Summary::sums( (int) $form->id, 'step', $until, $shell, null, [ 'step', 'variant' ] ) as $s ) {
				$k = (string) $s->step;
				if ( ! isset( $by[ $k ] ) ) {
					$by[ $k ] = (object) [ 'step' => $k, 'n' => 0, 'n_a' => 0, 'n_b' => 0 ];
				}
				$by[ $k ]->n += (int) $s->n;
				if ( $s->variant === 'A' ) {
					$by[ $k ]->n_a += (int) $s->n;
				} elseif ( $s->variant === 'B' ) {
					$by[ $k ]->n_b += (int) $s->n;
				}
			}
			$rows = array_values( $by );
		}

		$total    = 0;
		$unmarked = 0;
		foreach ( $rows as $r ) {
			$total += (int) $r->n;
			if ( $r->step === null || $r->step === '' ) {
				$unmarked += (int) $r->n;
			}
		}

		$html     = (string) ( $form->html ?? '' );
		$variants = Emblem_Studio_Install::table( 'variants' );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT html FROM $variants WHERE form_id = %d ORDER BY slot", (int) $form->id ) ) as $vh ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$html .= "\n" . (string) $vh;
		}
		$order = array_flip( self::step_markers( $html ) );

		$steps = [];
		foreach ( $rows as $r ) {
			if ( $r->step === null || $r->step === '' ) {
				continue;
			}
			$steps[] = [
				'step'       => (string) $r->step,
				'dropoffs'   => (int) $r->n,
				'share'      => $total > 0 ? round( (int) $r->n / $total * 100, 2 ) : 0.0,
				'dropoffs_a' => (int) $r->n_a,
				'dropoffs_b' => (int) $r->n_b,
			];
		}
		usort( $steps, static function ( array $a, array $b ) use ( $order ): int {
			$ia = $order[ $a['step'] ] ?? PHP_INT_MAX;
			$ib = $order[ $b['step'] ] ?? PHP_INT_MAX;
			return ( $ia <=> $ib ) ?: ( ( $b['dropoffs'] <=> $a['dropoffs'] ) ?: strcmp( $a['step'], $b['step'] ) );
		} );

		return [ 'total' => $total, 'unmarked' => $unmarked, 'steps' => $steps ];
	}

	public static function step_markers( string $html ): array {
		preg_match_all( '/\sdata-es-step\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER );
		$labels = [];
		foreach ( $m as $hit ) {
			$raw   = html_entity_decode( $hit[1] !== '' ? $hit[1] : ( $hit[2] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$label = Emblem_Studio_Event_Recorder::clean_step( $raw );
			if ( $label !== '' && ! in_array( $label, $labels, true ) ) {
				$labels[] = $label;
			}
		}
		return $labels;
	}

	public static function reset_stats( ?int $form_id = null, ?string $shell = null ): int {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );
		if ( class_exists( 'Emblem_Studio_Stats_Summary' ) ) {
			Emblem_Studio_Stats_Summary::forget( $form_id ? [ $form_id ] : null, $form_id ? $shell : null );
		}
		if ( $form_id && $shell ) {
			return (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM $events WHERE form_id = %d AND type = %s",
				$form_id, $shell
			) );
		}
		if ( $form_id ) {
			return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $events WHERE form_id = %d", $form_id ) );
		}
		return (int) $wpdb->query( "TRUNCATE TABLE $events" );
	}

	public static function shells_with_data( int $form_id ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );
		$until = class_exists( 'Emblem_Studio_Stats_Summary' ) ? Emblem_Studio_Stats_Summary::cover_until( $form_id ) : null;
		if ( $until === null ) {
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT type FROM $events WHERE form_id = %d AND type <> ''",
				$form_id
			) );
			return array_values( array_filter( (array) $rows, 'is_string' ) );
		}
		$stats = Emblem_Studio_Install::table( 'daily_stats' );
		$rows  = array_merge(
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT type FROM $events WHERE form_id = %d AND type <> '' AND created_at >= %s", $form_id, $until ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT shell FROM $stats WHERE form_id = %d AND breakdown = 'base' AND shell <> ''", $form_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return array_values( array_unique( array_filter( $rows, 'is_string' ) ) );
	}

	public static function aggregate_kpis( string $from, string $to ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN event_type = 'page_view'    THEN 1 ELSE 0 END) AS page_views,
				SUM(CASE WHEN event_type = 'view'         THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN event_type = 'engage'       THEN 1 ELSE 0 END) AS engagements,
				SUM(CASE WHEN event_type = 'conversion'   THEN 1 ELSE 0 END) AS conversions,
				SUM(CASE WHEN event_type = 'bot_filtered' THEN 1 ELSE 0 END) AS bot_filtered,
				SUM(CASE WHEN event_type = 'click' AND click_target IN ('__close__','__backdrop__') THEN 1 ELSE 0 END) AS dismissals,
				SUM(CASE WHEN event_type = 'click' AND (click_target IS NULL OR click_target NOT IN ('__close__','__backdrop__')) THEN 1 ELSE 0 END) AS clicks
			FROM $events
			WHERE created_at >= %s AND created_at <= %s",
			self::pad_dt( $from, 'start' ), self::pad_dt( $to, 'end' )
		) );

		$pv = (int) ( $row->page_views ?? 0 );
		$v  = (int) ( $row->views ?? 0 );
		$eng = (int) ( $row->engagements ?? 0 );
		$c  = (int) ( $row->conversions ?? 0 );
		$bf = (int) ( $row->bot_filtered ?? 0 );
		$dis = (int) ( $row->dismissals ?? 0 );
		$cl = (int) ( $row->clicks ?? 0 );

		return [
			'page_views'   => $pv,
			'views'        => $v,
			'engagements'  => $eng,
			'conversions'  => $c,
			'bot_filtered' => $bf,
			'dismissals'   => $dis,
			'clicks'       => $cl,
			'close_rate'   => $v  > 0 ? round( $dis / $v * 100, 2 ) : 0.0,
			'view_rate'    => $pv > 0 ? round( $v / $pv * 100, 2 ) : 0.0,
			'conv_rate'    => $v  > 0 ? round( $c / $v  * 100, 2 ) : 0.0,
			'click_rate'   => $v  > 0 ? round( $cl / $v * 100, 2 ) : 0.0,
		];
	}

	public static function bot_breakdown( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = "WHERE event_type = 'bot_filtered'";
		$params = [];
		if ( $form_id ) {
			$where   .= ' AND form_id = %d';
			$params[] = $form_id;
		}
		if ( $from ) {
			$where   .= ' AND created_at >= %s';
			$params[] = self::pad_dt( $from, 'start' );
		}
		if ( $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = self::pad_dt( $to, 'end' );
		}
		if ( $shell ) {
			$where   .= ' AND type = %s';
			$params[] = $shell;
		}

		$sql  = "SELECT click_target AS label, COUNT(*) AS n FROM $events $where GROUP BY click_target";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$total        = 0;
		$unattributed = 0;
		$cats         = [];

		foreach ( (array) $rows as $r ) {
			$n      = (int) $r->n;
			$total += $n;
			$label  = (string) ( $r->label ?? '' );
			if ( $label === '' || $label === '__seed__' ) {
				$unattributed += $n;
				continue;
			}
			$cat = Emblem_Studio_Event_Recorder::category_for_label( $label );
			if ( ! isset( $cats[ $cat ] ) ) {
				$cats[ $cat ] = [ 'total' => 0, 'bots' => [] ];
			}
			$cats[ $cat ]['total']          += $n;
			$cats[ $cat ]['bots'][ $label ]  = ( $cats[ $cat ]['bots'][ $label ] ?? 0 ) + $n;
		}

		$categories = [];
		foreach ( $cats as $cat => $data ) {
			arsort( $data['bots'] );
			$bots = [];
			foreach ( $data['bots'] as $label => $count ) {
				$bots[] = [ 'label' => $label, 'count' => (int) $count ];
			}
			$categories[] = [ 'category' => $cat, 'total' => (int) $data['total'], 'bots' => $bots ];
		}
		usort( $categories, static fn( $a, $b ) => $b['total'] <=> $a['total'] );

		return [
			'total'        => $total,
			'attributed'   => $total - $unattributed,
			'unattributed' => $unattributed,
			'categories'   => $categories,
		];
	}

	public static function device_bucket_sql(): string {
		$ua = 'LOWER(ua)';
		$has = static function ( string $word ) use ( $ua ): string {
			return "LOCATE('$word', $ua) > 0";
		};
		return "CASE
			WHEN ua IS NULL OR ua = '' THEN 'unknown'
			WHEN {$has('ipad')} OR {$has('tablet')} OR ({$has('android')} AND LOCATE('mobile', $ua) = 0) THEN 'tablet'
			WHEN {$has('mobi')} OR {$has('iphone')} OR {$has('ipod')} OR {$has('android')} THEN 'mobile'
			ELSE 'desktop' END";
	}

	public static function device_breakdown( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = "WHERE event_type IN ('view','conversion')";
		$params = [];
		if ( $form_id ) {
			$where .= ' AND form_id = %d';
			$params[] = $form_id;
		}
		if ( $from ) {
			$where .= ' AND created_at >= %s';
			$params[] = self::pad_dt( $from, 'start' );
		}
		if ( $to ) {
			$where .= ' AND created_at <= %s';
			$params[] = self::pad_dt( $to, 'end' );
		}
		if ( $shell ) {
			$where .= ' AND type = %s';
			$params[] = $shell;
		}

		$bucket = self::device_bucket_sql();

		$sql = "SELECT $bucket AS device,
				SUM(CASE WHEN event_type = 'view'       THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END) AS conversions
			FROM $events $where GROUP BY device ORDER BY views DESC";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'device'      => (string) $r->device,
				'views'       => (int) $r->views,
				'conversions' => (int) $r->conversions,
			];
		}
		return $out;
	}

	public static function source_breakdown( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = "WHERE event_type IN ('view','conversion')";
		$params = [];
		if ( $form_id ) {
			$where .= ' AND form_id = %d';
			$params[] = $form_id;
		}
		if ( $from ) {
			$where .= ' AND created_at >= %s';
			$params[] = self::pad_dt( $from, 'start' );
		}
		if ( $to ) {
			$where .= ' AND created_at <= %s';
			$params[] = self::pad_dt( $to, 'end' );
		}
		if ( $shell ) {
			$where .= ' AND type = %s';
			$params[] = $shell;
		}

		$sql = "SELECT COALESCE(source, 'unknown') AS src,
				SUM(CASE WHEN event_type = 'view'       THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END) AS conversions
			FROM $events $where GROUP BY src";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];
		foreach ( (array) $rows as $r ) {
			$views = (int) $r->views;
			$conv  = (int) $r->conversions;
			$out[] = [
				'source'      => (string) $r->src,
				'views'       => $views,
				'conversions' => $conv,
				'conv_rate'   => $views > 0 ? round( $conv / $views * 100, 2 ) : 0.0,
			];
		}
		usort( $out, static function ( array $a, array $b ): int {
			$au = $a['source'] === 'unknown' ? 1 : 0;
			$bu = $b['source'] === 'unknown' ? 1 : 0;
			return ( $au <=> $bu ) ?: ( $b['views'] <=> $a['views'] );
		} );
		return $out;
	}

	public static function page_breakdown( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null, int $limit = 20 ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where  = 'WHERE 1=1';
		$params = [];
		if ( $form_id ) {
			$where .= ' AND form_id = %d';
			$params[] = $form_id;
		}
		if ( $from ) {
			$where .= ' AND created_at >= %s';
			$params[] = self::pad_dt( $from, 'start' );
		}
		if ( $to ) {
			$where .= ' AND created_at <= %s';
			$params[] = self::pad_dt( $to, 'end' );
		}
		if ( $shell ) {
			$where .= ' AND type = %s';
			$params[] = $shell;
		}

		$sql = "SELECT post_id,
				SUM(CASE WHEN event_type = 'page_view'  THEN 1 ELSE 0 END) AS page_views,
				SUM(CASE WHEN event_type = 'view'       THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END) AS conversions
			FROM $events $where GROUP BY post_id ORDER BY views DESC LIMIT %d";
		$params[] = $limit;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$pid = $r->post_id !== null ? (int) $r->post_id : null;
			if ( $pid ) {
				$title = get_the_title( $pid );
				$label = $title !== '' ? $title : ( '#' . $pid );
			} else {
				$label = __( 'Site-wide / other', 'emblem-studio' );
			}
			$v = (int) $r->views;
			$c = (int) $r->conversions;
			$out[] = [
				'post_id'     => $pid,
				'label'       => $label,
				'page_views'  => (int) $r->page_views,
				'views'       => $v,
				'conversions' => $c,
				'conv_rate'   => $v > 0 ? round( $c / $v * 100, 2 ) : 0.0,
			];
		}
		return $out;
	}

	public static function visitor_stats( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$scope = '';
		$sp    = [];
		if ( $form_id ) { $scope .= ' AND form_id = %d'; $sp[] = $form_id; }
		if ( $shell )   { $scope .= ' AND type = %s';   $sp[] = $shell; }

		$range = '';
		$rp    = $sp;
		$from_sql = $from ? self::pad_dt( $from, 'start' ) : null;
		$to_sql   = $to ? self::pad_dt( $to, 'end' ) : null;
		if ( $from_sql ) { $range .= ' AND created_at >= %s'; $rp[] = $from_sql; }
		if ( $to_sql )   { $range .= ' AND created_at <= %s'; $rp[] = $to_sql; }

		$sql = "SELECT
				COUNT(DISTINCT CASE WHEN visitor IS NOT NULL AND visitor <> '' THEN visitor END) AS uniques,
				SUM(CASE WHEN visitor IS NULL OR visitor = '' THEN 1 ELSE 0 END) AS unknown_views
			FROM $events WHERE event_type = 'view'$scope$range";
		$row = $rp ? $wpdb->get_row( $wpdb->prepare( $sql, $rp ) ) : $wpdb->get_row( $sql );
		$uniques = (int) ( $row->uniques ?? 0 );
		$unknown = (int) ( $row->unknown_views ?? 0 );

		$new = 0;
		if ( $from_sql && $to_sql ) {
			$nsql = "SELECT COUNT(*) FROM (
					SELECT visitor, MIN(created_at) AS first_seen
					FROM $events
					WHERE event_type = 'view' AND visitor IS NOT NULL AND visitor <> ''$scope
					GROUP BY visitor
				) t WHERE t.first_seen >= %s AND t.first_seen <= %s";
			$np  = array_merge( $sp, [ $from_sql, $to_sql ] );
			$new = (int) $wpdb->get_var( $wpdb->prepare( $nsql, $np ) );
		}
		$returning = max( 0, $uniques - $new );

		$csql = "SELECT COUNT(DISTINCT visitor) FROM $events
			WHERE event_type = 'conversion' AND visitor IS NOT NULL AND visitor <> ''$scope$range";
		$unique_conv = (int) ( $rp ? $wpdb->get_var( $wpdb->prepare( $csql, $rp ) ) : $wpdb->get_var( $csql ) );

		$tsql = "SELECT COUNT(*) FROM $events WHERE event_type = 'conversion' AND visitor IS NOT NULL AND visitor <> ''$scope$range";
		$total_conv = (int) ( $rp ? $wpdb->get_var( $wpdb->prepare( $tsql, $rp ) ) : $wpdb->get_var( $tsql ) );

		return [
			'unique_visitors'    => $uniques,
			'new_visitors'       => $new,
			'returning_visitors' => $returning,
			'unknown_views'      => $unknown,
			'unique_conversions' => $unique_conv,
			'unique_conv_rate'   => $uniques > 0 ? round( $unique_conv / $uniques * 100, 2 ) : 0.0,
			'conversions_per_person' => $unique_conv > 0 ? round( $total_conv / $unique_conv, 2 ) : 0.0,
		];
	}

	public static function per_person_html( float $per_person ): string {
		if ( $per_person <= 0 ) {
			return '<span class="dim">&mdash;</span>';
		}
		return sprintf(
			'<span class="es-rate-num%s" data-es-tip="%s">&times;%s</span>',
			$per_person > 1 ? '' : ' dim',
			esc_attr__( 'Sign-ups per person who signed up. 1.00 means nobody submitted twice; 1.05 means five people in a hundred came back and signed up again.', 'emblem-studio' ),
			esc_html( number_format_i18n( $per_person, 2 ) )
		);
	}

	public static function rate_pair_html( float $views_rate, float $unique_rate, string $tip_views, string $tip_unique ): string {
		return sprintf(
			'<span class="es-rate-num" data-es-tip="%s">%s%%</span> <span class="es-rate-sep">/</span> <span class="es-rate-num" data-es-tip="%s">%s%%</span>',
			esc_attr( $tip_views ),
			esc_html( number_format_i18n( $views_rate, 2 ) ),
			esc_attr( $tip_unique ),
			esc_html( number_format_i18n( $unique_rate, 2 ) )
		);
	}

	public static function asset_visitors( int $form_id, ?string $shell = null ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );

		$where = "WHERE event_type = 'view' AND form_id = %d";
		$p     = [ $form_id ];
		if ( $shell ) { $where .= ' AND type = %s'; $p[] = $shell; }

		$until = class_exists( 'Emblem_Studio_Stats_Summary' ) ? Emblem_Studio_Stats_Summary::cover_until( $form_id ) : null;
		if ( $until !== null ) {
			return self::asset_visitors_with_summary( $form_id, $shell, $until, $where . ' AND created_at >= %s', array_merge( $p, [ $until ] ) );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(DISTINCT CASE WHEN visitor IS NOT NULL AND visitor <> '' THEN visitor END) AS uniques,
				COALESCE(SUM(CASE WHEN visitor IS NULL OR visitor = '' THEN 1 ELSE 0 END),0) AS unknown_views
			FROM $events $where",
			$p
		) );
		$uniques = (int) ( $row->uniques ?? 0 );

		$returning = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT visitor, COUNT(*) AS c FROM $events $where AND visitor IS NOT NULL AND visitor <> ''
				GROUP BY visitor HAVING c >= 2
			) t",
			$p
		) );

		return [
			'unique_visitors'    => $uniques,
			'new_visitors'       => max( 0, $uniques - $returning ),
			'returning_visitors' => $returning,
			'unknown_views'      => (int) ( $row->unknown_views ?? 0 ),
		];
	}

	private static function asset_visitors_with_summary( int $form_id, ?string $shell, string $until, string $where, array $p ): array {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT visitor, COUNT(*) AS c FROM $events $where AND visitor IS NOT NULL AND visitor <> '' GROUP BY visitor", $p ) );
		$all     = array_map( static fn ( $r ) => (string) $r->visitor, $ids );
		$twice   = array_map( static fn ( $r ) => (string) $r->visitor, array_filter( $ids, static fn ( $r ) => (int) $r->c >= 2 ) );
		$uniques = Emblem_Studio_Stats_Summary::people( $form_id, 'viewers', $until, $shell, null, $all )->count();
		$back    = min( $uniques, Emblem_Studio_Stats_Summary::people( $form_id, 'returners', $until, $shell, null, $twice )->count() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unknown = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $events $where AND (visitor IS NULL OR visitor = '')", $p ) );
		foreach ( Emblem_Studio_Stats_Summary::sums( $form_id, 'base', $until, $shell, null, [ 'labelled' ] ) as $s ) {
			if ( $s->event_type === 'view' && ! (int) $s->labelled ) {
				$unknown += (int) $s->n;
			}
		}
		return [
			'unique_visitors'    => $uniques,
			'new_visitors'       => max( 0, $uniques - $back ),
			'returning_visitors' => $back,
			'unknown_views'      => $unknown,
		];
	}

	public static function first_event_date(): ?string {
		global $wpdb;
		$events = Emblem_Studio_Install::table( 'events' );
		$min    = $wpdb->get_var( "SELECT MIN(created_at) FROM $events" );
		return $min ? substr( (string) $min, 0, 10 ) : null;
	}

	public static function total_leads(): int {
		global $wpdb;
		$leads = Emblem_Studio_Install::table( 'leads' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads WHERE is_bot = 0" );
	}

	public static function leads_in_range( string $from, string $to ): int {
		global $wpdb;
		$leads = Emblem_Studio_Install::table( 'leads' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $leads WHERE is_bot = 0 AND created_at >= %s AND created_at <= %s",
			self::pad_dt( $from, 'start' ), self::pad_dt( $to, 'end' )
		) );
	}
}
