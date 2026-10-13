<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Supported server-side measurement boundary. See docs/MEASUREMENT-AUTHORIZATION.md. */
final class ES_Measurement {
	public const API_VERSION = 1;
	private static bool $authorizing = false;

	/** true = active caller transaction; false = proven autocommit; null = unknown. */
	public static function transaction_active(): ?bool {
		return ES_Measurement_Transaction::active();
	}

	public static function storage_transactional(): bool {
		return ES_Measurement_Transaction::tables_transactional( [ ES_Install::table( 'leads' ), ES_Install::table( 'events' ) ] );
	}

	public static function policy( array $context ): string {
		try {
			$policy = apply_filters( 'emblem_studio_measurement_policy', 'legacy', $context );
			// A malformed server policy must not accidentally restore legacy tracking.
			return $policy === 'legacy' ? 'legacy' : 'strict';
		} catch ( Throwable $error ) {
			return 'strict';
		}
	}

	/**
	 * No request consent/lead/visitor value grants authority. The host supplies
	 * its current server decision, optionally inside an atomic write callback.
	 */
	public static function authorized_write( array $context, callable $write ): bool {
		if ( self::$authorizing || ! self::storage_transactional() ) {
			return false;
		}
		self::$authorizing = true;
		$scope = new ES_Measurement_Write_Scope();
		try {
			$authority = apply_filters( 'emblem_studio_measurement_authorization', null, $context );
			if ( ! is_callable( $authority ) ) {
				return false;
			}
			$inserted = false;
			$writer = static function ( $decision ) use ( $context, $write, &$inserted, $scope ): bool {
				// One insert at most, even if a buggy server adapter invokes twice.
				if ( ! $scope->claim() ) {
					return false;
				}
				if ( ! is_array( $decision ) || ( $decision['authorized'] ?? null ) !== true
					|| ! isset( $decision['personal'] ) || ! is_bool( $decision['personal'] ) ) {
					return false;
				}
				if ( $decision['personal'] ) {
					global $wpdb;
					$id = $context['lead_id'];
					if ( ! is_int( $id ) || $id < 1 ) {
						return false;
					}
					// Native submit passes its insert_id. No lookup by client id,
					// email, visitor cookie, matching timestamp, or posted consent.
					$lead = $wpdb->get_row( $wpdb->prepare(
						'SELECT id, form_id FROM ' . ES_Install::table( 'leads' ) . ' WHERE id = %d FOR UPDATE', $id
					) );
					if ( ! $lead || (int) $lead->form_id !== $context['form_id'] ) {
						return false;
					}
				}
				$inserted = $write( $decision ) === true;
				return $inserted;
			};
			// The host owns locking/commit. Returning true without calling the
			// writer cannot fabricate a recorded conversion. Plain decision arrays
			// are rejected because a separate read then write races withdrawal.
			return $authority( $writer, $context ) === true && $inserted;
		} catch ( Throwable $error ) {
			// Measurement failures must never prevent the already saved capture.
			return false;
		} finally {
			$scope->expire();
			self::$authorizing = false;
		}
	}

	/** Exact association only; the host must authorize access before calling. */
	public static function events_for_lead( int $lead_id ) {
		if ( $lead_id < 1 ) {
			return [];
		}
		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT id, form_id FROM ' . ES_Install::table( 'leads' ) . ' WHERE id = %d', $lead_id ) );
		if ( ES_Measurement_Transaction::query_failed() || ! $lead ) {
			return new WP_Error( 'es_measurement_capture_unavailable', __( 'The measurement capture could not be verified.', 'emblem-studio' ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . ES_Install::table( 'events' ) . ' WHERE lead_id = %d ORDER BY id', $lead_id
		) );
		if ( ES_Measurement_Transaction::query_failed() || ! is_array( $rows ) ) {
			return new WP_Error( 'es_measurement_read_failed', __( 'Associated measurement could not be read. Export completeness cannot be confirmed.', 'emblem-studio' ) );
		}
		foreach ( $rows as $row ) {
			if ( (int) $row->form_id !== (int) $lead->form_id || $row->measurement_mode !== 'personal' ) {
				return new WP_Error( 'es_measurement_association_invalid', __( 'Measurement association is malformed. No attribution or erasure was inferred.', 'emblem-studio' ) );
			}
		}
		return $rows;
	}

	/** Return false on SQL failure so callers preserve the capture for retry. */
	public static function erase_for_lead( int $lead_id ) {
		if ( $lead_id < 1 ) {
			return 0;
		}
		if ( ! self::storage_transactional() ) { return false; }
		return ES_Measurement_Transaction::run( static function () use ( $lead_id ) {
			global $wpdb;
			$lead = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ES_Install::table( 'leads' ) . ' WHERE id = %d FOR UPDATE', $lead_id ) );
			if ( ! $lead || is_wp_error( self::events_for_lead( $lead_id ) ) ) { return false; }
			return $wpdb->delete( ES_Install::table( 'events' ), [ 'lead_id' => $lead_id ], [ '%d' ] );
		} );
	}

	/**
	 * Legacy rows have no deterministic identity. Never claim they belong to a
	 * person or delete them with another person's erasure. NULL includes rows
	 * created before this API. Strict aggregate rows are explicitly minimized.
	 */
	public static function association_status( int $form_id ): array {
		global $wpdb;
		$events = ES_Install::table( 'events' );
		$leads = ES_Install::table( 'leads' );
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $events e LEFT JOIN $leads l ON l.id=e.lead_id
			WHERE e.form_id = %d AND (
				e.measurement_mode IS NULL OR e.measurement_mode NOT IN ('personal','aggregate')
				OR (e.measurement_mode='personal' AND (e.lead_id IS NULL OR l.id IS NULL OR l.form_id<>e.form_id))
				OR (e.measurement_mode='aggregate' AND (e.lead_id IS NOT NULL OR COALESCE(e.ua,'')<>'' OR COALESCE(e.visitor,'')<>'' OR COALESCE(e.ip,'')<>'' OR COALESCE(e.click_target,'')<>''))
			)", $form_id
		) );
		if ( $wpdb->last_error !== '' ) {
			$count = null;
		}
		return [ 'complete' => $count !== null && (int) $count === 0, 'unassociated' => $count === null ? null : (int) $count, 'legacy_unknown' => $count === null || (int) $count > 0 ];
	}
}

/** Internal single-use lifetime guard; a retained writer can never outlive its call. */
final class ES_Measurement_Write_Scope {
	private bool $active = true;
	private bool $used = false;
	public function claim(): bool {
		if ( ! $this->active || $this->used ) {
			return false;
		}
		$this->used = true;
		return true;
	}
	public function expire(): void { $this->active = false; }
}
