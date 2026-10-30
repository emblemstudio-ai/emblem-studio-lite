<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared direct-contact contract for every local FunnelKit transport. */
final class ES_FunnelKit_Upsert {
	/**
	 * The asset's "add new contacts as" choice, from a lead's `contact_status`.
	 * Only the exact word 'unsubscribed' counts. Missing, blank or anything else
	 * is 'subscribed', which is how every asset behaved before the option.
	 *
	 * @param mixed $value
	 */
	public static function contact_status( $value ): string {
		return $value === 'unsubscribed' ? 'unsubscribed' : 'subscribed';
	}

	/**
	 * Create or update one FunnelKit contact for a lead.
	 *
	 * `contact_status` (the asset's choice) only decides how a NEW contact
	 * starts. 'unsubscribed' creates the contact and then puts it on FunnelKit's
	 * unsubscribe list, before any list or tag is added, so a tag-triggered
	 * automation already sees it unsubscribed. A contact that already exists
	 * keeps its status under that choice: a subscriber is never unsubscribed,
	 * and an unsubscribed one is never resubscribed. 'subscribed' is the
	 * original behaviour, where a returning unsubscribed or unverified contact
	 * is resubscribed. Bounced and complaint contacts stay protected either way.
	 */
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

			$add_unsubscribed = self::contact_status( $lead['contact_status'] ?? null ) === 'unsubscribed';
			$needs_unsubscribe = $add_unsubscribed && ! $exists && ! $protected;

			$outcome = $exists ? 'updated' : 'created';
			$needs_resubscribe = ! $add_unsubscribed && $exists && ! $protected && in_array(
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

			// FunnelKit's unsubscribe() needs a saved contact, so it runs after
			// save(). A contact it could not unsubscribe gets no lists or tags,
			// for the same reason as a failed resubscribe: a tag can start an
			// automation that emails someone the owner chose not to email.
			if ( $needs_unsubscribe && $id > 0 ) {
				if ( method_exists( $contact, 'unsubscribe' ) ) {
					$contact->unsubscribe( false );
				}
				$post_status = method_exists( $contact, 'get_display_status' )
					? (int) $contact->get_display_status()
					: 0;
				if ( $post_status !== self::status( 'DISPLAY_STATUS_UNSUBSCRIBED', 2 ) ) {
					return [
						'ok'               => false,
						'contact_id'       => $id,
						'outcome'          => 'failed',
						'protected_status' => null,
						'error'            => 'Contact was created but could not be unsubscribed',
					];
				}
				$outcome = 'created_unsubscribed';
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
					? self::list_value( $value, self::field_type( 'slug', (string) $slug ) )
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
		foreach ( $custom as $key => $value ) {
			if ( is_array( $value ) ) {
				$custom[ $key ] = self::list_value( $value, self::field_type( 'id', (string) $key ) );
			}
		}
		if ( $custom && method_exists( $contact, 'set_fields' ) ) {
			$contact->set_fields( $custom );
			$written = true;
		}
		if ( $written && method_exists( $contact, 'save_fields' ) ) {
			$contact->save_fields();
		}
	}

	/** FunnelKit field types that pick from a list of options (BWFCRM_Fields). */
	private const CHOICE_TYPES = [ 4 /* select */, 5 /* radio */, 6 /* checkbox */ ];

	/**
	 * Several answers for one FunnelKit field (a ticked checkbox group).
	 *
	 * A choice field (checkbox, radio, select) gets a JSON list. FunnelKit's
	 * save_fields() decodes it and keeps every item that matches one of the
	 * field's option labels, case-insensitive. A checkbox field stores them all;
	 * radio and select keep the first match. JSON rather than a comma list,
	 * because FunnelKit splits a plain string on commas and an answer can
	 * contain one.
	 *
	 * Any other field (text, textarea, number) gets the answers joined with
	 * ", ". FunnelKit silently skips an array for those types, and a JSON list
	 * would show up in a text field as `["a","b"]`.
	 *
	 * Unknown type (FunnelKit's field model unavailable): JSON, as before.
	 *
	 * @param array<int|string, mixed> $values
	 */
	private static function list_value( array $values, ?int $type ): string {
		$items = array_values( array_map( 'strval', array_filter( $values, 'is_scalar' ) ) );
		if ( $type !== null && ! in_array( $type, self::CHOICE_TYPES, true ) ) {
			return implode( ', ', $items );
		}
		return (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $items ) : json_encode( $items ) );
	}

	/** A FunnelKit custom field's numeric type, looked up by slug or ID. Null when unknown. */
	private static function field_type( string $by, string $key ): ?int {
		if ( ! class_exists( 'BWFAN_Model_Fields' ) ) {
			return null;
		}
		try {
			$row = $by === 'slug'
				? BWFAN_Model_Fields::get_field_by_slug( $key )
				: BWFAN_Model_Fields::get_field_by_id( (int) $key );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $row ) && isset( $row['type'] ) ? (int) $row['type'] : null;
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
