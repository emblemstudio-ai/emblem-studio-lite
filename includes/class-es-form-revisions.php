<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-form HTML/CSS revision history.
 *
 * One row per save (when HTML or CSS changed). Repository surface for
 * inserting, listing, fetching, restoring, and pruning. The author runs
 * the Emblem Studio form-edit page; revisions are not a public-side
 * concern, so all reads are admin-context.
 */
class ES_Form_Revisions {

	private const KEEP_PER_FORM = 50;

	private static function table(): string {
		return ES_Install::table( 'form_revisions' );
	}

	/**
	 * Insert a revision row. Caller decides whether to skip (e.g. when
	 * the save didn't actually change html/css). Auto-prunes oldest rows
	 * beyond the per-form cap. Returns the new id, or null on failure.
	 */
	public static function insert( int $form_id, string $html, string $css, ?string $note = null ): ?int {
		if ( $form_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			[
				'form_id'    => $form_id,
				'html'       => $html,
				'css'        => $css,
				'note'       => $note,
				'created_at' => current_time( 'mysql' ),
				'created_by' => get_current_user_id() ?: null,
			]
		);
		if ( ! $ok ) {
			return null;
		}
		$new_id = (int) $wpdb->insert_id;
		self::prune( $form_id );
		return $new_id;
	}

	public static function latest_for_form( int $form_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT 1',
			$form_id
		) );
		return $row ?: null;
	}

	/**
	 * Newest first. Returns up to KEEP_PER_FORM rows.
	 */
	public static function list_for_form( int $form_id, int $limit = self::KEEP_PER_FORM ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
			$form_id, $limit
		) );
		return is_array( $rows ) ? $rows : [];
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d',
			$id
		) );
		return $row ?: null;
	}

	public static function set_note( int $id, string $note ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table(),
			[ 'note' => $note !== '' ? mb_substr( $note, 0, 191 ) : null ],
			[ 'id' => $id ]
		);
	}

	/**
	 * Drop every revision attached to a form. Called by Form_Repo::delete()
	 * when a form is permanently deleted — trash doesn't cascade, so the
	 * history survives "Move to Trash → Restore" round-trips.
	 */
	public static function delete_for_form( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'form_id' => $form_id ] );
	}

	/**
	 * Keep only the most recent KEEP_PER_FORM rows per form. Cheap; runs
	 * after every insert. Filterable cap.
	 */
	public static function prune( int $form_id ): int {
		$cap = (int) apply_filters( 'emblem_studio_revisions_per_form', self::KEEP_PER_FORM );
		if ( $cap < 1 ) {
			return 0;
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT %d, 999999',
			$form_id, $cap
		) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . ' WHERE id IN (' . $placeholders . ')',
			...array_map( 'intval', $ids )
		) );
	}
}
