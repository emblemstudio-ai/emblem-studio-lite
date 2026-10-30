<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ES_FunnelKit_Form_Provider extends BWFCRM_Form_Base {
	private string $asset_key = '';
	private array $entry = [];

	public function capture_submission( string $asset_key, array $fields ): array {
		$this->asset_key = $asset_key;
		$this->entry     = $fields;
		$this->find_feeds_and_create_contacts();
		$email = isset( $fields['email'] ) ? (string) $fields['email'] : '';
		if ( $email === '' || ! class_exists( 'BWFCRM_Contact' ) ) {
			return [ 'contact_id' => null ];
		}
		$contact = new BWFCRM_Contact( $email );
		$inner   = is_object( $contact->contact ?? null ) ? $contact->contact : null;
		$id      = $inner && method_exists( $inner, 'get_id' ) ? (int) $inner->get_id() : 0;
		return [ 'contact_id' => $id ?: null ];
	}

	public function capture_async_submission() {
		$data = class_exists( 'BWFAN_Common' ) ? (array) BWFAN_Common::$events_async_data : [];
		$this->capture_submission( (string) ( $data['asset_key'] ?? '' ), (array) ( $data['fields'] ?? [] ) );
	}

	public function filter_feeds_for_current_entry() {
		return array_values( array_filter(
			$this->feeds,
			fn( $feed ) => is_object( $feed )
				&& method_exists( $feed, 'get_data' )
				&& (string) $feed->get_data( 'form_id' ) === $this->asset_key
		) );
	}

	public function prepare_contact_data_from_feed_entry( $mapped_fields ) {
		$out = [];
		foreach ( (array) $mapped_fields as $source => $target ) {
			$source = (string) $source;
			if ( ! array_key_exists( $source, $this->entry ) && substr( $source, -2 ) === '[]' ) {
				$source = substr( $source, 0, -2 );
			}
			if ( ! array_key_exists( $source, $this->entry ) ) {
				continue;
			}
			$key = is_numeric( $target ) ? (int) $target : (string) $target;
			$out[ $key ] = $this->entry[ $source ];
		}
		return $out;
	}

	public function get_form_selection( $args, $return_all_available = false ) {
		$options = [];
		foreach ( ES_FunnelKit_Form_Feed::catalog() as $key => $asset ) {
			$options[ (string) $key ] = $asset['name'];
		}
		return $this->get_step_selection_array( 'Asset', 'form_id', 1, [ 'default' => $options ] );
	}

	public function update_form_selection( $args, $feed_id ) {
		$key = isset( $args['form_id'] ) ? (string) $args['form_id'] : '';
		if ( $key === '' || ! isset( ES_FunnelKit_Form_Feed::catalog()[ $key ] ) || ! class_exists( 'BWFCRM_Form_Feed' ) ) {
			return false;
		}
		$feed = new BWFCRM_Form_Feed( $feed_id );
		if ( ! $feed->is_feed_exists() ) {
			return false;
		}
		$feed->set_source( ES_FunnelKit_Form_Feed::SOURCE );
		$feed->set_data( 'form_id', $key );
		return (bool) $feed->save( true );
	}
	public function get_total_selection_steps() { return 1; }
	public function get_form_fields( $feed ) {
		$key     = is_object( $feed ) && method_exists( $feed, 'get_data' ) ? (string) $feed->get_data( 'form_id' ) : '';
		$catalog = ES_FunnelKit_Form_Feed::catalog();
		return isset( $catalog[ $key ] ) ? $catalog[ $key ]['fields'] : [];
	}
	public function get_meta() { return [ 'form_selection_fields' => [ 'form_id' => 'Asset' ] ]; }
	public function get_source() { return ES_FunnelKit_Form_Feed::SOURCE; }
	public function get_form_link( $feed ) {
		$key     = is_object( $feed ) && method_exists( $feed, 'get_data' ) ? (string) $feed->get_data( 'form_id' ) : '';
		$catalog = ES_FunnelKit_Form_Feed::catalog();
		return isset( $catalog[ $key ] ) ? $catalog[ $key ]['edit_url'] : '';
	}
}
