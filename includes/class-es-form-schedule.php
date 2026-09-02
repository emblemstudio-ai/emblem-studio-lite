<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The periods a form runs for (SER-1292).
 *
 * `active_from` / `active_until` could say "run from here to there". They could
 * not say "run in September, stop, run again in November", which is what a promo
 * actually does, so somebody had to remember to switch it back on.
 *
 * ONE CLOCK THROUGHOUT. Every stored value is a MySQL DATETIME in the SITE's own
 * time, and every comparison is against `current_time( 'mysql' )`, which is the
 * same clock. Both sides fixed-width, so a plain string compare orders them.
 * That is why this plugin never had the day-rollover bug the SaaS did: nothing
 * here converts anything.
 *
 * DORMANT PERIODS. Only the last period may be left open-ended. A period after
 * an open-ended one can never be reached, because the one before it never ends.
 * It is kept rather than refused, because "I do not know when I will pause this,
 * but I know when it starts again" is a real plan. It is reported as dormant so
 * no screen claims it will run.
 */
class ES_Form_Schedule {

	/** More than this is a repeating rule, not a list. */
	public const MAX_PERIODS = 6;

	private static function table(): string {
		return ES_Install::table( 'form_schedule_segments' );
	}

	/**
	 * A form's periods, soonest first.
	 *
	 * @return array<int,object>
	 */
	public static function for_form( int $form_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE form_id = %d ORDER BY starts_at ASC, position ASC, id ASC',
			$form_id
		) );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Replace a form's whole list.
	 *
	 * A list, not a row at a time, because every rule is about the list: only the
	 * last may be open-ended, the cap is on the whole thing, and overlaps fold
	 * together. Judging one row at a time lets somebody pass through a state the
	 * rules forbid on the way to a valid one.
	 *
	 * @param array<int,array{starts_at:?string,ends_at:?string}> $periods
	 * @return true|string true, or the reason it was refused
	 */
	public static function replace( int $form_id, array $periods ) {
		if ( count( $periods ) > self::MAX_PERIODS ) {
			return __( 'Six periods is the most. Past that, what you want is a repeating rule rather than a list.', 'emblem-studio' );
		}

		$clean = [];
		foreach ( $periods as $i => $p ) {
			$from  = ES_Form_Repo::normalize_datetime( $p['starts_at'] ?? '' );
			$until = ES_Form_Repo::normalize_datetime( $p['ends_at'] ?? '', true );

			if ( null === $from && null === $until ) {
				continue; // an empty row, not a period
			}
			if ( null === $from ) {
				return sprintf( __( 'Period %d has an end but no start. Leave the END empty to run from a date onwards.', 'emblem-studio' ), $i + 1 );
			}
			if ( null !== $until && strcmp( $until, $from ) <= 0 ) {
				return sprintf( __( 'Period %d ends before it starts.', 'emblem-studio' ), $i + 1 );
			}
			$clean[] = [ 'starts_at' => $from, 'ends_at' => $until ];
		}

		// The period running now cannot be dropped: that takes the form off the
		// site this second, and a Remove control does not look like that.
		$current = self::current( $form_id );
		if ( $current ) {
			$kept = false;
			foreach ( $clean as $row ) {
				if ( $row['starts_at'] === $current->starts_at ) {
					$kept = true;
					break;
				}
			}
			if ( ! $kept ) {
				return __( 'The period running right now cannot be removed. Change its end date to stop the form instead.', 'emblem-studio' );
			}
		}

		global $wpdb;
		$wpdb->delete( self::table(), [ 'form_id' => $form_id ] );
		foreach ( $clean as $i => $row ) {
			$wpdb->insert( self::table(), [
				'form_id'    => $form_id,
				'starts_at'  => $row['starts_at'],
				'ends_at'    => $row['ends_at'],
				'position'   => $i,
				'created_at' => current_time( 'mysql' ),
			] );
		}

		self::merge_overlaps( $form_id );

		// Deliberately no cache flush here. A form's stored status is DERIVED
		// from this list, so a caller has to re-save the form anyway to bring it
		// back in line, and that save flushes. Flushing here as well would clear
		// the caches a moment before the value that matters changes.
		return true;
	}

	/** Drop every period attached to a form. Permanent delete only. */
	public static function delete_for_form( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'form_id' => $form_id ] );
	}

	/**
	 * The periods that can actually run: up to and including the first
	 * open-ended one.
	 *
	 * @return array<int,object>
	 */
	public static function live( int $form_id ): array {
		$out = [];
		foreach ( self::for_form( $form_id ) as $row ) {
			$out[] = $row;
			if ( empty( $row->ends_at ) ) {
				break; // nothing after this can ever be reached
			}
		}
		return $out;
	}

	/** The ids of periods that will never run as things stand. */
	public static function dormant_ids( int $form_id ): array {
		$live = wp_list_pluck( self::live( $form_id ), 'id' );
		$all  = wp_list_pluck( self::for_form( $form_id ), 'id' );
		return array_values( array_diff( array_map( 'intval', $all ), array_map( 'intval', $live ) ) );
	}

	/** The period running right now, or null. */
	public static function current( int $form_id ): ?object {
		$now = (string) current_time( 'mysql' );
		foreach ( self::live( $form_id ) as $row ) {
			$started  = empty( $row->starts_at ) || strcmp( $now, (string) $row->starts_at ) >= 0;
			$finished = ! empty( $row->ends_at ) && strcmp( $now, (string) $row->ends_at ) > 0;
			if ( $started && ! $finished ) {
				return $row;
			}
		}
		return null;
	}

	/** The next period due to start, or null. */
	public static function next( int $form_id ): ?object {
		$now = (string) current_time( 'mysql' );
		foreach ( self::live( $form_id ) as $row ) {
			if ( ! empty( $row->starts_at ) && strcmp( $now, (string) $row->starts_at ) < 0 ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The state a list of periods puts a form in, or null when there are none.
	 *
	 * Pure on purpose, so it is unit-testable without a database or a frozen
	 * clock, the same way ES_Form_Repo::effective_state_at() is.
	 *
	 * @param array<int,array{starts_at:?string,ends_at:?string}> $periods soonest first
	 */
	public static function state_at( array $periods, string $now ): ?string {
		if ( empty( $periods ) ) {
			return null; // no periods at all means always on, not any of these
		}

		foreach ( $periods as $p ) {
			$from  = $p['starts_at'] ?? null;
			$until = $p['ends_at'] ?? null;

			$started  = empty( $from ) || strcmp( $now, (string) $from ) >= 0;
			$finished = ! empty( $until ) && strcmp( $now, (string) $until ) > 0;

			if ( $started && ! $finished ) {
				return 'active';
			}
			// The first one still ahead. Everything after it is later still.
			if ( ! $started ) {
				return 'scheduled';
			}
			// Reaching here means started AND finished, and "finished" can only
			// be true when this period HAS an end. An open-ended period is
			// therefore never behind us, so there is nothing to break out of.
		}

		return 'expired'; // every period is behind us
	}

	/** The same question, for a real form. */
	public static function state_for( int $form_id ): ?string {
		$periods = array_map(
			static fn( $r ) => [ 'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at ],
			self::live( $form_id )
		);
		return self::state_at( $periods, (string) current_time( 'mysql' ) );
	}

	/**
	 * Fold overlapping periods into one.
	 *
	 * An open end does NOT swallow what follows. It looks like an infinite
	 * overlap and is not: a period behind an open-ended one is dormant, which is
	 * a thing the person is told about and can undo by giving that one an end
	 * date. Merging them away would delete the plan instead, silently.
	 *
	 * @return int how many were absorbed
	 */
	public static function merge_overlaps( int $form_id ): int {
		$rows = self::for_form( $form_id );
		if ( count( $rows ) < 2 ) {
			return 0;
		}

		global $wpdb;
		$absorbed = 0;
		$kept     = array_shift( $rows );

		foreach ( $rows as $row ) {
			if ( empty( $kept->ends_at ) ) {
				break; // open-ended: everything after is dormant, not overlapping
			}
			$overlaps = empty( $row->starts_at ) || strcmp( (string) $row->starts_at, (string) $kept->ends_at ) <= 0;
			if ( ! $overlaps ) {
				$kept = $row;
				continue;
			}
			// The merged period runs to the later end, and an open end wins
			// outright because it runs forever.
			if ( empty( $row->ends_at ) || strcmp( (string) $row->ends_at, (string) $kept->ends_at ) > 0 ) {
				$wpdb->update( self::table(), [ 'ends_at' => $row->ends_at ], [ 'id' => (int) $kept->id ] );
				$kept->ends_at = $row->ends_at;
			}
			$wpdb->delete( self::table(), [ 'id' => (int) $row->id ] );
			++$absorbed;
		}

		return $absorbed;
	}
}
