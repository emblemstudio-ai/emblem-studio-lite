<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_FunnelKit {

	public const OPT_API_KEY    = 'emblem_studio_fk_api_key';
	public const OPT_API_SECRET = 'emblem_studio_fk_api_secret';
	public const OPT_API_BASE   = 'emblem_studio_fk_api_base';

	public static function is_active(): bool {
		return class_exists( 'BWFAN_Core' ) || class_exists( 'BWFCRM_Contact' ) || self::has_rest_credentials();
	}

	public static function has_rest_credentials(): bool {
		return get_option( self::OPT_API_KEY ) && get_option( self::OPT_API_SECRET );
	}

	public static function detection_status(): array {
		return [
			'fk_plugin_active'  => class_exists( 'BWFAN_Core' ),
			'crm_class_present' => class_exists( 'BWFCRM_Contact' ),
			'rest_configured'   => self::has_rest_credentials(),
		];
	}

	public static function push( array $lead ): array {
		if ( empty( $lead['email'] ) || ! is_email( $lead['email'] ) ) {
			return [ 'ok' => false, 'contact_id' => null, 'error' => 'Invalid email' ];
		}

		if ( class_exists( 'BWFCRM_Contact' ) ) {
			$res = self::push_direct( $lead );
			if ( $res['ok'] ) {
				return $res;
			}
			Emblem_Studio_Logger::error( 'FunnelKit direct push failed, trying REST fallback', $res );
		}

		if ( Emblem_Studio_FunnelKit_Upsert::contact_status( $lead['contact_status'] ?? null ) === 'unsubscribed' ) {
			return $res ?? [ 'ok' => false, 'contact_id' => null, 'error' => 'FunnelKit is not on this site, so the contact cannot be added as unsubscribed' ];
		}

		if ( self::has_rest_credentials() ) {
			return self::push_rest( $lead );
		}

		return [ 'ok' => false, 'contact_id' => null, 'error' => $res['error'] ?? 'FunnelKit unavailable' ];
	}

	private static function push_direct( array $lead ): array {
		return Emblem_Studio_FunnelKit_Upsert::run( $lead );
	}

	private static function push_rest( array $lead ): array {
		$base   = rtrim( (string) get_option( self::OPT_API_BASE, home_url() ), '/' );
		$key    = get_option( self::OPT_API_KEY );
		$secret = get_option( self::OPT_API_SECRET );
		$url    = $base . '/wp-json/autonami-app/v1/contacts/';

		$body = [
			'email'      => $lead['email'],
			'first_name' => $lead['first_name'] ?? '',
			'last_name'  => $lead['last_name'] ?? '',
			'contact_no' => $lead['phone'] ?? '',
		];
		if ( ! empty( $lead['tags'] ) )    { $body['tags']    = (array) $lead['tags']; }
		if ( ! empty( $lead['list_id'] ) ) { $body['lists']   = [ (int) $lead['list_id'] ]; }
		if ( ! empty( $lead['custom_fields'] ) ) { $body['meta'] = $lead['custom_fields']; }

		$res = wp_remote_post( $url, [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $key . ':' . $secret ),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'contact_id' => null, 'error' => 'REST error: ' . $res->get_error_message() ];
		}
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code >= 200 && $code < 300 ) {
			$id = isset( $json['id'] ) ? (int) $json['id'] : ( $json['contact_id'] ?? null );
			Emblem_Studio_Logger::info( 'FunnelKit REST push OK', [ 'contact_id' => $id ] );
			return [ 'ok' => true, 'contact_id' => $id, 'error' => null ];
		}

		return [
			'ok'         => false,
			'contact_id' => null,
			'error'      => 'REST HTTP ' . $code . ': ' . ( $json['message'] ?? wp_remote_retrieve_body( $res ) ),
		];
	}

	public static function get_lists(): array {
		static $memo = null;
		return $memo ??= self::load_lists();
	}

	private static function load_lists(): array {
		if ( class_exists( 'BWFCRM_List' ) ) {
			foreach ( [ 'get_all_lists', 'get_lists', 'get_all' ] as $m ) {
				if ( method_exists( 'BWFCRM_List', $m ) ) {
					try {
						$rows = call_user_func( [ 'BWFCRM_List', $m ] );
						$norm = self::normalize_lookup( $rows );
						if ( $norm ) { return $norm; }
					} catch ( \Throwable $e ) {
						Emblem_Studio_Logger::error( 'BWFCRM_List::' . $m . ' threw', [ 'message' => $e->getMessage() ] );
					}
				}
			}
		}

		global $wpdb;
		$terms = $wpdb->prefix . 'bwfan_terms';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms ) ) === $terms ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$terms`" );
			$id_col   = self::pick_col( $cols, [ 'ID', 'id', 'term_id' ] );
			$name_col = self::pick_col( $cols, [ 'name', 'title' ] );
			$type_col = self::pick_col( $cols, [ 'type', 'term_type', 'taxonomy' ] );
			if ( $id_col && $name_col ) {
				if ( $type_col ) {
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT `$id_col` as id, `$name_col` as name FROM `$terms` WHERE `$type_col` IN (%s, %s, %s, %s, %s) ORDER BY `$name_col` ASC",
						'2', 'list', 'lists', 'bwf-list', 'bwfan-list'
					) );
				} else {
					$rows = $wpdb->get_results( "SELECT `$id_col` as id, `$name_col` as name FROM `$terms` ORDER BY `$name_col` ASC" );
				}
				if ( $rows ) {
					return self::normalize_lookup( $rows );
				}
			}
		}

		$candidates = [
			$wpdb->prefix . 'bwf_lists',
			$wpdb->prefix . 'bwfan_lists',
			$wpdb->prefix . 'autonami_lists',
		];
		foreach ( $candidates as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" );
				$id_col   = self::pick_col( $cols, [ 'ID', 'id', 'list_id' ] );
				$name_col = self::pick_col( $cols, [ 'name', 'title', 'list_name' ] );
				if ( $id_col && $name_col ) {
					$rows = $wpdb->get_results( "SELECT `$id_col` as id, `$name_col` as name FROM `$table` ORDER BY `$name_col` ASC" );
					Emblem_Studio_Logger::info( 'Lists loaded via table probe', [ 'table' => $table, 'count' => is_array( $rows ) ? count( $rows ) : 0 ] );
					return self::normalize_lookup( $rows );
				}
			}
		}

		return [];
	}

	private static function pick_col( array $cols, array $candidates ): ?string {
		$lower = array_map( 'strtolower', $cols );
		foreach ( $candidates as $c ) {
			$i = array_search( strtolower( $c ), $lower, true );
			if ( $i !== false ) {
				return $cols[ $i ];
			}
		}
		return null;
	}

	public static function get_tags(): array {
		static $memo = null;
		return $memo ??= self::load_tags();
	}

	private static function load_tags(): array {
		global $wpdb;
		$terms = $wpdb->prefix . 'bwfan_terms';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms ) ) === $terms ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$terms`" );
			$id_col   = self::pick_col( $cols, [ 'ID', 'id', 'term_id' ] );
			$name_col = self::pick_col( $cols, [ 'name', 'title' ] );
			$type_col = self::pick_col( $cols, [ 'type', 'term_type', 'taxonomy' ] );
			if ( $id_col && $name_col && $type_col ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT `$id_col` as id, `$name_col` as name FROM `$terms` WHERE `$type_col` IN (%s, %s, %s, %s, %s) ORDER BY `$name_col` ASC",
					'1', 'tag', 'tags', 'bwf-tag', 'bwfan-tag'
				) );
				return self::normalize_lookup( (array) $rows );
			}
		}
		return [];
	}

	public static function get_all_fields_grouped(): array {
		$groups = [];

		$contact_group = [
			'label'  => __( 'Contact', 'emblem-studio' ),
			'fields' => [
				[ 'key' => 'col:email',      'label' => __( 'Email', 'emblem-studio' ),      'kind' => 'email',  'required' => true ],
				[ 'key' => 'col:f_name',     'label' => __( 'First Name', 'emblem-studio' ), 'kind' => 'text' ],
				[ 'key' => 'col:l_name',     'label' => __( 'Last Name', 'emblem-studio' ),  'kind' => 'text' ],
				[ 'key' => 'col:contact_no', 'label' => __( 'Phone', 'emblem-studio' ),      'kind' => 'tel' ],
				[ 'key' => 'col:country',    'label' => __( 'Country (ISO code)', 'emblem-studio' ), 'kind' => 'text' ],
				[ 'key' => 'col:state',      'label' => __( 'State', 'emblem-studio' ),      'kind' => 'text' ],
				[ 'key' => 'col:timezone',   'label' => __( 'Timezone', 'emblem-studio' ),   'kind' => 'text' ],
			],
		];
		$contact_group['fields'] = array_map(
			static fn( array $field ): array => $field + [ 'options' => [] ],
			$contact_group['fields']
		);
		$groups[] = $contact_group;

		global $wpdb;
		$fields_tbl = $wpdb->prefix . 'bwfan_fields';
		$groups_tbl = $wpdb->prefix . 'bwfan_field_groups';

		$has_fields_tbl = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fields_tbl ) ) === $fields_tbl;
		if ( ! $has_fields_tbl ) {
			return $groups;
		}

		$rows = $wpdb->get_results(
			"SELECT cf.ID AS field_id, cf.name AS field_name, cf.slug AS field_slug, cf.type AS field_type, cf.meta AS field_meta, cf.gid AS group_id, fg.name AS group_name
			FROM $fields_tbl AS cf
			LEFT JOIN $groups_tbl AS fg ON cf.gid = fg.ID
			ORDER BY COALESCE(fg.name, 'zzz'), cf.name ASC"
		);
		if ( ! $rows ) {
			return $groups;
		}

		$by_group = [];
		foreach ( $rows as $r ) {
			$group_label = $r->group_name ? (string) $r->group_name : __( 'Other custom fields', 'emblem-studio' );
			$by_group[ $group_label ][] = [
				'key'     => 'slug:' . (string) $r->field_slug,
				'label'   => (string) $r->field_name,
				'kind'    => self::field_type_to_kind( (int) $r->field_type ),
				'options' => Emblem_Studio_Field_Matcher::funnelkit_options( (int) $r->field_type, (string) ( $r->field_meta ?? '' ) ),
			];
		}
		foreach ( $by_group as $label => $fields ) {
			$groups[] = [ 'label' => $label, 'fields' => $fields ];
		}

		return $groups;
	}

	private static function field_type_to_kind( int $type ): string {
		switch ( $type ) {
			case 1:  return 'text';
			case 2:  return 'number';
			case 3:  return 'textarea';
			case 4:  return 'select';
			case 5:  return 'radio';
			case 6:  return 'checkbox-multi';
			case 7:  return 'date';
			case 8:  return 'datetime';
			case 9:  return 'time';
			default: return 'text';
		}
	}

	private static function normalize_lookup( $rows ): array {
		$out = [];
		foreach ( (array) $rows as $r ) {
			if ( is_object( $r ) ) {
				$id   = $r->id ?? $r->ID ?? null;
				$name = $r->name ?? $r->title ?? null;
			} else {
				$id   = $r['id'] ?? $r['ID'] ?? null;
				$name = $r['name'] ?? $r['title'] ?? null;
			}
			if ( $id && $name ) {
				$out[] = [ 'id' => (int) $id, 'name' => (string) $name ];
			}
		}
		return $out;
	}
}
