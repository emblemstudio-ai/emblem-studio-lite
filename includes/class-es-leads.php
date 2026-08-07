<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Leads {

	public const PER_PAGE = 20;

	/**
	 * Parse the filter set out of a request array (typically $_GET).
	 * Single source of truth — both the list view and the CSV export
	 * route through this so the filter shape is identical everywhere.
	 *
	 * Returns: [
	 *   'form_id' => int|null,
	 *   'from'    => string|null  (YYYY-MM-DD),
	 *   'to'      => string|null  (YYYY-MM-DD),
	 *   'status'  => 'all'|'synced'|'failed'|'pending',
	 *   'email'   => string|null  (search term, partial match),
	 *   'kind'    => 'humans'|'bots'|'all',  (default 'humans')
	 * ]
	 */
	public static function parse_filters( array $request ): array {
		$form_id = isset( $request['form_id'] ) && (int) $request['form_id'] > 0 ? (int) $request['form_id'] : null;

		$from = isset( $request['from'] ) ? trim( (string) $request['from'] ) : '';
		$to   = isset( $request['to'] )   ? trim( (string) $request['to'] )   : '';
		// Validate YYYY-MM-DD shape — anything else gets dropped.
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : null;
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to )   ? $to   : null;

		$status_in = isset( $request['status'] ) ? sanitize_key( (string) $request['status'] ) : 'all';
		$status    = in_array( $status_in, [ 'synced', 'failed', 'pending' ], true ) ? $status_in : 'all';

		$email_raw = isset( $request['email'] ) ? trim( (string) $request['email'] ) : '';
		$email     = $email_raw !== '' ? sanitize_text_field( $email_raw ) : null;

		// Bot / human partition. Default = humans so the page never shows
		// honeypot rows on first load; the user has to opt in.
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

	/**
	 * Build the WHERE clause + prepare-args for a given filter set.
	 * Returns [ 'sql' => string, 'args' => array ] where sql is empty
	 * or starts with "WHERE ...".
	 */
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
		// Bot partition. 'all' means no clause; default ('humans') hides
		// honeypot rows. 'bots' shows only them.
		$kind = $f['kind'] ?? 'humans';
		if ( $kind === 'humans' ) {
			$clauses[] = 'l.is_bot = 0';
		} elseif ( $kind === 'bots' ) {
			$clauses[] = 'l.is_bot = 1';
		}
		$sql = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
		return [ 'sql' => $sql, 'args' => $args ];
	}

	/**
	 * Per-tab counts (humans / bots / all) for the segmented control on
	 * the leads page. Honors every other active filter so the badge
	 * reflects "if you switched, this is what you'd see". One query so
	 * the page render doesn't pay three round-trips.
	 */
	public static function counts_by_kind( array $filters ): array {
		global $wpdb;
		$leads = ES_Install::table( 'leads' );
		// Build a where clause that EXCLUDES the kind filter — we want
		// counts split out by kind, not pre-filtered to one of them.
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

	/**
	 * Returns [ 'rows' => array, 'total' => int ] for the leads list.
	 * Accepts either a filter array (preferred) or — for backward compat
	 * with the previous signature — an int form_id as the second arg.
	 */
	public static function paginated( int $page = 1, $filters = null ): array {
		global $wpdb;
		$leads = ES_Install::table( 'leads' );
		$forms = ES_Install::table( 'forms' );

		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		// Backward-compat: callers passing just an int form_id still work.
		if ( is_int( $filters ) ) {
			$filters = [ 'form_id' => $filters ];
		}
		$filters = is_array( $filters ) ? $filters : [];

		$where = self::build_where( $filters );

		$count_sql = "SELECT COUNT(*) FROM $leads l {$where['sql']}";
		$total     = (int) ( $where['args'] ? $wpdb->get_var( $wpdb->prepare( $count_sql, $where['args'] ) ) : $wpdb->get_var( $count_sql ) );

		$rows_sql = "SELECT l.id, l.created_at, l.email, l.extra_fields,
				l.funnelkit_pushed, l.funnelkit_contact_id, l.error,
				l.is_bot, l.ip,
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

	/**
	 * Extract a displayable name from a lead's extra_fields JSON. Falls back to '—'.
	 */
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
		global $wpdb;
		return (bool) $wpdb->delete( ES_Install::table( 'leads' ), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Delete the local row mirrored down from a given SaaS-native lead (matched
	 * by saas_lead_id, not the local id — this lead never had a WordPress id of
	 * its own until pull_leads() mirrored it here). Used by lead-deletion sync,
	 * both the instant delete route and the periodic reconciliation pull.
	 */
	public static function delete_by_saas_id( int $saas_lead_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( ES_Install::table( 'leads' ), [ 'saas_lead_id' => $saas_lead_id ], [ '%d' ] );
	}

	/**
	 * Reclassify a bot lead as a human (flips is_bot to 0). Optional
	 * second arg controls whether the saved fields get pushed to
	 * FunnelKit + per-form webhooks retroactively — that's the whole
	 * reason for the button (deliver the freebie a real visitor missed).
	 *
	 * Returns: [ 'ok' => bool, 'message' => string|null ]
	 */
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

		// Every stat driven by the events table (Conversions, conv. rate,
		// "Blocked spam") needs correcting too — a real submission would
		// have recorded a 'conversion' event and never a 'honeypot_blocked'
		// one. Rather than delete one and insert the other (losing whatever
		// context the original event captured, and risking the two falling
		// out of sync), convert the SAME row in place: one UPDATE moves it
		// out of "Blocked spam" and into "Conversions" atomically.
		// Correlated by form_id + created_at (the event and the lead were
		// written in the same request; no direct FK between them exists).
		// No match (old data, second-boundary miss) leaves both stats as-is
		// rather than blocking the rest of this.
		$events_table = ES_Install::table( 'events' );
		$hp_event_id  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $events_table WHERE form_id = %d AND event_type = 'honeypot_blocked' AND created_at = %s LIMIT 1",
			(int) $lead->form_id,
			$lead->created_at
		) );
		if ( $hp_event_id ) {
			$wpdb->update( $events_table, [ 'event_type' => 'conversion' ], [ 'id' => (int) $hp_event_id ] );

			// Conversion is the A/B auto-winner's success metric — a lead
			// that now genuinely counts as one should be able to affect
			// that result, same as it would have at submit time. Throttled
			// / no-op when no test is running.
			if ( class_exists( 'ES_AB' ) ) {
				ES_AB::auto_winner_check( (int) $lead->form_id );
			}
		}

		if ( ! $push ) {
			return [ 'ok' => true, 'message' => null ];
		}

		return self::push_to_connections( $lead );
	}

	/**
	 * Manually re-try FunnelKit + webhook delivery for a lead that's a
	 * confirmed human but never landed — `funnelkit_pushed=0`, whether
	 * that's a genuine error (`error` set) or the form's FK destination
	 * was disabled at submit time and someone's since turned it on. The
	 * plugin's push either succeeds or fails within the same request (see
	 * `move_to_humans`'s docblock — there's no async "stuck pending" state
	 * to safeguard against here the way the SaaS's connector needs to), so
	 * this is a plain re-run, not a queued/backed-off retry.
	 *
	 * Returns: [ 'ok' => bool, 'message' => string|null ]
	 */
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

	/**
	 * The actual FunnelKit + per-form-webhook delivery for one lead row —
	 * shared by `move_to_humans`'s "Move + push" and `retry_push`, so the
	 * two can never drift on what a "push" actually does. Best-effort: if
	 * the form is gone, silently reports that and does nothing else; the
	 * caller (a human who already confirmed the action) can deal with it
	 * manually either way.
	 *
	 * @param object $lead A full row from the leads table.
	 * Returns: [ 'ok' => bool, 'message' => string|null ]
	 */
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

		// Per-form webhook fan-out.
		if ( class_exists( 'ES_Webhooks' ) ) {
			$payload = ES_Webhooks::build_payload( $form, (string) $lead->email, $first, $last, $phone, $extra, $push_res['contact_id'] ?? null );
			ES_Webhooks::send_for_form( $form, $payload );
		}

		return [ 'ok' => true, 'message' => null ];
	}

	public static function delete_by_email( string $email ): int {
		global $wpdb;
		$normalized = strtolower( trim( $email ) );
		return (int) $wpdb->delete( ES_Install::table( 'leads' ), [ 'email' => $normalized ], [ '%s' ] );
	}

	public static function find_by_email( string $email ): array {
		global $wpdb;
		$t = ES_Install::table( 'leads' );
		$normalized = strtolower( trim( $email ) );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE email = %s ORDER BY id DESC", $normalized ) );
	}

	/**
	 * Stream a CSV of leads filtered by the same shape `paginated()` uses.
	 * Accepts either a filter array (preferred) or an int form_id for
	 * backward compat with the v0.x-era export handler.
	 */
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

		// Decode every row's extra_fields once (kept alongside $rows so the second
		// pass below doesn't re-decode), and collect the union of whatever custom
		// question keys remain once the known columns (first/last/phone/tags,
		// already their own columns) are pulled out — every row gets a stable
		// column even if only some rows answered that particular question.
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

		// Consent proof travels with the row. An export is what a customer hands
		// over when someone asks what a subscriber agreed to, so leaving it only
		// in the database made the record unusable for the one job it exists
		// for. Appended last so every existing column keeps its position.
		// Blank (not "no") when the form had no consent control at all — that is
		// a different fact from someone declining, and must not read as one.
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

			// A submission can carry several separate agreements. Joined rather
			// than one column pair per consent field, which would make the header
			// shift depending on which leads the filter happened to catch. Each
			// entry names its checkbox so the two columns line up row by row.
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

	/** "site_role" / "site-role" → "Site Role", for a custom question's CSV header. */
	private static function humanize_custom_key( string $key ): string {
		$words = array_filter( preg_split( '/[_\-]+/', rtrim( $key, '[]' ) ) ?: [] );
		return $words ? implode( ' ', array_map( 'ucfirst', $words ) ) : $key;
	}

	/** A checkbox group's answer arrives as an array — join it into one readable cell. */
	private static function custom_field_display( $value ): string {
		if ( $value === null || $value === '' ) {
			return '';
		}
		return is_array( $value ) ? implode( '; ', array_map( 'strval', $value ) ) : (string) $value;
	}

	/**
	 * Build a CSV filename that reflects the active filter so the user
	 * can tell which file is which when they've exported multiple. Falls
	 * back to a plain dated filename when no filter is active.
	 */
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

	/**
	 * Defend against CSV formula injection: when Excel/Sheets opens a CSV cell that
	 * starts with =, +, -, @, tab, or CR, it interprets the cell as a formula.
	 * A submitted name like `=cmd|'/c calc.exe'!A1` would execute on open.
	 * Prefix any such cell with a single quote so it's treated as text.
	 */
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
}
