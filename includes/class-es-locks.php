<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Locks {

	const TIMEOUT = 90;

	private static function table(): string {
		return ES_Install::table( 'forms' );
	}

	private static function row( int $form_id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT locked_by, locked_token, locked_at FROM $t WHERE id = %d", $form_id ) );
	}

	private static function fresh( $row ): bool {
		if ( ! is_object( $row ) || empty( $row->locked_at ) ) {
			return false;
		}
		return ( time() - (int) strtotime( $row->locked_at . ' GMT' ) ) <= self::TIMEOUT;
	}

	public static function holder( int $form_id ): int {
		$row = self::row( $form_id );
		return self::fresh( $row ) ? (int) $row->locked_by : 0;
	}

	public static function holder_token( int $form_id ): string {
		$row = self::row( $form_id );
		return self::fresh( $row ) ? (string) $row->locked_token : '';
	}

	public static function holder_of_row( $row ): int {
		if ( ! is_object( $row ) || empty( $row->locked_by ) ) {
			return 0;
		}
		return self::fresh( $row ) ? (int) $row->locked_by : 0;
	}

	public static function is_locked_by_other_session( int $form_id, string $token ): bool {
		$h = self::holder_token( $form_id );
		return $h !== '' && $h !== $token;
	}

	public static function blocks_save( int $form_id, int $user_id, string $token ): bool {
		$row = self::row( $form_id );
		if ( ! self::fresh( $row ) ) {
			return false;
		}
		return (string) $row->locked_token !== $token || (int) $row->locked_by !== $user_id;
	}

	public static function acquire( int $form_id, int $user_id, string $token ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[ 'locked_by' => $user_id, 'locked_token' => $token, 'locked_at' => current_time( 'mysql', true ) ],
			[ 'id' => $form_id ],
			[ '%d', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function beat( int $form_id, int $user_id, string $token, bool $claim = false ): bool {
		$row = self::row( $form_id );
		$h   = self::fresh( $row ) ? (string) $row->locked_token : '';
		if ( $h !== '' && $h === $token ) {
			if ( (int) $row->locked_by !== get_current_user_id() ) {
				return false;
			}
			self::acquire( $form_id, $user_id, $token );
			return true;
		}
		if ( $h === '' ) {
			if ( ! $claim && self::released_by( $form_id, $token ) ) {
				return true;
			}
			self::acquire( $form_id, $user_id, $token );
			return true;
		}
		return false;
	}

	private static function released_by( int $form_id, string $token ): bool {
		$row = self::row( $form_id );
		return is_object( $row ) && empty( $row->locked_at ) && $token !== '' && (string) $row->locked_token === $token;
	}

	public static function holders( array $form_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $form_ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		global $wpdb;
		$t    = self::table();
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, locked_by, locked_token, locked_at FROM $t WHERE id IN ($in)", ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = [];
		foreach ( $rows as $row ) {
			$uid = self::holder_of_row( $row );
			if ( $uid ) {
				$out[ (int) $row->id ] = $uid;
			}
		}
		return $out;
	}

	public static function take_over( int $form_id, int $user_id, string $token ): void {
		self::acquire( $form_id, $user_id, $token );
	}

	public static function release( int $form_id, string $token ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET locked_by = NULL, locked_at = NULL WHERE id = %d AND locked_token = %s",
				$form_id,
				$token
			)
		);
	}

	public static function user_label( int $user_id ): string {
		$u = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $u ) {
			return __( 'Another user', 'emblem-studio' );
		}
		return $u->display_name ?: $u->user_login;
	}
}
