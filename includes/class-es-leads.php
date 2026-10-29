<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Leads {

	public const PER_PAGE = 20;

	public static function parse_filters( array $request ): array {
		$form_id = isset( $request['form_id'] ) && (int) $request['form_id'] > 0 ? (int) $request['form_id'] : null;

		$from = isset( $request['from'] ) ? trim( (string) $request['from'] ) : '';
		$to   = isset( $request['to'] )   ? trim( (string) $request['to'] )   : '';
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : null;
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to )   ? $to   : null;

		$status_in = isset( $request['status'] ) ? sanitize_key( (string) $request['status'] ) : 'all';
		$status    = in_array( $status_in, [ 'synced', 'failed', 'pending' ], true ) ? $status_in : 'all';

		$email_raw = isset( $request['email'] ) ? trim( (string) $request['email'] ) : '';
		$email     = $email_raw !== '' ? sanitize_text_field( $email_raw ) : null;

		$kind_in = isset( $request['kind'] ) ? sanitize_key( (string) $request['kind'] ) : 'humans';
		$kind    = in_array( $kind_in, [ 'humans', 'bots', 'all' ], true ) ? $kind_in : 'humans';

		return [
			'form_id' => $form_id,
			'from'    => $from,
			'to'      => $to,
			'status'  => $status,
			'email'   => $email,
			'kind'    => $kind,
		];
	}

	private static function build_where( array $f ): array {
		$clauses = [];
		$args    = [];
		if ( ! empty( $f['form_id'] ) ) {
			$clauses[] = 'l.form_id = %d';
			$args[]    = (int) $f['form_id'];
		}
		if ( ! empty( $f['from'] ) ) {
			$clauses[] = 'l.created_at >= %s';
			$args[]    = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) ) {
			$clauses[] = 'l.created_at <= %s';
			$args[]    = $f['to'] . ' 23:59:59';
		}
		if ( ! empty( $f['status'] ) ) {
			switch ( $f['status'] ) {
				case 'synced':
					$clauses[] = 'l.funnelkit_pushed = 1';
					break;
				case 'failed':
					$clauses[] = '(l.error IS NOT NULL AND l.error <> %s)';
					$args[]    = '';
					break;
				case 'pending':
					$clauses[] = 'l.funnelkit_pushed = 0 AND (l.error IS NULL OR l.error = %s)';
					$args[]    = '';
					break;
			}
		}
		if ( ! empty( $f['email'] ) ) {
			global $wpdb;
			$clauses[] = 'l.email LIKE %s';
			$args[]    = '%' . $wpdb->esc_like( strtolower( $f['email'] ) ) . '%';
		}
		$kind = $f['kind'] ?? 'humans';
		if ( $kind === 'humans' ) {
			$clauses[] = 'l.is_bot = 0';
		} elseif ( $kind === 'bots' ) {
			$clauses[] = 'l.is_bot = 1';
		}
		$sql = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
		return [ 'sql' => $sql, 'args' => $args ];
	}

	public static function counts_by_kind( array $filters ): array {
		global $wpdb;
		$leads = ES_Install::table( 'leads' );
		$f = $filters;
		$f['kind'] = 'all';
		$where = self::build_where( $f );
		$sql = "SELECT
			SUM(CASE WHEN l.is_bot = 0 THEN 1 ELSE 0 END) AS humans,
			SUM(CASE WHEN l.is_bot = 1 THEN 1 ELSE 0 END) AS bots,
			COUNT(*) AS total
			FROM $leads l {$where['sql']}";
		$row = $where['args']
			? $wpdb->get_row( $wpdb->prepare( $sql, $where['args'] ) )
			: $wpdb->get_row( $sql );
		return [
			'humans' => (int) ( $row->humans ?? 0 ),
			'bots'   => (int) ( $row->bots ?? 0 ),
			'all'    => (int) ( $row->total ?? 0 ),
		];
	}

	public static function paginated( int $page = 1, $filters = null ): array {
		global $wpdb;
		$leads = ES_Install::table( 'leads' );
		$forms = ES_Install::table( 'forms' );

		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		if ( is_int( $filters ) ) {
			$filters = [ 'form_id' => $filters ];
		}
		$filters = is_array( $filters ) ? $filters : [];

		$where = self::build_where( $filters );

		$count_sql = "SELECT COUNT(*) FROM $leads l {$where['sql']}";
		$total     = (int) ( $where['args'] ? $wpdb->get_var( $wpdb->prepare( $count_sql, $where['args'] ) ) : $wpdb->get_var( $count_sql ) );

		$rows_sql = "SELECT l.id, l.created_at, l.email, l.extra_fields,
				l.funnelkit_pushed, l.funnelkit_contact_id, l.error,
				l.is_bot, l.bot_signals, l.ip,
				f.id AS form_id, f.name AS form_name, f.slug AS form_slug
			FROM $leads l
			LEFT JOIN $forms f ON f.id = l.form_id
			{$where['sql']}
			ORDER BY l.id DESC
			LIMIT %d OFFSET %d";
		$args = array_merge( $where['args'], [ self::PER_PAGE, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $args ) );

		return [ 'rows' => $rows ?: [], 'total' => $total ];
	}

	public static function lead_capable_forms( array $forms ): array {
		if ( ! $forms ) {
			return [];
		}

		global $wpdb;
		$ids = array_map( static fn( object $f ): int => (int) $f->id, $forms );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
		$with_leads = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT form_id FROM ' . ES_Install::table( 'leads' ) . " WHERE form_id IN ($in)",
			$ids
		) );
		$with_leads = array_flip( array_map( 'intval', $with_leads ?: [] ) );

		return array_values( array_filter(
			$forms,
			static fn( object $f ): bool => isset( $with_leads[ (int) $f->id ] )
				|| ES_Form_Repo::primary_metric( (string) ( $f->html ?? '' ) ) === 'conversions'
		) );
	}

	public static function bot_signals( ?string $json ): array {
		if ( ! $json ) {
			return [];
		}
		$s = json_decode( $json, true );
		return ( is_array( $s ) && ! empty( $s['hp_filled'] ) ) ? $s : [];
	}

	public static function bot_confirmed( array $signals ): bool {
		return ! empty( $signals['ua_bot'] ) || ! empty( $signals['datacenter'] );
	}

	public static function bot_reason( array $signals, bool $is_bot ): string {
		if ( ! $signals ) {
			return '';
		}

		if ( empty( $signals['ts_received'] ) ) {
			$trap = __( 'The hidden trap came back filled, and the submission carried no valid time stamp, so it never ran our script.', 'emblem-studio' );
		} elseif ( ! empty( $signals['clock_skew'] ) ) {
			$ahead = abs( round( ( (int) ( $signals['elapsed_ms'] ?? 0 ) ) / 1000, 1 ) );
			/* translators: %s: seconds this visitor's device clock runs ahead of the server's. */
			$trap  = sprintf( __( "The hidden trap came back filled, but this visitor's device clock runs about %ss ahead of ours, so the speed check had nothing readable to go on.", 'emblem-studio' ), $ahead );
		} else {
			$seconds = round( ( (int) ( $signals['elapsed_ms'] ?? 0 ) ) / 1000, 1 );
			$trap = ( $signals['timing'] ?? null ) === 'stamp'
				/* translators: %s: seconds between the visitor starting on the form and the submission. */
				? sprintf( __( 'The hidden trap came back filled, and the form was sent %ss after the visitor started on it.', 'emblem-studio' ), $seconds )
				/* translators: %s: seconds between the form appearing and the submission. */
				: sprintf( __( 'The hidden trap came back filled, and the form was sent %ss after it appeared.', 'emblem-studio' ), $seconds );
		}

		$ua = (string) ( $signals['ua_bot'] ?? '' );
		$dc = (string) ( $signals['datacenter'] ?? '' );

		if ( $ua !== '' && $dc !== '' ) {
			/* translators: 1: bot name from the user agent, 2: cloud provider name. */
			$backing = sprintf( __( 'The browser called itself %1$s, from a %2$s address. That is a bot.', 'emblem-studio' ), $ua, $dc );
		} elseif ( $ua !== '' ) {
			/* translators: %s: bot name from the user agent. */
			$backing = sprintf( __( 'The browser called itself %s. That is a bot.', 'emblem-studio' ), $ua );
		} elseif ( $dc !== '' ) {
			/* translators: %s: cloud provider name. */
			$backing = sprintf( __( 'It came from a %s address, which is a server rather than a home or phone connection. That is a bot.', 'emblem-studio' ), $dc );
		} elseif ( $is_bot ) {
			$backing = __( 'Nothing else backs that up: an ordinary browser, on an ordinary connection.', 'emblem-studio' );
		} else {
			$backing = __( 'Nothing else identified a bot either, so it counted as a person.', 'emblem-studio' );
		}

		return $trap . ' ' . $backing;
	}

	public static function display_name( ?string $extra_json ): string {
		if ( ! $extra_json ) {
			return '—';
		}
		$x = json_decode( $extra_json, true );
		if ( ! is_array( $x ) ) {
			return '—';
		}
		$parts = array_filter( [
			$x['first_name'] ?? '',
			$x['last_name']  ?? '',
		], fn( $v ) => $v !== '' );
		if ( ! $parts ) {
			return $x['name'] ?? '—';
		}
		return implode( ' ', $parts );
	}

	public static function delete( int $id ): bool {
		if ( ! ES_Measurement::storage_transactional() ) {
			global $wpdb;
			$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT id, form_id FROM ' . ES_Install::table( 'leads' ) . ' WHERE id = %d', $id ) );
			$events = ES_Measurement::events_for_lead( $id );
			if ( ! $lead || is_wp_error( $events ) || $events !== [] || ES_Measurement::policy( [ 'form_id' => (int) $lead->form_id, 'event_type' => 'privacy', 'lead_id' => $id ] ) !== 'legacy' ) { return false; }
			return $wpdb->delete( ES_Install::table( 'leads' ), [ 'id' => $id ], [ '%d' ] ) === 1;
		}
		return ES_Measurement_Transaction::run( static function () use ( $id ): bool {
			global $wpdb;
			$lead = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ES_Install::table( 'leads' ) . ' WHERE id = %d FOR UPDATE', $id ) );
			if ( ! $lead || ES_Measurement::erase_for_lead( $id ) === false ) { return false; }
			return $wpdb->delete( ES_Install::table( 'leads' ), [ 'id' => $id ], [ '%d' ] ) === 1;
		} ) === true;
	}

	public static function delete_by_saas_id( int $saas_lead_id ): bool {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ES_Install::table( 'leads' ) . ' WHERE saas_lead_id = %d', $saas_lead_id ) );
		return $id ? self::delete( (int) $id ) : false;
	}

	public static function move_to_humans( int $lead_id, bool $push = true ): array {
		global $wpdb;
		$leads_table = ES_Install::table( 'leads' );

		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $leads_table WHERE id = %d", $lead_id ) );
		if ( ! $lead ) {
			return [ 'ok' => false, 'message' => __( 'Lead not found.', 'emblem-studio' ) ];
		}
		if ( (int) $lead->is_bot !== 1 ) {
			return [ 'ok' => false, 'message' => __( 'Lead is already a human.', 'emblem-studio' ) ];
		}

		$wpdb->update( $leads_table, [ 'is_bot' => 0 ], [ 'id' => $lead_id ] );

		$events_table = ES_Install::table( 'events' );
		$hp_event_id  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $events_table WHERE form_id = %d AND event_type = 'honeypot_blocked' AND created_at = %s LIMIT 1",
			(int) $lead->form_id,
			$lead->created_at
		) );
		if ( $hp_event_id ) {
			$wpdb->update( $events_table, [ 'event_type' => 'conversion' ], [ 'id' => (int) $hp_event_id ] );

			if ( class_exists( 'ES_AB' ) ) {
				ES_AB::auto_winner_check( (int) $lead->form_id );
			}
		}

		if ( ! $push ) {
			return [ 'ok' => true, 'message' => null ];
		}

		return self::push_to_connections( $lead );
	}

	public static function retry_push( int $lead_id ): array {
		global $wpdb;
		$leads_table = ES_Install::table( 'leads' );

		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $leads_table WHERE id = %d", $lead_id ) );
		if ( ! $lead ) {
			return [ 'ok' => false, 'message' => __( 'Lead not found.', 'emblem-studio' ) ];
		}
		if ( (int) $lead->is_bot === 1 ) {
			return [ 'ok' => false, 'message' => __( 'This lead is still flagged as a bot — move it to Humans first.', 'emblem-studio' ) ];
		}
		if ( (int) $lead->funnelkit_pushed === 1 ) {
			return [ 'ok' => true, 'message' => __( 'Already synced.', 'emblem-studio' ) ];
		}

		return self::push_to_connections( $lead );
	}

	private static function push_to_connections( object $lead ): array {
		global $wpdb;
		$leads_table = ES_Install::table( 'leads' );

		$form = ES_Form_Repo::find( (int) $lead->form_id );
		if ( ! $form ) {
			return [ 'ok' => true, 'message' => __( 'Source form is gone — nothing was pushed.', 'emblem-studio' ) ];
		}
		$extra = $lead->extra_fields ? json_decode( (string) $lead->extra_fields, true ) : [];
		$extra = is_array( $extra ) ? $extra : [];
		$first = (string) ( $extra['first_name'] ?? '' );
		$last  = (string) ( $extra['last_name']  ?? '' );
		$phone = (string) ( $extra['phone']      ?? '' );

		$fk_enabled = ! isset( $form->funnelkit_enabled ) || ! empty( $form->funnelkit_enabled );
		$lists = is_array( $form->funnelkit_lists ?? null )
			? $form->funnelkit_lists
			: json_decode( (string) ( $form->funnelkit_lists ?? '[]' ), true );
		$tags = is_array( $form->funnelkit_tags ?? null )
			? $form->funnelkit_tags
			: json_decode( (string) ( $form->funnelkit_tags ?? '[]' ), true );
		$asset_key = ! empty( $form->saas_form_id )
			? 'saas:' . (int) $form->saas_form_id
			: 'wp:' . (int) $form->id;
		$push_res = ES_FunnelKit_Delivery::dispatch(
			$asset_key,
			array_merge( [ 'email' => (string) $lead->email ], $extra ),
			[
				'email'         => (string) $lead->email,
				'first_name'    => $first,
				'last_name'     => $last,
				'phone'         => $phone,
				'lists'         => is_array( $lists ) ? $lists : [],
				'tags'          => is_array( $tags ) ? $tags : [],
				'custom_fields' => $extra,
				'contact_status' => ES_FunnelKit_Upsert::contact_status( $form->funnelkit_contact_status ?? null ),
			],
			$fk_enabled
		);
		if ( ( $push_res['mode'] ?? '' ) !== 'disabled' ) {
			$wpdb->update( $leads_table, [
				'funnelkit_pushed'     => $push_res['ok'] ? 1 : 0,
				'funnelkit_contact_id' => $push_res['contact_id'] ?? null,
				'error'                => $push_res['ok'] ? null : ( $push_res['error'] ?? 'Unknown' ),
			], [ 'id' => (int) $lead->id ] );
		}

		if ( class_exists( 'ES_Webhooks' ) ) {
			$payload = ES_Webhooks::build_payload( $form, (string) $lead->email, $first, $last, $phone, $extra, $push_res['contact_id'] ?? null );
			ES_Webhooks::send_for_form( $form, $payload );
		}

		return [ 'ok' => true, 'message' => null ];
	}

	public static function delete_by_email( string $email ): int {
		global $wpdb;
		$normalized = strtolower( trim( $email ) );
		$removed = 0;
		foreach ( self::find_by_email( $normalized ) as $lead ) {
			$removed += self::delete( (int) $lead->id ) ? 1 : 0;
		}
		return $removed;
	}

	public static function find_by_email( string $email ): array {
		global $wpdb;
		$t = ES_Install::table( 'leads' );
		$normalized = strtolower( trim( $email ) );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE email = %s ORDER BY id DESC", $normalized ) );
	}

	public static function stream_csv( $filters = null ): void {
		global $wpdb;
		$leads = ES_Install::table( 'leads' );
		$forms = ES_Install::table( 'forms' );

		if ( is_int( $filters ) ) {
			$filters = [ 'form_id' => $filters ];
		}
		$filters = is_array( $filters ) ? $filters : [];

		$where = self::build_where( $filters );

		$sql = "SELECT l.id, l.created_at, l.email, l.extra_fields,
				l.funnelkit_pushed, l.funnelkit_contact_id, l.error,
				f.name AS form_name, f.slug AS form_slug
			FROM $leads l LEFT JOIN $forms f ON f.id = l.form_id
			{$where['sql']}
			ORDER BY l.id DESC";
		$rows = $where['args']
			? $wpdb->get_results( $wpdb->prepare( $sql, $where['args'] ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::csv_filename( $filters ) . '"' );

		$decoded = [];
		$custom_keys = [];
		foreach ( (array) $rows as $r ) {
			$extra = $r['extra_fields'] ? json_decode( $r['extra_fields'], true ) : [];
			$extra = is_array( $extra ) ? $extra : [];
			unset( $extra['first_name'], $extra['last_name'], $extra['phone'], $extra['tags'] );
			foreach ( array_keys( $extra ) as $key ) {
				$custom_keys[ $key ] = true;
			}
			$decoded[] = $extra;
		}
		$custom_keys = array_keys( $custom_keys );

		$consent = ES_Consent_Log::for_leads( array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) ) );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array_merge(
			[ 'id', 'created_at', 'form', 'form_slug', 'email', 'first_name', 'last_name', 'phone', 'tags' ],
			array_map( [ self::class, 'humanize_custom_key' ], $custom_keys ),
			[ 'funnelkit_pushed', 'funnelkit_contact_id', 'error', 'consent', 'consent_wording' ]
		) );

		foreach ( (array) $rows as $i => $r ) {
			$extra = $r['extra_fields'] ? json_decode( $r['extra_fields'], true ) : [];
			$extra = is_array( $extra ) ? $extra : [];
			$first = $extra['first_name'] ?? '';
			$last  = $extra['last_name']  ?? '';
			$phone = $extra['phone']      ?? '';
			$tags  = isset( $extra['tags'] ) ? ( is_array( $extra['tags'] ) ? implode( '|', $extra['tags'] ) : (string) $extra['tags'] ) : '';

			$custom_cells = [];
			foreach ( $custom_keys as $key ) {
				$custom_cells[] = self::custom_field_display( $decoded[ $i ][ $key ] ?? '' );
			}

			$cs = $consent[ (int) $r['id'] ] ?? [];

			$row = array_merge(
				[ $r['id'], $r['created_at'], $r['form_name'], $r['form_slug'], $r['email'], $first, $last, $phone, $tags ],
				$custom_cells,
				[
					$r['funnelkit_pushed'],
					$r['funnelkit_contact_id'],
					$r['error'],
					implode( '; ', array_map( static fn( $c ) => $c['field'] . ': ' . ( $c['agreed'] ? 'yes' : 'no' ), $cs ) ),
					implode( ' | ', array_map( static fn( $c ) => $c['text'], array_filter( $cs, static fn( $c ) => '' !== $c['text'] ) ) ),
				]
			);
			fputcsv( $out, array_map( [ self::class, 'neutralize_formula' ], $row ) );
		}
		fclose( $out );
		exit;
	}

	private static function humanize_custom_key( string $key ): string {
		$words = array_filter( preg_split( '/[_\-]+/', rtrim( $key, '[]' ) ) ?: [] );
		return $words ? implode( ' ', array_map( 'ucfirst', $words ) ) : $key;
	}

	private static function custom_field_display( $value ): string {
		if ( $value === null || $value === '' ) {
			return '';
		}
		return is_array( $value ) ? implode( '; ', array_map( 'strval', $value ) ) : (string) $value;
	}

	private static function csv_filename( array $filters ): string {
		$parts = [ 'emblem-studio-leads' ];
		if ( ! empty( $filters['form_id'] ) ) {
			$parts[] = 'form-' . (int) $filters['form_id'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$parts[] = 'from-' . $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$parts[] = 'to-' . $filters['to'];
		}
		if ( ! empty( $filters['status'] ) && $filters['status'] !== 'all' ) {
			$parts[] = $filters['status'];
		}
		$parts[] = gmdate( 'Y-m-d' );
		return implode( '-', $parts ) . '.csv';
	}

	private static function neutralize_formula( $value ): string {
		$value = (string) $value;
		if ( $value === '' ) {
			return $value;
		}
		$first = $value[0];
		if ( $first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r" ) {
			return "'" . $value;
		}
		return $value;
	}

	public static function purge_bot_leads( int $days ): int {
		global $wpdb;
		$t       = ES_Install::table( 'leads' );
		$cutoff  = wp_date( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$deleted = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE is_bot = 1 AND created_at < %s LIMIT 5000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted += $n;
			if ( $n < 5000 ) {
				break;
			}
		}
		return $deleted;
	}
}
