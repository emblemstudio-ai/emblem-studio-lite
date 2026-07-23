<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared direct-contact contract for every local FunnelKit transport. */
final class ES_FunnelKit_Upsert {
	public static function run( array $lead ): array {
		try {
			$args = array_filter(
				[
					'f_name'     => $lead['first_name'] ?? '',
					'l_name'     => $lead['last_name'] ?? '',
					'contact_no' => $lead['phone'] ?? '',
					'status'     => 1,
					'source'     => 'emblem-studio',
				],
				static fn( $value ) => $value !== ''
			);

			$contact = new BWFCRM_Contact( (string) $lead['email'], true, $args );
			$inner   = is_object( $contact->contact ?? null ) ? $contact->contact : null;
			$exists  = property_exists( $contact, 'already_exists' ) && (bool) $contact->already_exists;
			$status  = method_exists( $contact, 'get_display_status' )
				? (int) $contact->get_display_status()
				: self::status( 'DISPLAY_STATUS_SUBSCRIBED', 1 );

			self::update_profile( $inner, $lead );
			self::update_fields( $contact, $lead );

			$protected = in_array(
				$status,
				[
					self::status( 'DISPLAY_STATUS_BOUNCED', 4 ),
					self::status( 'DISPLAY_STATUS_SOFT_BOUNCED', 5 ),
					self::status( 'DISPLAY_STATUS_COMPLAINT', 6 ),
				],
				true
			);

			$outcome = $exists ? 'updated' : 'created';
			$needs_resubscribe = $exists && ! $protected && in_array(
				$status,
				[
					self::status( 'DISPLAY_STATUS_UNSUBSCRIBED', 2 ),
					self::status( 'DISPLAY_STATUS_UNVERIFIED', 3 ),
				],
				true
			);
			if ( $needs_resubscribe ) {
				if ( method_exists( $contact, 'resubscribe' ) ) {
					$contact->resubscribe( false );
				}
				$outcome = 'resubscribed';
			}

			if ( $inner && method_exists( $inner, 'save' ) ) {
				$inner->save();
			}
			if ( method_exists( $contact, 'save' ) ) {
				$contact->save();
			}

			$id = self::contact_id( $contact, $inner );
			if ( $needs_resubscribe ) {
				$post_status = method_exists( $contact, 'get_display_status' )
					? (int) $contact->get_display_status()
					: 0;
				if ( $post_status !== self::status( 'DISPLAY_STATUS_SUBSCRIBED', 1 ) ) {
					return [
						'ok'               => false,
						'contact_id'       => $id ?: null,
						'outcome'          => 'failed',
						'protected_status' => null,
						'error'            => 'Contact remained unsubscribed after the resubscribe attempt',
					];
				}
			}

			if ( ! $protected ) {
				self::apply_terms( $contact, $lead );
			} else {
				$outcome = 'protected';
			}

			return [
				'ok'               => $id > 0,
				'contact_id'       => $id ?: null,
				'outcome'          => $outcome,
				'protected_status' => $protected ? $status : null,
				'error'            => $id > 0 ? null : 'No contact ID returned',
			];
		} catch ( \Throwable $e ) {
			return [
				'ok'               => false,
				'contact_id'       => null,
				'outcome'          => 'failed',
				'protected_status' => null,
				'error'            => 'Direct push exception: ' . $e->getMessage(),
			];
		}
	}

	private static function status( string $property, int $fallback ): int {
		return property_exists( 'BWFCRM_Contact', $property )
			? (int) BWFCRM_Contact::${$property}
			: $fallback;
	}

	private static function update_profile( $inner, array $lead ): void {
		if ( ! is_object( $inner ) ) {
			return;
		}
		$fields = [
			'first_name' => 'set_f_name',
			'last_name'  => 'set_l_name',
			'phone'      => 'set_contact_no',
			'country'    => 'set_country',
			'state'      => 'set_state',
			'timezone'   => 'set_timezone',
		];
		foreach ( $fields as $key => $setter ) {
			$value = $lead[ $key ] ?? '';
			if ( $value !== '' && method_exists( $inner, $setter ) ) {
				$inner->{$setter}( (string) $value );
			}
		}
	}

	private static function update_fields( $contact, array $lead ): void {
		$values = is_array( $lead['slugs'] ?? null ) ? $lead['slugs'] : [];
		$written = false;
		if ( method_exists( $contact, 'set_field_by_slug' ) ) {
			foreach ( $values as $slug => $value ) {
				if ( $value === '' || $value === null || $value === [] ) {
					continue;
				}
				$encoded = is_array( $value )
					? ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ) )
					: (string) $value;
				$written = $contact->set_field_by_slug( (string) $slug, (string) $encoded ) || $written;
			}
		}

		$custom = is_array( $lead['custom_fields'] ?? null ) ? $lead['custom_fields'] : [];
		foreach ( is_array( $lead['custom'] ?? null ) ? $lead['custom'] : [] as $key => $value ) {
			$custom[ $key ] = $value;
		}
		$custom = array_filter(
			$custom,
			static fn( $value, $key ) => is_numeric( $key ) && $value !== '' && $value !== null && $value !== [],
			ARRAY_FILTER_USE_BOTH
		);
		if ( $custom && method_exists( $contact, 'set_fields' ) ) {
			$contact->set_fields( $custom );
			$written = true;
		}
		if ( $written && method_exists( $contact, 'save_fields' ) ) {
			$contact->save_fields();
		}
	}

	private static function apply_terms( $contact, array $lead ): void {
		$lists = ! empty( $lead['lists'] ) && is_array( $lead['lists'] )
			? array_values( array_filter( array_map( 'intval', $lead['lists'] ) ) )
			: ( ! empty( $lead['list_id'] ) ? [ (int) $lead['list_id'] ] : [] );
		$tags = array_values( array_filter( array_map( 'strval', (array) ( $lead['tags'] ?? [] ) ) ) );

		if ( $lists && method_exists( $contact, 'add_lists' ) ) {
			$contact->add_lists( array_map(
				static fn( int $id ) => [ 'id' => $id, 'name' => '', 'value' => '' ],
				$lists
			) );
		}
		if ( $tags && method_exists( $contact, 'add_tags' ) ) {
			$contact->add_tags( array_map(
				static fn( string $name ) => [ 'id' => 0, 'name' => $name, 'value' => $name ],
				$tags
			) );
		}
	}

	private static function contact_id( $contact, $inner ): int {
		if ( is_object( $inner ) && method_exists( $inner, 'get_id' ) ) {
			return (int) $inner->get_id();
		}
		return method_exists( $contact, 'get_id' ) ? (int) $contact->get_id() : 0;
	}
}
