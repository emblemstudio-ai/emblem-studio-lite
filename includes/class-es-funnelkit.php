<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FunnelKit Automations integration.
 *
 * Two strategies, tried in order:
 *   1. Direct PHP via BWFCRM_Contact (zero-config, same WP install).
 *   2. REST API using a stored key (needs setup in FunnelKit → Settings → API).
 */
class ES_FunnelKit {

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

	/**
	 * Push a lead to FunnelKit.
	 *
	 * @param array $lead {
	 *     @type string $email
	 *     @type string $first_name
	 *     @type string $last_name
	 *     @type string $phone
	 *     @type array  $custom_fields
	 *     @type array  $tags     Tag names; auto-created in FunnelKit if new.
	 *     @type array  $lists    Array of list IDs. (Older $list_id still accepted.)
	 *     @type int    $list_id  Deprecated — use $lists.
	 * }
	 * @return array { ok: bool, contact_id: ?int, error: ?string }
	 */
	public static function push( array $lead ): array {
		if ( empty( $lead['email'] ) || ! is_email( $lead['email'] ) ) {
			return [ 'ok' => false, 'contact_id' => null, 'error' => 'Invalid email' ];
		}

		if ( class_exists( 'BWFCRM_Contact' ) ) {
			$res = self::push_direct( $lead );
			if ( $res['ok'] ) {
				return $res;
			}
			ES_Logger::error( 'FunnelKit direct push failed, trying REST fallback', $res );
		}

		if ( self::has_rest_credentials() ) {
			return self::push_rest( $lead );
		}

		return [ 'ok' => false, 'contact_id' => null, 'error' => $res['error'] ?? 'FunnelKit unavailable' ];
	}

	/**
	 * Return the set of column names present on `wp_bwf_contact`. Cached per request.
	 * Different FunnelKit versions ship different column sets; only send values
	 * whose columns exist or the underlying INSERT will fail.
	 */
	private static function contact_columns(): array {
		static $cols = null;
		if ( $cols !== null ) {
			return $cols;
		}
		global $wpdb;
		$rows = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}bwf_contact`" );
		$cols = is_array( $rows ) ? array_map( 'strtolower', $rows ) : [];
		return $cols;
	}

	private static function column_exists( string $col ): bool {
		return in_array( strtolower( $col ), self::contact_columns(), true );
	}

	private static function push_direct( array $lead ): array {
		$trace = [];
		try {
			$lists = [];
			if ( ! empty( $lead['lists'] ) && is_array( $lead['lists'] ) ) {
				$lists = array_values( array_filter( array_map( 'intval', $lead['lists'] ) ) );
			} elseif ( ! empty( $lead['list_id'] ) ) {
				$lists = [ (int) $lead['list_id'] ];
			}
			$tags = ! empty( $lead['tags'] ) ? array_values( array_filter( (array) $lead['tags'] ) ) : [];
			$trace['lists_input'] = $lists;
			$trace['tags_input']  = $tags;

			// Format lists/tags into the structure FunnelKit's parse_terms_request expects.
			// New term (by name): id=0. Existing term (by id): id=N.
			$lists_formatted = array_map( fn( $id ) => [ 'id' => (int) $id, 'name' => '', 'value' => '' ], $lists );
			$tags_formatted  = array_map( fn( $name ) => [ 'id' => 0, 'name' => (string) $name, 'value' => (string) $name ], $tags );

			// Step 1: create OR load the contact with basic fields only.
			// Pass ONLY direct-column args to the constructor. Slug-based fields
			// (city, postcode, address-1/2, gender, company, dob) are written
			// after construction via set_meta + save.
			// We also gate country/state/timezone on actual column existence —
			// older FunnelKit installs don't have these columns and would fail
			// the INSERT silently.
			$args = [
				'f_name'     => $lead['first_name'] ?? '',
				'l_name'     => $lead['last_name'] ?? '',
				'contact_no' => $lead['phone'] ?? '',
				'status'     => 1,
				'source'     => 'emblem-studio',
			];
			if ( ! empty( $lead['country'] )  && self::column_exists( 'country' ) )  { $args['country']  = $lead['country']; }
			if ( ! empty( $lead['state'] )    && self::column_exists( 'state' ) )    { $args['state']    = $lead['state']; }
			if ( ! empty( $lead['timezone'] ) && self::column_exists( 'timezone' ) ) { $args['timezone'] = $lead['timezone']; }
			$args = array_filter( $args, fn( $v ) => $v !== '' );
			$trace['args_keys']      = array_keys( $args );
			$trace['contact_columns'] = self::contact_columns();

			global $wpdb;
			$wpdb->last_error = ''; // clear so we capture only this push's error

			$contact = new BWFCRM_Contact( $lead['email'], true, $args );

			$already_existed = property_exists( $contact, 'already_exists' ) ? (bool) $contact->already_exists : true;
			$trace['already_existed'] = $already_existed;
			$trace['contact_id_after_ctor'] = is_object( $contact->contact ) && method_exists( $contact->contact, 'get_id' ) ? (int) $contact->contact->get_id() : 0;
			if ( $wpdb->last_error ) {
				$trace['wpdb_last_error'] = $wpdb->last_error;
			}

			// Existing contact — args ignored by the constructor. Update direct
			// columns manually.
			if ( $already_existed && is_object( $contact->contact ) ) {
				$inner   = $contact->contact;
				$changed = false;
				if ( ! empty( $lead['first_name'] ) && method_exists( $inner, 'set_f_name' ) ) { $inner->set_f_name( $lead['first_name'] ); $changed = true; }
				if ( ! empty( $lead['last_name'] )  && method_exists( $inner, 'set_l_name' ) ) { $inner->set_l_name( $lead['last_name'] );  $changed = true; }
				if ( ! empty( $lead['phone'] )      && method_exists( $inner, 'set_contact_no' ) ) { $inner->set_contact_no( $lead['phone'] ); $changed = true; }
				if ( ! empty( $lead['country'] )    && method_exists( $inner, 'set_country' )    && self::column_exists( 'country' ) )  { $inner->set_country( $lead['country'] );   $changed = true; }
				if ( ! empty( $lead['state'] )      && method_exists( $inner, 'set_state' )      && self::column_exists( 'state' ) )    { $inner->set_state( $lead['state'] );       $changed = true; }
				if ( ! empty( $lead['timezone'] )   && method_exists( $inner, 'set_timezone' )   && self::column_exists( 'timezone' ) ) { $inner->set_timezone( $lead['timezone'] ); $changed = true; }
				if ( $changed && method_exists( $inner, 'save' ) ) { $inner->save(); }
			}

			// Custom-field writes via slug. Accepts arbitrary slugs from the
			// form's `field_mapping` config (set in the admin), plus the
			// FK auto-created defaults (city, postcode, etc.). `set_field_by_slug`
			// looks up the field ID for the slug internally and stages the
			// value into $contact->fields; save_fields() persists.
			$slug_values = is_array( $lead['slugs'] ?? null ) ? $lead['slugs'] : [];
			$slugs_written = [];
			if ( method_exists( $contact, 'set_field_by_slug' ) ) {
				foreach ( $slug_values as $slug => $value ) {
					if ( $value === '' || $value === null || $value === [] ) { continue; }
					try {
						$write_value = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
						$ok = $contact->set_field_by_slug( (string) $slug, $write_value );
						$trace[ 'field_set_' . $slug ] = $ok ? 'ok' : 'no-field-by-slug';
						if ( $ok ) { $slugs_written[] = $slug; }
					} catch ( \Throwable $e ) {
						$trace[ 'field_set_' . $slug . '_err' ] = $e->getMessage();
					}
				}
			} else {
				$trace['set_field_by_slug_missing'] = true;
			}
			if ( $slugs_written && method_exists( $contact, 'save_fields' ) ) {
				try { $contact->save_fields(); $trace['fields_saved'] = $slugs_written; }
				catch ( \Throwable $e ) { $trace['save_fields_err'] = $e->getMessage(); }
			}

			// Wrapper add_lists / add_tags with properly-formatted arrays.
			if ( $lists_formatted && method_exists( $contact, 'add_lists' ) ) {
				$r = $contact->add_lists( $lists_formatted );
				$trace['add_lists_result'] = is_wp_error( $r ) ? ( 'wp_error: ' . $r->get_error_message() ) : ( is_array( $r ) ? count( $r ) . ' added' : gettype( $r ) );
			}
			if ( $tags_formatted && method_exists( $contact, 'add_tags' ) ) {
				$r = $contact->add_tags( $tags_formatted );
				$trace['add_tags_result'] = is_wp_error( $r ) ? ( 'wp_error: ' . $r->get_error_message() ) : ( is_array( $r ) ? count( $r ) . ' added' : gettype( $r ) );
			}

			// Re-read current lists/tags from the contact for log verification.
			if ( method_exists( $contact, 'get_lists' ) ) { $trace['lists_after'] = $contact->get_lists(); }
			if ( method_exists( $contact, 'get_tags' ) )  { $trace['tags_after']  = $contact->get_tags(); }

			$id = method_exists( $contact->contact, 'get_id' ) ? (int) $contact->contact->get_id() : 0;
			$trace['final_contact_id'] = $id;

			// Always log the trace — gives us visibility into every push attempt
			// while we're still calibrating new fields. Strip if too noisy later.
			ES_Logger::info( 'FunnelKit push trace', $trace );

			return [ 'ok' => (bool) $id, 'contact_id' => $id ?: null, 'error' => $id ? null : 'No contact ID returned' ];

		} catch ( \Throwable $e ) {
			$trace['exception'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
			$trace['email_hash'] = isset( $lead['email'] ) ? substr( md5( strtolower( $lead['email'] ) ), 0, 12 ) : '';
			ES_Logger::error( 'FunnelKit push failed', $trace );
			return [ 'ok' => false, 'contact_id' => null, 'error' => 'Direct push exception: ' . $e->getMessage() ];
		}
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
			ES_Logger::info( 'FunnelKit REST push OK', [ 'contact_id' => $id ] );
			return [ 'ok' => true, 'contact_id' => $id, 'error' => null ];
		}

		return [
			'ok'         => false,
			'contact_id' => null,
			'error'      => 'REST HTTP ' . $code . ': ' . ( $json['message'] ?? wp_remote_retrieve_body( $res ) ),
		];
	}

	/**
	 * Return available lists for the FK list dropdown in the form edit screen.
	 * Returns [] if FunnelKit isn't available — UI shows "none".
	 */
	public static function get_lists(): array {
		// Strategy 1: BWFCRM_List class with multiple known method names.
		if ( class_exists( 'BWFCRM_List' ) ) {
			foreach ( [ 'get_all_lists', 'get_lists', 'get_all' ] as $m ) {
				if ( method_exists( 'BWFCRM_List', $m ) ) {
					try {
						$rows = call_user_func( [ 'BWFCRM_List', $m ] );
						$norm = self::normalize_lookup( $rows );
						if ( $norm ) { return $norm; }
					} catch ( \Throwable $e ) {
						ES_Logger::error( 'BWFCRM_List::' . $m . ' threw', [ 'message' => $e->getMessage() ] );
					}
				}
			}
		}

		// Strategy 2: unified bwfan_terms table (FunnelKit free uses this for lists + tags).
		global $wpdb;
		$terms = $wpdb->prefix . 'bwfan_terms';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms ) ) === $terms ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$terms`" );
			$id_col   = self::pick_col( $cols, [ 'ID', 'id', 'term_id' ] );
			$name_col = self::pick_col( $cols, [ 'name', 'title' ] );
			$type_col = self::pick_col( $cols, [ 'type', 'term_type', 'taxonomy' ] );
			if ( $id_col && $name_col ) {
				if ( $type_col ) {
					// FunnelKit stores lists with type=2 (numeric).
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

		// Strategy 3: standalone lists table (older / pro layouts).
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
					ES_Logger::info( 'Lists loaded via table probe', [ 'table' => $table, 'count' => is_array( $rows ) ? count( $rows ) : 0 ] );
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

	/**
	 * Return available tags for the FK tag selector. Same shape as get_lists().
	 */
	public static function get_tags(): array {
		global $wpdb;
		$terms = $wpdb->prefix . 'bwfan_terms';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms ) ) === $terms ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$terms`" );
			$id_col   = self::pick_col( $cols, [ 'ID', 'id', 'term_id' ] );
			$name_col = self::pick_col( $cols, [ 'name', 'title' ] );
			$type_col = self::pick_col( $cols, [ 'type', 'term_type', 'taxonomy' ] );
			if ( $id_col && $name_col && $type_col ) {
				// FunnelKit stores tags with type=1 (numeric), lists with type=2.
				// Also accept string variants for safety across versions.
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT `$id_col` as id, `$name_col` as name FROM `$terms` WHERE `$type_col` IN (%s, %s, %s, %s, %s) ORDER BY `$name_col` ASC",
					'1', 'tag', 'tags', 'bwf-tag', 'bwfan-tag'
				) );
				return self::normalize_lookup( (array) $rows );
			}
		}
		return [];
	}

	/**
	 * Return every FunnelKit field that can be a mapping target, organised
	 * into groups for the form-edit UI.
	 *
	 * Result shape:
	 *   [
	 *     [
	 *       'label'  => 'Contact',
	 *       'fields' => [
	 *         [ 'key' => 'col:email',     'label' => 'Email',        'kind' => 'email',  'required' => true ],
	 *         [ 'key' => 'col:f_name',    'label' => 'First Name',   'kind' => 'text' ],
	 *         ...
	 *       ],
	 *     ],
	 *     [ 'label' => 'Address', 'fields' => [...] ],
	 *     [ 'label' => '{user group name}', 'fields' => [...] ],
	 *   ]
	 *
	 * 'key' uses `col:` prefix for direct DB columns on bwf_contact and `slug:`
	 * prefix for custom-field slugs (looked up in bwf_contact_fields at write
	 * time). 'kind' is one of: text, email, tel, number, textarea, select,
	 * select-multi, date, datetime, time (matches the FK custom-field types).
	 */
	public static function get_all_fields_grouped(): array {
		$groups = [];

		// Group 1: built-in contact columns (always present).
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
		$groups[] = $contact_group;

		// Group 2..N: custom fields grouped by their bwfan_field_groups row.
		global $wpdb;
		$fields_tbl = $wpdb->prefix . 'bwfan_fields';
		$groups_tbl = $wpdb->prefix . 'bwfan_field_groups';

		$has_fields_tbl = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fields_tbl ) ) === $fields_tbl;
		if ( ! $has_fields_tbl ) {
			return $groups;
		}

		$rows = $wpdb->get_results(
			"SELECT cf.ID AS field_id, cf.name AS field_name, cf.slug AS field_slug, cf.type AS field_type, cf.gid AS group_id, fg.name AS group_name
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
				'key'   => 'slug:' . (string) $r->field_slug,
				'label' => (string) $r->field_name,
				'kind'  => self::field_type_to_kind( (int) $r->field_type ),
			];
		}
		foreach ( $by_group as $label => $fields ) {
			$groups[] = [ 'label' => $label, 'fields' => $fields ];
		}

		return $groups;
	}

	/**
	 * Map FunnelKit's numeric field type to our normalized "kind" string.
	 * Type constants from BWFCRM_Fields: 1=text, 2=number, 3=textarea,
	 * 4=select, 5=radio, 6=checkbox, 7=date, 8=datetime, 9=time.
	 */
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
