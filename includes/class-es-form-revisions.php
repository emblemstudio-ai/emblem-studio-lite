<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Form_Revisions {

	private const KEEP_PER_FORM = 50;

	private static function table(): string {
		return ES_Install::table( 'form_revisions' );
	}

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

	public static function delete_for_form( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'form_id' => $form_id ] );
	}

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
