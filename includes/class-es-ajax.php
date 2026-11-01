<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Ajax {

	private const SUBMIT_WINDOW       = 60;
	private const SUBMIT_MAX          = 8;
	private const EVENT_WINDOW        = 60;
	private const EVENT_MAX           = 120;
	private const CLICK_WINDOW        = 60;
	private const CLICK_MAX           = 60;
	private const BOT_WINDOW          = 60;
	private const BOT_MAX             = 10;
	private const MIN_HUMAN_SUBMIT_MS = 1200;
	public const EMAIL_SHAPE = '/^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)*\.[A-Za-z](?:[A-Za-z0-9-]*[A-Za-z0-9])$/';

	public static function is_deliverable_email( string $email ): bool {
		$email = trim( $email );
		return $email !== '' && is_email( $email ) && (bool) preg_match( self::EMAIL_SHAPE, $email );
	}

	public function __construct() {
		add_action( 'wp_ajax_es_page_view',           [ $this, 'page_view' ] );
		add_action( 'wp_ajax_nopriv_es_page_view',    [ $this, 'page_view' ] );
		add_action( 'wp_ajax_es_view',                [ $this, 'view' ] );
		add_action( 'wp_ajax_nopriv_es_view',         [ $this, 'view' ] );
		add_action( 'wp_ajax_es_trigger_view',        [ $this, 'trigger_view' ] );
		add_action( 'wp_ajax_nopriv_es_trigger_view', [ $this, 'trigger_view' ] );
		add_action( 'wp_ajax_es_click',               [ $this, 'click' ] );
		add_action( 'wp_ajax_nopriv_es_click',        [ $this, 'click' ] );
		add_action( 'wp_ajax_es_engage',              [ $this, 'engage' ] );
		add_action( 'wp_ajax_nopriv_es_engage',       [ $this, 'engage' ] );
		add_action( 'wp_ajax_es_dropoff',             [ $this, 'dropoff' ] );
		add_action( 'wp_ajax_nopriv_es_dropoff',      [ $this, 'dropoff' ] );
		add_action( 'wp_ajax_es_stamp',               [ $this, 'stamp' ] );
		add_action( 'wp_ajax_nopriv_es_stamp',        [ $this, 'stamp' ] );
		add_action( 'wp_ajax_es_submit',              [ $this, 'submit' ] );
		add_action( 'wp_ajax_nopriv_es_submit',       [ $this, 'submit' ] );
		add_action( 'wp_ajax_es_test_webhook',        [ $this, 'test_webhook' ] );
		add_action( 'wp_ajax_es_preview_payload',     [ $this, 'preview_payload' ] );
	}

	public function preview_payload(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( 'es_preview_payload', 'nonce' );

		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$form = $id ? ES_Form_Repo::find( $id ) : null;
		if ( ! $form ) {
			wp_send_json_error( [ 'message' => 'Not found' ], 404 );
		}

		$payload = json_decode( ( new ES_Shortcode() )->build_payload( $form ), true );
		wp_send_json_success( [
			'html' => (string) ( $payload['html'] ?? '' ),
			'css'  => (string) ( $payload['css'] ?? '' ),
			'shadow' => ! empty( $form->use_shadow_dom ),
		] );
	}

	public function page_view(): void {
		$this->record_event( 'page_view' );
	}

	public function view(): void {
		$this->record_event( 'view' );
	}

	public function trigger_view(): void {
		$this->record_event( 'trigger_view' );
	}

	public function engage(): void {
		$this->record_event( 'engage' );
	}

	public function stamp(): void {
		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( ! $form_id || $this->bucket_exceeded( 'stamp', 60, 60 ) ) {
			wp_send_json_success( [ 'stamp' => '' ] );
		}
		wp_send_json_success( [ 'stamp' => ES_Form_Stamp::issue( (string) $form_id ) ] );
	}

	public function dropoff(): void {
		$this->record_event( 'dropoff' );
	}

	public function click(): void {
		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( ! $form_id ) {
			wp_send_json_error( [ 'message' => 'Missing form_id' ], 400 );
		}

		$shell        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
		$valid_shells = ES_Form_Repo::valid_types();
		if ( ! in_array( $shell, $valid_shells, true ) ) {
			$shell = 'inline';
		}

		if ( ES_Event_Recorder::is_excluded_ip( $this->client_ip() ) ) {
			wp_send_json_success();
		}

		if ( ES_Event_Recorder::filter_bots_enabled() && ES_Event_Recorder::is_bot_request() ) {
			$this->record_bot_hit( $form_id, $shell );
			wp_send_json_success();
		}
		if ( $this->maybe_record_datacenter( $form_id, $shell ) ) {
			wp_send_json_success();
		}

		$variant     = $this->ab_slot( $form_id );
		$bucket_part = $variant ? '_v' . $variant : '';
		if ( $this->bucket_exceeded( 'click_' . $shell . '_' . $form_id . $bucket_part, self::CLICK_WINDOW, self::CLICK_MAX ) ) {
			wp_send_json_success();
		}

		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form || ! ES_Form_Repo::is_currently_active( $form ) ) {
			wp_send_json_success();
		}
		if ( empty( $form->track_clicks ) ) {
			wp_send_json_success();
		}

		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target'] ) ) : '';
		$target = preg_replace( '/\s+/', ' ', trim( $target ) );
		if ( $target === '' ) {
			$target = '__untagged__';
		}

		$post_id = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		$recorded = ES_Event_Recorder::record( $form_id, 'click', $post_id, $shell, $target, $variant );

		if ( $recorded && class_exists( 'ES_AB' ) && $target !== '__close__' && $target !== '__backdrop__' ) {
			$running = ES_AB::running_test_for_form( $form_id );
			if ( $running && isset( $running->primary_metric ) && $running->primary_metric === 'clicks' ) {
				ES_AB::auto_winner_check( $form_id );
			}
		}

		wp_send_json_success();
	}

	private function record_event( string $event_type ): void {
		if ( ES_Event_Recorder::is_excluded_ip( $this->client_ip() ) ) {
			wp_send_json_success();
		}

		if ( ES_Event_Recorder::filter_bots_enabled() && ES_Event_Recorder::is_bot_request() ) {
			$bot_form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
			$bot_shell   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
			if ( ! in_array( $bot_shell, ES_Form_Repo::valid_types(), true ) ) {
				$bot_shell = 'inline';
			}
			if ( $bot_form_id ) {
				$this->record_bot_hit( $bot_form_id, $bot_shell );
			}
			wp_send_json_success();
		}
		$dc_form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$dc_shell   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
		if ( ! in_array( $dc_shell, ES_Form_Repo::valid_types(), true ) ) {
			$dc_shell = 'inline';
		}
		if ( $dc_form_id && $this->maybe_record_datacenter( $dc_form_id, $dc_shell ) ) {
			wp_send_json_success();
		}

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( ES_Measurement::policy( [ 'form_id' => $form_id, 'event_type' => $event_type, 'lead_id' => null ] ) === 'legacy' ) {
			ES_Display_Rules::ensure_visitor_cookie();
		}

		$post_id = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		$shell   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
		$valid_shells = ES_Form_Repo::valid_types();
		if ( ! in_array( $shell, $valid_shells, true ) ) {
			$shell = 'inline';
		}

		if ( ! $form_id ) {
			wp_send_json_error( [ 'message' => 'Missing form_id' ], 400 );
		}
		$variant     = $this->ab_slot( $form_id );
		$bucket_part = $variant ? '_v' . $variant : '';
		if ( $this->bucket_exceeded( 'ev_' . $event_type . '_' . $shell . '_' . $form_id . $bucket_part, self::EVENT_WINDOW, self::EVENT_MAX ) ) {
			wp_send_json_success();
		}
		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form || ! ES_Form_Repo::is_currently_active( $form ) ) {
			wp_send_json_success();
		}
		$step = $event_type === 'dropoff' && isset( $_POST['step'] ) ? wp_unslash( (string) $_POST['step'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		ES_Event_Recorder::record( $form_id, $event_type, $post_id, $shell, null, $variant, null, $step );
		wp_send_json_success();
	}

	public static function configured_terms( object $form ): array {
		$tags = [];
		if ( ! empty( $form->funnelkit_tags ) ) {
			$decoded = json_decode( (string) $form->funnelkit_tags, true );
			$tags    = is_array( $decoded ) ? array_values( array_unique( array_map( 'strval', array_filter( $decoded, 'is_scalar' ) ) ) ) : [];
		}

		$lists = [];
		if ( ! empty( $form->funnelkit_lists ) ) {
			$decoded = json_decode( (string) $form->funnelkit_lists, true );
			$lists   = is_array( $decoded ) ? array_map( 'intval', $decoded ) : [];
		}
		if ( ! $lists && ! empty( $form->funnelkit_list_id ) ) {
			$lists = [ (int) $form->funnelkit_list_id ];
		}

		return [ 'tags' => $tags, 'lists' => array_values( $lists ) ];
	}

	public function submit(): void {
		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( ES_Measurement::policy( [ 'form_id' => $form_id, 'event_type' => 'conversion', 'lead_id' => null ] ) === 'legacy' ) {
			ES_Display_Rules::ensure_visitor_cookie();
		}

		if ( ! $form_id ) {
			wp_send_json_error( [ 'message' => 'Missing form_id' ], 400 );
		}

		$is_preview = ! empty( $_POST['es_preview'] ) && current_user_can( 'edit_posts' );

		$trap_filled = ! empty( $_POST['esf_hp'] );

		$stamp      = isset( $_POST['es_stamp'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['es_stamp'] ) ) : '';
		$stamped_at = ES_Form_Stamp::issued_at( $stamp, (string) $form_id );
		if ( $stamped_at !== null && $trap_filled && ! ES_Form_Stamp::claim( $stamp ) ) {
			$stamped_at = null;
		}
		$elapsed_ms  = $stamped_at !== null ? (int) round( microtime( true ) * 1000 ) - $stamped_at : null;
		$looks_human = $elapsed_ms !== null && $elapsed_ms >= self::MIN_HUMAN_SUBMIT_MS;

		$ua        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$ua_bot    = ES_Event_Recorder::matched_bot_label( $ua );
		$dc        = ES_Event_Recorder::detect_datacenter_enabled()
			? ES_Event_Recorder::datacenter_provider( $this->client_ip() )
			: '';
		$bot_label = $ua_bot !== '' ? $ua_bot : $dc;
		$named_bot = $bot_label !== '';

		$ran_our_script = $stamped_at !== null;
		$hp_filled      = $trap_filled
			&& ! $looks_human
			&& ( ! $ran_our_script || $named_bot );

		$bot_signals = null;
		if ( $trap_filled ) {
			$bot_signals = [
				'hp_filled'   => true,
				'ts_received' => $stamped_at !== null,
				'elapsed_ms'  => $elapsed_ms,
				'timing'      => $stamped_at !== null ? 'stamp' : null,
				'ua_bot'      => $ua_bot !== '' ? $ua_bot : null,
				'datacenter'  => $dc !== '' ? $dc : null,
			];
		}

		if ( $hp_filled ) {
			if ( ! $this->bot_row_allowed( 'hp', $form_id ) ) {
				wp_send_json_success( [ 'mode' => 'inline_state' ] );
			}
			ES_Logger::info( 'Honeypot triggered', [ 'form_id' => $form_id ] );
			$hp_shell = isset( $_POST['type'] ) ? sanitize_key( (string) $_POST['type'] ) : 'inline';
			if ( ! in_array( $hp_shell, ES_Form_Repo::valid_types(), true ) ) {
				$hp_shell = 'inline';
			}
			ES_Event_Recorder::record( $form_id, 'honeypot_blocked', null, $hp_shell, $bot_label !== '' ? $bot_label : null );
			$this->save_bot_lead( $form_id, $bot_signals );
			wp_send_json_success( [ 'mode' => 'inline_state' ] );
		}

		if ( $this->bucket_exceeded( 'sub_' . $form_id, self::SUBMIT_WINDOW, self::SUBMIT_MAX ) ) {
			if ( ! $this->bucket_exceeded( 'log_sub_' . $form_id, MINUTE_IN_SECONDS, 1 ) ) {
				ES_Logger::error( 'es_submit aborted: rate-limited', [ 'ip' => $this->client_ip(), 'form_id' => $form_id ] );
			}
			wp_send_json_error( [ 'message' => 'Too many submissions, please wait a moment.' ], 429 );
		}

		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form || ! ES_Form_Repo::is_currently_active( $form ) ) {
			wp_send_json_error( [ 'message' => 'Form not available' ], 404 );
		}

		$fields = $this->collect_fields();

		$geo = [];
		foreach ( $fields as $k => $v ) {
			if ( is_string( $k ) && strpos( $k, '__es_geo_' ) === 0 ) {
				$geo[ substr( $k, 9 ) ] = is_array( $v ) ? '' : (string) $v;
				unset( $fields[ $k ] );
			}
		}

		$mapping = [];
		if ( ! empty( $form->field_mapping ) ) {
			$decoded = json_decode( (string) $form->field_mapping, true );
			if ( is_array( $decoded ) ) {
				$mapping = $decoded;
			}
		}
		$lookup_field = function ( string $name ) use ( $fields ) {
			return self::posted_value( $fields, $name ) ?? '';
		};
		$field_kinds = [];
		foreach ( ES_Form_Parser::extract_fields( (string) $form->html ) as $f ) {
			$field_kinds[ $f['name'] ] = $f['kind'];
		}
		$posted_names = array_keys( $fields );
		$value_for = function ( string $fk_key, array $default_names = [], string $default_kind = '' ) use ( $mapping, $lookup_field, $posted_names, $field_kinds ) {
			$form_name = (string) ( $mapping[ $fk_key ] ?? '' );
			if ( $form_name === '' && $default_names ) {
				$form_name = (string) ES_Field_Matcher::find_by_name( $posted_names, $default_names );
			}
			if ( $form_name === '' && $default_kind !== '' ) {
				$form_name = (string) ES_Field_Matcher::find_by_kind( $posted_names, $field_kinds, $default_kind );
			}
			if ( $form_name === '' ) {
				return '';
			}
			$val = $lookup_field( $form_name );
			return is_array( $val ) ? implode( ', ', array_map( 'strval', $val ) ) : (string) $val;
		};
		$geo_value_for = function ( string $fk_key, string $geo_key ) use ( $mapping, $value_for, $geo ) {
			if ( ! empty( $mapping[ $fk_key ] ) ) {
				return $value_for( $fk_key );
			}
			return $geo[ $geo_key ] ?? '';
		};

		$email = self::normalize_email( $value_for( 'col:email', ES_Field_Matcher::EMAIL_NAME_VARIANTS, 'email' ) );
		if ( ! self::is_deliverable_email( $email ) ) {
			wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ], 422 );
		}

		$missing_consent = $this->find_missing_consent_fields( (string) $form->html, $fields );
		if ( $missing_consent ) {
			ES_Logger::error( 'es_submit aborted: missing consent fields', [ 'missing' => $missing_consent, 'fields_received' => array_keys( $fields ) ] );
			wp_send_json_error( [ 'message' => __( 'Please confirm the consent checkbox before submitting.', 'emblem-studio' ) ], 422 );
		}

		$first_name = $value_for( 'col:f_name', ES_Field_Matcher::FIRST_NAME_VARIANTS );
		$last_name  = $value_for( 'col:l_name', ES_Field_Matcher::LAST_NAME_VARIANTS );
		if ( ! $first_name && ! $last_name && empty( $mapping['col:f_name'] ) && ! empty( $fields['name'] ) && is_scalar( $fields['name'] ) ) {
			$full       = self::normalize_name( trim( (string) $fields['name'] ) );
			$parts      = preg_split( '/\s+/', $full, 2 );
			$first_name = $parts[0] ?? '';
			$last_name  = $parts[1] ?? '';
		} else {
			$first_name = self::normalize_name( $first_name );
			$last_name  = self::normalize_name( $last_name );
		}

		$phone       = $value_for( 'col:contact_no', ES_Field_Matcher::PHONE_NAME_VARIANTS, 'tel' );
		$fk_country  = $geo_value_for( 'col:country',  'country' );
		$fk_state    = $geo_value_for( 'col:state',    'state' );
		$fk_timezone = $geo_value_for( 'col:timezone', 'timezone' );

		$slug_values = [
			'city'     => $geo['city']     ?? '',
			'postcode' => $geo['postcode'] ?? '',
		];
		foreach ( $mapping as $fk_key => $form_field_name ) {
			if ( strpos( (string) $fk_key, 'slug:' ) === 0 && $form_field_name !== '' ) {
				$slug = substr( $fk_key, 5 );
				$slug_values[ $slug ] = $lookup_field( (string) $form_field_name );
			}
		}
		$slug_values = array_filter( $slug_values, function ( $v ) {
			return $v !== '' && $v !== [];
		} );

		$terms = self::configured_terms( $form );
		$tags  = $terms['tags'];
		$lists = $terms['lists'];

		$reserved      = [
			'email', 'first_name', 'last_name', 'name', 'phone',
			'form_id', 'esf_hp', 'es_stamp', 'es_ts', 'es_el', 'esf_tag', 'esf_list', 'es_source',
			'action', '_wpnonce', '_wp_http_referer',
		];
		$allowed       = $this->allowed_field_names( $form );
		$custom_fields = [];
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, $reserved, true ) ) {
				continue;
			}
			if ( ! isset( $allowed[ $k ] ) ) {
				continue;
			}
			$custom_fields[ sanitize_key( $k ) ] = self::sanitize_field_value( $v );
		}

		$source = ES_Visit_Source::from_request( isset( $_POST['es_source'] ) ? wp_unslash( (string) $_POST['es_source'] ) : null );

		global $wpdb;
		$wpdb->insert( ES_Install::table( 'leads' ), [
			'form_id'      => $form_id,
			'email'        => $email,
			'source'       => $source !== null ? wp_json_encode( $source ) : null,
			'extra_fields' => wp_json_encode( array_merge(
				[ 'first_name' => $first_name, 'last_name' => $last_name, 'phone' => $phone, 'tags' => $tags ],
				$custom_fields,
				$is_preview ? [ '_es_test' => 1 ] : []
			) ),
			'bot_signals'  => $bot_signals !== null ? wp_json_encode( $bot_signals ) : null,
			'created_at'   => current_time( 'mysql' ),
		] );
		$lead_id = (int) $wpdb->insert_id;

		ES_Consent_Log::record(
			$form_id,
			(string) $form->html,
			$lead_id,
			$fields,
			isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : null,
			$this->ab_slot( $form_id ) ?: null
		);

		$fk_enabled = ! isset( $form->funnelkit_enabled ) || ! empty( $form->funnelkit_enabled );
		$feed_fields = $fields;
		$feed_fields['email'] = $email;
		$fk_asset_key = ! empty( $form->saas_form_id )
			? 'saas:' . (int) $form->saas_form_id
			: 'wp:' . $form_id;
		$push = ES_FunnelKit_Delivery::dispatch(
			$fk_asset_key,
			$feed_fields,
			[
				'email'         => $email,
				'first_name'    => $first_name,
				'last_name'     => $last_name,
				'phone'         => $phone,
				'country'       => $fk_country,
				'state'         => $fk_state,
				'timezone'      => $fk_timezone,
				'slugs'         => $slug_values,
				'tags'          => $tags,
				'lists'         => $lists,
				'custom_fields' => $custom_fields,
				'contact_status' => ES_FunnelKit_Upsert::contact_status( $form->funnelkit_contact_status ?? null ),
			],
			$fk_enabled
		);

		if ( $push['mode'] !== 'disabled' ) {
			$wpdb->update( ES_Install::table( 'leads' ), [
				'funnelkit_pushed'     => $push['ok'] ? 1 : 0,
				'funnelkit_contact_id' => $push['contact_id'] ?? null,
				'error'                => $push['ok'] ? null : ( $push['error'] ?? 'Unknown' ),
			], [ 'id' => $lead_id ] );
		}

		$post_id      = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		$shell        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
		$valid_shells = ES_Form_Repo::valid_types();
		if ( ! in_array( $shell, $valid_shells, true ) ) {
			$shell = 'inline';
		}
		if ( ! $is_preview ) {
			$recorded = ES_Event_Recorder::record( $form_id, 'conversion', $post_id, $shell, null, $this->ab_slot( $form_id ), $lead_id, null, $source['channel'] ?? null );

			if ( $recorded && class_exists( 'ES_AB' ) ) {
				ES_AB::auto_winner_check( $form_id );
			}
		}

		$form_for_hooks = class_exists( 'ES_Webhooks' ) ? ES_Form_Repo::find( $form_id ) : null;
		if ( $form_for_hooks ) {
			$webhook_payload = ES_Webhooks::build_payload(
				$form_for_hooks,
				$email,
				$first_name,
				$last_name,
				$phone,
				$custom_fields,
				$push['contact_id'] ?? null,
				$source
			);
			ES_Webhooks::send_for_form( $form_for_hooks, $webhook_payload );
		}

		wp_send_json_success( [
			'mode'             => $form->success_mode,
			'success_message'  => null,
			'redirect_url'     => $form->success_mode === 'redirect' ? ( ES_Form_Repo::safe_redirect_url( (string) $form->success_redirect_url ) ?: null ) : null,
			'funnelkit_pushed' => (bool) $push['ok'],
		] );
	}

	private function ab_slot( int $form_id ): string {
		return class_exists( 'ES_AB' ) ? (string) ES_AB::slot_from_cookie( $form_id ) : '';
	}

	public function test_webhook(): void {
		if ( ! class_exists( 'ES_Webhooks' ) ) {
			wp_send_json_error( [ 'message' => 'Webhooks are not available on this plan.' ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( 'es_test_webhook', 'nonce' );

		$url     = isset( $_POST['url'] )     ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) )         : '';
		$secret  = isset( $_POST['secret'] )  ? (string) wp_unslash( $_POST['secret'] )                    : '';
		$headers = isset( $_POST['headers'] ) ? (string) wp_unslash( $_POST['headers'] )                   : '';
		if ( $url === '' ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a URL first.', 'emblem-studio' ) ], 400 );
		}
		$sample = [
			'form_id'              => 0,
			'form_slug'            => 'test',
			'form_name'            => __( 'Test webhook (no real form)', 'emblem-studio' ),
			'email'                => 'test@example.com',
			'first_name'           => 'Test',
			'last_name'            => 'User',
			'phone'                => '',
			'custom_fields'        => (object) [],
			'funnelkit_contact_id' => null,
			'submitted_at'         => current_time( 'mysql' ),
			'referrer'             => '',
			'site_url'             => home_url(),
			'_test'                => true,
		];
		$result = ES_Webhooks::send_test(
			[ 'url' => $url, 'secret' => $secret, 'headers' => $headers, 'active' => true ],
			$sample
		);
		wp_send_json_success( $result );
	}

	private function allowed_field_names( object $form ): array {
		$html = (string) ( $form->html ?? '' );

		if ( class_exists( 'ES_AB' ) && ! empty( $form->id ) ) {
			foreach ( ES_AB::live_variants_for_form( (int) $form->id ) as $variant ) {
				$html .= "\n" . (string) ( $variant->html ?? '' );
			}
		}

		$allowed = [];
		foreach ( ES_Form_Parser::extract_fields( $html ) as $field ) {
			$name = preg_replace( '/\[\]$/', '', (string) $field['name'] );
			if ( $name !== '' ) {
				$allowed[ $name ] = true;
			}
		}

		return $allowed;
	}

	private function find_missing_consent_fields( string $html, array $fields ): array {
		if ( trim( $html ) === '' ) {
			return [];
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$required = [];
		foreach ( $dom->getElementsByTagName( 'input' ) as $input ) {
			$type = strtolower( $input->getAttribute( 'type' ) );
			$name = $input->getAttribute( 'name' );
			if ( ! $name ) {
				continue;
			}
			if ( $type === 'checkbox' && $input->hasAttribute( 'required' ) ) {
				$required[] = $name;
			}
		}

		$missing = [];
		foreach ( array_unique( $required ) as $field ) {
			if ( ! self::checkbox_value_is_ticked( self::posted_value( $fields, $field ) ) ) {
				$missing[] = $field;
			}
		}
		return $missing;
	}

	public static function posted_value( array $fields, string $name ) {
		if ( array_key_exists( $name, $fields ) ) {
			return $fields[ $name ];
		}
		if ( substr( $name, -2 ) === '[]' ) {
			$stripped = substr( $name, 0, -2 );
			if ( array_key_exists( $stripped, $fields ) ) {
				return $fields[ $stripped ];
			}
		}
		return null;
	}

	public static function checkbox_value_is_ticked( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::checkbox_value_is_ticked( $item ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! is_scalar( $value ) || empty( $value ) ) {
			return false;
		}
		return ! in_array( strtolower( (string) $value ), [ 'off', 'false' ], true );
	}
	public static function normalize_email( $raw ): string {
		return sanitize_email( strtolower( trim( (string) $raw ) ) );
	}

	public static function normalize_name( $raw ): string {
		$s = trim( (string) $raw );
		if ( $s === '' ) {
			return '';
		}
		$lower = mb_strtolower( $s, 'UTF-8' );
		return preg_replace_callback(
			'/(?:^|[\s\-\'])([\p{L}])/u',
			fn( $m ) => mb_strtoupper( $m[0], 'UTF-8' ),
			$lower
		);
	}

	public const MAX_FIELD_VALUES = 100;

	public const MAX_FIELD_LENGTH = 5000;

	public static function sanitize_field_value( $value ) {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) ? self::clean_answer( (string) $value ) : '';
		}

		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$out[] = self::clean_answer( (string) $item );
			if ( count( $out ) >= self::MAX_FIELD_VALUES ) {
				break;
			}
		}
		return $out;
	}

	private static function clean_answer( string $value, int $max = self::MAX_FIELD_LENGTH ): string {
		$value = str_replace( [ "\r\n", "\r" ], "\n", $value );

		return mb_substr( sanitize_textarea_field( $value ), 0, $max );
	}

	private function collect_fields(): array {
		$out = [];
		foreach ( $_POST as $k => $v ) {
			if ( in_array( $k, [ 'action', '_wpnonce', '_wp_http_referer', 'form_id', 'post_id', 'esf_hp', 'es_stamp', 'es_ts', 'es_el', 'es_source' ], true ) ) {
				continue;
			}
			$out[ $k ] = self::sanitize_field_value( wp_unslash( $v ) );
		}
		return $out;
	}

	private function bucket_exceeded( string $scope, int $window, int $max ): bool {
		return ES_Rate_Limit::exceeded( $scope . '|' . $this->client_ip(), $window, $max );
	}

	private function bot_row_allowed( string $kind, int $form_id ): bool {
		return ! $this->bucket_exceeded( 'bot_' . $kind . '_' . $form_id, self::BOT_WINDOW, self::BOT_MAX );
	}

	private function save_bot_lead( int $form_id, ?array $bot_signals = null ): void {
		global $wpdb;
		$fields = $this->collect_fields();
		foreach ( $fields as $k => $v ) {
			if ( is_string( $k ) && ( strpos( $k, '__es_geo_' ) === 0 || $k === 'esf_hp' || $k === 'action' || $k === 'form_id' || $k === 'post_id' || $k === 'type' ) ) {
				unset( $fields[ $k ] );
			}
		}

		$email = isset( $fields['email'] ) && is_scalar( $fields['email'] )
			? self::normalize_email( (string) $fields['email'] )
			: '';
		if ( ! $email || ! is_email( $email ) ) {
			$email = '(no email)';
		}

		$first = isset( $fields['first_name'] ) && is_scalar( $fields['first_name'] ) ? sanitize_text_field( (string) $fields['first_name'] ) : '';
		$last  = isset( $fields['last_name'] )  && is_scalar( $fields['last_name'] )  ? sanitize_text_field( (string) $fields['last_name'] )  : '';
		if ( ! $first && ! $last && ! empty( $fields['name'] ) && is_scalar( $fields['name'] ) ) {
			$parts = preg_split( '/\s+/', trim( (string) $fields['name'] ), 2 );
			$first = sanitize_text_field( $parts[0] ?? '' );
			$last  = sanitize_text_field( $parts[1] ?? '' );
		}

		$form     = ES_Form_Repo::find( $form_id );
		$allowed  = $form ? $this->allowed_field_names( $form ) : [];
		$captured = [];
		foreach ( $fields as $k => $v ) {
			if ( ! is_string( $k ) || $k === 'email' || ! isset( $allowed[ $k ] ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$flat = implode( ', ', array_map( 'strval', $v ) );
			} elseif ( is_scalar( $v ) ) {
				$flat = (string) $v;
			} else {
				continue;
			}
			$captured[ sanitize_key( $k ) ] = self::clean_answer( $flat, 500 );
		}

		$wpdb->insert( ES_Install::table( 'leads' ), [
			'form_id'      => $form_id,
			'email'        => $email,
			'extra_fields' => wp_json_encode( array_merge(
				[ 'first_name' => $first, 'last_name' => $last ],
				$captured
			) ),
			'is_bot'       => 1,
			'bot_signals'  => $bot_signals !== null ? wp_json_encode( $bot_signals ) : null,
			'ip'           => $this->client_ip(),
			'created_at'   => current_time( 'mysql' ),
		] );
	}

	private function record_bot_hit( int $form_id, string $shell, string $label_override = '' ): void {
		if ( ! $this->bot_row_allowed( 'hit', $form_id ) ) {
			return;
		}
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$label   = $label_override !== '' ? $label_override : ES_Event_Recorder::matched_bot_label( $ua );
		$ip      = ES_Event_Recorder::anonymize_ip( $this->client_ip() );
		$post_id = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		ES_Event_Recorder::record_bot( $form_id, $shell, $post_id, $label, $ua, $ip );
	}

	private function maybe_record_datacenter( int $form_id, string $shell ): bool {
		if ( ! ES_Event_Recorder::filter_bots_enabled() || ! ES_Event_Recorder::detect_datacenter_enabled() ) {
			return false;
		}
		$provider = ES_Event_Recorder::datacenter_provider( $this->client_ip() );
		if ( $provider === '' ) {
			return false;
		}
		$this->record_bot_hit( $form_id, $shell, $provider );
		return true;
	}

	private function client_ip(): string {
		return ES_Event_Recorder::client_ip();
	}
}
