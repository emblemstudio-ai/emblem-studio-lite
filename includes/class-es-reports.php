<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Reports {

	/**
	 * Pad a date string to a full datetime if it's date-only. Backward-compatible
	 * with callers that pass 'Y-m-d' — accepts 'Y-m-d H:i:s' as-is.
	 */
	private static function pad_dt( string $s, string $end ): string {
		if ( strlen( $s ) <= 10 ) {
			return $s . ( $end === 'end' ? ' 23:59:59' : ' 00:00:00' );
		}
		return $s;
	}

	public static function kpis( int $form_id, string $from, string $to, ?string $shell = null ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

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
			// Dismissals = close-button + backdrop clicks; close_rate is the
			// "annoyance index" (how often a shown asset gets dismissed).
			'dismissals'    => $dis,
			'close_rate'    => $v  > 0 ? round( $dis / $v * 100, 2 ) : 0.0,
			'view_rate'     => $pv > 0 ? round( $v / $pv * 100, 2 ) : 0.0,
			'conv_rate'     => $v  > 0 ? round( $c / $v  * 100, 2 ) : 0.0,
			'conv_rate_pv'  => $pv > 0 ? round( $c / $pv * 100, 2 ) : 0.0,
			// Trigger click-through: of the people who SAW the trigger button,
			// how many opened the popup. Only the popup-click shell fires
			// trigger_view, so this is null on every other shell and null on an
			// unscoped call — blending it would put inline views over
			// click-popup triggers and produce a rate well past 100%.
			'ctr'           => ( $shell === 'popup-click' && $tv > 0 ) ? round( $v / $tv * 100, 2 ) : null,
		];
	}

	/**
	 * Time series bucketed by day or hour, zero-filled.
	 * Pass form_id = null to aggregate across all forms.
	 * granularity: 'day' (default) or 'hour'.
	 * 'date' key is 'Y-m-d' for day, 'Y-m-d\TH:00' for hour.
	 */
	public static function timeseries( ?int $form_id, string $from, string $to, string $granularity = 'day', ?string $shell = null ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

		$from_full = self::pad_dt( $from, 'start' );
		$to_full   = self::pad_dt( $to, 'end' );

		// Note: %% escapes the percent sign so $wpdb->prepare doesn't treat it as a placeholder.
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
		$events = ES_Install::table( 'events' );

		$where  = 'WHERE form_id = %d';
		$params = [ $form_id ];
		if ( $shell ) {
			$where .= ' AND type = %s';
			$params[] = $shell;
		}
		// Optional per-variant scope. Only 'A' / 'B' are valid; anything
		// else is ignored (caller bug, not a place for fancy errors).
		if ( $variant !== null && in_array( $variant, [ 'A', 'B' ], true ) ) {
			$where .= ' AND variant = %s';
			$params[] = $variant;
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
				SUM(CASE WHEN event_type = 'view' AND (visitor IS NULL OR visitor = '') THEN 1 ELSE 0 END) AS unknown_views
			FROM $events $where",
			$params
		) );

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

		return [
			'page_views'       => $pv,
			'trigger_views'    => $tv,
			'views'            => $v,
			'conversions'      => $c,
			'engagements'      => $eng,
			// Drop-offs: visitors who started filling in the form but left the
			// page without submitting. Counted directly from `dropoff` events.
			'dropoffs'         => $drop,
			// Share of visitors who started but didn't finish. Denominator is
			// everyone who engaged (started + dropped + converted-this-period),
			// which is self-consistent with the dropoff event going forward.
			'dropoff_rate'     => $eng > 0 ? round( $drop / $eng * 100, 2 ) : 0.0,
			'bot_filtered'     => $bf,
			'honeypot_blocked' => $hp,
			// `clicks` = all click events (including close + backdrop). Used for
			// raw debugging; the success-rate calc uses `meaningful_clicks`.
			'clicks'              => $cl_all,
			'meaningful_clicks'   => $cl_mean,
			// Dismissals = close + backdrop clicks; close_rate is the annoyance index.
			'dismissals'        => $dis,
			'close_rate'        => $v > 0 ? round( $dis / $v * 100, 2 ) : 0.0,
			// Anonymous unique visitors + the Unknown bucket (untagged views).
			'unique_visitors'   => $uv,
			// People who converted at least once — the numerator a per-person
			// rate needs. Distinct visitor ids on `conversion` events, the same
			// shape visitor_stats() has always used for its range view.
			'unique_conversions' => $uc,
			'unknown_views'     => (int) ( $row->unknown_views ?? 0 ),
			'view_rate'     => $pv > 0 ? round( $v / $pv * 100, 2 ) : 0.0,
			// Each rate twice: against asset views (per impression) and against
			// unique visitors (per person). The UI shows them as "X% / Y%".
			'conv_rate'          => $v  > 0 ? round( $c / $v  * 100, 2 ) : 0.0,
			// THREE conversion figures, and they answer three different
			// questions. Keep them straight before quoting any of them.
			//
			//  conv_rate              conversions ÷ views      per impression
			//  unique_conv_rate       people ÷ people          the share of people who signed up
			//  conversions_per_person conversions ÷ people     how often a signer-up came back
			//
			// The identity that ties them together:
			//   conv_rate_unique = unique_conv_rate × conversions_per_person
			//
			// conv_rate_unique is conversions ÷ unique VIEWERS, so it mixes
			// grains (events over people) and routinely reads past 100%. That is
			// not a fault, it is a real signal — repeat sign-ups — but it is the
			// two figures below multiplied together, and each one on its own says
			// what it means. Kept for callers that already read it.
			'conv_rate_unique'   => $uv > 0 ? round( $c / $uv * 100, 2 ) : 0.0,
			// Share of the people who saw it who signed up at all, counting each
			// person once however many times they submitted. Same formula as
			// visitor_stats()'s key of the same name, deliberately.
			//
			// It can still tip a little past 100%, and the reason is worth
			// knowing: the numerator comes from `conversion` rows written on
			// submit, the denominator from `view` beacons, and the two paths are
			// not filtered alike (record_event drops excluded-IP and bot-UA hits;
			// submit does not). A converter whose view beacon never landed sits
			// in the top and not the bottom. Not clamped: a clamp would hide
			// that, and a rate quietly pinned at 100% reads as measured when it
			// isn't.
			'unique_conv_rate'   => $uv > 0 ? round( $uc / $uv * 100, 2 ) : 0.0,
			// Sign-ups per person who signed up. 1.0 means nobody submitted
			// twice; 1.05 means five in a hundred came back and did it again.
			// This is the number that tells you WHICH assets attract repeat
			// submissions, which the blended rate above could only hint at.
			'conversions_per_person' => $uc > 0 ? round( $c / $uc, 2 ) : 0.0,
			// Trigger click-through: of the people who SAW the trigger button,
			// how many opened the popup. Only the popup-click shell fires
			// trigger_view, so this is null on every other shell and null on an
			// unscoped call — blending it would put inline views over
			// click-popup triggers and produce a rate well past 100%.
			'ctr'                => ( $shell === 'popup-click' && $tv > 0 ) ? round( $v / $tv * 100, 2 ) : null,
			// click_rate is the success metric for non-form assets: how many
			// engaged clicks per view. Excludes close/backdrop dismissals so
			// "people who hit X" aren't counted as a win.
			'click_rate'          => $v  > 0 ? round( $cl_mean / $v * 100, 2 ) : 0.0,
			'click_rate_unique'   => $uv > 0 ? round( $cl_mean / $uv * 100, 2 ) : 0.0,
			'dropoff_rate_views'  => $v  > 0 ? round( $drop / $v * 100, 2 ) : 0.0,
			'dropoff_rate_unique' => $uv > 0 ? round( $drop / $uv * 100, 2 ) : 0.0,
		];
	}

	/**
	 * Per-target click counts for a form, optionally narrowed by shell
	 * and/or date range. Newest data first when there are ties. Targets
	 * are author-defined labels (data-es-track) or auto-derived from id
	 * / visible text — see frontend.js clickLabel(). The reserved
	 * sentinels `__close__` and `__untagged__` are surfaced as-is so the
	 * Stats UI can give them friendly labels.
	 */
	public static function clicks_for_form( int $form_id, ?string $shell = null, ?string $from = null, ?string $to = null ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

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

		// Split per variant alongside the total. Every event already carries its
		// slot, so this costs one more pass over rows we are reading anyway, and
		// it answers the question the blended table could not: is the close
		// button doing the work on one side and the CTA on the other?
		//
		// `clicks` stays the TOTAL, not A + B. Events recorded before a test ever
		// ran have no slot, so the two columns can legitimately sum to less.
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
			$out[] = [
				'target'   => (string) $r->target,
				'shell'    => (string) $r->shell,
				'clicks'   => (int) $r->clicks,
				'clicks_a' => (int) $r->clicks_a,
				'clicks_b' => (int) $r->clicks_b,
				'last_at'  => (string) $r->last_at,
			];
		}
		return $out;
	}

	public static function reset_stats( ?int $form_id = null, ?string $shell = null ): int {
		global $wpdb;
		$events = ES_Install::table( 'events' );
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

	/**
	 * Return shell types that have at least one recorded event for the form.
	 * Used by the Forms list to keep showing a shell row (with status="inactive")
	 * after the user unchecks it, so historical data stays visible.
	 */
	public static function shells_with_data( int $form_id ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT type FROM $events WHERE form_id = %d AND type <> ''",
			$form_id
		) );
		return array_values( array_filter( (array) $rows, 'is_string' ) );
	}

	public static function aggregate_kpis( string $from, string $to ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

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
		// Meaningful clicks only (excludes the close-button/backdrop dismissal
		// clicks) — same exclusion the per-form report uses for its own 'clicks'.
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

	/**
	 * Per-bot breakdown of filtered hits, folded into display categories.
	 * Pass form_id = null to aggregate across all forms; from/to/shell are
	 * optional scopes (null = all time / all shells).
	 *
	 * The matched bot label lives in click_target on each bot_filtered row.
	 * Rows with no label — legacy hits recorded before per-bot tracking, plus
	 * the dev seeder's '__seed__' sentinel — collapse into an 'unattributed'
	 * bucket so totals still reconcile.
	 *
	 * Returns:
	 *   [
	 *     'total'        => int,   // every bot_filtered row in scope
	 *     'attributed'   => int,   // rows we could name
	 *     'unattributed' => int,   // rows with no label
	 *     'categories'   => [ [ 'category', 'total', 'bots' => [ ['label','count'], ... ] ], ... ]  // by total desc
	 *   ]
	 */
	public static function bot_breakdown( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

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
		$cats         = []; // category => [ 'total' => int, 'bots' => [ label => count ] ]

		foreach ( (array) $rows as $r ) {
			$n      = (int) $r->n;
			$total += $n;
			$label  = (string) ( $r->label ?? '' );
			if ( $label === '' || $label === '__seed__' ) {
				$unattributed += $n;
				continue;
			}
			$cat = ES_Event_Recorder::category_for_label( $label );
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

	/**
	 * Visits split into coarse device buckets (mobile / tablet / desktop /
	 * unknown) from the stored user-agent. Identity-free — we only ever bucket
	 * the UA, never use it to identify a person. Tablet is tested before mobile
	 * (Android tablets send "Android" without "Mobile"). LOWER() keeps it
	 * collation-independent. Counts build up from when UA stamping began.
	 *
	 * @return array<int, array{device:string, views:int, conversions:int}>
	 */
	public static function device_breakdown( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

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

		$bucket = "CASE
			WHEN ua IS NULL OR ua = '' THEN 'unknown'
			WHEN LOWER(ua) LIKE '%ipad%' OR LOWER(ua) LIKE '%tablet%' OR (LOWER(ua) LIKE '%android%' AND LOWER(ua) NOT LIKE '%mobile%') THEN 'tablet'
			WHEN LOWER(ua) LIKE '%mobi%' OR LOWER(ua) LIKE '%iphone%' OR LOWER(ua) LIKE '%ipod%' OR LOWER(ua) LIKE '%android%' THEN 'mobile'
			ELSE 'desktop' END";

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

	/**
	 * Per-page performance — which page each asset converts best on, grouped by
	 * post_id (the page the asset was shown on, captured on every event). Page
	 * titles are resolved from post_id; rows with no post_id roll up under a
	 * "Site-wide / other" bucket. Identity-free and cheap. Top pages by views.
	 *
	 * @return array<int, array{post_id:int|null, label:string, page_views:int, views:int, conversions:int, conv_rate:float}>
	 */
	public static function page_breakdown( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null, int $limit = 20 ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

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

	/**
	 * Unique-visitor stats (Stage 2). Counts distinct anonymous visitor ids:
	 * unique people who saw an asset, split into new (first-ever view in range)
	 * vs returning (seen before), plus the "Unknown" bucket — impressions we
	 * couldn't attribute to a person (private mode / cookie blocked). Per-person
	 * numbers are short-window estimates (browser cookies cap at ~7 days).
	 *
	 * @return array{unique_visitors:int, new_visitors:int, returning_visitors:int, unknown_views:int, unique_conversions:int, unique_conv_rate:float, conversions_per_person:float}
	 */
	public static function visitor_stats( ?int $form_id, ?string $from = null, ?string $to = null, ?string $shell = null ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

		// Shared form/shell scope (no date) — for the first-seen subquery.
		$scope = '';
		$sp    = [];
		if ( $form_id ) { $scope .= ' AND form_id = %d'; $sp[] = $form_id; }
		if ( $shell )   { $scope .= ' AND type = %s';   $sp[] = $shell; }

		// Date-bounded params (form/shell first, then from/to).
		$range = '';
		$rp    = $sp;
		$from_sql = $from ? self::pad_dt( $from, 'start' ) : null;
		$to_sql   = $to ? self::pad_dt( $to, 'end' ) : null;
		if ( $from_sql ) { $range .= ' AND created_at >= %s'; $rp[] = $from_sql; }
		if ( $to_sql )   { $range .= ' AND created_at <= %s'; $rp[] = $to_sql; }

		// Uniques + Unknown among impressions (views) in range.
		$sql = "SELECT
				COUNT(DISTINCT CASE WHEN visitor IS NOT NULL AND visitor <> '' THEN visitor END) AS uniques,
				SUM(CASE WHEN visitor IS NULL OR visitor = '' THEN 1 ELSE 0 END) AS unknown_views
			FROM $events WHERE event_type = 'view'$scope$range";
		$row = $rp ? $wpdb->get_row( $wpdb->prepare( $sql, $rp ) ) : $wpdb->get_row( $sql );
		$uniques = (int) ( $row->uniques ?? 0 );
		$unknown = (int) ( $row->unknown_views ?? 0 );

		// New = visitors whose FIRST-ever view falls in range; returning = the rest.
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

		// Unique converters in range, and the conversions they account for. Two
		// numbers, because one person can submit twice and the pair is the only
		// way to see that: `conversions_per_person` above 1 says some did.
		$csql = "SELECT COUNT(DISTINCT visitor) FROM $events
			WHERE event_type = 'conversion' AND visitor IS NOT NULL AND visitor <> ''$scope$range";
		$unique_conv = (int) ( $rp ? $wpdb->get_var( $wpdb->prepare( $csql, $rp ) ) : $wpdb->get_var( $csql ) );

		$tsql = "SELECT COUNT(*) FROM $events WHERE event_type = 'conversion'$scope$range";
		$total_conv = (int) ( $rp ? $wpdb->get_var( $wpdb->prepare( $tsql, $rp ) ) : $wpdb->get_var( $tsql ) );

		return [
			'unique_visitors'    => $uniques,
			'new_visitors'       => $new,
			'returning_visitors' => $returning,
			'unknown_views'      => $unknown,
			'unique_conversions' => $unique_conv,
			'unique_conv_rate'   => $uniques > 0 ? round( $unique_conv / $uniques * 100, 2 ) : 0.0,
			// Sign-ups per person who signed up. Above 1 = some submitted twice.
			'conversions_per_person' => $unique_conv > 0 ? round( $total_conv / $unique_conv, 2 ) : 0.0,
		];
	}

	/**
	 * Render a "X% / Y%" rate pair (vs asset views / vs unique visitors) as two
	 * spans, each carrying an instant tooltip (data-es-tip) describing what it
	 * means. Numbers stay on one line (the cells are white-space:nowrap).
	 */
	/**
	 * Render `conversions_per_person` as "×1.05" with its own tooltip. Dimmed at
	 * exactly 1.00, the ordinary case, so only an asset people submit twice draws
	 * the eye. Zero (nobody signed up) renders as a dash, not "×0.00".
	 */
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

	/**
	 * All-time visitor split for one asset (the Stats tab has no date range).
	 * "Returning" = a visitor who came back (2+ view events); "new" = seen once.
	 * (Differs from visitor_stats(), where new/returning is range-relative — both
	 * are correct for their context.) Unknown = views we couldn't tag.
	 *
	 * @return array{unique_visitors:int, new_visitors:int, returning_visitors:int, unknown_views:int}
	 */
	public static function asset_visitors( int $form_id, ?string $shell = null ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );

		$where = "WHERE event_type = 'view' AND form_id = %d";
		$p     = [ $form_id ];
		if ( $shell ) { $where .= ' AND type = %s'; $p[] = $shell; }

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

	/**
	 * Date of the earliest recorded event ('Y-m-d'), or null if there are
	 * none yet. Used by the "All time" range so the From field starts at the
	 * first real data point instead of a hardcoded epoch like 2000-01-01.
	 */
	public static function first_event_date(): ?string {
		global $wpdb;
		$events = ES_Install::table( 'events' );
		$min    = $wpdb->get_var( "SELECT MIN(created_at) FROM $events" );
		return $min ? substr( (string) $min, 0, 10 ) : null;
	}

	/**
	 * People, not bots. Both lead counts leave out rows the bot filter caught,
	 * as the web app's Reports::leadsInRange() does. They used to count them,
	 * so "Leads (7d)" on the asset list showed a bigger number here than the
	 * web app did for the same site (item 28 of Catalina's Lite test pass).
	 */
	public static function total_leads(): int {
		global $wpdb;
		$leads = ES_Install::table( 'leads' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads WHERE is_bot = 0" );
	}

	public static function leads_in_range( string $from, string $to ): int {
		global $wpdb;
		$leads = ES_Install::table( 'leads' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $leads WHERE is_bot = 0 AND created_at >= %s AND created_at <= %s",
			self::pad_dt( $from, 'start' ), self::pad_dt( $to, 'end' )
		) );
	}
}
