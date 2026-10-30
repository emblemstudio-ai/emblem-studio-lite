<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates with WordPress's built-in privacy framework (Tools → Export
 * Personal Data / Erase Personal Data). When an admin processes a data
 * request keyed by email, our exporter returns every lead row we hold,
 * and our eraser removes them.
 */
class ES_Privacy {

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers',   [ $this, 'register_eraser' ] );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['emblem-studio'] = [
			'exporter_friendly_name' => __( 'Emblem Studio Leads', 'emblem-studio' ),
			'callback'               => [ $this, 'exporter' ],
		];
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['emblem-studio'] = [
			'eraser_friendly_name' => __( 'Emblem Studio Leads', 'emblem-studio' ),
			'callback'             => [ $this, 'eraser' ],
		];
		return $erasers;
	}

	public function exporter( string $email, int $page = 1 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ES_Install::table( 'leads' ) . ' WHERE email = %s ORDER BY id', strtolower( trim( $email ) ) ) );
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) {
			return [ 'data' => [], 'done' => false, 'measurement_complete' => false, 'error' => 'es_measurement_capture_unavailable' ];
		}
		$data = [];
		$measurement_complete = true;

		foreach ( $rows as $r ) {
			$extra = $r->extra_fields ? json_decode( $r->extra_fields, true ) : [];
			$extra = is_array( $extra ) ? $extra : [];

			$item_data = [
				[ 'name' => __( 'Email', 'emblem-studio' ),       'value' => $r->email ],
				[ 'name' => __( 'First name', 'emblem-studio' ),  'value' => $extra['first_name'] ?? '' ],
				[ 'name' => __( 'Last name', 'emblem-studio' ),   'value' => $extra['last_name']  ?? '' ],
				[ 'name' => __( 'Phone', 'emblem-studio' ),       'value' => $extra['phone']      ?? '' ],
				[ 'name' => __( 'Submitted at', 'emblem-studio' ), 'value' => $r->created_at ],
			];
			$status = ES_Measurement::association_status( (int) $r->form_id );
			if ( $status['unassociated'] === null ) {
				return [ 'data' => [], 'done' => false, 'measurement_complete' => false, 'error' => 'es_measurement_read_failed' ];
			}
			if ( ! $status['complete'] ) {
				$measurement_complete = false;
				$item_data[] = [ 'name' => __( 'Measurement export limitation', 'emblem-studio' ), 'value' => __( 'Legacy or malformed measurement cannot be deterministically associated. Only exactly associated events are included; measurement completeness cannot be confirmed.', 'emblem-studio' ) ];
			}

			// What they bought afterwards, where the shop told us (SER-1346). It is
			// a fact about this person, so it belongs in their own copy of the file.
			if ( class_exists( 'ES_Purchases' ) ) {
				$purchases = ES_Purchases::for_lead( (int) $r->id );
				if ( is_wp_error( $purchases ) ) {
					return [ 'data' => [], 'done' => false, 'measurement_complete' => false, 'error' => $purchases->get_error_code() ];
				}
				foreach ( $purchases as $i => $purchase ) {
					$item_data[] = [
						/* translators: %d: the number of this purchase in the list */
						'name'  => sprintf( __( 'Purchase %d after signing up', 'emblem-studio' ), $i + 1 ),
						'value' => sprintf( '%s %s, order #%d, %s', $purchase['total'], $purchase['currency'], $purchase['order_id'], $purchase['ordered_at'] ),
					];
				}
			}

			$data[] = [
				'group_id'    => 'emblem-studio-leads',
				'group_label' => __( 'Emblem Studio Form Submissions', 'emblem-studio' ),
				'item_id'     => 'emblem-studio-lead-' . (int) $r->id,
				'data'        => $item_data,
			];
			$events = ES_Measurement::events_for_lead( (int) $r->id );
			if ( is_wp_error( $events ) ) {
				return [ 'data' => [], 'done' => false, 'error' => $events->get_error_code() ];
			}
			foreach ( $events as $event ) {
				$item = [];
				foreach ( [ 'form_id', 'event_type', 'type', 'post_id', 'variant', 'visitor', 'ua', 'created_at' ] as $key ) {
					if ( isset( $event->$key ) ) {
						$item[] = [ 'name' => $key, 'value' => (string) $event->$key ];
					}
				}
				$data[] = [
					'group_id' => 'emblem-studio-measurement',
					'group_label' => __( 'Emblem Studio Measurement', 'emblem-studio' ),
					'item_id' => 'emblem-studio-event-' . (int) $event->id,
					'data' => $item,
				];
			}
		}

		return [ 'data' => $data, 'done' => true, 'measurement_complete' => $measurement_complete ];
	}

	public function eraser( string $email, int $page = 1 ): array {
		global $wpdb;
		$leads = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ES_Install::table( 'leads' ) . ' WHERE email = %s', strtolower( trim( $email ) ) ) );
		if ( ! is_array( $leads ) || $wpdb->last_error !== '' ) {
			return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [], 'done' => false ];
		}
		$protected = false;
		foreach ( $leads as $lead ) {
			$context = [ 'form_id' => (int) $lead->form_id, 'event_type' => 'privacy', 'lead_id' => (int) $lead->id ];
			$events = ES_Measurement::events_for_lead( (int) $lead->id );
			if ( is_wp_error( $events ) ) {
				return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [ $events->get_error_message() ], 'done' => false ];
			}
			$protected = $protected || ES_Measurement::policy( $context ) === 'strict' || $events !== [];
		}
		$external = ( class_exists( 'Emblem_Studio_SaaS_Connector' ) && Emblem_Studio_SaaS_Connector::is_connected() )
			|| has_action( 'es_erasure_ready' ) !== false
			|| ( class_exists( 'ES_Purchases' ) && has_action( ES_Purchases::HOOK_READY ) !== false );
		if ( $external && $protected ) {
			return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [ __( 'Connected or external erasure cannot be committed atomically by this measurement API. No capture was erased and no external callback was dispatched.', 'emblem-studio' ) ], 'done' => false, 'atomic_scope' => false ];
		}
		if ( $external ) {
			// Preserve the pre-existing connected legacy workflow. It does not
			// gain the strict API's local atomicity or privacy-completeness claim.
			$result = $this->eraser_locked( $email, false );
			$result['atomic_scope'] = false;
			return $result;
		}
		$tables = [ ES_Install::table( 'leads' ), ES_Install::table( 'events' ), ES_Install::table( 'lead_consents' ) ];
		if ( class_exists( 'ES_Purchases' ) ) { $tables[] = ES_Install::table( 'purchases' ); }
		if ( ! ES_Measurement_Transaction::tables_transactional( $tables ) ) {
			if ( ! $protected ) {
				$result = $this->eraser_locked( $email, false );
				$result['atomic_scope'] = false;
				return $result;
			}
			return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [ __( 'Atomic erasure requires verified InnoDB native tables. No capture was erased.', 'emblem-studio' ) ], 'done' => false ];
		}
		$result = null;
		$ok = ES_Measurement_Transaction::run( function () use ( $email, &$result ) {
			$result = $this->eraser_locked( $email );
			return $result['done'] ? $result : false;
		} );
		return is_array( $ok ) ? $ok : [ 'items_removed' => false, 'items_retained' => true, 'messages' => [ __( 'Erasure could not be completed atomically. The capture was retained for retry.', 'emblem-studio' ) ], 'done' => false ];
	}

	private function eraser_locked( string $email, bool $atomic = true ): array {
		global $wpdb;
		$leads = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ES_Install::table( 'leads' ) . ' WHERE email = %s' . ( $atomic ? ' FOR UPDATE' : '' ), strtolower( trim( $email ) ) ) );
		if ( ! is_array( $leads ) || $wpdb->last_error !== '' ) {
			return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [], 'done' => false ];
		}
		if ( ! $atomic ) {
			// Recheck the actual mutable snapshot, not only the earlier routing
			// read. A newly captured strict lead must never enter legacy dispatch.
			foreach ( $leads as $lead ) {
				$events = ES_Measurement::events_for_lead( (int) $lead->id );
				$context = [ 'form_id' => (int) $lead->form_id, 'event_type' => 'privacy', 'lead_id' => (int) $lead->id ];
				if ( is_wp_error( $events ) || $events !== [] || ES_Measurement::policy( $context ) !== 'legacy' ) {
					return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [ __( 'The mutable capture snapshot requires protected erasure. Legacy external dispatch was refused.', 'emblem-studio' ) ], 'done' => false ];
				}
			}
		}
		$lead_ids = array_map( static fn ( $lead ) => (int) $lead->id, $leads );
		$measurement_retained = false;
		$measurement_removed = 0;
		foreach ( $leads as $lead ) {
			$status = ES_Measurement::association_status( (int) $lead->form_id );
			if ( $status['unassociated'] === null ) {
				return [ 'items_removed' => $measurement_removed > 0, 'items_retained' => true, 'messages' => [ __( 'Measurement completeness could not be read. The capture was retained for retry.', 'emblem-studio' ) ], 'done' => false ];
			}
			$measurement_retained = $measurement_retained || ! $status['complete'];
			$count = $atomic ? ES_Measurement::erase_for_lead( (int) $lead->id ) : 0;
			if ( $count === false ) {
				return [ 'items_removed' => $measurement_removed > 0, 'items_retained' => true, 'messages' => [ __( 'Associated measurement could not be erased. The capture was retained for retry.', 'emblem-studio' ) ], 'done' => false ];
			}
			$measurement_removed += $count;
		}

		// Take this person out of the purchase records BEFORE the leads go, while
		// there is still a lead id to find them by. An order stays with the shop,
		// which has its own rules about keeping it; what goes is the line joining
		// that order to this person.
		if ( class_exists( 'ES_Purchases' ) ) {
			foreach ( $lead_ids as $lead_id ) {
				if ( ES_Purchases::forget_lead( $lead_id, ! $atomic ) === false ) {
					return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [], 'done' => false ];
				}
			}
		}

		// The proof of what they agreed to goes too (SER-1431). A normal lead
		// delete keeps it; a request like this one is the only thing that
		// removes it. Also found by lead id, for records written before the
		// address was stored on them.
		$consents = ES_Consent_Log::erase( $email, $lead_ids );
		if ( $consents === false || ES_Measurement_Transaction::query_failed() ) {
			return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [], 'done' => false ];
		}

		$removed = 0;
		foreach ( $lead_ids as $id ) { $removed += ES_Leads::delete( $id ) ? 1 : 0; }
		if ( $removed !== count( $lead_ids ) ) {
			return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [], 'done' => false ];
		}

		// On a site connected to Emblem Studio the leads live there, and this
		// site holds only a mirror. Erasing the mirror alone would erase nothing.
		$messages = [];
		if ( $measurement_retained ) {
			$messages[] = __( 'Legacy measurement has no deterministic capture association. Those records were not attributed to this person or erased; measurement erasure completeness cannot be confirmed.', 'emblem-studio' );
		}
		if ( class_exists( 'Emblem_Studio_SaaS_Connector' ) && Emblem_Studio_SaaS_Connector::is_connected() ) {
			self::queue_saas_erasure( $email );
			do_action( 'es_erasure_ready' );
			// Said either way. On a connected site the leads are mostly held in
			// Emblem Studio, so "nothing removed here" alone would read as
			// "nothing found", which is not true.
			$messages[] = in_array( strtolower( trim( $email ) ), self::queued_saas_erasures(), true )
				? __( 'Emblem Studio will also erase this person from your connected dashboard the next time this site syncs.', 'emblem-studio' )
				: __( 'Emblem Studio also erased this person from your connected dashboard.', 'emblem-studio' );
		}

		if ( $removed ) {
			/* translators: %d: number of leads */
			$messages[] = sprintf( _n( '%d Emblem Studio lead removed.', '%d Emblem Studio leads removed.', $removed, 'emblem-studio' ), $removed );
		}
		if ( $consents ) {
			/* translators: %d: number of consent records */
			$messages[] = sprintf( _n( '%d Emblem Studio consent record removed.', '%d Emblem Studio consent records removed.', $consents, 'emblem-studio' ), $consents );
		}

		return [
			'items_removed'  => ( $removed + $consents + $measurement_removed ) > 0,
			'items_retained' => $measurement_retained,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	/**
	 * Addresses erased here that Emblem Studio still has to erase (SER-1431).
	 *
	 * Held only until Emblem Studio confirms, then cleared. Kept only while the
	 * site is connected, so an address is never parked here with nobody to
	 * send it to.
	 */
	public const SAAS_ERASE_OPTION = 'emblem_studio_saas_erasures';

	public static function queue_saas_erasure( string $email ): void {
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return;
		}
		$queue = self::queued_saas_erasures();
		if ( ! in_array( $email, $queue, true ) ) {
			$queue[] = $email;
			update_option( self::SAAS_ERASE_OPTION, $queue, false );
		}
	}

	/** @return array<int, string> */
	public static function queued_saas_erasures(): array {
		return array_values( array_filter( array_map( 'strval', (array) get_option( self::SAAS_ERASE_OPTION, [] ) ) ) );
	}

	/** @param array<int, string> $done */
	public static function clear_saas_erasures( array $done ): void {
		$left = array_values( array_diff( self::queued_saas_erasures(), array_map( 'strval', $done ) ) );
		if ( $left === [] ) {
			delete_option( self::SAAS_ERASE_OPTION );
			return;
		}
		update_option( self::SAAS_ERASE_OPTION, $left, false );
	}
}
