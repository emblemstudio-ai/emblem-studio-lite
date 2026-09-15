<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live values: a price or a discount in an asset that stays true by itself
 * (SER-1350, SER-1351, SER-1352).
 *
 * THE CONTRACT IS THE SAAS REPO'S docs/LIVE-VALUES.md. The web app's
 * App\Services\LiveValues is this class's twin, and the two must agree with that
 * file field by field and reason by reason.
 *
 * An asset marks a spot with an element, and the element's own words are the
 * fallback:
 *
 *     <span data-es-live="coupon.discount" data-es-product="22" data-es-coupon="TIERED">a discount</span>
 *
 * This class does three jobs.
 *
 *  1. KEEPS READINGS FRESH. It measures every value the site's assets use with
 *     ES_Commerce, exactly as the store routes do, and measures again when
 *     something that could change one happens: an order uses or gives back a
 *     coupon, an order is paid, a coupon or product is saved or its stock moves,
 *     WooCommerce starts or ends a dated sale. A 15-minute timer is the safety
 *     net. Triggers are debounced into one run ten seconds later, so a burst of
 *     orders measures once, and measuring never happens inside the request that
 *     placed the order.
 *
 *  2. HANDS THEM ON. Connected to Emblem Studio, the connector supplies the watch
 *     list and sends what changed (the `es_live_values_watch_list` filter and the
 *     `es_live_values_measured` action). Standalone, the watch list comes from the
 *     site's own assets and the readings stay here.
 *
 *  3. FILLS THEM IN, for a site rendering its own assets: build_payload() carries
 *     the `live` text, and frontend.js asks admin-ajax for fresh text once per
 *     page, because a cached page can carry a number that has since moved.
 *
 * ONE RULE ABOVE THE REST: an unsafe number is never shown. Old, refused, zero,
 * out of stock or missing leaves the author's words in place.
 *
 * The pure parts (markers, keys, judging, formatting) call no WordPress function,
 * so the unit tests run them without WordPress.
 */
class ES_Live_Values {

	/** A reading older than this is not shown. */
	const FRESH_SECONDS = 7200;

	/** Most keys one run measures, so a runaway watch list cannot stall a cron request. */
	const MAX_PER_RUN = 50;

	const OPTION_VALUES = 'es_live_values';
	const OPTION_SENT   = 'es_live_values_sent';
	const HOOK_REFRESH  = 'es_live_values_refresh';
	const HOOK_TICK     = 'es_live_values_tick';
	const SCHEDULE      = 'es_live_values_fifteen_minutes';

	/** field => kind. */
	const FIELDS = [
		'price.today'        => 'money',
		'price.regular'      => 'money',
		'price.sale'         => 'money',
		'sale.ends'          => 'date',
		'coupon.discount'    => 'money',
		'coupon.percent_off' => 'percent',
		'coupon.price_after' => 'money',
		'coupon.expires'     => 'date',
		'coupon.uses_left'   => 'count',
	];

	const FORMATS = [
		'money'   => [ 'exact' ],
		'percent' => [ 'number' ],
		'date'    => [ 'datetime', 'weekday', 'short' ],
		'count'   => [],
	];

	/** ES_Commerce errors that are the shop's own answer, and so worth keeping. */
	const SHOP_ERRORS = [ 'not_found', 'choose_variation', 'no_store', 'no_code' ];

	/* ── Wiring ──────────────────────────────────────────────────────────── */

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		add_action( 'init', [ __CLASS__, 'ensure_tick' ] );
		add_action( self::HOOK_TICK, [ __CLASS__, 'refresh' ] );
		add_action( self::HOOK_REFRESH, [ __CLASS__, 'refresh' ] );

		// What moves a tiered coupon: an order using it, or giving it back.
		add_action( 'woocommerce_increase_coupon_usage_count', [ __CLASS__, 'nudge' ] );
		add_action( 'woocommerce_decrease_coupon_usage_count', [ __CLASS__, 'nudge' ] );
		// A theme can price by seats sold, which no coupon hook sees.
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'nudge' ] );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'nudge' ] );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'nudge' ] );
		// Somebody edited a coupon or a product, or stock moved.
		foreach ( [
			'woocommerce_new_coupon',
			'woocommerce_update_coupon',
			'woocommerce_trash_coupon',
			'woocommerce_delete_coupon',
			'woocommerce_update_product',
			'woocommerce_update_product_variation',
			'woocommerce_product_set_stock',
			'woocommerce_product_set_stock_status',
			'woocommerce_variation_set_stock_status',
		] as $hook ) {
			add_action( $hook, [ __CLASS__, 'nudge' ] );
		}
		// After WooCommerce's own wc_scheduled_sales (priority 10) has started or
		// ended today's dated sales.
		add_action( 'woocommerce_scheduled_sales', [ __CLASS__, 'nudge' ], 20 );

		// Fresh text for a page that may have come out of a page cache.
		add_action( 'wp_ajax_es_live_values', [ __CLASS__, 'serve_ajax' ] );
		add_action( 'wp_ajax_nopriv_es_live_values', [ __CLASS__, 'serve_ajax' ] );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = [
				'interval' => 15 * 60,
				'display'  => __( 'Every 15 minutes (Emblem Studio live values)', 'emblem-studio' ),
			];
		}

		return $schedules;
	}

	/** The safety-net timer, only on a site that has a shop to read. */
	public static function ensure_tick(): void {
		if ( ! class_exists( 'ES_Commerce' ) || ! ES_Commerce::available() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK_TICK );
		}
	}

	/** Something changed that could move a value: measure once, ten seconds from now. */
	public static function nudge(): void {
		if ( ! wp_next_scheduled( self::HOOK_REFRESH ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK_REFRESH );
		}
	}

	/* ── Measuring ───────────────────────────────────────────────────────── */

	/** The cron hooks' entry point. See run(). */
	public static function refresh(): void {
		self::run();
	}

	/**
	 * Measure every watched value, keep the answers, and hand on what changed.
	 *
	 * @return array{measured: int, changed: int}
	 */
	public static function run(): array {
		if ( ! class_exists( 'ES_Commerce' ) || ! ES_Commerce::available() ) {
			return [ 'measured' => 0, 'changed' => 0 ];
		}
		// One run at a time: a tick and a nudge landing together must not measure twice.
		if ( get_transient( 'es_live_values_lock' ) ) {
			// Something moved while a run was already measuring, and that run may have
			// read the shop before it moved. Look again shortly rather than wait for the
			// 15-minute timer.
			if ( ! wp_next_scheduled( self::HOOK_REFRESH ) ) {
				wp_schedule_single_event( time() + 30, self::HOOK_REFRESH );
			}

			return [ 'measured' => 0, 'changed' => 0 ];
		}
		set_transient( 'es_live_values_lock', 1, 120 );

		try {
			$watch  = array_slice( self::watch_list(), 0, self::MAX_PER_RUN, true );
			$stored = self::stored();
			$sent   = get_option( self::OPTION_SENT, [] );
			$sent   = is_array( $sent ) ? $sent : [];
			$now    = time();

			$changed   = [];
			$unchanged = [];
			foreach ( $watch as $key => $w ) {
				$answer = $w['coupon'] !== null
					? ES_Commerce::coupon_value( (string) $w['coupon'], (int) $w['product_id'] )
					: ES_Commerce::product( (int) $w['product_id'] );

				if ( ! self::is_shop_answer( $answer ) ) {
					continue; // A measuring failure says nothing about the shop.
				}
				unset( $answer['basis'] );

				$hash = self::hash( $answer );
				if ( isset( $sent[ $key ], $stored[ $key ] ) && $sent[ $key ] === $hash ) {
					$stored[ $key ]['confirmed_at'] = $now;
					$unchanged[]                    = $key;
					continue;
				}

				$stored[ $key ] = [
					'product_id'   => (int) $w['product_id'],
					'coupon'       => $w['coupon'],
					'answer'       => $answer,
					'as_of'        => $now,
					'confirmed_at' => $now,
				];
				$sent[ $key ]   = $hash;
				$changed[]      = [
					'key'        => $key,
					'product_id' => (int) $w['product_id'],
					'coupon'     => $w['coupon'],
					'answer'     => $answer,
				];
			}

			// Readings nothing uses any more go, so the option cannot grow forever.
			$stored = array_intersect_key( $stored, $watch );
			$sent   = array_intersect_key( $sent, $watch );
			update_option( self::OPTION_VALUES, $stored, false );
			update_option( self::OPTION_SENT, $sent, false );

			/**
			 * The connector sends these to Emblem Studio. A push that fails is
			 * not retried here: the next run finds the SaaS copy unconfirmed,
			 * and the SaaS reads the site itself after 20 minutes regardless.
			 *
			 * @param list<array<string, mixed>> $changed
			 * @param list<string>               $unchanged
			 */
			do_action( 'es_live_values_measured', $changed, $unchanged );

			return [ 'measured' => count( $changed ) + count( $unchanged ), 'changed' => count( $changed ) ];
		} finally {
			delete_transient( 'es_live_values_lock' );
		}
	}

	/**
	 * Every value the site's assets use: key => { key, product_id, coupon }.
	 *
	 * Connected, the connector answers with Emblem Studio's list, because there
	 * the assets live in the SaaS. Standalone, it is read from this site's assets,
	 * their live A/B variants and their queued content changes.
	 *
	 * @return array<string, array{key: string, product_id: int, coupon: ?string}>
	 */
	public static function watch_list(): array {
		$remote = apply_filters( 'es_live_values_watch_list', null );
		if ( is_array( $remote ) ) {
			return $remote;
		}

		return self::keys_in( self::local_htmls() );
	}

	/** @return list<string> */
	private static function local_htmls(): array {
		global $wpdb;
		$forms  = ES_Install::table( 'forms' );
		$like   = '%' . $wpdb->esc_like( 'data-es-live' ) . '%';
		$htmls  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT html FROM {$forms} WHERE trashed_at IS NULL AND archived_at IS NULL AND html LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$htmls  = array_merge( $htmls, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT html FROM ' . ES_Install::table( 'variants' ) . " WHERE status = 'live' AND html LIKE %s", $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			$htmls = array_merge( $htmls, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT html FROM ' . ES_Install::table( 'form_scheduled_revisions' ) . " WHERE status = 'queued' AND html LIKE %s", $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return array_map( 'strval', $htmls );
	}

	/**
	 * @param list<string> $htmls
	 * @return array<string, array{key: string, product_id: int, coupon: ?string}>
	 */
	public static function keys_in( array $htmls ): array {
		$keys = [];
		foreach ( $htmls as $html ) {
			foreach ( self::markers( $html ) as $m ) {
				if ( $m['key'] !== null && ! isset( $keys[ $m['key'] ] ) ) {
					$keys[ $m['key'] ] = [
						'key'        => $m['key'],
						'product_id' => (int) $m['product_id'],
						'coupon'     => strpos( $m['key'], 'c:' ) === 0 ? $m['coupon'] : null,
					];
				}
			}
		}

		return $keys;
	}

	/** @return array<string, array{product_id: int, coupon: ?string, answer: array<string, mixed>, as_of: int, confirmed_at: int}> */
	public static function stored(): array {
		$stored = get_option( self::OPTION_VALUES, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/** @param array<string, mixed> $answer */
	public static function is_shop_answer( array $answer ): bool {
		return ! empty( $answer['ok'] ) || in_array( $answer['error'] ?? '', self::SHOP_ERRORS, true );
	}

	/** @param array<string, mixed> $answer */
	private static function hash( array $answer ): string {
		unset( $answer['as_of'] );

		return md5( (string) wp_json_encode( $answer ) );
	}

	/* ── Serving ─────────────────────────────────────────────────────────── */

	/**
	 * The `live` map for an asset's HTML from this site's own readings, or null
	 * when the HTML has no markers at all.
	 *
	 * @return array<string, array<string, string>>|null
	 */
	public static function resolve_html( string $html ): ?array {
		$markers = self::markers( $html );
		if ( $markers === [] ) {
			return null;
		}

		return self::resolve( $markers, self::stored(), time() );
	}

	/**
	 * @param list<array<string, mixed>> $markers
	 * @param array<string, array<string, mixed>> $stored
	 * @return array<string, array<string, string>>
	 */
	public static function resolve( array $markers, array $stored, int $now ): array {
		$live = [];
		foreach ( $markers as $m ) {
			if ( $m['problem'] !== null || $m['key'] === null ) {
				continue;
			}
			$verdict = self::evaluate( (string) $m['field'], $m['format'], $stored[ $m['key'] ] ?? null, $now );
			if ( $verdict['ok'] ) {
				$live[ $m['key'] ][ $m['name'] ] = (string) $verdict['text'];
			}
		}

		return $live;
	}

	/**
	 * admin-ajax: fresh text for the markers a page shows. Takes
	 * `m` = JSON [[key, name], ...] and answers with only the safe ones.
	 */
	public static function serve_ajax(): void {
		$raw   = isset( $_GET['m'] ) ? wp_unslash( (string) $_GET['m'] ) : '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$pairs = json_decode( $raw, true );
		$pairs = is_array( $pairs ) ? array_slice( $pairs, 0, 100 ) : [];

		$markers = [];
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || count( $pair ) !== 2 || ! is_string( $pair[0] ) || ! is_string( $pair[1] ) ) {
				continue;
			}
			$parts = explode( '@', $pair[1], 2 );
			if ( ! isset( self::FIELDS[ $parts[0] ] ) ) {
				continue;
			}
			$markers[] = [
				'key'     => $pair[0],
				'field'   => $parts[0],
				'format'  => $parts[1] ?? null,
				'name'    => $pair[1],
				'problem' => null,
			];
		}

		nocache_headers();
		wp_send_json( [ 'ok' => true, 'live' => (object) self::resolve( $markers, self::stored(), time() ) ] );
	}

	/* ── The pure part: markers, keys, judging, writing ─────────────────── */

	/**
	 * @return list<array{field: string, format: ?string, name: string, product_id: ?int, coupon: ?string, key: ?string, fallback: string, problem: ?string}>
	 */
	public static function markers( string $html ): array {
		if ( strpos( $html, 'data-es-live' ) === false ) {
			return [];
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$out   = [];
		$nodes = ( new DOMXPath( $dom ) )->query( '//*[@data-es-live]' );
		foreach ( $nodes ?: [] as $el ) {
			if ( $el instanceof DOMElement ) {
				$out[] = self::describe( $el );
			}
		}

		return $out;
	}

	/** @return array{field: string, format: ?string, name: string, product_id: ?int, coupon: ?string, key: ?string, fallback: string, problem: ?string} */
	private static function describe( DOMElement $el ): array {
		$field       = strtolower( trim( $el->getAttribute( 'data-es-live' ) ) );
		$format      = strtolower( trim( $el->getAttribute( 'data-es-format' ) ) );
		$format      = $format === '' ? null : $format;
		$raw_product = trim( $el->getAttribute( 'data-es-product' ) );
		$product_id  = preg_match( '/^\d+$/', $raw_product ) && (int) $raw_product > 0 ? (int) $raw_product : null;
		$coupon      = trim( $el->getAttribute( 'data-es-coupon' ) );
		$coupon      = $coupon === '' ? null : $coupon;
		$fallback    = trim( (string) preg_replace( '/\s+/u', ' ', $el->textContent ) );

		$nested = false;
		for ( $p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode ) {
			if ( $p->hasAttribute( 'data-es-live' ) ) {
				$nested = true;
				break;
			}
		}

		if ( ! isset( self::FIELDS[ $field ] ) ) {
			$problem = 'unknown_field';
		} elseif ( $product_id === null ) {
			$problem = 'no_product';
		} elseif ( strpos( $field, 'coupon.' ) === 0 && $coupon === null ) {
			$problem = 'no_coupon';
		} elseif ( $fallback === '' ) {
			$problem = 'empty_fallback';
		} elseif ( $nested ) {
			$problem = 'nested';
		} else {
			$problem = null;
		}

		return [
			'field'      => $field,
			'format'     => $format,
			'name'       => $format === null ? $field : $field . '@' . $format,
			'product_id' => $product_id,
			'coupon'     => $coupon,
			'key'        => ( $problem === null || $problem === 'empty_fallback' ) ? self::key_for( $field, $product_id, $coupon ) : null,
			'fallback'   => $fallback,
			'problem'    => $problem,
		];
	}

	public static function key_for( string $field, ?int $product_id, ?string $coupon ): ?string {
		if ( ! isset( self::FIELDS[ $field ] ) || ! $product_id || $product_id < 1 ) {
			return null;
		}
		if ( strpos( $field, 'coupon.' ) !== 0 ) {
			return 'p:' . $product_id;
		}
		$code = self::normalize_code( (string) $coupon );

		return $code === '' ? null : 'c:' . $product_id . ':' . $code;
	}

	/** How WooCommerce compares codes: wc_format_coupon_code(), then lower case. */
	public static function normalize_code( string $code ): string {
		return mb_strtolower( trim( html_entity_decode( $code, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * One field, from one stored reading.
	 *
	 * @param array<string, mixed>|null $row { answer, as_of, confirmed_at }
	 * @return array{ok: bool, text: ?string, reason: ?string}
	 */
	public static function evaluate( string $field, ?string $format, ?array $row, int $now ): array {
		if ( ! $row ) {
			return self::no( 'not_measured' );
		}
		$fresh_at = max( (int) ( $row['as_of'] ?? 0 ), (int) ( $row['confirmed_at'] ?? 0 ) );
		if ( $fresh_at < $now - self::FRESH_SECONDS ) {
			return self::no( 'stale' );
		}

		$a = (array) ( $row['answer'] ?? [] );
		if ( empty( $a['ok'] ) ) {
			$map = [ 'not_found' => 'not_found', 'choose_variation' => 'options', 'no_store' => 'no_store' ];

			return self::no( $map[ $a['error'] ?? '' ] ?? 'error' );
		}

		$currency = (array) ( $a['currency'] ?? [] );
		$kind     = self::FIELDS[ $field ] ?? null;
		$format   = in_array( $format, self::FORMATS[ $kind ] ?? [], true ) ? $format : null;
		$product  = (array) ( $a['product'] ?? [] );

		if ( strpos( $field, 'coupon.' ) === 0 ) {
			$coupon = (array) ( $a['coupon'] ?? [] );
			if ( array_key_exists( 'found', $coupon ) && ! $coupon['found'] ) {
				return self::no( 'not_found' );
			}
			if ( empty( $a['applies'] ) ) {
				return self::no( 'refused' );
			}
			if ( empty( $product['buyable_now'] ) ) {
				return self::no( 'out_of_stock' );
			}
			$discount = (float) ( $a['discount'] ?? 0 );
			if ( $discount <= 0 ) {
				return self::no( 'zero' );
			}

			switch ( $field ) {
				case 'coupon.discount':
					return self::yes( self::money( $discount, $currency, $format ) );
				case 'coupon.percent_off':
					return self::percent( (float) ( $a['percent_off'] ?? 0 ), $format );
				case 'coupon.price_after':
					return (float) ( $a['price_after'] ?? 0 ) > 0 ? self::yes( self::money( (float) $a['price_after'], $currency, $format ) ) : self::no( 'free' );
				case 'coupon.expires':
					return self::moment( $coupon['expires'] ?? null, $format, $now, 'no_expiry', 'expired' );
				case 'coupon.uses_left':
					if ( ! isset( $coupon['uses_left'] ) ) {
						return self::no( 'no_limit' );
					}

					return (int) $coupon['uses_left'] > 0 ? self::yes( (string) (int) $coupon['uses_left'] ) : self::no( 'used_up' );
			}

			return self::no( 'unknown_field' );
		}

		if ( empty( $product['buyable_now'] ) ) {
			return self::no( 'out_of_stock' );
		}
		$running = ( $product['sale_status'] ?? '' ) === 'running';

		switch ( $field ) {
			case 'price.today':
				return self::positive_money( $product['price_today'] ?? null, $currency, $format, ! empty( $product['price_range'] ) ? 'options' : 'no_price' );
			case 'price.regular':
				return self::positive_money( $product['regular_price'] ?? null, $currency, $format, 'no_price' );
			case 'price.sale':
				return $running ? self::positive_money( $product['sale_price'] ?? null, $currency, $format, 'no_price' ) : self::no( 'no_sale' );
			case 'sale.ends':
				return $running ? self::moment( $product['sale_ends'] ?? null, $format, $now, 'no_end', 'ended' ) : self::no( 'no_sale' );
		}

		return self::no( 'unknown_field' );
	}

	/** @return array{ok: bool, text: ?string, reason: ?string} */
	private static function no( string $reason ): array {
		return [ 'ok' => false, 'text' => null, 'reason' => $reason ];
	}

	/** @return array{ok: bool, text: ?string, reason: ?string} */
	private static function yes( string $text ): array {
		return [ 'ok' => true, 'text' => $text, 'reason' => null ];
	}

	/**
	 * @param mixed                $amount
	 * @param array<string, mixed> $currency
	 * @return array{ok: bool, text: ?string, reason: ?string}
	 */
	private static function positive_money( $amount, array $currency, ?string $format, string $when_missing ): array {
		if ( $amount === null || $amount === '' ) {
			return self::no( $when_missing );
		}
		if ( (float) $amount <= 0 ) {
			return self::no( 'zero' );
		}

		return self::yes( self::money( (float) $amount, $currency, $format ) );
	}

	/** @return array{ok: bool, text: ?string, reason: ?string} */
	private static function percent( float $percent, ?string $format ): array {
		$whole = (int) floor( $percent + 1e-6 );
		if ( $whole < 1 ) {
			return self::no( 'zero' );
		}

		return self::yes( $format === 'number' ? (string) $whole : $whole . '%' );
	}

	/**
	 * @param mixed $when { local: "Y-m-d H:i", utc: ISO 8601 }
	 * @return array{ok: bool, text: ?string, reason: ?string}
	 */
	private static function moment( $when, ?string $format, int $now, string $when_missing, string $when_past ): array {
		if ( ! is_array( $when ) || empty( $when['local'] ) ) {
			return self::no( $when_missing );
		}
		if ( ! empty( $when['utc'] ) ) {
			$at = strtotime( (string) $when['utc'] );
			if ( $at !== false && $at <= $now ) {
				return self::no( $when_past );
			}
		}
		$text = self::date( (string) $when['local'], $format );

		return $text === null ? self::no( $when_missing ) : self::yes( $text );
	}

	/** @param array<string, mixed> $currency */
	public static function money( float $amount, array $currency, ?string $format = null ): string {
		$decimals = max( 0, (int) ( $currency['decimals'] ?? 2 ) );
		$whole    = abs( $amount - round( $amount ) ) < 0.005;
		$places   = $format !== 'exact' && $whole ? 0 : $decimals;
		$number   = number_format(
			$amount,
			$places,
			(string) ( $currency['decimal_separator'] ?? '.' ),
			(string) ( $currency['thousand_separator'] ?? ',' )
		);
		$symbol   = (string) ( $currency['symbol'] ?? '' );
		if ( $symbol === '' ) {
			$code = (string) ( $currency['code'] ?? '' );

			return $code === '' ? $number : $number . ' ' . $code;
		}

		switch ( $currency['position'] ?? 'left' ) {
			case 'right':
				return $number . $symbol;
			case 'left_space':
				return $symbol . ' ' . $number;
			case 'right_space':
				return $number . ' ' . $symbol;
		}

		return $symbol . $number;
	}

	/** A shop-clock "Y-m-d H:i" as words. Exactly midnight is the end of the day before. */
	public static function date( string $local, ?string $format = null ): ?string {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $local, new DateTimeZone( 'UTC' ) );
		if ( ! $at ) {
			return null;
		}
		if ( $at->format( 'H:i' ) === '00:00' ) {
			$at = $at->modify( '-1 minute' );
		}
		$patterns = [ 'datetime' => 'F j, g:i A', 'weekday' => 'l, F j', 'short' => 'M j' ];

		return $at->format( $patterns[ (string) $format ] ?? 'F j' );
	}
}
