<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Live_Values {

	const FRESH_SECONDS = 7200;

	const MAX_PER_RUN = 50;

	const OPTION_VALUES = 'es_live_values';
	const OPTION_SENT   = 'es_live_values_sent';
	const HOOK_REFRESH  = 'es_live_values_refresh';
	const HOOK_TICK     = 'es_live_values_tick';
	const SCHEDULE      = 'es_live_values_fifteen_minutes';

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

	const SHOP_ERRORS = [ 'not_found', 'choose_variation', 'no_store', 'no_code' ];

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		add_action( 'init', [ __CLASS__, 'ensure_tick' ] );
		add_action( self::HOOK_TICK, [ __CLASS__, 'refresh' ] );
		add_action( self::HOOK_REFRESH, [ __CLASS__, 'refresh' ] );

		add_action( 'woocommerce_increase_coupon_usage_count', [ __CLASS__, 'nudge' ] );
		add_action( 'woocommerce_decrease_coupon_usage_count', [ __CLASS__, 'nudge' ] );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'nudge' ] );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'nudge' ] );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'nudge' ] );
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
		add_action( 'woocommerce_scheduled_sales', [ __CLASS__, 'nudge' ], 20 );

		add_action( 'wp_ajax_es_live_values', [ __CLASS__, 'serve_ajax' ] );
		add_action( 'wp_ajax_nopriv_es_live_values', [ __CLASS__, 'serve_ajax' ] );
	}

	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = [
				'interval' => 15 * 60,
				'display'  => __( 'Every 15 minutes (Emblem Studio live values)', 'emblem-studio' ),
			];
		}

		return $schedules;
	}

	public static function ensure_tick(): void {
		if ( ! class_exists( 'Emblem_Studio_Commerce' ) || ! Emblem_Studio_Commerce::available() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK_TICK );
		}
	}

	public static function nudge(): void {
		if ( ! wp_next_scheduled( self::HOOK_REFRESH ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK_REFRESH );
		}
	}

	public static function refresh(): void {
		self::run();
	}

	public static function run(): array {
		if ( ! class_exists( 'Emblem_Studio_Commerce' ) || ! Emblem_Studio_Commerce::available() ) {
			return [ 'measured' => 0, 'changed' => 0 ];
		}
		if ( get_transient( 'es_live_values_lock' ) ) {
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
					? Emblem_Studio_Commerce::coupon_value( (string) $w['coupon'], (int) $w['product_id'] )
					: Emblem_Studio_Commerce::product( (int) $w['product_id'] );

				if ( ! self::is_shop_answer( $answer ) ) {
					continue;
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

			$stored = array_intersect_key( $stored, $watch );
			$sent   = array_intersect_key( $sent, $watch );
			update_option( self::OPTION_VALUES, $stored, false );
			update_option( self::OPTION_SENT, $sent, false );

			do_action( 'es_live_values_measured', $changed, $unchanged );

			return [ 'measured' => count( $changed ) + count( $unchanged ), 'changed' => count( $changed ) ];
		} finally {
			delete_transient( 'es_live_values_lock' );
		}
	}

	public static function watch_list(): array {
		$remote = apply_filters( 'es_live_values_watch_list', null );
		if ( is_array( $remote ) ) {
			return $remote;
		}

		return self::keys_in( self::local_htmls() );
	}

	private static function local_htmls(): array {
		global $wpdb;
		$forms  = Emblem_Studio_Install::table( 'forms' );
		$like   = '%' . $wpdb->esc_like( 'data-es-live' ) . '%';
		$htmls  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT html FROM {$forms} WHERE trashed_at IS NULL AND archived_at IS NULL AND html LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$htmls  = array_merge( $htmls, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT html FROM ' . Emblem_Studio_Install::table( 'variants' ) . " WHERE status = 'live' AND html LIKE %s", $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( class_exists( 'Emblem_Studio_Form_Scheduled_Revisions' ) ) {
			$htmls = array_merge( $htmls, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT html FROM ' . Emblem_Studio_Install::table( 'form_scheduled_revisions' ) . " WHERE status = 'queued' AND html LIKE %s", $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return array_map( 'strval', $htmls );
	}

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

	public static function stored(): array {
		$stored = get_option( self::OPTION_VALUES, [] );

		return is_array( $stored ) ? $stored : [];
	}

	public static function is_shop_answer( array $answer ): bool {
		return ! empty( $answer['ok'] ) || in_array( $answer['error'] ?? '', self::SHOP_ERRORS, true );
	}

	private static function hash( array $answer ): string {
		unset( $answer['as_of'] );

		return md5( (string) wp_json_encode( $answer ) );
	}

	public static function resolve_html( string $html ): ?array {
		$markers = self::markers( $html );
		if ( $markers === [] ) {
			return null;
		}

		return self::resolve( $markers, self::stored(), time() );
	}

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

	public static function normalize_code( string $code ): string {
		return mb_strtolower( trim( html_entity_decode( $code, ENT_QUOTES, 'UTF-8' ) ) );
	}

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

	private static function no( string $reason ): array {
		return [ 'ok' => false, 'text' => null, 'reason' => $reason ];
	}

	private static function yes( string $text ): array {
		return [ 'ok' => true, 'text' => $text, 'reason' => null ];
	}

	private static function positive_money( $amount, array $currency, ?string $format, string $when_missing ): array {
		if ( $amount === null || $amount === '' ) {
			return self::no( $when_missing );
		}
		if ( (float) $amount <= 0 ) {
			return self::no( 'zero' );
		}

		return self::yes( self::money( (float) $amount, $currency, $format ) );
	}

	private static function percent( float $percent, ?string $format ): array {
		$whole = (int) floor( $percent + 1e-6 );
		if ( $whole < 1 ) {
			return self::no( 'zero' );
		}

		return self::yes( $format === 'number' ? (string) $whole : $whole . '%' );
	}

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
