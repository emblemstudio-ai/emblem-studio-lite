<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Form_Repo {

	/** The close-button themes an asset can ask for. */
	const CLOSE_THEMES = [ 'light', 'dark', 'auto' ];

	/**
	 * An asset name as typed: one line, trimmed, at most 191 characters.
	 *
	 * It used to go through sanitize_text_field, which removes anything that
	 * looks like an HTML tag. "Offer <b>new</b>" was saved as "Offer new" with
	 * no word, while the web app kept it as typed, so one asset read two ways
	 * (item 20 of Catalina's Lite test pass). A name is plain text and every
	 * place that shows it escapes it, so the brackets are safe to keep.
	 *
	 * @param mixed $raw
	 */
	public static function clean_name( $raw ): string {
		$name = wp_check_invalid_utf8( is_scalar( $raw ) ? (string) $raw : '' );
		$name = preg_replace( '/[\r\n\t\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $name ) ?? '';
		$name = trim( preg_replace( '/ {2,}/', ' ', $name ) ?? '' );
		return mb_substr( $name, 0, 191 );
	}

	/**
	 * One close-button theme, clamped to the three that exist.
	 *
	 * 'auto' works its colour out from what is behind the button (see
	 * esAutoCloseCss in assets/frontend.js). Every spot that read this column
	 * used to allow only 'light' and 'dark', so an asset set to Auto in the SaaS
	 * came back as 'light' the moment it was mirrored here or drawn by a
	 * shortcode. TST Prep's click popups were exactly that path (SER-1403).
	 * Anything unrecognised falls back to $fallback.
	 *
	 * @param mixed $value
	 */
	public static function close_theme( $value, string $fallback = 'light' ): string {
		return is_string( $value ) && in_array( $value, self::CLOSE_THEMES, true ) ? $value : $fallback;
	}

	public static function table(): string {
		return ES_Install::table( 'forms' );
	}

	/**
	 * Predicted id the next created form will get — for the "Asset <id>" name
	 * placeholder on the new-form screen (before the row exists). Uses the table's
	 * real AUTO_INCREMENT (accurate even after deletes), falling back to max+1. The
	 * actual id is assigned on save; a blank name then becomes "Asset <real id>".
	 */
	public static function next_id(): int {
		global $wpdb;
		$t  = self::table();
		$ai = $wpdb->get_var( $wpdb->prepare(
			'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$wpdb->dbname,
			$t
		) );
		$after_max = ( (int) $wpdb->get_var( "SELECT MAX(id) FROM $t" ) ) + 1; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// MySQL 8 caches AUTO_INCREMENT in information_schema for up to a day
		// (information_schema_stats_expiry), so it can lag behind rows already
		// made (audit B5). It can only be too low, never too high, so the larger
		// of the two is the right one. The counter still matters when the last
		// rows were deleted, which MAX(id) cannot see.
		return max( (int) $ai, $after_max );
	}

	/**
	 * Every FunnelKit tag name this site's assets carry, once each (SER-1464).
	 *
	 * A tag is created in FunnelKit only on the first signup that carries it,
	 * so a tag typed on one asset exists nowhere to pick from until then. The
	 * editor offers these alongside FunnelKit's own, marked as not created yet,
	 * so the next asset gets the same spelling instead of "beta users " with a
	 * stray space. Nothing is written to FunnelKit for them.
	 *
	 * @return array<int, string>
	 */
	public static function all_funnelkit_tags(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_col( 'SELECT funnelkit_tags FROM ' . self::table() . " WHERE funnelkit_tags IS NOT NULL AND funnelkit_tags <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = [];
		foreach ( $rows as $raw ) {
			$tags = json_decode( (string) $raw, true );
			foreach ( (array) ( is_array( $tags ) ? $tags : [] ) as $tag ) {
				$name = trim( (string) $tag );
				$key  = self::tag_key( $name );
				if ( $name !== '' && ! isset( $out[ $key ] ) ) {
					$out[ $key ] = $name;
				}
			}
		}
		sort( $out ); // Also drops the keys, which were only there to de-duplicate.
		return $out;
	}

	/** One tag under one key, so case and stray spaces don't make a second. */
	public static function tag_key( string $name ): string {
		return mb_strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ?? '' ) );
	}

	/**
	 * Fetch forms. By default, only non-trashed, non-archived rows.
	 *
	 * Filter values: 'all' | 'active' | 'inactive' | 'scheduled' | 'expired' |
	 * 'archived' | 'trash'. Archived and Trash have their own tabs, so they are
	 * excluded from the other five and no form is ever counted twice. A trashed
	 * row wins over an archived one.
	 */
	public static function all( string $filter = 'all' ): array {
		global $wpdb;
		$t = self::table();
		// Bring the stored statuses in line with the clock first, so the list a
		// person reads matches what the site is actually doing (SER-1270).
		self::reconcile_statuses();
		if ( $filter === 'trash' ) {
			return $wpdb->get_results( "SELECT * FROM $t WHERE trashed_at IS NOT NULL ORDER BY trashed_at DESC" );
		}
		if ( $filter === 'archived' ) {
			return $wpdb->get_results( "SELECT * FROM $t WHERE trashed_at IS NULL AND archived_at IS NOT NULL ORDER BY archived_at DESC" );
		}
		$where  = 'trashed_at IS NULL AND archived_at IS NULL';
		$params = [];
		if ( in_array( $filter, [ 'active', 'inactive', 'scheduled', 'expired' ], true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $filter;
		}
		// Newest first: the highest display_order sits at the top, with id
		// as a stable tie-breaker (both DESC) so rows with identical orders
		// keep a stable shape across renders. New forms get MAX+1 on create,
		// so they appear at the top; drag-and-drop overrides the order.
		$sql = "SELECT * FROM $t WHERE $where ORDER BY display_order DESC, id DESC";
		return $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
			: $wpdb->get_results( $sql );
	}

	/**
	 * Counts per view-tab. Used by the Forms list header to display
	 * "All (n) | Active (n) | ..." style filters.
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_filter(): array {
		global $wpdb;
		$t = self::table();
		self::reconcile_statuses();
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL THEN 1 ELSE 0 END)                              AS all_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'active'    THEN 1 ELSE 0 END)     AS active_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'inactive'  THEN 1 ELSE 0 END)     AS inactive_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'scheduled' THEN 1 ELSE 0 END)     AS scheduled_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'expired'   THEN 1 ELSE 0 END)     AS expired_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NOT NULL THEN 1 ELSE 0 END)                         AS archived_count,
				SUM(CASE WHEN trashed_at IS NOT NULL THEN 1 ELSE 0 END)                                                 AS trash_count
			FROM $t"
		);
		return [
			'all'       => (int) ( $row->all_count       ?? 0 ),
			'active'    => (int) ( $row->active_count    ?? 0 ),
			'inactive'  => (int) ( $row->inactive_count  ?? 0 ),
			'scheduled' => (int) ( $row->scheduled_count ?? 0 ),
			'expired'   => (int) ( $row->expired_count   ?? 0 ),
			'archived'  => (int) ( $row->archived_count  ?? 0 ),
			'trash'     => (int) ( $row->trash_count     ?? 0 ),
		];
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
		return $row ?: null;
	}

	/**
	 * Targeted update of just the display_rules column for one form.
	 *
	 * save() rewrites the WHOLE row from a $data array — wrong tool when
	 * a caller only wants to nudge targeting and doesn't hold the rest
	 * of the form's fields. This touches display_rules (and updated_at)
	 * only. Used by the per-post exclusion metabox, which edits a form's
	 * hide list from a post's edit screen.
	 */
	public static function update_display_rules( int $form_id, array $rules ): void {
		if ( $form_id <= 0 ) {
			return;
		}
		global $wpdb;
		$sanitized = ES_Display_Rules::sanitize_all_shells( $rules );
		$wpdb->update(
			self::table(),
			[
				'display_rules' => wp_json_encode( $sanitized ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $form_id ]
		);
		self::flush_caches();
	}

	/**
	 * The redirect a visitor is sent to after signing up, or '' if it is not
	 * safe to send them there. frontend.js hands this straight to
	 * window.location, so a `javascript:` value would run on the visitor's
	 * page. Allowed: an absolute http(s) URL with a host, or a path on this
	 * site starting with ONE slash. "//x" and "/\x" are rejected because a
	 * browser reads both as another site. Control characters are rejected
	 * because a browser silently drops tabs and newlines, which can turn
	 * "/<tab>/x" into "//x" after we have checked it.
	 */
	public static function safe_redirect_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' || preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return '';
		}
		// A backslash anywhere makes browsers and PHP disagree on the host.
		if ( strpos( $url, '\\' ) !== false ) {
			return '';
		}
		if ( $url[0] === '/' ) {
			return ( strlen( $url ) > 1 && $url[1] === '/' ) ? '' : $url;
		}
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || empty( $parts['host'] ) ) {
			return '';
		}
		return esc_url_raw( $url, [ 'http', 'https' ] );
	}

	public static function save( array $data, ?int $id = null ): int {
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql' );

		$lists = isset( $data['funnelkit_lists'] ) && is_array( $data['funnelkit_lists'] )
			? array_values( array_filter( array_map( 'intval', $data['funnelkit_lists'] ) ) )
			: [];
		$tags  = isset( $data['funnelkit_tags'] ) && is_array( $data['funnelkit_tags'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $data['funnelkit_tags'] ) ) ) )
			: [];

		// Mapping: associative array { fk_key => form_field_name }. fk_key uses
		// `col:<column>` for built-in contact columns and `slug:<slug>` for
		// custom-field slugs.
		$mapping = [];
		if ( isset( $data['field_mapping'] ) && is_array( $data['field_mapping'] ) ) {
			foreach ( $data['field_mapping'] as $fk_key => $form_field ) {
				$fk_key     = sanitize_text_field( (string) $fk_key );
				$form_field = sanitize_text_field( (string) $form_field );
				if ( $fk_key !== '' && $form_field !== '' ) {
					$mapping[ $fk_key ] = $form_field;
				}
			}
		}

		// Shell types the form is enabled for: array of valid type slugs.
		// Empty array → form exists but renders nowhere.
		$valid_types = self::valid_types();
		$types       = isset( $data['form_types'] ) && is_array( $data['form_types'] )
			? array_values( array_intersect( array_map( 'sanitize_text_field', $data['form_types'] ), $valid_types ) )
			: [ 'inline' ];
		// Auto-injected shells (popup-auto / ribbon / slide-in) are account-gated
		// (Free and up). The Lite edition strips them on save so they can never be
		// stored or rendered, whatever the POST contained.
		if ( ! ES_Caps::has( 'auto_shells' ) ) {
			$types = array_values( array_diff( $types, [ 'popup-auto', 'ribbon', 'slide-in' ] ) );
			if ( empty( $types ) ) {
				$types = [ 'inline' ];
			}
		}
		// Ribbon and slide-in are exclusive with all other shells.
		$types = self::enforce_shell_exclusivity( $types );

		// Coalesce once: in_array() must see a defined value AND the
		// ternary's true-branch must not re-read a possibly-absent key
		// (that re-read raised an "Undefined array key" warning).
		// The SWITCH, not the state. A caller sends 'active' or 'inactive' (or
		// `enabled` directly); where that leaves the form once the dates are
		// applied is worked out below, by resolve_status(). Nobody sets
		// 'scheduled' or 'expired' by hand — a caller sending one of those is
		// describing a state, and means the switch is on (SER-1270).
		$enabled = array_key_exists( 'enabled', $data )
			? (bool) $data['enabled']
			: in_array( ( $data['status'] ?? 'inactive' ), [ 'active', 'scheduled', 'expired' ], true );
		$success_mode = $data['success_mode'] ?? 'inline_state';
		// Two modes. The plain message is retired (SER-1419): it replaced the card
		// with an unstyled <div>, so a signup ended as black text in the corner of
		// the page. An asset still carrying it lands on the thank-you state.
		$success_mode = in_array( $success_mode, [ 'inline_state', 'redirect' ], true ) ? $success_mode : 'inline_state';

		$row = [
			// name / success copy / redirect are cleaned HERE, not only in the
			// admin handler, because the SaaS connector and the JSON import call
			// save() straight with values they did not author.
			'name'                 => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'                 => $data['slug'] ?? '',
			'html'                 => ES_HTML_Sanitizer::sanitize( (string) ( $data['html'] ?? '' ) ),
			'css'                  => ES_HTML_Sanitizer::sanitize_css( (string) ( $data['css'] ?? '' ) ),
			'enabled'              => $enabled ? 1 : 0,
			// Placeholder — replaced below with the resolved state, once the
			// schedule fields on $row are known.
			'status'               => $enabled ? 'active' : 'inactive',
			'funnelkit_list_id'    => $lists ? $lists[0] : null,
			'funnelkit_lists'      => $lists ? wp_json_encode( $lists ) : null,
			'funnelkit_tags'       => $tags  ? wp_json_encode( $tags )  : null,
			'field_mapping'        => $mapping ? wp_json_encode( $mapping ) : null,
			'form_types'           => wp_json_encode( $types ),
			'success_mode'         => $success_mode,
			'success_message'      => isset( $data['success_message'] ) ? wp_kses_post( (string) $data['success_message'] ) : null,
			'success_redirect_url' => isset( $data['success_redirect_url'] ) ? self::safe_redirect_url( (string) $data['success_redirect_url'] ) : null,
			'updated_at'           => $now,
		];

		// Auto-trigger config is opt-in: only touch the column when the caller
		// passed it. This way the saved config isn't wiped by a save that
		// doesn't include the trigger form fields (e.g. an HTML-only edit).
		// Stored as a per-shell map; sanitize_all_shell_trigger_configs handles
		// both legacy-flat and per-shell input transparently.
		if ( isset( $data['auto_trigger_config'] ) && is_array( $data['auto_trigger_config'] ) ) {
			$row['auto_trigger_config'] = wp_json_encode( self::sanitize_all_shell_trigger_configs( $data['auto_trigger_config'] ) );
		}

		// Display rules: same opt-in pattern, same per-shell shape. The Display
		// Rules sanitizer handles both legacy-flat and per-shell input shapes.
		if ( isset( $data['display_rules'] ) && is_array( $data['display_rules'] ) ) {
			$row['display_rules'] = wp_json_encode( ES_Display_Rules::sanitize_all_shells( $data['display_rules'] ) );
		}

		// Shadow DOM toggle: opt-in like the others. Caller passes a bool/int.
		if ( array_key_exists( 'use_shadow_dom', $data ) ) {
			$row['use_shadow_dom'] = ! empty( $data['use_shadow_dom'] ) ? 1 : 0;
		}

		// Design System opt-out (default ON): inherit the shared per-install CSS.
		if ( array_key_exists( 'use_design_system', $data ) ) {
			$row['use_design_system'] = ! empty( $data['use_design_system'] ) ? 1 : 0;
		}

		// Popup max-width in px. Applies to click + auto popup and (future)
		// slide-in. Clamped to a sane range; bad / missing input → default 720.
		if ( array_key_exists( 'popup_max_width', $data ) ) {
			$row['popup_max_width'] = max( 100, min( 2000, (int) ( $data['popup_max_width'] ?: 720 ) ) );
		}

		// Popup fullscreen toggle. When on, modal goes edge-to-edge.
		if ( array_key_exists( 'popup_fullscreen', $data ) ) {
			$row['popup_fullscreen'] = ! empty( $data['popup_fullscreen'] ) ? 1 : 0;
		}

		// Close-button theme: 'light' | 'dark' | 'auto'. Whitelist-clamped.
		if ( array_key_exists( 'popup_close_theme', $data ) ) {
			$row['popup_close_theme'] = self::close_theme( $data['popup_close_theme'], 'dark' );
		}

		// Per-form click tracking. Default ON for new forms; opt-out per form
		// in the Stats tab. When OFF, frontend.js skips the click listener
		// entirely so no es_click beacons fire.
		if ( array_key_exists( 'track_clicks', $data ) ) {
			$row['track_clicks'] = ! empty( $data['track_clicks'] ) ? 1 : 0;
		}

		// Purchases after a click (SER-1348). OFF by default, and off for every
		// asset that existed before the feature: a shop's takings are not a thing
		// to start measuring on somebody's behalf without them asking.
		if ( array_key_exists( 'track_purchases', $data ) ) {
			$row['track_purchases'] = ! empty( $data['track_purchases'] ) ? 1 : 0;
		}

		// FunnelKit destination toggle — when OFF, ES_Ajax::submit skips
		// the FK push for this form entirely.
		if ( array_key_exists( 'funnelkit_enabled', $data ) ) {
			$row['funnelkit_enabled'] = ! empty( $data['funnelkit_enabled'] ) ? 1 : 0;
		}

		// How FunnelKit adds a NEW contact from this asset: 'subscribed' (the
		// default) or 'unsubscribed'. Existing contacts keep their status
		// either way; ES_FunnelKit_Upsert owns that rule.
		if ( array_key_exists( 'funnelkit_contact_status', $data ) ) {
			$row['funnelkit_contact_status'] = ES_FunnelKit_Upsert::contact_status( $data['funnelkit_contact_status'] );
		}

		// Webhooks — array of { url, secret, headers, active } configs.
		// Sanitized + empty-row-stripped through ES_Webhooks::sanitize_array.
		if ( array_key_exists( 'webhooks', $data ) && is_array( $data['webhooks'] ) && class_exists( 'ES_Webhooks' ) ) {
			$clean = ES_Webhooks::sanitize_array( $data['webhooks'] );
			$row['webhooks'] = $clean ? wp_json_encode( $clean ) : null;
		}

		// Connections — "already subscribed" suppression config. Stored only
		// when it actually does something (suppresses, is mutual, or replaces);
		// otherwise NULL so the column stays a clean signal.
		if ( array_key_exists( 'connections', $data ) && class_exists( 'ES_Connections' ) ) {
			$clean = ES_Connections::sanitize( is_array( $data['connections'] ) ? $data['connections'] : [] );
			$row['connections'] = ES_Connections::is_active( $clean ) ? wp_json_encode( $clean ) : null;
			ES_Connections::flush();
		}

		// Schedule window — DATETIME strings or NULL. Empty / invalid → NULL.
		// Date-only "active_from" defaults to start-of-day; "active_until" to end-of-day.
		if ( array_key_exists( 'active_from', $data ) ) {
			$row['active_from'] = self::normalize_datetime( $data['active_from'], false );
		}
		if ( array_key_exists( 'active_until', $data ) ) {
			$row['active_until'] = self::normalize_datetime( $data['active_until'], true );
		}

		$existing = $id ? self::find( $id ) : null;

		// An UPDATE changes only what the caller sent. The core columns above
		// are built with a default for every one of them, which is right for a
		// new form and wrong for an existing one: restoring a revision sends
		// html and css alone, and used to rename the form "Asset N", blank its
		// slug, drop its FunnelKit lists, mapping and success settings, reset it
		// to inline and switch it off. So every core column whose key the
		// caller did not send is taken back off $row, and the stored value
		// stands. Whatever IS sent still goes through the cleaning above.
		if ( $existing ) {
			$sent_by_column = [
				'name'                 => [ 'name' ],
				'slug'                 => [ 'slug' ],
				'html'                 => [ 'html' ],
				'css'                  => [ 'css' ],
				'enabled'              => [ 'enabled', 'status' ],
				'funnelkit_list_id'    => [ 'funnelkit_lists' ],
				'funnelkit_lists'      => [ 'funnelkit_lists' ],
				'funnelkit_tags'       => [ 'funnelkit_tags' ],
				'field_mapping'        => [ 'field_mapping' ],
				'form_types'           => [ 'form_types' ],
				'success_mode'         => [ 'success_mode' ],
				'success_message'      => [ 'success_message' ],
				'success_redirect_url' => [ 'success_redirect_url' ],
			];
			foreach ( $sent_by_column as $column => $keys ) {
				$sent = false;
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $data ) ) {
						$sent = true;
						break;
					}
				}
				if ( ! $sent ) {
					unset( $row[ $column ] );
				}
			}
		}

		// Derive the state from the switch and the dates, now that both are on
		// $row. A partial save that sends no schedule fields, or no switch,
		// keeps whatever the row already had, so those are read back off the
		// existing form rather than assumed absent (SER-1270). With nothing
		// sent at all, this is a plain recompute of the stored state.
		$resolved = (object) [
			'trashed_at'   => $existing->trashed_at  ?? null,
			'archived_at'  => $existing->archived_at ?? null,
			'active_from'  => array_key_exists( 'active_from', $row )  ? $row['active_from']  : ( $existing->active_from  ?? null ),
			'active_until' => array_key_exists( 'active_until', $row ) ? $row['active_until'] : ( $existing->active_until ?? null ),
		];
		if ( array_key_exists( 'enabled', $row ) ) {
			$resolved->enabled = $row['enabled'];
		} elseif ( $existing && isset( $existing->enabled ) ) {
			$resolved->enabled = $existing->enabled;
		} else {
			$resolved->status = $existing->status ?? 'inactive';
		}
		$row['status'] = self::resolve_status( $resolved );

		// A blank name defaults to "Asset <id>" (matches the field's placeholder).
		// The id is known for an update; for a new form it's assigned after insert.
		$name_blank = array_key_exists( 'name', $row ) && trim( (string) $row['name'] ) === '';
		if ( $name_blank && $id ) {
			$row['name'] = 'Asset ' . $id;
		}

		if ( $id ) {
			// Capture the PRE-save HTML/CSS as a revision before overwriting.
			// Each revision row represents "what the form looked like before
			// this point" — the top row of the revisions list is the most
			// recent thing the user overwrote, not the current state.
			self::record_pre_save_revision( $id, $row, $data );
			$wpdb->update( $t, $row, [ 'id' => $id ] );
			self::flush_caches();
			// The form may have just gone off or come back on. A test attached
			// to it follows (SER-1271).
			if ( class_exists( 'ES_AB' ) ) {
				$after = self::find( $id );
				if ( $after ) {
					ES_AB::sync_pause_with_form( $after );
				}
			}
			return $id;
		}

		// New form — no prior state, so no revision yet. The first revision
		// row appears the second time the form is saved (if html/css change).
		$row['created_at']    = $now;
		// Append new forms at the end of the manual order. Existing
		// drag-rearranged rows are above; this sits below them.
		$max_order            = (int) $wpdb->get_var( "SELECT MAX(display_order) FROM $t" );
		$row['display_order'] = $max_order + 1;
		$wpdb->insert( $t, $row );
		$new_id = (int) $wpdb->insert_id;
		// New form with a blank name → "Asset <id>" now that the id exists.
		if ( $name_blank && $new_id ) {
			$wpdb->update( $t, [ 'name' => 'Asset ' . $new_id ], [ 'id' => $new_id ] );
		}
		self::flush_caches();
		return $new_id;
	}

	/**
	 * Snapshot the form's current HTML/CSS as a revision before the
	 * incoming save overwrites it. Skipped when the save isn't actually
	 * changing html/css (settings-only save = nothing to remember).
	 * An optional revision_note in $data attaches to the new row (used by
	 * the restore flow to label "Saved before restoring from May 14").
	 */
	private static function record_pre_save_revision( int $form_id, array $row, array $data ): void {
		if ( ! array_key_exists( 'html', $data ) && ! array_key_exists( 'css', $data ) ) {
			return;
		}
		$previous = self::find( $form_id );
		if ( ! $previous ) {
			return;
		}
		$old_html = (string) ( $previous->html ?? '' );
		$old_css  = (string) ( $previous->css  ?? '' );
		$new_html = array_key_exists( 'html', $data ) ? (string) ( $row['html'] ?? '' ) : $old_html;
		$new_css  = array_key_exists( 'css',  $data ) ? (string) ( $row['css']  ?? '' ) : $old_css;
		// Save unchanged content? No revision noise.
		if ( $old_html === $new_html && $old_css === $new_css ) {
			return;
		}
		$note = isset( $data['revision_note'] ) ? (string) $data['revision_note'] : null;
		if ( class_exists( 'ES_Form_Revisions' ) ) {
			ES_Form_Revisions::insert( $form_id, $old_html, $old_css, $note );
		}
	}

	/**
	 * Apply a new display order. $ids is an array of form IDs in the
	 * desired top-to-bottom order. The list sorts display_order DESC, so
	 * the top item needs the HIGHEST value: id at index I (of N) gets
	 * display_order = N - I (top → N, bottom → 1). Returns the number of
	 * rows actually updated.
	 *
	 * Only touches the forms the caller passed; doesn't reset other
	 * rows. If the user reorders the "All" view, all current rows are
	 * in the list and the resulting order is fully manual.
	 */
	public static function reorder( array $ids ): int {
		global $wpdb;
		$t       = self::table();
		$updated = 0;
		$ids     = array_values( $ids );
		$n       = count( $ids );
		foreach ( $ids as $i => $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) continue;
			$ok = $wpdb->update(
				$t,
				[ 'display_order' => $n - $i ],
				[ 'id' => $id ]
			);
			if ( $ok ) {
				$updated++;
			}
		}
		return $updated;
	}

	/**
	 * Soft-delete: send a form to the trash. The form is hidden from the
	 * regular list and its shortcodes stop rendering on the frontend, but
	 * its row and stats are kept until permanently deleted.
	 *
	 * CHANGED 2026-08-24: trashing SWITCHES THE FORM OFF as well, the same way
	 * archive() has since 2026-08-17. A form is in ONE state at a time, and
	 * Trash is a state a form is IN, not a label sitting on top of Active.
	 */
	public static function trash( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'trashed_at' => current_time( 'mysql' ),
				'enabled'    => 0,
				'status'     => 'inactive',
			],
			[ 'id' => $id ]
		);
		// A queued content change on a form nobody can see is a surprise waiting
		// for whoever restores it (SER-1287). Revisions deliberately survive a
		// trash-and-restore round trip; an INTENTION should not.
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::cancel_for_form( $id, __( 'The form was moved to the trash.', 'emblem-studio' ) );
		}
		self::flush_caches();
	}

	/**
	 * Restore a trashed form (NULLs trashed_at). Lands INACTIVE, for the same
	 * reason restore_from_archive() does: coming out of the trash is a
	 * deliberate act, and the form going live the same second is not what
	 * anyone means by it. Before this, a form trashed while live came back live
	 * on restore, with nobody asking for it and nothing on screen saying so.
	 */
	public static function restore( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'trashed_at' => null,
				'enabled'    => 0,
				'status'     => 'inactive',
			],
			[ 'id' => $id ]
		);
		self::flush_caches();
	}

	/**
	 * Archive — a hidden bucket like Trash, but a separate flag, so an archived
	 * form keeps its own tab and its own counter and is never auto-deleted.
	 *
	 * CHANGED 2026-08-17: archiving now SWITCHES THE FORM OFF as well. It used to
	 * leave `status` untouched, so a form archived straight from Active stayed
	 * Active underneath — a state the admin screens never show, since they display
	 * one or the other.
	 *
	 * Harmless here, because is_currently_active() checks archived_at first and is
	 * the single gate every public-side path goes through. It was NOT harmless in
	 * the SaaS, where the list connected WordPress sites read trusted `status`
	 * alone: three archived assets were placed on every page of a live client site
	 * and refused on every request. Same shape of data, one client that checked it
	 * and one that did not.
	 *
	 * Matched here for parity, and because a state nobody can see is a state
	 * something will eventually trust.
	 */
	public static function archive( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'archived_at' => current_time( 'mysql' ),
				'enabled'     => 0,
				'status'      => 'inactive',
			],
			[ 'id' => $id ]
		);
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::cancel_for_form( $id, __( 'The form was archived.', 'emblem-studio' ) );
		}
		self::flush_caches();
	}

	/**
	 * Restore a form from Archive. Lands INACTIVE, never back on whatever it held
	 * before: coming out of the archive is a deliberate act, and pulling last
	 * year's sale ribbon out to copy a headline must not republish it.
	 */
	public static function restore_from_archive( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'archived_at' => null,
				'enabled'     => 0,
				'status'      => 'inactive',
			],
			[ 'id' => $id ]
		);
		self::flush_caches();
	}

	/**
	 * Flip the on/off switch for one form, and nothing else.
	 *
	 * `enabled` is what every public-side gate reads (is_currently_active()), and
	 * `status` is the stored copy of where that leaves the form once the dates
	 * and flags are applied. The two are written together here, so the list and
	 * the page can never disagree. The bulk Set Active / Set Inactive used to
	 * write `status` alone: the list said Active while the shortcode rendered
	 * nothing, and Inactive left the form live on the page.
	 *
	 * Switching ON is refused for an archived or trashed form. Those are states
	 * the form is IN, and leaving them is unarchive() / restore(), not this.
	 *
	 * @return bool true when the form was changed.
	 */
	public static function set_enabled( int $id, bool $on ): bool {
		$form = self::find( $id );
		if ( ! $form ) {
			return false;
		}
		if ( $on && ( ! empty( $form->archived_at ) || ! empty( $form->trashed_at ) ) ) {
			return false;
		}

		$form->enabled = $on ? 1 : 0;
		$status        = self::resolve_status( $form );

		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'enabled'    => $on ? 1 : 0,
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
		self::flush_caches();

		// A test follows its form, the same as after save() (SER-1271).
		if ( class_exists( 'ES_AB' ) ) {
			$form->status = $status;
			ES_AB::sync_pause_with_form( $form );
		}
		return true;
	}

	/**
	 * Set or clear archived_at ALONE, leaving status exactly as it is.
	 *
	 * This is the copy operation, not the user action. archive() and
	 * restore_from_archive() above are what a person clicks in wp-admin, and
	 * switching the form off is the right thing to do there. A mirror is
	 * different: the SaaS has just sent both the archive flag AND the status it
	 * wants, so applying the flag must not overwrite the answer that came with
	 * it.
	 *
	 * Calling archive()/restore_from_archive() from the connector's pull forced
	 * every mirrored asset to inactive, including every asset that was not
	 * archived at all, because the SaaS sends archived_at as null rather than
	 * omitting it.
	 *
	 * @param int         $id   The form's local id.
	 * @param string|null $when MySQL datetime to archive at, or null to unarchive.
	 */
	public static function set_archived_at( int $id, ?string $when ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[ 'archived_at' => $when ],
			[ 'id' => $id ]
		);
		self::flush_caches();
	}

	/**
	 * Build a portable JSON-shaped array representing one form's
	 * definition. Stats, leads, revisions, timestamps, and the row id
	 * are intentionally excluded — only the fields that DEFINE the
	 * form's design + behavior travel. JSON arrays/objects stored as
	 * strings in the DB get decoded so the export is clean to read.
	 *
	 * display_order travels too. It is not design, but it IS the setup:
	 * without it the receiver has to re-derive the order from the arrival
	 * sequence, and since exports are emitted newest-first while ids ascend
	 * oldest-first, that re-derivation lands on exactly the reverse of the
	 * list it was copied from. The value is relative — a receiver rebases it
	 * onto its own list rather than trusting the number.
	 */
	public static function to_export_array( object $form ): array {
		$decode = function ( $raw ) {
			if ( is_string( $raw ) && $raw !== '' ) {
				$d = json_decode( $raw, true );
				return is_array( $d ) ? $d : null;
			}
			return null;
		};
		return [
			'name'                 => (string) ( $form->name ?? '' ),
			'slug'                 => (string) ( $form->slug ?? '' ),
			'html'                 => (string) ( $form->html ?? '' ),
			'css'                  => (string) ( $form->css ?? '' ),
			'status'               => (string) ( $form->status ?? 'inactive' ),
			'archived_at'          => ! empty( $form->archived_at ) ? (string) $form->archived_at : null,
			'display_order'        => (int) ( $form->display_order ?? 0 ),
			'success_mode'         => (string) ( $form->success_mode ?? 'inline_state' ),
			'success_message'      => (string) ( $form->success_message ?? '' ),
			'success_redirect_url' => (string) ( $form->success_redirect_url ?? '' ),
			'funnelkit_lists'      => $decode( $form->funnelkit_lists ?? null )  ?? [],
			'funnelkit_tags'       => $decode( $form->funnelkit_tags  ?? null )  ?? [],
			'field_mapping'        => $decode( $form->field_mapping   ?? null )  ?? new stdClass(),
			'form_types'           => $decode( $form->form_types      ?? null )  ?? [ 'inline' ],
			'auto_trigger_config'  => $decode( $form->auto_trigger_config ?? null ) ?? new stdClass(),
			'display_rules'        => $decode( $form->display_rules   ?? null )  ?? new stdClass(),
			'use_shadow_dom'       => ! empty( $form->use_shadow_dom ) ? 1 : 0,
			'use_design_system'    => ! empty( $form->use_design_system ) ? 1 : 0,
			'popup_max_width'      => (int) ( $form->popup_max_width ?? 720 ),
			'popup_fullscreen'     => ! empty( $form->popup_fullscreen ) ? 1 : 0,
			'popup_close_theme'    => self::close_theme( $form->popup_close_theme ?? 'light' ),
			'track_clicks'         => ! empty( $form->track_clicks ) ? 1 : 0,
			'track_purchases'      => ! empty( $form->track_purchases ) ? 1 : 0,
			'funnelkit_enabled'    => ! empty( $form->funnelkit_enabled ) ? 1 : 0,
			'funnelkit_contact_status' => ES_FunnelKit_Upsert::contact_status( $form->funnelkit_contact_status ?? null ),
			'webhooks'             => class_exists( 'ES_Webhooks' ) ? ES_Webhooks::for_form( $form ) : [],
			// Connections reference form IDs, so they round-trip on the same
			// site (duplicate / re-import); cross-site the IDs won't map and the
			// reviewer re-picks on the Connections tab.
			'connections'          => class_exists( 'ES_Connections' ) ? ES_Connections::for_form( $form ) : null,
			'active_from'          => $form->active_from  ? (string) $form->active_from  : null,
			'active_until'         => $form->active_until ? (string) $form->active_until : null,
		];
	}

	/**
	 * Create a form from an export-array payload. Always inserts a new
	 * row (status forced to 'inactive' so the import can be reviewed
	 * before going live). Slug gets a numeric suffix on collision via
	 * unique_slug. Returns the new id, or null on failure.
	 */
	public static function from_import_array( array $data ): ?int {
		// Decode nested objects/arrays back to arrays for the save path.
		$decode_array = function ( $val ) {
			if ( is_array( $val ) ) return $val;
			if ( is_object( $val ) ) return (array) $val;
			if ( is_string( $val ) && $val !== '' ) {
				$d = json_decode( $val, true );
				return is_array( $d ) ? $d : [];
			}
			return [];
		};

		$name = isset( $data['name'] ) ? self::clean_name( $data['name'] ) : '';
		if ( $name === '' ) {
			$name = __( 'Imported form', 'emblem-studio' );
		}
		$slug_seed = isset( $data['slug'] ) && $data['slug'] !== ''
			? (string) $data['slug']
			: $name;
		$slug = self::unique_slug( $slug_seed );

		// Coalesce once: in_array() must see a defined value AND the ternary's
		// true-branch must not re-read a possibly-absent key.
		// A new asset starts on Auto, as it does in the SaaS (SER-1403).
		$popup_close_theme = $data['popup_close_theme'] ?? 'auto';

		$payload = [
			'name'                 => $name,
			'slug'                 => $slug,
			'html'                 => (string) ( $data['html'] ?? '' ),
			'css'                  => (string) ( $data['css']  ?? '' ),
			// Always inactive on import — reviewer flips it live after checking.
			'status'               => 'inactive',
			'success_mode'         => in_array( $data['success_mode'] ?? 'inline_state', [ 'inline_state', 'redirect' ], true ) ? $data['success_mode'] : 'inline_state',
			'success_message'      => isset( $data['success_message'] ) ? (string) $data['success_message'] : '',
			'success_redirect_url' => isset( $data['success_redirect_url'] ) ? (string) $data['success_redirect_url'] : '',
			'funnelkit_lists'      => $decode_array( $data['funnelkit_lists'] ?? [] ),
			'funnelkit_tags'       => $decode_array( $data['funnelkit_tags']  ?? [] ),
			'field_mapping'        => $decode_array( $data['field_mapping']   ?? [] ),
			'form_types'           => $decode_array( $data['form_types']      ?? [ 'inline' ] ),
			'auto_trigger_config'  => $decode_array( $data['auto_trigger_config'] ?? [] ),
			'display_rules'        => $decode_array( $data['display_rules']   ?? [] ),
			'use_shadow_dom'       => ! empty( $data['use_shadow_dom'] ) ? 1 : 0,
			// Default ON when the imported payload predates the Design System.
			'use_design_system'    => array_key_exists( 'use_design_system', $data ) ? ( ! empty( $data['use_design_system'] ) ? 1 : 0 ) : 1,
			'popup_max_width'      => (int) ( $data['popup_max_width'] ?? 720 ),
			'popup_fullscreen'     => ! empty( $data['popup_fullscreen'] ) ? 1 : 0,
			'popup_close_theme'    => self::close_theme( $popup_close_theme, 'dark' ),
			'track_clicks'         => array_key_exists( 'track_clicks', $data ) ? ( ! empty( $data['track_clicks'] ) ? 1 : 0 ) : 1,
			'track_purchases'      => ! empty( $data['track_purchases'] ) ? 1 : 0,
			// New forms (blank + template install) default FK off — user
			// explicitly turns it on when they want this form's leads to
			// push to FunnelKit. Round-tripped imports preserve whatever
			// the source set (to_export_array always emits the field).
			'funnelkit_enabled'    => array_key_exists( 'funnelkit_enabled', $data ) ? ( ! empty( $data['funnelkit_enabled'] ) ? 1 : 0 ) : 0,
			// An export from before the option has no key: subscribed, as then.
			'funnelkit_contact_status' => ES_FunnelKit_Upsert::contact_status( $data['funnelkit_contact_status'] ?? null ),
			'webhooks'             => $decode_array( $data['webhooks'] ?? [] ),
			'connections'          => $decode_array( $data['connections'] ?? [] ),
			'active_from'          => isset( $data['active_from'] )  ? self::import_datetime( (string) $data['active_from'] )  : '',
			'active_until'         => isset( $data['active_until'] ) ? self::import_datetime( (string) $data['active_until'] ) : '',
		];

		$new_id = self::save( $payload );
		return $new_id ?: null;
	}

	/**
	 * Hard delete: the form, its revisions and its schedule.
	 *
	 * Its leads and events are KEPT on purpose (audit D1, Alex 2026-10-30):
	 * sign-ups are worth more to a site owner than the space they take, and a
	 * deleted form's stats still belong in site-wide reports. They stay
	 * reachable: the Leads list LEFT JOINs forms (and labels these "Deleted
	 * asset"), exports and erasure go by the leads table, and reports read
	 * events by form_id.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		// Cascade — revisions follow the form to the grave. Trash doesn't
		// cascade so the history survives "Move to Trash → Restore".
		if ( class_exists( 'ES_Form_Revisions' ) ) {
			ES_Form_Revisions::delete_for_form( $id );
		}
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::delete_for_form( $id );
		}
		if ( class_exists( 'ES_Form_Schedule' ) ) {
			ES_Form_Schedule::delete_for_form( $id );
		}
		self::flush_caches();
	}

	/**
	 * Duplicate a form. Returns the new form's ID, or null on failure.
	 * Copies HTML/CSS/integrations/mapping/success behaviour. Status resets
	 * to "inactive" so the duplicate isn't accidentally live. Stats and leads
	 * are not copied (they belong to the original form by ID).
	 */
	public static function duplicate( int $id ): ?int {
		$source = self::find( $id );
		if ( ! $source ) {
			return null;
		}

		$lists         = json_decode( (string) ( $source->funnelkit_lists     ?? '' ), true );
		$tags          = json_decode( (string) ( $source->funnelkit_tags      ?? '' ), true );
		$mapping       = json_decode( (string) ( $source->field_mapping       ?? '' ), true );
		$auto_config   = json_decode( (string) ( $source->auto_trigger_config ?? '' ), true );
		$display_rules = json_decode( (string) ( $source->display_rules       ?? '' ), true );

		$data = [
			'name'                 => $source->name . ' (copy)',
			'slug'                 => self::unique_slug( $source->slug . '-copy' ),
			'html'                 => (string) $source->html,
			'css'                  => (string) $source->css,
			'status'               => 'inactive',
			'success_mode'         => (string) $source->success_mode,
			'success_message'      => $source->success_message,
			'success_redirect_url' => $source->success_redirect_url,
			'funnelkit_lists'      => is_array( $lists )   ? $lists   : [],
			'funnelkit_tags'       => is_array( $tags )    ? $tags    : [],
			'field_mapping'        => is_array( $mapping ) ? $mapping : [],
			// The shells travel too. Left out, save() gave every copy its
			// new-form default of inline, so a duplicated ribbon or popup came
			// out as an inline form.
			'form_types'           => self::types_for( $source ),
		];
		if ( is_array( $auto_config ) ) {
			$data['auto_trigger_config'] = $auto_config;
		}
		if ( is_array( $display_rules ) ) {
			$data['display_rules'] = $display_rules;
		}
		$data['use_shadow_dom']   = ! empty( $source->use_shadow_dom ) ? 1 : 0;
		$data['use_design_system'] = ! empty( $source->use_design_system ) ? 1 : 0;
		$data['popup_max_width']  = (int) ( $source->popup_max_width ?? 720 );
		$data['popup_fullscreen']   = ! empty( $source->popup_fullscreen ) ? 1 : 0;
		$data['popup_close_theme']  = self::close_theme( $source->popup_close_theme ?? 'light' );
		$data['track_clicks']     = ! empty( $source->track_clicks ) ? 1 : 0;
		$data['track_purchases']  = ! empty( $source->track_purchases ) ? 1 : 0;
		$data['funnelkit_enabled'] = ! empty( $source->funnelkit_enabled ) ? 1 : 0;
		$data['funnelkit_contact_status'] = ES_FunnelKit_Upsert::contact_status( $source->funnelkit_contact_status ?? null );
		$data['webhooks']         = class_exists( 'ES_Webhooks' ) ? ES_Webhooks::for_form( $source ) : [];
		$data['connections']      = class_exists( 'ES_Connections' ) ? ES_Connections::for_form( $source ) : null;

		$new_id = self::save( $data );
		return $new_id ?: null;
	}

	/**
	 * The shell types a form can be enabled for. Order matters — used for
	 * display order in the admin UI.
	 */
	public static function valid_types(): array {
		return [ 'inline', 'popup-click', 'popup-auto', 'ribbon', 'slide-in' ];
	}

	/**
	 * Auto-injected shells. Each shell will get its own slice of the per-shell
	 * trigger-config and display-rules JSON columns. Centralising the list here
	 * means sanitizers + accessors can iterate it without hard-coding names.
	 */
	public static function auto_shells(): array {
		return [ 'popup-auto', 'ribbon', 'slide-in' ];
	}

	/**
	 * Ribbon (and slide-in when it lands) are FULLY exclusive: a form using
	 * either of those shells can't also be embedded inline, opened as a click
	 * popup, or auto-popped. They each take a fixed screen slot and combining
	 * them with the other shells gets noisy fast.
	 *
	 * Priority order if multiple exclusive shells arrive at once: first one
	 * wins, others are dropped. The client-side exclusivity JS normally
	 * prevents that combo from ever reaching the server.
	 */
	private static function enforce_shell_exclusivity( array $types ): array {
		foreach ( [ 'ribbon', 'slide-in' ] as $exclusive ) {
			if ( in_array( $exclusive, $types, true ) ) {
				return [ $exclusive ];
			}
		}
		return $types;
	}

	/**
	 * Normalise + sanitize the popup-auto trigger config.
	 *
	 * Shape (single trigger per form — pick one):
	 *   trigger             = 'delay' | 'scroll' | 'inactivity' | 'exit' | ''
	 *   delay_seconds       = int >= 0
	 *   scroll_percent      = int 1..100
	 *   inactivity_seconds  = int >= 5
	 *   exit_include_hidden = bool
	 *   exit_buffer_seconds = int >= 0 (exit: ignore exit-intent for N s after load)
	 *   exit_window_seconds = int >= 0 (exit: stop watching N s after load; 0 = no limit)
	 *   exit_window_autofire = bool (exit: show the shell when that window runs out)
	 *   order_wait_minutes  = int 0..1440 (order: wait after the first order; 0 = at once)
	 *   order_min_count     = int 1..1000 (order: show only when at least this many)
	 *   order_check_minutes = int 1..60   (order: how often the page asks for orders)
	 *   order_close_seconds = int 0..600  (order: close by itself after N s; 0 = stays)
	 *   order_backlog_minutes = int 0..1440 (order: a new visitor sees this far back; 0 = none)
	 *   order_landing_seconds = int 0..600 (order: never in the first N s after landing)
	 *
	 * The order trigger is armed by the web app's embed.js only. A standalone plugin
	 * keeps the settings so a round trip through WordPress never wipes them (SER-1689).
	 *
	 * All four value fields are kept regardless of which trigger is selected, so
	 * the user doesn't lose what they typed when they switch the radio.
	 *
	 * Backwards-compat: any saved config in the old multi-trigger shape
	 * (`triggers.delay.enabled`, ...) is migrated on read by picking the first
	 * enabled trigger as `trigger`.
	 */
	public static function sanitize_auto_trigger_config( array $raw ): array {
		// Old shape → new shape migration.
		if ( isset( $raw['triggers'] ) && is_array( $raw['triggers'] ) && ! isset( $raw['trigger'] ) ) {
			$old   = $raw['triggers'];
			$first = '';
			foreach ( [ 'delay', 'scroll', 'inactivity', 'exit' ] as $name ) {
				if ( ! empty( $old[ $name ]['enabled'] ) ) {
					$first = $name;
					break;
				}
			}
			$raw = [
				'trigger'             => $first,
				'delay_seconds'       => $old['delay']['seconds']        ?? 5,
				'scroll_percent'      => $old['scroll']['percent']       ?? 50,
				'inactivity_seconds'  => $old['inactivity']['seconds']   ?? 30,
				'exit_include_hidden' => ! empty( $old['exit']['include_hidden'] ),
			];
		}

		$valid_triggers = [ 'delay', 'scroll', 'inactivity', 'exit', 'order' ];
		$trigger        = isset( $raw['trigger'] ) ? sanitize_key( (string) $raw['trigger'] ) : '';
		if ( ! in_array( $trigger, $valid_triggers, true ) ) {
			$trigger = '';
		}

		// Position is meaningful for ribbon only ('top' | 'bottom'). Other shells
		// ignore it. Kept in the same flat shape so the per-shell map serialises
		// uniformly.
		$position       = isset( $raw['position'] ) ? sanitize_key( (string) $raw['position'] ) : 'top';
		$valid_positions = [ 'top', 'bottom' ];
		if ( ! in_array( $position, $valid_positions, true ) ) {
			$position = 'top';
		}

		// Corner is meaningful for slide-in only ('bottom-right' | 'bottom-left').
		$corner        = isset( $raw['corner'] ) ? sanitize_key( (string) $raw['corner'] ) : 'bottom-right';
		$valid_corners = [ 'bottom-right', 'bottom-left' ];
		if ( ! in_array( $corner, $valid_corners, true ) ) {
			$corner = 'bottom-right';
		}

		// Close-button visibility + after-close dismissal behavior. Applies to
		// every auto-injected shell uniformly. Defaults: show close, re-show
		// the shell on every page load (no persistent suppression).
		$close_button     = array_key_exists( 'close_button', $raw ) ? ! empty( $raw['close_button'] ) : true;
		$valid_dismiss    = [ 'show_again', 'hide_hours', 'never' ];
		$dismiss_behavior = isset( $raw['dismiss_behavior'] ) ? sanitize_key( (string) $raw['dismiss_behavior'] ) : 'show_again';
		if ( ! in_array( $dismiss_behavior, $valid_dismiss, true ) ) {
			$dismiss_behavior = 'show_again';
		}
		$dismiss_hide_hours = max( 1, (int) ( $raw['dismiss_hide_hours'] ?? 24 ) );

		// Sticky is meaningful for ribbon only — true (default) = fixed at top/
		// bottom of viewport, scrolling page slides under it. False = inserted
		// in document flow at the top, scrolls away with the page.
		$sticky = array_key_exists( 'sticky', $raw ) ? ! empty( $raw['sticky'] ) : true;

		return [
			'trigger'             => $trigger,
			'delay_seconds'       => max( 0, (int) ( $raw['delay_seconds'] ?? 5 ) ),
			'scroll_percent'      => max( 1, min( 100, (int) ( $raw['scroll_percent'] ?? 50 ) ) ),
			'inactivity_seconds'  => max( 5, (int) ( $raw['inactivity_seconds'] ?? 30 ) ),
			'exit_include_hidden' => ! empty( $raw['exit_include_hidden'] ),
			'exit_buffer_seconds' => max( 0, (int) ( $raw['exit_buffer_seconds'] ?? 0 ) ),
			'exit_window_seconds' => max( 0, (int) ( $raw['exit_window_seconds'] ?? 0 ) ),
			'exit_window_autofire' => ! empty( $raw['exit_window_autofire'] ),
			'order_wait_minutes'  => max( 0, min( 1440, (int) ( $raw['order_wait_minutes'] ?? 5 ) ) ),
			'order_min_count'     => max( 1, min( 1000, (int) ( $raw['order_min_count'] ?? 1 ) ) ),
			'order_check_minutes' => max( 1, min( 60, (int) ( $raw['order_check_minutes'] ?? 1 ) ) ),
			'order_close_seconds' => max( 0, min( 600, (int) ( $raw['order_close_seconds'] ?? 5 ) ) ),
			'order_backlog_minutes' => max( 0, min( 1440, (int) ( $raw['order_backlog_minutes'] ?? 60 ) ) ),
			'order_landing_seconds' => max( 0, min( 600, (int) ( $raw['order_landing_seconds'] ?? 5 ) ) ),
			'position'            => $position,
			'corner'              => $corner,
			'sticky'              => $sticky,
			'close_button'        => $close_button,
			'dismiss_behavior'    => $dismiss_behavior,
			'dismiss_hide_hours'  => $dismiss_hide_hours,
		];
	}

	/**
	 * @deprecated since v0.17.0 — kept as a thin compat shim. Returns the
	 * popup-auto slice of the per-shell trigger-config map as a flat array,
	 * matching the pre-v0.17 interface. New code should use
	 * get_shell_trigger_config() or get_all_shell_trigger_configs() directly.
	 */
	public static function get_auto_trigger_config( object $form ): array {
		return self::get_shell_trigger_config( $form, 'popup-auto' );
	}

	/**
	 * Read ONE shell's trigger config (sanitized flat array — same shape the
	 * single-shell sanitizer accepts and emits).
	 */
	public static function get_shell_trigger_config( object $form, string $shell ): array {
		$all = self::get_all_shell_trigger_configs( $form );
		return $all[ $shell ] ?? self::sanitize_auto_trigger_config( [] );
	}

	/**
	 * Read the full per-shell trigger-config map for a form (always normalised
	 * to the per-shell shape: { "popup-auto": {...flat...}, ... }).
	 */
	public static function get_all_shell_trigger_configs( object $form ): array {
		$raw     = $form->auto_trigger_config ?? '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return self::sanitize_all_shell_trigger_configs( is_array( $decoded ) ? $decoded : [] );
	}

	/**
	 * Sanitize the per-shell trigger-config map.
	 *
	 * Accepts both shapes for backwards-compat:
	 *   - Legacy flat:   { trigger: "delay", delay_seconds: 5, ... }       (pre v0.17)
	 *   - Per-shell map: { "popup-auto": { trigger: "delay", ... }, ... }  (v0.17+)
	 *
	 * Always emits the per-shell map. This means a save with the legacy flat
	 * input migrates the row to the new shape; reads handle both transparently.
	 */
	public static function sanitize_all_shell_trigger_configs( array $raw ): array {
		// Legacy flat shape → wrap under popup-auto.
		if ( isset( $raw['trigger'] ) || isset( $raw['delay_seconds'] ) || isset( $raw['triggers'] ) ) {
			$raw = [ 'popup-auto' => $raw ];
		}
		$out = [];
		foreach ( self::auto_shells() as $shell ) {
			$shell_raw     = isset( $raw[ $shell ] ) && is_array( $raw[ $shell ] ) ? $raw[ $shell ] : [];
			$out[ $shell ] = self::sanitize_auto_trigger_config( $shell_raw );
		}
		return $out;
	}

	/**
	 * Normalize a schedule input (datetime-local string, MySQL DATETIME,
	 * date-only, or empty) into a MySQL DATETIME string or NULL. Bad input
	 * → NULL so we never store garbage that breaks NOW() comparisons at
	 * render time.
	 *
	 * Date-only input (e.g. "2026-11-25") gets a sensible default time:
	 *   $end_of_day = false → "00:00:00"  (start-of-day; intuitive for "from")
	 *   $end_of_day = true  → "23:59:59"  (end-of-day;   intuitive for "until")
	 */
	/**
	 * A web-app export writes schedule dates as ISO 8601 with an offset
	 * ("2026-11-25T14:30:00+00:00"), which normalize_datetime() rejects, so
	 * the schedule was silently dropped on import. Convert those to site-local
	 * time, the way this plugin stores them. Anything else passes through.
	 */
	public static function import_datetime( string $value ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
			return $value;
		}
		try {
			return ( new DateTimeImmutable( $value ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	public static function normalize_datetime( $value, bool $end_of_day = false ): ?string {
		$s = trim( (string) ( $value ?? '' ) );
		if ( $s === '' ) {
			return null;
		}
		// HTML5 datetime-local emits "2026-11-25T14:30" — MySQL wants a space.
		$s = str_replace( 'T', ' ', $s );
		// Date-only: append a default time so the column always stores a full DATETIME.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
			$s .= $end_of_day ? ' 23:59:59' : ' 00:00:00';
		}
		// Tolerate "YYYY-MM-DD HH:MM" by appending seconds (end-of-day uses :59).
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s ) ) {
			$s .= $end_of_day ? ':59' : ':00';
		}
		// Final shape check.
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $s, $m ) ) {
			return null;
		}
		// Shape is right — now confirm it's a REAL calendar moment, so a
		// digit-correct nonsense like "2026-13-99" can't reach the DB and
		// poison NOW() comparisons. (checkdate rejects month 13 / day 99.)
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
			|| (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
			return null;
		}
		return $s;
	}

	/**
	 * Does this form's schedule window currently include "now"? A form with
	 * no schedule (both NULL) is always within window. Times are interpreted
	 * in the WP site timezone via current_time('mysql').
	 */
	public static function is_in_schedule_window( object $form ): bool {
		// The active-from/until schedule window is account-gated (Free and up).
		// The Lite edition ignores it — a form is governed by its status only.
		if ( class_exists( 'ES_Caps' ) && ! ES_Caps::has( 'schedule_window' ) ) {
			return true;
		}
		return self::schedule_window_contains(
			$form->active_from  ?? null,
			$form->active_until ?? null,
			(string) current_time( 'mysql' )
		);
	}

	/**
	 * Pure core of is_in_schedule_window(): does the [from, until] window
	 * contain $now? Either bound may be null / empty (open-ended). All
	 * three are MySQL DATETIME strings — fixed-width, so a plain string
	 * compare orders them correctly. "Now" is a parameter so the logic
	 * is unit-testable without freezing the clock.
	 */
	public static function schedule_window_contains( ?string $from, ?string $until, string $now ): bool {
		if ( ! empty( $from ) && strcmp( $now, (string) $from ) < 0 ) {
			return false; // before start
		}
		if ( ! empty( $until ) && strcmp( $now, (string) $until ) > 0 ) {
			return false; // after end
		}
		return true;
	}

	/**
	 * Single source of truth for "should this form render right now?" — used
	 * by every public-side gate (shortcode, auto-injection, submit, view).
	 * Combines status + schedule. Display rules are a per-shell concern and
	 * stay in ES_Display_Rules.
	 */
	public static function is_currently_active( object $form ): bool {
		return self::resolve_status( $form ) === 'active';
	}

	/**
	 * Where the form actually IS, from the switch, the schedule window and the
	 * archive/trash flags. The single definition of the four states (SER-1270).
	 *
	 *   'inactive'  — switched off by a person, or archived, or trashed.
	 *   'scheduled' — switched on, but the start date has not arrived.
	 *   'expired'   — switched on, but the end date has passed.
	 *   'active'    — switched on and inside the window. The only one that renders.
	 *
	 * The schedule window is account-gated (Free+). Lite has no windows at all,
	 * so Lite only ever sees active and inactive, which is correct rather than a
	 * degraded version of this: a form with no schedule cannot be scheduled.
	 *
	 * `enabled` is the switch a person presses. Older rows predate the column;
	 * they fall back to the stored status, which is what the switch used to be.
	 */
	public static function resolve_status( object $form ): string {
		// `enabled` is the switch a person presses. Rows written before the
		// column existed fall back to the stored status, which is what the
		// switch used to be.
		$enabled = isset( $form->enabled )
			? (bool) $form->enabled
			: in_array( ( $form->status ?? 'inactive' ), [ 'active', 'scheduled', 'expired' ], true );

		$ignore_window = class_exists( 'ES_Caps' ) && ! ES_Caps::has( 'schedule_window' );

		return self::resolve_status_at(
			$enabled,
			! empty( $form->trashed_at ),
			! empty( $form->archived_at ),
			$ignore_window ? null : ( isset( $form->active_from )  ? (string) $form->active_from  : null ),
			$ignore_window ? null : ( isset( $form->active_until ) ? (string) $form->active_until : null ),
			(string) current_time( 'mysql' )
		);
	}

	/**
	 * Pure core of resolve_status(): the switch, the two flags, the schedule
	 * bounds and "now" (all MySQL DATETIME strings — fixed-width, so a plain
	 * string compare orders them). "Now" is a parameter so the rule is testable
	 * without freezing a clock, the same pattern as effective_state_at().
	 */
	public static function resolve_status_at( bool $enabled, bool $trashed, bool $archived, ?string $from, ?string $until, string $now ): string {
		if ( $trashed || $archived || ! $enabled ) {
			return 'inactive';
		}
		if ( $from && $from > $now ) {
			return 'scheduled';
		}
		if ( $until && $until < $now ) {
			return 'expired';
		}

		return 'active';
	}

	/**
	 * Move every form whose schedule boundary has passed since the last check.
	 *
	 * WP-Cron is the only timer a WordPress site has, and on a quiet site it does
	 * not fire until someone visits. So this also runs whenever the admin list is
	 * read, and the public side never depends on it at all: is_currently_active()
	 * resolves live, so a form that has not been reconciled yet still renders (or
	 * does not) correctly. The stored status is for counting and listing.
	 *
	 * Narrow by design — only rows with a date on them, or already parked in a
	 * state only a date can produce, can move.
	 *
	 * @return int how many forms actually moved.
	 */
	/** Whether reconcile_statuses() already ran in this request. Reset on any write. */
	private static bool $reconciled = false;

	public static function reconcile_statuses( bool $force = false ): int {
		// Once per request (audit P6): the list page called this from all() and
		// counts_by_filter(), so it ran three times per render. The clock has
		// not moved enough between them to matter. The cron passes $force.
		if ( self::$reconciled && ! $force ) {
			return 0;
		}
		self::$reconciled = true;

		global $wpdb;
		$t = self::table();

		// Only what resolve_status() and the A/B pause need, never the LONGTEXT
		// html and css of every scheduled asset (audit P6).
		$rows = $wpdb->get_results(
			"SELECT id, status, enabled, trashed_at, archived_at, active_from, active_until FROM $t
			 WHERE trashed_at IS NULL
			   AND archived_at IS NULL
			   AND ( active_from IS NOT NULL OR active_until IS NOT NULL OR status IN ('scheduled','expired') )"
		);
		if ( ! $rows ) {
			return 0;
		}

		$moved = 0;
		foreach ( $rows as $row ) {
			$resolved = self::resolve_status( $row );
			if ( $resolved === ( $row->status ?? '' ) ) {
				continue;
			}
			$wpdb->update( $t, [ 'status' => $resolved ], [ 'id' => (int) $row->id ] );
			$moved++;

			// A test follows its form: paused while it is off, resumed when it
			// comes back. Same rule as the SaaS (SER-1271).
			if ( class_exists( 'ES_AB' ) ) {
				$row->status = $resolved;
				ES_AB::sync_pause_with_form( $row );
			}
		}

		if ( $moved > 0 ) {
			self::flush_caches();
		}

		return $moved;
	}

	/**
	 * Effective display state for an asset — what an admin should *see*, derived
	 * from status + the schedule window without ever mutating the stored status.
	 * Surfaces the "Active but not showing" trap (asset is set Active yet its
	 * schedule window hasn't started, or has already passed).
	 *
	 * Returns one of:
	 *   'inactive'  — status is not 'active' (or trashed, or archived); never renders.
	 *   'scheduled' — Active, but "now" is before active_from (not started yet).
	 *   'expired'   — Active, but "now" is after active_until (window passed).
	 *   'ending'    — Active and rendering now, but an end date is still ahead.
	 *   'active'    — Active and inside the window, no end date; renders now.
	 *
	 * Pure-core counterpart: schedule_window_contains(). "Now" is the WP site
	 * timezone via current_time('mysql').
	 */
	public static function effective_state( object $form ): string {
		// Schedule window is account-gated (Free+); Lite ignores it, so the
		// effective state collapses to active/inactive (no scheduled/expired/ending).
		$ignore_window = class_exists( 'ES_Caps' ) && ! ES_Caps::has( 'schedule_window' );

		// A form can hold a LIST of periods now (SER-1292). When it does, that
		// list is the answer: "scheduled" also covers a gap between two of them,
		// and "expired" means past the LAST one rather than past the only one.
		// The pair below stays for a form not yet migrated and for anything the
		// SaaS mirror still sends.
		if ( ! $ignore_window && class_exists( 'ES_Form_Schedule' ) && ! empty( $form->id ) ) {
			$from_periods = ES_Form_Schedule::state_for( (int) $form->id );
			if ( null !== $from_periods ) {
				if ( ! empty( $form->trashed_at ) || ! empty( $form->archived_at ) || 'active' !== ( $form->status ?? 'inactive' ) ) {
					return 'inactive';
				}
				if ( 'active' === $from_periods ) {
					// "Ending" is live now with a stop still ahead, which with a
					// list means the period running now has an end.
					$current = ES_Form_Schedule::current( (int) $form->id );
					return ( $current && ! empty( $current->ends_at ) ) ? 'ending' : 'active';
				}
				return $from_periods;
			}
		}

		return self::effective_state_at(
			( $form->status ?? 'inactive' ),
			! empty( $form->trashed_at ),
			$ignore_window ? null : ( $form->active_from  ?? null ),
			$ignore_window ? null : ( $form->active_until ?? null ),
			(string) current_time( 'mysql' ),
			! empty( $form->archived_at )
		);
	}

	/**
	 * Pure core of effective_state(): given the stored status, trashed flag, the
	 * schedule bounds and "now" (all MySQL DATETIME strings — fixed-width, so a
	 * plain string compare orders them), return the derived display state. "Now"
	 * is a parameter so the logic is unit-testable without freezing the clock.
	 * $archived mirrors $trashed — an archived form never renders either.
	 */
	public static function effective_state_at( ?string $status, bool $trashed, ?string $from, ?string $until, string $now, bool $archived = false ): string {
		if ( $trashed || $archived || ( $status ?? 'inactive' ) !== 'active' ) {
			return 'inactive';
		}
		if ( ! empty( $from ) && strcmp( $now, (string) $from ) < 0 ) {
			return 'scheduled';
		}
		if ( ! empty( $until ) && strcmp( $now, (string) $until ) > 0 ) {
			return 'expired';
		}
		if ( ! empty( $until ) ) {
			return 'ending'; // active now, but an end date is still ahead
		}
		return 'active';
	}

	/**
	 * Does this asset actually capture input?
	 *
	 * Not the same question as "is there a <form> tag". The authoring spec puts
	 * a <form class="esf-form"> root on EVERY asset — form-less announcements
	 * included — so the tag says nothing about whether there is anything to fill
	 * in. Only a real field does. Reading the tag instead scored pure-CTA assets
	 * on conversions that can never happen.
	 *
	 * The honeypot is injected at render time, never authored, so it cannot make
	 * a form-less asset look like a form here.
	 */
	public static function has_fields( string $html ): bool {
		if ( preg_match( '/<(?:textarea|select)[\s>]/i', $html ) ) {
			return true;
		}

		if ( ! preg_match_all( '/<input\b[^>]*>/i', $html, $matches ) ) {
			return false;
		}

		// <input> types that capture nothing a visitor typed or chose.
		$non_fields = array( 'hidden', 'submit', 'button', 'image', 'reset' );

		foreach ( $matches[0] as $tag ) {
			// An <input> with no type= is a text field.
			$type = preg_match( '/\btype\s*=\s*("|\'|)([a-zA-Z-]+)\1/i', $tag, $m )
				? strtolower( $m[2] )
				: 'text';

			if ( ! in_array( $type, $non_fields, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Default metric for an asset: 'conversions' when it has fields to fill in,
	 * else 'clicks'. A pure heuristic shared by the editor, the Forms list and
	 * the A/B engine. Lives here (core) so the Lite build keeps it without the
	 * account-gated A/B module.
	 */
	public static function primary_metric( string $html ): string {
		return self::has_fields( $html ) ? 'conversions' : 'clicks';
	}

	public static function types_for( object $form ): array {
		$raw = $form->form_types ?? '';
		// Accept either a raw DB row (JSON string) or an already-hydrated form
		// object whose form_types is an array. The form-edit view passes the
		// hydrated object — decoding only strings made this always fall back to
		// ['inline'], so the Design preview showed "Inline" for every asset.
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
		} else {
			$decoded = null;
		}
		if ( ! is_array( $decoded ) ) {
			return [ 'inline' ]; // safe default for legacy rows
		}
		return array_values( array_intersect( $decoded, self::valid_types() ) );
	}

	public static function unique_slug( string $base, ?int $ignore_id = null ): string {
		global $wpdb;
		$t       = self::table();
		$base    = sanitize_title( $base );
		$base    = $base !== '' ? $base : 'form';
		$slug    = $base;
		$i       = 2;
		$ignore  = $ignore_id ?: 0;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE slug = %s AND id <> %d", $slug, $ignore ) ) ) {
			$slug = $base . '-' . $i;
			$i++;
		}
		return $slug;
	}

	/**
	 * Drop the per-request render caches derived from form state — the
	 * visitor-cookie flag and the auto-shell flag. Called after every
	 * form mutation (save / trash / restore / delete) so the next
	 * front-end request recomputes them from current data.
	 */
	private static function flush_caches(): void {
		// A write can move a status, so the next read reconciles again.
		self::$reconciled = false;
		if ( class_exists( 'ES_FunnelKit_Form_Feed' ) ) {
			ES_FunnelKit_Form_Feed::forget_catalog();
		}
		ES_Display_Rules::flush_visitor_state_cache();
		ES_Shortcode::flush_auto_shell_cache();
	}
}
