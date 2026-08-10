<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Ajax {

	// Per-IP × form bucket. Submissions are far more sensitive than impressions/views.
	private const SUBMIT_WINDOW       = 60;    // seconds
	private const SUBMIT_MAX          = 8;     // submissions / window / (ip, form)
	private const EVENT_WINDOW        = 60;    // seconds
	private const EVENT_MAX           = 120;   // page_view+view events / window / (ip, form)
	private const CLICK_WINDOW        = 60;    // seconds
	private const CLICK_MAX           = 60;    // click events / window / (ip, form, shell)
	// Below this, a filled honeypot is trusted as a bot signal on its own. A
	// scripted submission fills every field and posts near-instantly; a real
	// visitor — even one whose browser autofill cross-filled the trap — takes
	// at least this long to see the form and click submit.
	private const MIN_HUMAN_SUBMIT_MS = 1200;

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
		add_action( 'wp_ajax_es_submit',              [ $this, 'submit' ] );
		add_action( 'wp_ajax_nopriv_es_submit',       [ $this, 'submit' ] );
		// Admin-only — used by the "Send test" button on the Connect tab.
		add_action( 'wp_ajax_es_test_webhook',        [ $this, 'test_webhook' ] );
		// Admin-only — render payload for the Forms list hover thumbnail.
		add_action( 'wp_ajax_es_preview_payload',     [ $this, 'preview_payload' ] );
	}

	/**
	 * Return a form's render payload (html + Design-System-composed css) so the
	 * Forms list can show a live hover thumbnail. Admin-only, nonce-protected.
	 */
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

		// build_payload() is the same choke point the front end uses (Design System
		// already composed in); it returns a JSON string we decode back to fields.
		$payload = json_decode( ( new ES_Shortcode() )->build_payload( $form ), true );
		wp_send_json_success( [
			'html' => (string) ( $payload['html'] ?? '' ),
			'css'  => (string) ( $payload['css'] ?? '' ),
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

	/**
	 * Visitor started filling in the form (first keystroke / tick / pick).
	 * Fired once per mounted form by frontend.js. Paired with `conversion`
	 * to derive drop-offs: engaged − converted. Goes through the same generic
	 * path as views, so it inherits the bot filter, exclusion, per-(ip,form,
	 * shell,variant) rate-limit bucket, and active-form check.
	 */
	public function engage(): void {
		$this->record_event( 'engage' );
	}

	/**
	 * Visitor engaged with the form but left the page without submitting — a
	 * drop-off. Fired by frontend.js on `pagehide` (via sendBeacon). Recorded
	 * directly as its own event rather than derived by subtracting conversions
	 * from engagements, which is unreliable against historical data.
	 */
	public function dropoff(): void {
		$this->record_event( 'dropoff' );
	}

	/**
	 * Record a click on a tracked element inside a form. Same bot filter +
	 * active-form check as views; separate rate-limit bucket because clicks
	 * can be more frequent than page_view/view but less than typing.
	 *
	 * Target identifier comes from frontend.js — it's the author's
	 * `data-es-track` value when set, else element id, else truncated visible
	 * text, else a tag+class fallback. Reserved sentinel `__close__` is used
	 * for shell close buttons.
	 */
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

		// Excluded IPs (own monitors / load tests) are dropped entirely — not
		// even counted as bot_filtered. Catches browser-spoofing crawlers the
		// UA bot filter can't.
		if ( ES_Event_Recorder::is_excluded_ip( $this->client_ip() ) ) {
			wp_send_json_success();
		}

		// Bot filter — same policy as page_view/view. Bot clicks roll up under
		// bot_filtered (no click target, but tagged with which bot + page) so the
		// admin sees suppression at work in the breakdown report.
		if ( ES_Event_Recorder::filter_bots_enabled() && ES_Event_Recorder::is_bot_request() ) {
			$this->record_bot_hit( $form_id, $shell );
			wp_send_json_success();
		}
		// Datacenter fallback: cloud-VM "browser" the UA filter missed.
		if ( $this->maybe_record_datacenter( $form_id, $shell ) ) {
			wp_send_json_success();
		}

		// Per-(IP, form, shell, variant) rate-limit bucket. Including
		// variant in the key gives each side of the test its own
		// allowance — visitors flipping cookies don't share buckets
		// with their previous slot.
		$variant     = $this->ab_slot( $form_id );
		$bucket_part = $variant ? '_v' . $variant : '';
		if ( $this->bucket_exceeded( 'click_' . $shell . '_' . $form_id . $bucket_part, self::CLICK_WINDOW, self::CLICK_MAX ) ) {
			wp_send_json_success();
		}

		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form || ! ES_Form_Repo::is_currently_active( $form ) ) {
			wp_send_json_success();
		}
		// Server-side respect for the per-form opt-out — defends against a
		// page that's still serving cached JS after the toggle was flipped off.
		if ( empty( $form->track_clicks ) ) {
			wp_send_json_success();
		}

		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target'] ) ) : '';
		// Collapse whitespace runs so visible-text labels group cleanly.
		$target = preg_replace( '/\s+/', ' ', trim( $target ) );
		if ( $target === '' ) {
			$target = '__untagged__';
		}

		$post_id = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		ES_Event_Recorder::record( $form_id, 'click', $post_id, $shell, $target, $variant );

		// A/B auto-winner eval for click-primary tests (non-form assets
		// where there's no submit to trigger from). Skip when the click
		// target is a close / backdrop sentinel — those don't represent
		// success and shouldn't drive an auto-declare. Throttled to
		// once per minute per form by auto_winner_check.
		if ( class_exists( 'ES_AB' ) && $target !== '__close__' && $target !== '__backdrop__' ) {
			$running = ES_AB::running_test_for_form( $form_id );
			if ( $running && isset( $running->primary_metric ) && $running->primary_metric === 'clicks' ) {
				ES_AB::auto_winner_check( $form_id );
			}
		}

		wp_send_json_success();
	}

	private function record_event( string $event_type ): void {
		// Excluded IPs (own monitors / load tests) are dropped entirely before
		// anything is counted — the fix for headless-browser monitors that spoof
		// a real UA and would otherwise inflate page-view / impression stats.
		if ( ES_Event_Recorder::is_excluded_ip( $this->client_ip() ) ) {
			wp_send_json_success();
		}

		// Bot filter — keeps the impression metric trustworthy. When the UA
		// looks like a known bot, count it under bot_filtered (so admins can
		// SEE the filtering is working) and bail before recording the real
		// event. Doesn't apply to submit() — let the honeypot + rate limit
		// handle bots that try to actually submit forms.
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
		// Datacenter fallback: a "browser" from a cloud VM is almost certainly a
		// crawler — keep it out of the page-view / impression metrics.
		$dc_form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$dc_shell   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
		if ( ! in_array( $dc_shell, ES_Form_Repo::valid_types(), true ) ) {
			$dc_shell = 'inline';
		}
		if ( $dc_form_id && $this->maybe_record_datacenter( $dc_form_id, $dc_shell ) ) {
			wp_send_json_success();
		}

		// Mark this visitor as "returning" for any future page render that
		// has visitor_state targeting. Cheap; no-op if cookie already set.
		ES_Display_Rules::ensure_visitor_cookie();

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$post_id = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		// Shell type: tagged on the host in PHP, sent back with every event.
		$shell   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
		$valid_shells = ES_Form_Repo::valid_types();
		if ( ! in_array( $shell, $valid_shells, true ) ) {
			$shell = 'inline';
		}

		if ( ! $form_id ) {
			wp_send_json_error( [ 'message' => 'Missing form_id' ], 400 );
		}
		// Throttle event firehose per (ip, form, shell, variant) — variant
		// included so each side of an A/B test has its own bucket; visitors
		// re-bucketed mid-test don't trip the previous slot's limit.
		$variant     = $this->ab_slot( $form_id );
		$bucket_part = $variant ? '_v' . $variant : '';
		if ( $this->bucket_exceeded( 'ev_' . $event_type . '_' . $shell . '_' . $form_id . $bucket_part, self::EVENT_WINDOW, self::EVENT_MAX ) ) {
			wp_send_json_success();
		}
		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form || ! ES_Form_Repo::is_currently_active( $form ) ) {
			wp_send_json_success();
		}
		ES_Event_Recorder::record( $form_id, $event_type, $post_id, $shell, null, $variant );
		wp_send_json_success();
	}

	public function submit(): void {
		// Submissions also count as a visit — mark the visitor as "returning"
		// so future page renders gated on visitor_state see them correctly.
		ES_Display_Rules::ensure_visitor_cookie();

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;

		if ( ! $form_id ) {
			wp_send_json_error( [ 'message' => 'Missing form_id' ], 400 );
		}

		// "Submit for real" from the Design-tab previewer: process the submission
		// normally (save the lead, push to the CRM) but DON'T let it count as a
		// conversion in reports. Capability-gated so a visitor can't spoof the
		// flag to suppress their own conversion.
		$is_preview = ! empty( $_POST['es_preview'] ) && current_user_can( 'edit_posts' );

		// Honeypot — must be empty, UNLESS the submission also took a
		// normal human amount of time. Browser autofill (an accepted
		// address/profile suggestion cascading into every empty field on
		// the form) can drop a stray value into the trap for a genuine
		// visitor; a scripted bot fills and submits near-instantly, so
		// pairing the trap with submit speed tells the two apart. `es_ts`
		// is the client-recorded moment the form appeared (injected
		// alongside the trap itself — see injectHoneypot in frontend.js);
		// missing/invalid timing falls back to today's honeypot-alone rule.
		$hp_filled = ! empty( $_POST['esf_hp'] );
		if ( $hp_filled ) {
			$form_ready_at = isset( $_POST['es_ts'] ) ? (int) $_POST['es_ts'] : 0;
			if ( $form_ready_at > 0 ) {
				$elapsed_ms = (int) round( microtime( true ) * 1000 ) - $form_ready_at;
				if ( $elapsed_ms >= self::MIN_HUMAN_SUBMIT_MS ) {
					$hp_filled = false;
				}
			}
		}

		// Record the trip as an event AND save a bot-flagged lead so admins
		// can inspect what attackers send without those rows polluting the
		// real Humans list. We skip the FunnelKit / webhook push so the CRM
		// never sees garbage. Still return success silently so a real
		// attacker gets no signal that the trick was detected.
		if ( $hp_filled ) {
			ES_Logger::info( 'Honeypot triggered', [ 'form_id' => $form_id ] );
			$hp_shell = isset( $_POST['type'] ) ? sanitize_key( (string) $_POST['type'] ) : 'inline';
			if ( ! in_array( $hp_shell, ES_Form_Repo::valid_types(), true ) ) {
				$hp_shell = 'inline';
			}
			ES_Event_Recorder::record( $form_id, 'honeypot_blocked', null, $hp_shell );
			$this->save_bot_lead( $form_id );
			wp_send_json_success( [ 'mode' => 'inline_state' ] );
		}

		// Rate limit per (ip, form_id) — a single attacker can't hammer multiple forms in parallel.
		if ( $this->bucket_exceeded( 'sub_' . $form_id, self::SUBMIT_WINDOW, self::SUBMIT_MAX ) ) {
			ES_Logger::error( 'es_submit aborted: rate-limited', [ 'ip' => $this->client_ip(), 'form_id' => $form_id ] );
			wp_send_json_error( [ 'message' => 'Too many submissions, please wait a moment.' ], 429 );
		}

		$form = ES_Form_Repo::find( $form_id );
		if ( ! $form || ! ES_Form_Repo::is_currently_active( $form ) ) {
			wp_send_json_error( [ 'message' => 'Form not available' ], 404 );
		}

		$fields = $this->collect_fields();

		// Pull out any silent hints frontend.js wrote to __es_geo_* keys (so they
		// never collide with user-named inputs). As of the ipapi.co removal the
		// only hint left is the browser's local timezone — IP-derived country /
		// state / city are no longer looked up here (that's a hosted-Emblem-Studio
		// feature, Free tier onwards / connected sites). So these keys are mostly
		// empty now; an explicit Field Mapping still fills any FK field the visitor
		// actually types, which always wins.
		$geo = [];
		foreach ( $fields as $k => $v ) {
			if ( is_string( $k ) && strpos( $k, '__es_geo_' ) === 0 ) {
				$geo[ substr( $k, 9 ) ] = is_array( $v ) ? '' : (string) $v;
				unset( $fields[ $k ] );
			}
		}

		// Field mapping: { 'col:<column>' | 'slug:<slug>' => form_input_name }.
		// Wins over name-convention; we look up the mapped input's value in
		// $fields and route to the right FK target.
		$mapping = [];
		if ( ! empty( $form->field_mapping ) ) {
			$decoded = json_decode( (string) $form->field_mapping, true );
			if ( is_array( $decoded ) ) {
				$mapping = $decoded;
			}
		}
		// PHP strips the trailing `[]` from $_POST keys for multi-value inputs.
		// Field-parser stores names with `[]` intact (to flag them as multi),
		// so the lookup needs to tolerate both forms.
		$lookup_field = function ( string $name ) use ( $fields ) {
			if ( array_key_exists( $name, $fields ) ) {
				return $fields[ $name ];
			}
			if ( substr( $name, -2 ) === '[]' ) {
				$stripped = substr( $name, 0, -2 );
				if ( array_key_exists( $stripped, $fields ) ) {
					return $fields[ $stripped ];
				}
			}
			return '';
		};
		// The field's HTML input kind (email/tel/...), parsed from the form's
		// own stored HTML — a last-resort auto-detect signal when no name
		// variant matches (e.g. a field named "contact" but type="tel").
		$field_kinds = [];
		foreach ( ES_Form_Parser::extract_fields( (string) $form->html ) as $f ) {
			$field_kinds[ $f['name'] ] = $f['kind'];
		}
		$posted_names = array_keys( $fields );
		/**
		 * Resolve a FunnelKit column's value: an explicit mapping always wins;
		 * otherwise try each name-convention variant (case-insensitive, via
		 * ES_Field_Matcher — shared with assets/admin.js's mapper-UI hint),
		 * then (only when $default_kind is given) any field whose HTML input
		 * type matches — e.g. an email column will pick up a field named
		 * "contact" if it's the only type="email" input on the form.
		 *
		 * @param string[] $default_names
		 */
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
		// FK-routed fields: name-convention is REMOVED. Only mapping or geo.
		$geo_value_for = function ( string $fk_key, string $geo_key ) use ( $mapping, $value_for, $geo ) {
			if ( ! empty( $mapping[ $fk_key ] ) ) {
				return $value_for( $fk_key );
			}
			return $geo[ $geo_key ] ?? '';
		};

		$email = self::normalize_email( $value_for( 'col:email', ES_Field_Matcher::EMAIL_NAME_VARIANTS, 'email' ) );
		if ( ! $email || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ], 422 );
		}

		// Server-side enforcement of mandatory consent checkboxes (defends against
		// bypassed browser-side `required` validation).
		$missing_consent = $this->find_missing_consent_fields( (string) $form->html, $fields );
		if ( $missing_consent ) {
			ES_Logger::error( 'es_submit aborted: missing consent fields', [ 'missing' => $missing_consent, 'fields_received' => array_keys( $fields ) ] );
			wp_send_json_error( [ 'message' => __( 'Please confirm the consent checkbox before submitting.', 'emblem-studio' ) ], 422 );
		}

		// First / last name: mapping wins, then explicit form fields, then split "name".
		$first_name = $value_for( 'col:f_name', ES_Field_Matcher::FIRST_NAME_VARIANTS );
		$last_name  = $value_for( 'col:l_name', ES_Field_Matcher::LAST_NAME_VARIANTS );
		// Only split the full-name field when NEITHER half resolved on its own —
		// otherwise a form with (say) an explicit last-name field but no
		// first-name field would have the split overwrite the already-correct
		// last name with the tail of the split full name.
		if ( ! $first_name && ! $last_name && empty( $mapping['col:f_name'] ) && ! empty( $fields['name'] ) ) {
			$full       = self::normalize_name( trim( (string) $fields['name'] ) );
			$parts      = preg_split( '/\s+/', $full, 2 );
			$first_name = $parts[0] ?? '';
			$last_name  = $parts[1] ?? '';
		} else {
			$first_name = self::normalize_name( $first_name );
			$last_name  = self::normalize_name( $last_name );
		}

		$phone       = $value_for( 'col:contact_no', ES_Field_Matcher::PHONE_NAME_VARIANTS, 'tel' );
		// FK-routed columns: only set if mapping says so, OR geo provides it.
		$fk_country  = $geo_value_for( 'col:country',  'country' );
		$fk_state    = $geo_value_for( 'col:state',    'state' );
		$fk_timezone = $geo_value_for( 'col:timezone', 'timezone' );

		// Slug-based custom fields. Geo provides city + postcode by default;
		// explicit `slug:` mapping wins. Other slug fields (gender, company, dob,
		// address-1, address-2) require explicit mapping — no name-convention.
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
			return $v !== '' && $v !== [] && $v !== null;
		} );

		// Tags: form's configured tags + any esf_tag overrides from the HTML.
		$form_tags = [];
		if ( ! empty( $form->funnelkit_tags ) ) {
			$decoded   = json_decode( $form->funnelkit_tags, true );
			$form_tags = is_array( $decoded ) ? $decoded : [];
		}
		$override_tags = [];
		if ( isset( $_POST['esf_tag'] ) ) {
			$raw           = is_array( $_POST['esf_tag'] ) ? $_POST['esf_tag'] : [ $_POST['esf_tag'] ];
			$override_tags = array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
		}
		$tags = array_values( array_unique( array_merge( $form_tags, $override_tags ) ) );

		// Lists: form's configured lists + any esf_list override.
		$form_lists = [];
		if ( ! empty( $form->funnelkit_lists ) ) {
			$decoded    = json_decode( $form->funnelkit_lists, true );
			$form_lists = is_array( $decoded ) ? array_map( 'intval', $decoded ) : [];
		}
		if ( ! $form_lists && ! empty( $form->funnelkit_list_id ) ) {
			$form_lists = [ (int) $form->funnelkit_list_id ];
		}
		$lists = $form_lists;
		if ( ! empty( $_POST['esf_list'] ) ) {
			$lists = [ (int) $_POST['esf_list'] ];
		}

		// Custom fields = anything not in the reserved set. Reserved keys are
		// either routed to dedicated paths above or are infrastructure (nonces,
		// form_id, etc.). FK-routed names (country, state, etc.) are no longer
		// auto-routed — they're only handled if explicitly mapped, so we don't
		// reserve them here; they pass through as plain custom data when not mapped.
		$reserved      = [
			'email', 'first_name', 'last_name', 'name', 'phone',
			'form_id', 'esf_hp', 'es_ts', 'esf_tag', 'esf_list',
			'action', '_wpnonce', '_wp_http_referer',
		];
		// Only keep fields that actually exist on the form (SER-872). Without this
		// the public endpoint stored any posted field. `email`/`first_name`/etc are
		// handled on their own paths above and stay reserved; everything else must
		// be a real form field to be kept.
		$allowed       = $this->allowed_field_names( $form );
		$custom_fields = [];
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, $reserved, true ) ) {
				continue;
			}
			if ( ! isset( $allowed[ $k ] ) ) {
				continue; // not a field on this form — drop it silently
			}
			$custom_fields[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
		}

		global $wpdb;
		$wpdb->insert( ES_Install::table( 'leads' ), [
			'form_id'      => $form_id,
			'email'        => $email,
			'extra_fields' => wp_json_encode( array_merge(
				[ 'first_name' => $first_name, 'last_name' => $last_name, 'phone' => $phone, 'tags' => $tags ],
				$custom_fields,
				// Local-only marker (not in the CRM payload) so a preview test
				// lead is identifiable in the lead record.
				$is_preview ? [ '_es_test' => 1 ] : []
			) ),
			'created_at'   => current_time( 'mysql' ),
		] );
		$lead_id = (int) $wpdb->insert_id;

		// Immutable consent evidence (SER-853) — a no-op when this form has no
		// consent checkbox at all. See docs/CONSENT-AND-LIABILITY.md (SaaS repo).
		ES_Consent_Log::record(
			$form_id,
			(string) $form->html,
			$lead_id,
			$fields,
			isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : null,
			$this->ab_slot( $form_id ) ?: null
		);

		// An active FunnelKit Form Feed is authoritative. Otherwise the direct
		// destination toggle decides whether the corrected direct upsert runs.
		// Webhooks remain independent in either case.
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

		// Always record the conversion locally even if FK fails (lead is saved).
		$post_id      = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		$shell        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'inline';
		$valid_shells = ES_Form_Repo::valid_types();
		if ( ! in_array( $shell, $valid_shells, true ) ) {
			$shell = 'inline';
		}
		// Skip for preview test submits — the lead is saved and pushed to the
		// CRM above, but it must not register as a conversion (or move an A/B
		// winner) in reports.
		if ( ! $is_preview ) {
			ES_Event_Recorder::record( $form_id, 'conversion', $post_id, $shell, null, $this->ab_slot( $form_id ) );

			// A/B auto-winner eval. Conversions only ever increase, so this
			// is the right (and only necessary) trigger point. Per-form
			// once-per-minute throttle inside auto_winner_check prevents a
			// burst of submits from thrashing. (A/B is account-gated; absent in Lite.)
			if ( class_exists( 'ES_AB' ) ) {
				ES_AB::auto_winner_check( $form_id );
			}
		}

		// Per-form webhook fan-out — fires after FK so the payload can
		// include the funnelkit_contact_id when FK succeeded. Non-blocking
		// transport so webhook latency doesn't delay the visitor's
		// success state. Refresh the $form object to pick up the
		// webhooks column (find() returns a fresh row).
		$form_for_hooks = class_exists( 'ES_Webhooks' ) ? ES_Form_Repo::find( $form_id ) : null;
		if ( $form_for_hooks ) {
			$webhook_payload = ES_Webhooks::build_payload(
				$form_for_hooks,
				$email,
				$first_name,
				$last_name,
				$phone,
				$custom_fields,
				$push['contact_id'] ?? null
			);
			ES_Webhooks::send_for_form( $form_for_hooks, $webhook_payload );
		}

		wp_send_json_success( [
			'mode'             => $form->success_mode,
			'success_message'  => $form->success_mode === 'custom_message' ? $form->success_message : null,
			'redirect_url'     => $form->success_mode === 'redirect' ? $form->success_redirect_url : null,
			'funnelkit_pushed' => (bool) $push['ok'],
		] );
	}

	/**
	 * A/B slot ('A'/'B') the visitor is bucketed into for this form, or '' when
	 * no test runs. A/B is account-gated, so the Lite build (no ES_AB) has no
	 * variant — always ''.
	 *
	 * The cast is load-bearing: ES_AB::slot_from_cookie() is `?string` and
	 * returns NULL for the common case — a visitor with no esf_ab_<id> cookie,
	 * which is EVERY visitor to a form that has no running two-variant test.
	 * Returning that null straight out of a `: string` method is a TypeError,
	 * and this method is called unconditionally by page_view, view, click and
	 * submit, so the fatal took the whole beacon (and the submit response) with
	 * it. Covered by tests/unit/AjaxAbSlotTest.php.
	 */
	private function ab_slot( int $form_id ): string {
		return class_exists( 'ES_AB' ) ? (string) ES_AB::slot_from_cookie( $form_id ) : '';
	}

	/**
	 * Admin-only AJAX endpoint — synchronously POST a sample payload to
	 * a webhook URL so the user can verify their endpoint works before
	 * trusting it with real submissions. Returns status + body excerpt.
	 */
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

	/**
	 * The set of field names a form legitimately offers, as a lookup keyed by
	 * name. Read from the form's own authored HTML (and, in the Full edition, its
	 * live A/B variant HTML), via ES_Form_Parser.
	 *
	 * The submit endpoint is public and used to store EVERY posted field, so
	 * anyone could POST arbitrary fields — no browser needed — and have them saved
	 * onto the customer's lead and pushed to their CRM. A visitor can only fill a
	 * field that exists in the rendered HTML, so the markup is a complete allowlist
	 * by construction. Matches the SaaS App\Support\FormFields (SER-872).
	 *
	 * `interests[]` submits as `interests`, so the trailing `[]` is stripped to
	 * match how the value actually arrives.
	 *
	 * @return array<string, true>
	 */
	private function allowed_field_names( object $form ): array {
		$html = (string) ( $form->html ?? '' );

		// A/B variants can add a field the parent form doesn't have. class_exists
		// because ES_Ab is stripped from the Lite build (which has no A/B).
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

	/**
	 * Parse the form's HTML and find names of inputs that MUST be checked
	 * (any `<input type="checkbox" required>` or any input with `data-esf-consent`).
	 * Returns the list of those field names that are missing from the submission.
	 */
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
			$is_required_checkbox = ( $type === 'checkbox' && $input->hasAttribute( 'required' ) );
			$has_consent_marker   = $input->hasAttribute( 'data-esf-consent' );
			if ( $is_required_checkbox || $has_consent_marker ) {
				$required[] = $name;
			}
		}

		$missing = [];
		foreach ( array_unique( $required ) as $field ) {
			$value = $fields[ $field ] ?? '';
			if ( empty( $value ) || strtolower( (string) $value ) === 'off' || strtolower( (string) $value ) === 'false' ) {
				$missing[] = $field;
			}
		}
		return $missing;
	}

	/**
	 * Lowercase + trim the email. Emails are technically case-sensitive in the
	 * local part per RFC, but every real-world provider treats them as
	 * case-insensitive. Normalising prevents duplicate contacts in FunnelKit
	 * for the same person ("Alex@x.com" vs "alex@x.com").
	 */
	public static function normalize_email( $raw ): string {
		return sanitize_email( strtolower( trim( (string) $raw ) ) );
	}

	/**
	 * Normalise a name's capitalisation. Always lowercases then title-cases,
	 * splitting on spaces, hyphens, and apostrophes. Unicode-safe.
	 *
	 *  - "alex"      → "Alex"
	 *  - "ALEX"      → "Alex"
	 *  - "AleX"      → "Alex"
	 *  - "uranGUtan" → "Urangutan"
	 *  - "anne-marie" → "Anne-Marie"
	 *  - "o'brien"   → "O'Brien"
	 *  - "von trapp" → "Von Trapp"
	 *
	 * Trade-off: intentionally-cased names like "McDonald" or "DiCaprio" become
	 * "Mcdonald" / "Dicaprio". Accepted because input typos are the much more
	 * common case on opt-in forms; admins can correct in FunnelKit when needed.
	 */
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

	private function collect_fields(): array {
		$out = [];
		foreach ( $_POST as $k => $v ) {
			if ( in_array( $k, [ 'action', '_wpnonce', '_wp_http_referer', 'form_id', 'post_id', 'esf_hp', 'es_ts' ], true ) ) {
				continue;
			}
			$out[ $k ] = is_array( $v ) ? array_map( 'sanitize_text_field', wp_unslash( $v ) ) : sanitize_text_field( wp_unslash( $v ) );
		}
		return $out;
	}

	/**
	 * Sliding-window counter keyed by (ip, scope). Returns true if the limit is hit.
	 * Counter is stored as a WP transient; auto-expires when the window closes.
	 */
	private function bucket_exceeded( string $scope, int $window, int $max ): bool {
		$ip   = $this->client_ip();
		$key  = 'esf_rl_' . md5( $scope . '|' . $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return true;
		}
		set_transient( $key, $hits + 1, $window );
		return false;
	}

	/**
	 * Best-effort client IP. Honors common proxy headers but only if the
	 * `EMBLEM_STUDIO_TRUST_PROXY` constant is defined — opt-in to prevent
	 * spoofing on bare deployments where any request can fake the header.
	 */
	/**
	 * Save a honeypot-tripped submission as a lead with is_bot=1 so it
	 * shows up in the Bots tab. Captures email, name, every text-ish
	 * field (so we can inspect what attackers fill in) and the IP. Best
	 * effort — never throws, never blocks the response.
	 */
	private function save_bot_lead( int $form_id ): void {
		global $wpdb;
		// Reuse the same field collector the human path uses so we get the
		// same raw shape; strip silent geo keys (they don't belong with
		// captured user-visible inputs).
		$fields = $this->collect_fields();
		foreach ( $fields as $k => $v ) {
			if ( is_string( $k ) && ( strpos( $k, '__es_geo_' ) === 0 || $k === 'esf_hp' || $k === 'action' || $k === 'form_id' || $k === 'post_id' || $k === 'type' ) ) {
				unset( $fields[ $k ] );
			}
		}

		// Pull email + name with name-convention fallbacks. Mapping-aware
		// extraction would duplicate a lot of the human-submit logic, and
		// for bot leads we just want a best-effort label — name-convention
		// is enough.
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

		// Captured text-ish inputs — but only fields that exist on the form, same
		// rule as a real submission (SER-872). We don't warehouse arbitrary
		// attacker-controlled fields even on a bot row: a bot could POST hundreds
		// of junk fields to bloat the row, and it's data we were never asked to
		// hold. The honeypot catch itself is the signal, not the payload. Matches
		// the SaaS, which applies the same allowlist to bot leads. Length still
		// capped per value.
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
			$captured[ sanitize_key( $k ) ] = mb_substr( sanitize_text_field( $flat ), 0, 500 );
		}

		$wpdb->insert( ES_Install::table( 'leads' ), [
			'form_id'      => $form_id,
			'email'        => $email,
			'extra_fields' => wp_json_encode( array_merge(
				[ 'first_name' => $first, 'last_name' => $last ],
				$captured
			) ),
			'is_bot'       => 1,
			'ip'           => $this->client_ip(),
			'created_at'   => current_time( 'mysql' ),
		] );
	}

	/**
	 * Record a filtered bot hit with attribution detail: which bot (from the
	 * UA), which page (post_id), the raw UA, and an anonymized IP. Shared by
	 * the click and view/page_view bot-filter paths so both feed the
	 * breakdown report identically.
	 */
	private function record_bot_hit( int $form_id, string $shell, string $label_override = '' ): void {
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		// label_override carries the cloud provider for datacenter-detected hits
		// (whose UA is a browser and so wouldn't match the catalog).
		$label   = $label_override !== '' ? $label_override : ES_Event_Recorder::matched_bot_label( $ua );
		$ip      = ES_Event_Recorder::anonymize_ip( $this->client_ip() );
		$post_id = isset( $_POST['post_id'] ) && (int) $_POST['post_id'] > 0 ? (int) $_POST['post_id'] : null;
		ES_Event_Recorder::record_bot( $form_id, $shell, $post_id, $label, $ua, $ip );
	}

	/**
	 * Datacenter fallback: a "browser" whose real IP is a known cloud VM is
	 * almost certainly a crawler. Records it as a bot_filtered hit labelled by
	 * provider. Returns true if it handled (and the caller should stop).
	 */
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
