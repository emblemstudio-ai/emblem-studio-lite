<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content changes set now and applied on a date the person chose (SER-1287).
 *
 * The mirror of ES_Form_Revisions. That one records what a form USED to say;
 * this one holds what it WILL say, and when.
 *
 * Emblem Studio already schedules one thing: a form switching on and off from
 * its own start and end dates. This is the other thing, and they answer
 * different questions. That decides WHETHER a form shows. This decides WHAT it
 * says when it does.
 *
 * A scheduled change carries HTML, CSS and a time. Not settings: a form already
 * owns its own dates, so "stop showing this on the 8th" is something the form
 * does, not something a change to its content does.
 *
 * Three rules, matching the SaaS exactly, and each one a choice:
 *
 * A LATE CHANGE STILL APPLIES. WP-Cron only fires when somebody visits the
 * site, so on a quiet site a change WILL run late. It runs late rather than
 * never, because a promo panel that comes down late is untidy and one that
 * never comes down is a lie on a live page.
 *
 * A CHANGE DUE DURING AN A/B TEST IS SKIPPED, NOT DELAYED. A running test shows
 * variants, so the form's own HTML is not what visitors see. Applying to it
 * would edit something invisible and quietly change what the test measures.
 *
 * A TIME ALREADY PAST IS REFUSED rather than applied at once. Somebody who
 * mistypes the year should be told, not shown their live form changing.
 */
class ES_Form_Scheduled_Revisions {

	public const QUEUED    = 'queued';
	public const APPLIED   = 'applied';
	public const SKIPPED   = 'skipped';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	private static function table(): string {
		return ES_Install::table( 'form_scheduled_revisions' );
	}

	/**
	 * Queue a change for a moment in the future.
	 *
	 * $apply_at is site-local wall-clock time ("2026-09-07 09:00"), because that
	 * is what the person typed into the admin screen and WordPress stores its
	 * dates that way. Comparisons below use current_time( 'mysql' ), so both
	 * sides of every comparison are on the same clock.
	 *
	 * @return int|null the new id, or null when refused or on failure
	 */
	public static function insert( int $form_id, string $html, string $css, string $apply_at, ?string $name = null ): ?int {
		if ( $form_id <= 0 ) {
			return null;
		}
		$when = self::normalise_time( $apply_at );
		if ( null === $when || $when <= current_time( 'mysql' ) ) {
			return null;
		}

		global $wpdb;
		$latest = ES_Form_Revisions::latest_for_form( $form_id );
		$ok     = $wpdb->insert(
			self::table(),
			[
				'form_id'              => $form_id,
				'html'                 => $html,
				'css'                  => $css,
				'name'                 => ( null !== $name && '' !== $name ) ? mb_substr( $name, 0, 191 ) : null,
				'apply_at'             => $when,
				'status'               => self::QUEUED,
				// Which version the form was on when this was built. Lets the
				// admin screen say "made before your last two saves" rather than
				// silently undoing them on the day.
				'based_on_revision_id' => $latest ? (int) $latest->id : null,
				'created_at'           => current_time( 'mysql' ),
				'created_by'           => get_current_user_id() ?: null,
			]
		);

		return $ok ? (int) $wpdb->insert_id : null;
	}

	/** Queued first and soonest first, then the record of ones that already ran. */
	public static function list_for_form( int $form_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE form_id = %d '
			. "ORDER BY CASE WHEN status = 'queued' THEN 0 ELSE 1 END, apply_at ASC, id DESC LIMIT %d",
			$form_id,
			$limit
		) );
		return is_array( $rows ) ? $rows : [];
	}

	/** How many are still waiting. Drives the "Changes Sep 7" label in the list. */
	public static function queued_count( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE form_id = %d AND status = %s',
			$form_id,
			self::QUEUED
		) );
	}

	/** The soonest queued change's time, or null. */
	public static function next_at( int $form_id ): ?string {
		global $wpdb;
		$at = $wpdb->get_var( $wpdb->prepare(
			'SELECT MIN(apply_at) FROM ' . self::table() . ' WHERE form_id = %d AND status = %s',
			$form_id,
			self::QUEUED
		) );
		return $at ? (string) $at : null;
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $row ?: null;
	}

	/**
	 * Edit a queued change. Only the keys given are touched.
	 *
	 * Rewriting the content re-bases it on what is live now, because the caller
	 * has just looked at it. Moving only the date does not: the staleness
	 * warning is still true and must keep showing.
	 *
	 * @param array<string,mixed> $fields html, css, name, apply_at
	 */
	public static function update( int $id, array $fields ): bool {
		$row = self::find( $id );
		if ( ! $row || self::QUEUED !== $row->status ) {
			return false;
		}

		$data = [];
		if ( array_key_exists( 'html', $fields ) ) {
			$data['html'] = (string) $fields['html'];
		}
		if ( array_key_exists( 'css', $fields ) ) {
			$data['css'] = (string) $fields['css'];
		}
		if ( array_key_exists( 'name', $fields ) ) {
			$name         = trim( (string) $fields['name'] );
			$data['name'] = '' !== $name ? mb_substr( $name, 0, 191 ) : null;
		}
		if ( array_key_exists( 'apply_at', $fields ) ) {
			$when = self::normalise_time( (string) $fields['apply_at'] );
			if ( null === $when || $when <= current_time( 'mysql' ) ) {
				return false;
			}
			$data['apply_at'] = $when;
		}
		if ( array_key_exists( 'html', $fields ) || array_key_exists( 'css', $fields ) ) {
			$latest                       = ES_Form_Revisions::latest_for_form( (int) $row->form_id );
			$data['based_on_revision_id'] = $latest ? (int) $latest->id : null;
		}
		if ( empty( $data ) ) {
			return true;
		}

		global $wpdb;
		return false !== $wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	/** Call a queued change off. */
	public static function cancel( int $id, string $reason ): bool {
		$row = self::find( $id );
		if ( ! $row || self::QUEUED !== $row->status ) {
			return false;
		}
		return self::settle( $id, self::CANCELLED, $reason );
	}

	/**
	 * Cancel everything queued on a form.
	 *
	 * Called when a form is trashed or archived. A queued change on a form
	 * nobody can see is a surprise waiting for whoever restores it.
	 */
	public static function cancel_for_form( int $form_id, string $reason ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET status = %s, reason = %s, applied_at = %s WHERE form_id = %d AND status = %s',
			self::CANCELLED,
			mb_substr( $reason, 0, 500 ),
			current_time( 'mysql' ),
			$form_id,
			self::QUEUED
		) );
	}

	/** Drop every scheduled change attached to a form. Permanent delete only. */
	public static function delete_for_form( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'form_id' => $form_id ] );
	}

	/**
	 * Apply every queued change whose time has come. The cron hook calls this.
	 *
	 * Ordered by time then id, so two set for the same minute land in the order
	 * they were made and the later one wins. That is the intended reading of
	 * "I changed my mind twice".
	 *
	 * @return array{applied:int,skipped:int,failed:int,cancelled:int}
	 */
	public static function apply_due(): array {
		$tally = [
			'applied'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'cancelled' => 0,
		];

		global $wpdb;
		$due = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE status = %s AND apply_at <= %s ORDER BY apply_at ASC, id ASC LIMIT 200',
			self::QUEUED,
			current_time( 'mysql' )
		) );
		if ( ! is_array( $due ) ) {
			return $tally;
		}

		foreach ( $due as $row ) {
			$outcome = self::apply( (int) $row->id );
			if ( isset( $tally[ $outcome ] ) ) {
				++$tally[ $outcome ];
			}
		}

		update_option( 'emblem_studio_scheduled_last_run', current_time( 'mysql' ), false );

		return $tally;
	}

	/**
	 * Apply one queued change and record what happened.
	 *
	 * Used by the cron runner and by "apply now" in the admin. Identical either
	 * way, so applying early cannot sneak past a running test.
	 *
	 * @return string the resulting status
	 */
	public static function apply( int $id ): string {
		$row = self::find( $id );
		if ( ! $row ) {
			return self::FAILED;
		}
		if ( self::QUEUED !== $row->status ) {
			return (string) $row->status;
		}

		$form_id = (int) $row->form_id;
		$form    = ES_Form_Repo::find( $form_id );
		if ( ! $form ) {
			self::settle( $id, self::CANCELLED, __( 'The form no longer exists.', 'emblem-studio' ) );
			return self::CANCELLED;
		}
		if ( ! empty( $form->trashed_at ) || ! empty( $form->archived_at ) ) {
			self::settle( $id, self::CANCELLED, __( 'The form was put away before this change was due.', 'emblem-studio' ) );
			return self::CANCELLED;
		}
		if ( class_exists( 'ES_AB' ) && ES_AB::running_test_for_form( $form_id ) ) {
			self::settle(
				$id,
				self::SKIPPED,
				__( 'An A/B test was running on this form, so the change was not applied. Visitors are being shown variants, not the form’s own content. Set it again for a date after the test.', 'emblem-studio' )
			);
			return self::SKIPPED;
		}

		// Through Form_Repo::save with only html/css present, so it passes the
		// same sanitiser gate as an admin save and snapshots the version it
		// replaces into revision history. The scheduled change is therefore
		// itself undoable.
		$saved = ES_Form_Repo::save(
			[
				'html'          => (string) $row->html,
				'css'           => (string) $row->css,
				'revision_note' => __( 'Saved before a scheduled change', 'emblem-studio' ),
			],
			$form_id
		);

		if ( ! $saved ) {
			self::settle( $id, self::FAILED, __( 'The change could not be saved to the form.', 'emblem-studio' ) );
			return self::FAILED;
		}

		self::settle( $id, self::APPLIED, null );
		return self::APPLIED;
	}

	/**
	 * Has the form been saved since this change was built?
	 *
	 * true  — yes, and applying this will undo those saves
	 * false — no
	 * null  — cannot tell, and that is a real answer rather than a shrug. The
	 *         revision it came from may have been pruned away. Reporting it as
	 *         false is how somebody gets talked into losing an edit.
	 */
	public static function has_moved_on( object $row ): ?bool {
		if ( empty( $row->based_on_revision_id ) ) {
			return null;
		}
		$from = ES_Form_Revisions::find( (int) $row->based_on_revision_id );
		if ( ! $from ) {
			return null;
		}
		$latest = ES_Form_Revisions::latest_for_form( (int) $row->form_id );
		return $latest ? (int) $latest->id !== (int) $row->based_on_revision_id : false;
	}

	/** When the cron runner last reported in, or null. */
	public static function last_run_at(): ?string {
		$at = get_option( 'emblem_studio_scheduled_last_run', '' );
		return is_string( $at ) && '' !== $at ? $at : null;
	}

	/** Write the outcome down. */
	private static function settle( int $id, string $status, ?string $reason ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			[
				'status'     => $status,
				'reason'     => ( null !== $reason ) ? mb_substr( $reason, 0, 500 ) : null,
				'applied_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * A person's typing into "Y-m-d H:i:s", or null if it is not a real moment.
	 *
	 * Deliberately the SAME normaliser the form's own start and end dates use.
	 * Two date parsers in one plugin is two sets of edge cases, and this one
	 * already handles the "2026-09-07T09:00" a datetime-local input emits, a
	 * date with no time, and digit-correct nonsense like "2026-13-99".
	 */
	private static function normalise_time( string $input ): ?string {
		return ES_Form_Repo::normalize_datetime( $input );
	}
}
