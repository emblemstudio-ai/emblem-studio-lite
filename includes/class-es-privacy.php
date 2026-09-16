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
		// Take this person out of the purchase records BEFORE the leads go, while
		// there is still a lead id to find them by. An order stays with the shop,
		// which has its own rules about keeping it; what goes is the line joining
		// that order to this person.
		if ( class_exists( 'ES_Purchases' ) ) {
			foreach ( ES_Leads::find_by_email( $email ) as $lead ) {
				ES_Purchases::forget_lead( (int) $lead->id );
			}
		}
		$removed = ES_Leads::delete_by_email( $email );
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed
				? [ sprintf( _n( '%d Emblem Studio lead removed.', '%d Emblem Studio leads removed.', $removed, 'emblem-studio' ), $removed ) ]
				: [],
			'done'           => true,
		];
	}
}
