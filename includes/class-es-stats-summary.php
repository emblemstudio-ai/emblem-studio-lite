<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Emblem_Studio_Stats_Summary {

	public const KINDS = [ 'viewers' => 'view', 'signups' => 'conversion', 'clickers' => 'click', 'leavers' => 'dropoff' ];

	public const KEEP_DAYS = 14;

	public const CRON_HOOK = 'emblem_studio_stats_summary';

	public static function table( string $name ): string {
		return Emblem_Studio_Install::table( $name );
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c      = $wpdb->get_charset_collate();
		$stats  = self::table( 'daily_stats' );
		$people = self::table( 'daily_people' );
		$prog   = self::table( 'summary_progress' );
		dbDelta( "CREATE TABLE {$stats} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			day DATE NOT NULL,
			breakdown VARCHAR(8) NOT NULL,
			event_type VARCHAR(20) NOT NULL,
			shell VARCHAR(20) NOT NULL DEFAULT '',
			variant VARCHAR(8) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			click_target VARCHAR(191) NOT NULL DEFAULT '',
			step VARCHAR(64) NOT NULL DEFAULT '',
			device VARCHAR(10) NOT NULL DEFAULT '',
			source VARCHAR(12) NOT NULL DEFAULT '',
			labelled TINYINT(1) NOT NULL DEFAULT 0,
			dims CHAR(40) NOT NULL,
			events INT(10) UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY form_day_dims (form_id, day, dims),
			KEY form_breakdown_day (form_id, breakdown, day),
			KEY form_event_day (form_id, event_type, day)
		) $c;" );
		dbDelta( "CREATE TABLE {$people} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			day DATE NOT NULL,
			kind VARCHAR(12) NOT NULL,
			shell VARCHAR(20) NOT NULL DEFAULT '',
			variant VARCHAR(8) NOT NULL DEFAULT '',
			device VARCHAR(10) NOT NULL DEFAULT '',
			people INT(10) UNSIGNED NOT NULL,
			sketch MEDIUMTEXT NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY person_group (form_id, day, kind, shell, variant, device),
			KEY form_kind_day (form_id, kind, day)
		) $c;" );
		dbDelta( "CREATE TABLE {$prog} (
			form_id BIGINT(20) UNSIGNED NOT NULL,
			through_day DATE NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (form_id)
		) $c;" );
	}

	public static function ready(): bool {
		global $wpdb;
		foreach ( [ 'daily_stats', 'daily_people', 'summary_progress' ] as $name ) {
			$t = self::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
				return false;
			}
		}
		return true;
	}

	public static function reads_enabled(): bool {
		static $ready = null;
		if ( $ready === null ) {
			$ready = self::ready();
		}
		return $ready && (bool) apply_filters( 'emblem_studio_summary_reads', true );
	}

	public static function cover_until( int $form_id ): ?string {
		if ( ! self::reads_enabled() ) {
			return null;
		}
		$done = self::through( $form_id );
		return $done === null ? null : ( new DateTimeImmutable( $done ) )->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );
	}

	public static function sums( int $form_id, string $breakdown, string $until, ?string $shell = null, ?string $variant = null, array $group = [] ): array {
		global $wpdb;
		$t      = self::table( 'daily_stats' );
		$where  = 'form_id = %d AND breakdown = %s AND day < %s';
		$params = [ $form_id, $breakdown, substr( $until, 0, 10 ) ];
		if ( $shell ) {
			$where   .= ' AND shell = %s';
			$params[] = $shell;
		}
		if ( $variant !== null && in_array( $variant, [ 'A', 'B' ], true ) ) {
			$where   .= ' AND variant = %s';
			$params[] = $variant;
		}
		$cols = implode( ', ', array_merge( [ 'event_type' ], $group ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cols are column names from this class.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT $cols, SUM(events) AS n FROM $t WHERE $where GROUP BY $cols", $params ) );
	}

	public static function people( int $form_id, string $kind, string $until, ?string $shell = null, ?string $variant = null, iterable $raw_visitors = [] ): Emblem_Studio_Hll {
		global $wpdb;
		$t      = self::table( 'daily_people' );
		$where  = 'form_id = %d AND kind = %s AND day < %s';
		$params = [ $form_id, $kind, substr( $until, 0, 10 ) ];
		if ( $shell ) {
			$where   .= ' AND shell = %s';
			$params[] = $shell;
		}
		if ( $variant !== null && in_array( $variant, [ 'A', 'B' ], true ) ) {
			$where   .= ' AND variant = %s';
			$params[] = $variant;
		}
		$h = new Emblem_Studio_Hll();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT sketch FROM $t WHERE $where", $params ) ) as $sketch ) {
			$h->merge( Emblem_Studio_Hll::decode( (string) $sketch ) );
		}
		foreach ( $raw_visitors as $v ) {
			$h->add( (string) $v );
		}
		return $h;
	}

	public static function bounds( string $date ): array {
		$d = new DateTimeImmutable( $date . ' 00:00:00' );
		return [ $d->format( 'Y-m-d H:i:s' ), $d->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) ];
	}

	public static function due_through( ?DateTimeImmutable $now = null ): string {
		$now = $now ?? new DateTimeImmutable( current_time( 'mysql' ) );
		return $now->setTime( 0, 0 )->modify( '-' . self::KEEP_DAYS . ' days' )->format( 'Y-m-d' );
	}

	public static function through( int $form_id ): ?string {
		global $wpdb;
		$d = $wpdb->get_var( $wpdb->prepare( 'SELECT through_day FROM ' . self::table( 'summary_progress' ) . ' WHERE form_id = %d', $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $d ? substr( (string) $d, 0, 10 ) : null;
	}

	public static function dims( array $row ): string {
		return sha1( implode( "\x1f", [
			$row['breakdown'], $row['event_type'], $row['shell'], $row['variant'], '',
			(string) $row['post_id'], $row['click_target'], $row['step'], $row['device'], $row['source'],
			empty( $row['labelled'] ) ? '0' : '1',
		] ) );
	}

	public static function day( int $form_id, string $date ): array {
		global $wpdb;
		$events   = self::table( 'events' );
		[ $from, $to ] = self::bounds( $date );
		$device   = '(' . Emblem_Studio_Reports::device_bucket_sql() . ')';
		$labelled = "(CASE WHEN visitor IS NOT NULL AND visitor <> '' THEN 1 ELSE 0 END)";

		$breakdowns = [
			'base'   => [ 'type', 'variant', "$labelled AS labelled" ],
			'page'   => [ 'type', 'variant', 'post_id' ],
			'item'   => [ 'type', 'variant', "$device AS device", 'click_target' ],
			'device' => [ 'type', 'variant', "$device AS device", "$labelled AS labelled" ],
			'step'   => [ 'type', 'variant', 'step' ],
			'source' => [ 'type', 'variant', 'source' ],
		];
		$stats = [];
		foreach ( $breakdowns as $breakdown => $cols ) {
			$where = 'form_id = %d AND created_at >= %s AND created_at < %s';
			if ( $breakdown === 'item' ) {
				$where .= " AND click_target IS NOT NULL AND click_target <> ''";
			}
			if ( $breakdown === 'step' ) {
				$where .= " AND event_type = 'dropoff'";
			}
			$select = implode( ', ', $cols );
			$group  = implode( ', ', array_map( static function ( string $c ): string {
				return preg_replace( '/ AS \w+$/', '', $c );
			}, $cols ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns and SQL fragments are constants above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_type, $select, COUNT(*) AS n FROM $events WHERE $where GROUP BY event_type, $group", $form_id, $from, $to ) );
			foreach ( (array) $rows as $r ) {
				$row = [
					'form_id'      => $form_id,
					'day'          => $date,
					'breakdown'    => $breakdown,
					'event_type'   => (string) $r->event_type,
					'shell'        => (string) ( $r->type ?? '' ),
					'variant'      => (string) ( $r->variant ?? '' ),
					'post_id'      => (int) ( $r->post_id ?? 0 ),
					'click_target' => (string) ( $r->click_target ?? '' ),
					'step'         => (string) ( $r->step ?? '' ),
					'device'       => (string) ( $r->device ?? '' ),
					'source'       => (string) ( $r->source ?? '' ),
					'labelled'     => (int) ( $r->labelled ?? 0 ) ? 1 : 0,
				];
				$dims = self::dims( $row );
				if ( isset( $stats[ $dims ] ) ) {
					$stats[ $dims ]['events'] += (int) $r->n;
					continue;
				}
				$row['dims']     = $dims;
				$row['events']   = (int) $r->n;
				$stats[ $dims ]  = $row;
			}
		}

		$people = [];
		foreach ( self::KINDS as $kind => $type ) {
			$where = "form_id = %d AND event_type = %s AND created_at >= %s AND created_at < %s AND visitor IS NOT NULL AND visitor <> ''";
			if ( $type === 'click' ) {
				$where .= " AND (click_target IS NULL OR click_target NOT IN ('__close__', '__backdrop__'))";
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$seen = (array) $wpdb->get_results( $wpdb->prepare( "SELECT visitor, type, variant, $device AS device, COUNT(*) AS n FROM $events WHERE $where GROUP BY visitor, type, variant, $device", $form_id, $type, $from, $to ) );

			$returned = [];
			if ( $kind === 'viewers' && $seen ) {
				foreach ( $seen as $v ) {
					$returned[ $v->visitor ] = ( $returned[ $v->visitor ] ?? 0 ) + (int) $v->n;
				}
				$until = ( new DateTimeImmutable( $to ) )->modify( '+' . self::KEEP_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
				$ids   = array_values( array_unique( array_map( static function ( $v ) {
					return (string) $v->visitor;
				}, $seen ) ) );
				foreach ( array_chunk( $ids, 500 ) as $chunk ) {
					$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$later = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT visitor FROM $events WHERE form_id = %d AND event_type = 'view' AND created_at >= %s AND created_at < %s AND visitor IN ($in)", array_merge( [ $form_id, $to, $until ], $chunk ) ) );
					foreach ( (array) $later as $l ) {
						$returned[ $l ] = ( $returned[ $l ] ?? 0 ) + 2;
					}
				}
			}
			foreach ( $seen as $v ) {
				$back  = ( $returned[ $v->visitor ] ?? 0 ) >= 2;
				$kinds = $kind === 'viewers' && $back ? [ $kind, 'returners' ] : [ $kind ];
				foreach ( $kinds as $k ) {
					$key = $k . '|' . ( $v->type ?? '' ) . '|' . ( $v->variant ?? '' ) . '|' . ( $v->device ?? '' );
					if ( ! isset( $people[ $key ] ) ) {
						$people[ $key ] = [
							'row' => [
								'form_id' => $form_id, 'day' => $date, 'kind' => $k,
								'shell' => (string) ( $v->type ?? '' ), 'variant' => (string) ( $v->variant ?? '' ),
								'device' => (string) ( $v->device ?? '' ), 'people' => 0,
							],
							'hll' => new Emblem_Studio_Hll(),
							'ids' => [],
						];
					}
					if ( ! isset( $people[ $key ]['ids'][ $v->visitor ] ) ) {
						$people[ $key ]['ids'][ $v->visitor ] = true;
						$people[ $key ]['row']['people']++;
						$people[ $key ]['hll']->add( (string) $v->visitor );
					}
				}
			}
		}
		$out = [];
		foreach ( $people as $p ) {
			$out[] = [ 'row' => $p['row'], 'hll' => $p['hll'] ];
		}
		return [ 'stats' => array_values( $stats ), 'people' => $out ];
	}

	public static function write( int $form_id, string $date, array $built, bool $advance = false ): bool {
		global $wpdb;
		$stats  = self::table( 'daily_stats' );
		$people = self::table( 'daily_people' );
		$wpdb->query( 'START TRANSACTION' );
		$ok = false !== $wpdb->query( $wpdb->prepare( "DELETE FROM $stats WHERE form_id = %d AND day = %s", $form_id, $date ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			&& false !== $wpdb->query( $wpdb->prepare( "DELETE FROM $people WHERE form_id = %d AND day = %s", $form_id, $date ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $built['stats'] as $row ) {
			if ( ! $ok ) {
				break;
			}
			$ok = false !== $wpdb->insert( $stats, $row );
		}
		foreach ( $built['people'] as $p ) {
			if ( ! $ok ) {
				break;
			}
			$ok = false !== $wpdb->insert( $people, $p['row'] + [ 'sketch' => $p['hll']->encode() ] );
		}
		if ( $ok && $advance ) {
			$ok = false !== $wpdb->replace( self::table( 'summary_progress' ), [ 'form_id' => $form_id, 'through_day' => $date, 'updated_at' => current_time( 'mysql' ) ] );
		}
		$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
		return $ok;
	}

	public static function catch_up( int $form_id, ?DateTimeImmutable $now = null, ?int $deadline = null ): int {
		global $wpdb;
		$due  = self::due_through( $now );
		$done = self::through( $form_id );
		if ( $done !== null ) {
			$day = ( new DateTimeImmutable( $done ) )->modify( '+1 day' )->format( 'Y-m-d' );
		} else {
			$first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at) FROM ' . self::table( 'events' ) . ' WHERE form_id = %d', $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $first ) {
				return 0;
			}
			$day = substr( (string) $first, 0, 10 );
		}
		$written = 0;
		while ( $day <= $due ) {
			if ( $deadline !== null && time() >= $deadline ) {
				break;
			}
			if ( ! self::write( $form_id, $day, self::day( $form_id, $day ), true ) ) {
				if ( class_exists( 'Emblem_Studio_Logger' ) ) {
					Emblem_Studio_Logger::error( 'Daily stats summary could not be written', [ 'form_id' => $form_id, 'day' => $day ] );
				}
				break;
			}
			$written++;
			$day = ( new DateTimeImmutable( $day ) )->modify( '+1 day' )->format( 'Y-m-d' );
		}
		return $written;
	}

	public static function run_nightly( int $budget = 120 ): int {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$deadline = time() + max( 10, $budget );
		$ids      = $wpdb->get_col( 'SELECT DISTINCT form_id FROM ' . self::table( 'events' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$days     = 0;
		foreach ( (array) $ids as $id ) {
			if ( time() >= $deadline ) {
				break;
			}
			$days += self::catch_up( (int) $id, null, $deadline );
		}
		return $days;
	}

	public static function forget( ?array $form_ids = null, ?string $shell = null ): void {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		$stats  = self::table( 'daily_stats' );
		$people = self::table( 'daily_people' );
		$prog   = self::table( 'summary_progress' );
		if ( $form_ids === null ) {
			foreach ( [ $stats, $people, $prog ] as $t ) {
				$wpdb->query( "TRUNCATE TABLE $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return;
		}
		foreach ( $form_ids as $id ) {
			foreach ( [ $stats, $people ] as $t ) {
				if ( $shell ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE form_id = %d AND shell = %s", $id, $shell ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				} else {
					$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE form_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			if ( ! $shell ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM $prog WHERE form_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	public static function rewind( array $earliest ): void {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		foreach ( $earliest as $form_id => $at ) {
			$done = self::through( (int) $form_id );
			$day  = substr( (string) $at, 0, 10 );
			if ( $done === null || $day > $done ) {
				continue;
			}
			$back = ( new DateTimeImmutable( $day ) )->modify( '-1 day' )->format( 'Y-m-d' );
			$wpdb->query( 'START TRANSACTION' );
			foreach ( [ 'daily_stats', 'daily_people' ] as $name ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( $name ) . ' WHERE form_id = %d AND day >= %s', (int) $form_id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->update( self::table( 'summary_progress' ), [ 'through_day' => $back, 'updated_at' => current_time( 'mysql' ) ], [ 'form_id' => (int) $form_id ] );
			$wpdb->query( 'COMMIT' );
		}
	}
}
