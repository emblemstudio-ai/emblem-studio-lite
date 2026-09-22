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
		$rows = ES_Leads::find_by_email( $email );
		$data = [];

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

			// What they bought afterwards, where the shop told us (SER-1346). It is
			// a fact about this person, so it belongs in their own copy of the file.
			if ( class_exists( 'ES_Purchases' ) ) {
				foreach ( ES_Purchases::for_lead( (int) $r->id ) as $i => $purchase ) {
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
		}

		return [ 'data' => $data, 'done' => true ];
	}

	public function eraser( string $email, int $page = 1 ): array {
		$lead_ids = array_map( static fn ( $lead ) => (int) $lead->id, ES_Leads::find_by_email( $email ) );

		// Take this person out of the purchase records BEFORE the leads go, while
		// there is still a lead id to find them by. An order stays with the shop,
		// which has its own rules about keeping it; what goes is the line joining
		// that order to this person.
		if ( class_exists( 'ES_Purchases' ) ) {
			foreach ( $lead_ids as $lead_id ) {
				ES_Purchases::forget_lead( $lead_id );
			}
		}

		// The proof of what they agreed to goes too (SER-1431). A normal lead
		// delete keeps it; a request like this one is the only thing that
		// removes it. Also found by lead id, for records written before the
		// address was stored on them.
		$consents = ES_Consent_Log::erase( $email, $lead_ids );

		$removed = ES_Leads::delete_by_email( $email );

		// On a site connected to Emblem Studio the leads live there, and this
		// site holds only a mirror. Erasing the mirror alone would erase nothing.
		$messages = [];
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
			'items_removed'  => ( $removed + $consents ) > 0,
			'items_retained' => false,
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
