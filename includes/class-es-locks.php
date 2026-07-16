<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Concurrent-edit lock for assets (forms).
 *
 * When an admin opens an asset's edit screen, a lock is recorded on the
 * form row (`locked_by` = user id, `locked_token` = a per-tab/session id,
 * `locked_at` = last check-in). While the editor stays open the client
 * checks in periodically, refreshing `locked_at`. A lock with no check-in
 * within TIMEOUT seconds is considered stale (the editor was closed/crashed)
 * and the asset is free for anyone else.
 *
 * Identity is the **session token**, not the user id, so a second TAB of the
 * *same* admin counts as a different session: opening it offers "Take over",
 * and the first tab's next check-in sees a different token holds the lock and
 * is booted (hard lock — can't save). `locked_by` rides along only to show a
 * human name (and, with future teams, to tell self-takeover from a colleague).
 *
 * All static; the lock lives on the form row, no extra table.
 */
class ES_Locks {

	/** Seconds without a check-in before a lock is treated as stale/free. */
	const TIMEOUT = 90;

	private static function table(): string {
		return ES_Install::table( 'forms' );
	}

	private static function row( int $form_id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT locked_by, locked_token, locked_at FROM $t WHERE id = %d", $form_id ) );
	}

	/** Is a lock row fresh (checked in within TIMEOUT)? Times stored in GMT. */
	private static function fresh( $row ): bool {
		if ( ! is_object( $row ) || empty( $row->locked_at ) ) {
			return false;
		}
		return ( time() - (int) strtotime( $row->locked_at . ' GMT' ) ) <= self::TIMEOUT;
	}

	/**
	 * The current effective holder's user id, or 0 when the asset is free
	 * (no lock, or the lock is stale). Used for the human name.
	 */
	public static function holder( int $form_id ): int {
		$row = self::row( $form_id );
		return self::fresh( $row ) ? (int) $row->locked_by : 0;
	}

	/** The current effective holder's session token, or '' when free/stale. */
	public static function holder_token( int $form_id ): string {
		$row = self::row( $form_id );
		return self::fresh( $row ) ? (string) $row->locked_token : '';
	}

	/**
	 * Like holder(), but reads from an already-fetched form row (which carries
	 * locked_by/locked_at via SELECT *), so list views don't pay a query per
	 * asset. Returns the effective holder's id, or 0 when free/stale.
	 */
	public static function holder_of_row( $row ): int {
		if ( ! is_object( $row ) || empty( $row->locked_by ) ) {
			return 0;
		}
		return self::fresh( $row ) ? (int) $row->locked_by : 0;
	}

	/** Is the asset held by a different SESSION than $token right now? */
	public static function is_locked_by_other_session( int $form_id, string $token ): bool {
		$h = self::holder_token( $form_id );
		return $h !== '' && $h !== $token;
	}

	/** Claim (or refresh) the lock for ($user_id, $token). Timestamp in GMT. */
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

	/**
	 * Heartbeat check-in. Keeps the lock if my token holds it, OR re-claims it
	 * if the asset is currently free (my lock went stale but nobody took over).
	 * Returns false only when ANOTHER SESSION now holds it (I've been booted).
	 */
	public static function beat( int $form_id, int $user_id, string $token ): bool {
		$h = self::holder_token( $form_id );
		if ( $h === '' || $h === $token ) {
			self::acquire( $form_id, $user_id, $token );
			return true;
		}
		return false;
	}

	/** Force the lock to ($user_id, $token) (the "Take over" action). */
	public static function take_over( int $form_id, int $user_id, string $token ): void {
		self::acquire( $form_id, $user_id, $token );
	}

	/** Release the lock, but only if $token currently holds it. */
	public static function release( int $form_id, string $token ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET locked_by = NULL, locked_token = NULL, locked_at = NULL WHERE id = %d AND locked_token = %s",
				$form_id,
				$token
			)
		);
	}

	/** Display name for a user id (falls back to a generic label). */
	public static function user_label( int $user_id ): string {
		$u = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $u ) {
			return __( 'Another user', 'emblem-studio' );
		}
		return $u->display_name ?: $u->user_login;
	}
}
