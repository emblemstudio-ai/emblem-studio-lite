<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Commerce {

	private const MAX_VARIATIONS = 25;

	public static function available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	private static function no_store(): array {
		return [
			'ok'      => false,
			'error'   => 'no_store',
			'message' => 'This site has no WooCommerce store.',
		];
	}

	public static function product( int $product_id ): array {
		if ( ! self::available() ) {
			return self::no_store();
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return [
				'ok'      => false,
				'error'   => 'not_found',
				'message' => sprintf( 'No product with id %d on this site.', $product_id ),
			];
		}

		return [
			'ok'            => true,
			'as_of'         => gmdate( 'c' ),
			'shop_timezone' => self::shop_timezone(),
			'currency'      => self::currency(),
			'product'       => self::price_shape( $product, true ),
		];
	}

	public static function search( string $term, int $limit = 10 ): array {
		if ( ! self::available() || ! function_exists( 'wc_get_products' ) ) {
			return self::no_store();
		}

		$term  = trim( $term );
		$limit = max( 1, min( 50, $limit ) );

		$args = [
			'status' => 'publish',
			'limit'  => $limit,
		];
		if ( $term !== '' ) {
			$args['s'] = $term;
		}

		$found = wc_get_products( $args );
		$rows  = [];
		foreach ( (array) $found as $product ) {
			if ( $product instanceof WC_Product ) {
				$rows[] = self::price_shape( $product, false );
			}
		}

		return [
			'ok'            => true,
			'as_of'         => gmdate( 'c' ),
			'shop_timezone' => self::shop_timezone(),
			'currency'      => self::currency(),
			'query'         => $term,
			'products'      => $rows,
		];
	}

	public static function coupon_value( string $code, int $product_id, int $quantity = 1 ): array {
		if ( ! self::available() || ! class_exists( 'WC_Discounts' ) || ! class_exists( 'WC_Coupon' ) || ! function_exists( 'WC' ) ) {
			return self::no_store();
		}

		$code = trim( $code );
		if ( $code === '' ) {
			return [
				'ok'      => false,
				'error'   => 'no_code',
				'message' => 'Give the coupon code to check.',
			];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return [
				'ok'      => false,
				'error'   => 'not_found',
				'message' => sprintf( 'No product with id %d on this site.', $product_id ),
			];
		}

		if ( $product instanceof WC_Product_Variable ) {
			return [
				'ok'         => false,
				'error'      => 'choose_variation',
				'message'    => sprintf( '"%s" comes in options, and a coupon is measured against one of them. Ask again with a variation id.', $product->get_name() ),
				'variations' => array_map( 'intval', (array) $product->get_children() ),
			];
		}

		$quantity = max( 1, min( 100, $quantity ) );
		$unit     = (float) $product->get_price();
		$before   = self::round_money( $unit * $quantity );
		$regular  = self::money( $product->get_regular_price() );

		$answer = [
			'ok'            => true,
			'as_of'         => gmdate( 'c' ),
			'shop_timezone' => self::shop_timezone(),
			'currency'      => self::currency(),
			'coupon'        => [ 'code' => $code ],
			'product'       => [
				'id'            => (int) $product->get_id(),
				'name'          => (string) $product->get_name(),
				'regular_price' => $regular,
				'price_today'   => self::money( $product->get_price() ),
				'in_stock'      => (bool) $product->is_in_stock(),
				'purchasable'   => (bool) $product->is_purchasable(),
				'buyable_now'   => (bool) ( $product->is_in_stock() && $product->is_purchasable() ),
			],
			'quantity'      => $quantity,
			'basis'         => 'Measured by applying the coupon at checkout for a shopper with no purchase history and nothing else in the basket, at the moment of this call. A coupon whose value depends on the shopper, on the rest of the basket, or on how many times it has been used can answer differently for a real buyer and later today.',
			'warnings'      => [],
		];

		if ( ! $product->is_in_stock() ) {
			$answer['warnings'][] = 'This product is out of stock, so nobody can buy it with this code right now.';
		} elseif ( ! $product->is_purchasable() ) {
			$answer['warnings'][] = 'This product cannot be bought right now, so nobody can use this code on it.';
		}

		require_once __DIR__ . '/class-es-commerce-basket.php';

		$wc                = WC();
		$previous_cart     = $wc->cart;
		$previous_customer = $wc->customer;

		try {
			$basket       = new Emblem_Studio_Commerce_Basket( $product, $quantity );
			$wc->cart     = $basket;
			$wc->customer = $basket->get_customer();

			$coupon = new WC_Coupon( $code );
			$found  = $coupon->get_id() > 0 || (bool) $coupon->get_virtual();

			$answer['coupon']['found'] = $found;
			if ( $found ) {
				$answer['coupon'] += self::coupon_facts( $coupon );
			}

			$discounts = new WC_Discounts( $basket );
			$applied   = $discounts->apply_coupon( $coupon );

			if ( is_wp_error( $applied ) ) {
				$answer['applies']       = false;
				$answer['reason']        = self::plain( $applied->get_error_message() );
				$answer['likely_reason'] = $found ? self::likely_refusal( $coupon, $product, $before ) : 'does_not_exist';

				return $answer;
			}

			$discount = self::total_of( $discounts );

			$other_qty   = $quantity === 1 ? 2 : 1;
			$other_value = self::measure( $wc, $product, $code, $other_qty );
		} catch ( \Throwable $e ) {
			return [
				'ok'      => false,
				'error'   => 'cannot_measure',
				'message' => 'The shop\'s own coupon code failed while the discount was being measured, so there is no reliable value to report. ' . self::plain( substr( $e->getMessage(), 0, 200 ) ),
			];
		} finally {
			$wc->cart     = $previous_cart;
			$wc->customer = $previous_customer;
		}

		$discount = self::round_money( min( $discount, $before ) );
		$after    = self::round_money( $before - $discount );

		$one_item = $quantity === 1 ? $discount : ( $other_value ?? 0.0 );
		$one_item = self::round_money( min( $one_item, $unit ) );

		$answer['applies']               = true;
		$answer['price_before']          = $before;
		$answer['discount']              = $discount;
		$answer['discount_for_one_item'] = $one_item;
		$answer['price_after']           = $after;
		$answer['how_it_scales']         = $quantity === 1
			? self::how_it_scales( $one_item, $other_value, 2 )
			: self::how_it_scales( $one_item, $discount, $quantity );

		$answer['percent_off'] = $before > 0 ? round( $discount / $before * 100, 2 ) : 0.0;

		$normal       = $regular !== null ? self::round_money( $regular * $quantity ) : null;
		$sale_running = $normal !== null && $normal > $before;
		$both         = $discount > 0 && $sale_running;

		$answer['sale_and_code_saving']      = $both ? self::round_money( $normal - $after ) : null;
		$answer['sale_and_code_percent_off'] = $both ? round( ( $normal - $after ) / $normal * 100, 2 ) : null;

		$answer['capped_at_price'] = $discount > 0 && $discount >= $before;
		$answer['reason']          = $discount > 0 ? null : 'WooCommerce accepts this coupon for this product, and it takes nothing off.';

		if ( $answer['capped_at_price'] ) {
			$answer['warnings'][] = 'The coupon is worth more than this product costs, so it takes off the whole price. Do not call that "free" unless free is the offer.';
		}

		$expected = self::expected_from_settings(
			(string) $coupon->get_discount_type( 'edit' ),
			(float) $coupon->get_amount( 'edit' ),
			$unit,
			$quantity
		);
		$answer['value_set_by'] = self::value_set_by( $expected, $discount );

		$answer['best_described_as'] = $answer['value_set_by'] === 'coupon' && (string) $coupon->get_discount_type( 'edit' ) === 'percent'
			? 'percent'
			: 'amount';

		if ( $answer['how_it_scales'] === 'once_per_basket' ) {
			$answer['warnings'][] = sprintf(
				'This coupon takes %s off the whole basket once, not off each item. Buying more does not add to the discount, so do not write "off each".',
				self::money_text( $one_item )
			);
		} elseif ( $answer['how_it_scales'] === 'varies' ) {
			$answer['warnings'][] = 'What this coupon takes off depends on how much is in the basket, so the number above is only true for this quantity.';
		}

		if ( $answer['value_set_by'] === 'shop' ) {
			$answer['warnings'][] = 'The shop works this coupon\'s value out with a rule of its own. Emblem Studio sees the result, not the rule, so it cannot say why or when the value will change. It can fall, even to nothing, without anyone editing the coupon, and the coupon having no use limit does not make it stable. Write "currently", and check again right before publishing.';
		}

		if ( $coupon->get_exclude_sale_items() && self::sale_status_of( $product ) === 'scheduled' ) {
			$starts               = $product->get_date_on_sale_from();
			$answer['warnings'][] = sprintf(
				'This code does not work on products that are on sale, and this product\'s sale starts %s shop time. From then, the shop will refuse this code on it.',
				$starts ? $starts->date( 'Y-m-d H:i' ) : 'soon'
			);
		}

		return $answer;
	}

	private static function measure( WooCommerce $wc, WC_Product $product, string $code, int $quantity ): ?float {
		$basket   = new Emblem_Studio_Commerce_Basket( $product, $quantity );
		$wc->cart = $basket;

		$discounts = new WC_Discounts( $basket );
		if ( is_wp_error( $discounts->apply_coupon( new WC_Coupon( $code ) ) ) ) {
			return null;
		}

		return self::total_of( $discounts );
	}

	private static function total_of( WC_Discounts $discounts ): float {
		$total = 0.0;
		foreach ( (array) $discounts->get_discounts_by_coupon() as $amount ) {
			$total += (float) $amount;
		}

		return self::round_money( $total );
	}

	public static function how_it_scales( float $one_item, ?float $n_items, int $n ): string {
		if ( $one_item <= 0 ) {
			return 'none';
		}
		if ( $n_items === null || $n < 2 ) {
			return 'varies';
		}
		if ( abs( $n_items - $one_item * $n ) <= 0.011 * $n ) {
			return 'each_item';
		}
		if ( abs( $n_items - $one_item ) <= 0.011 ) {
			return 'once_per_basket';
		}

		return 'varies';
	}

	private static function money_text( float $amount ): string {
		$currency = self::currency();

		return $currency['symbol'] . number_format( $amount, (int) $currency['decimals'] );
	}

	private static function coupon_facts( WC_Coupon $coupon ): array {
		$limit = (int) $coupon->get_usage_limit();
		$used  = (int) $coupon->get_usage_count();

		return [
			'expires'                  => self::when( $coupon->get_date_expires() ),
			'times_used'               => $used,
			'usage_limit'              => $limit > 0 ? $limit : null,
			'uses_left'                => $limit > 0 ? max( 0, $limit - $used ) : null,
			'usage_limit_per_customer' => (int) $coupon->get_usage_limit_per_user() > 0 ? (int) $coupon->get_usage_limit_per_user() : null,
			'minimum_spend'            => (float) $coupon->get_minimum_amount() > 0 ? self::money( $coupon->get_minimum_amount() ) : null,
			'maximum_spend'            => (float) $coupon->get_maximum_amount() > 0 ? self::money( $coupon->get_maximum_amount() ) : null,
		];
	}

	private static function likely_refusal( WC_Coupon $coupon, WC_Product $product, float $basket ): ?string {
		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() < time() ) {
			return 'expired';
		}

		$limit = (int) $coupon->get_usage_limit();
		if ( $limit > 0 && (int) $coupon->get_usage_count() >= $limit ) {
			return 'usage_limit_reached';
		}

		if ( $coupon->get_exclude_sale_items() && $product->is_on_sale() ) {
			return 'not_for_sale_items';
		}

		$ids = [ (int) $product->get_id(), (int) $product->get_parent_id() ];

		$only = array_map( 'intval', (array) $coupon->get_product_ids() );
		if ( $only && ! array_intersect( $ids, $only ) ) {
			return 'only_for_other_products';
		}
		if ( array_intersect( $ids, array_map( 'intval', (array) $coupon->get_excluded_product_ids() ) ) ) {
			return 'product_excluded';
		}

		$cats = function_exists( 'wc_get_product_cat_ids' )
			? array_map( 'intval', (array) wc_get_product_cat_ids( $product->get_parent_id() ?: $product->get_id() ) )
			: [];
		$only_cats = array_map( 'intval', (array) $coupon->get_product_categories() );
		if ( $only_cats && ! array_intersect( $cats, $only_cats ) ) {
			return 'only_for_other_categories';
		}
		if ( array_intersect( $cats, array_map( 'intval', (array) $coupon->get_excluded_product_categories() ) ) ) {
			return 'category_excluded';
		}

		$minimum = (float) $coupon->get_minimum_amount();
		if ( $minimum > 0 && $basket < $minimum ) {
			return 'below_minimum_spend';
		}
		$maximum = (float) $coupon->get_maximum_amount();
		if ( $maximum > 0 && $basket > $maximum ) {
			return 'above_maximum_spend';
		}

		if ( (array) $coupon->get_email_restrictions() ) {
			return 'only_for_certain_customers';
		}

		return null;
	}

	public static function expected_from_settings( string $type, float $amount, float $unit_price, int $quantity ): ?float {
		$line = $unit_price * max( 1, $quantity );

		switch ( $type ) {
			case 'percent':
				return round( $line * $amount / 100, 2 );
			case 'fixed_product':
				return round( min( $amount, $unit_price ) * max( 1, $quantity ), 2 );
			case 'fixed_cart':
				return round( min( $amount, $line ), 2 );
			default:
				return null;
		}
	}

	public static function value_set_by( ?float $expected, float $measured ): string {
		if ( $expected === null ) {
			return 'unknown';
		}

		return abs( $expected - $measured ) <= 0.011 ? 'coupon' : 'shop';
	}

	private static function plain( string $text ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function price_shape( WC_Product $product, bool $deep ): array {
		$shape = [
			'id'            => (int) $product->get_id(),
			'name'          => (string) $product->get_name(),
			'type'          => (string) $product->get_type(),
			'sku'           => (string) $product->get_sku(),
			'url'           => (string) $product->get_permalink(),
			'purchasable'   => (bool) $product->is_purchasable(),
			'in_stock'      => (bool) $product->is_in_stock(),
			'buyable_now'   => (bool) ( $product->is_in_stock() && $product->is_purchasable() ),
			'on_sale_now'   => (bool) $product->is_on_sale(),
			'sale_status'   => self::sale_status_of( $product ),
			'regular_price' => self::money( $product->get_regular_price() ),
			'sale_price'    => self::money( $product->get_sale_price() ),
			'price_today'   => self::money( $product->get_price() ),
			'sale_starts'   => self::when( $product->get_date_on_sale_from() ),
			'sale_ends'     => self::when( $product->get_date_on_sale_to() ),
			'price_range'   => null,
			'variations'    => null,
		];

		if ( ! $product instanceof WC_Product_Variable ) {
			return $shape;
		}

		$prices  = $product->get_variation_prices();
		$options = 0;
		$on_sale = 0;
		foreach ( (array) ( $prices['price'] ?? [] ) as $id => $price ) {
			$options++;
			if ( isset( $prices['regular_price'][ $id ] ) && (float) $price < (float) $prices['regular_price'][ $id ] ) {
				$on_sale++;
			}
		}
		$shape['options']         = $options;
		$shape['options_on_sale'] = $on_sale;
		$shape['sale_status']     = self::options_sale_status( $on_sale, $options );

		$shape['price_today'] = null;

		$shape['price_range'] = [
			'min' => self::money( $product->get_variation_price( 'min' ) ),
			'max' => self::money( $product->get_variation_price( 'max' ) ),
		];

		if ( ! $deep ) {
			return $shape;
		}

		$children   = (array) $product->get_children();
		$variations = [];
		$seen       = 0;
		foreach ( $children as $child_id ) {
			if ( $seen >= self::MAX_VARIATIONS ) {
				break;
			}
			$child = wc_get_product( (int) $child_id );
			if ( ! $child instanceof WC_Product ) {
				continue;
			}
			$seen++;
			$variations[] = [
				'id'            => (int) $child->get_id(),
				'name'          => (string) $child->get_name(),
				'in_stock'      => (bool) $child->is_in_stock(),
				'on_sale_now'   => (bool) $child->is_on_sale(),
				'sale_status'   => self::sale_status_of( $child ),
				'regular_price' => self::money( $child->get_regular_price() ),
				'sale_price'    => self::money( $child->get_sale_price() ),
				'price_today'   => self::money( $child->get_price() ),
				'sale_starts'   => self::when( $child->get_date_on_sale_from() ),
				'sale_ends'     => self::when( $child->get_date_on_sale_to() ),
			];
		}
		$shape['variations'] = $variations;

		$shape['variations_total']    = count( $children );
		$shape['variations_complete'] = count( $children ) <= self::MAX_VARIATIONS;

		return $shape;
	}

	private static function sale_status_of( WC_Product $product ): string {
		$from = $product->get_date_on_sale_from();
		$to   = $product->get_date_on_sale_to();

		return self::sale_status(
			self::money( $product->get_sale_price() ),
			(bool) $product->is_on_sale(),
			$from ? (int) $from->getTimestamp() : null,
			$to ? (int) $to->getTimestamp() : null,
			time()
		);
	}

	public static function sale_status( ?float $sale_price, bool $on_sale_now, ?int $starts, ?int $ends, int $now ): string {
		if ( $on_sale_now ) {
			return 'running';
		}
		if ( $sale_price === null ) {
			return 'none';
		}
		if ( $starts !== null && $starts > $now ) {
			return 'scheduled';
		}
		if ( $ends !== null && $ends < $now ) {
			return 'ended';
		}

		return 'none';
	}

	public static function options_sale_status( int $on_sale, int $options ): string {
		if ( $on_sale <= 0 || $options <= 0 ) {
			return 'none';
		}

		return $on_sale >= $options ? 'all_options' : 'some_options';
	}

	private static function shop_timezone(): string {
		return function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
	}

	private static function when( ?WC_DateTime $date ): ?array {
		if ( ! $date ) {
			return null;
		}

		return [
			'local' => (string) $date->date( 'Y-m-d H:i' ),
			'utc'   => gmdate( 'c', (int) $date->getTimestamp() ),
		];
	}

	private static function currency(): array {
		return [
			'code'         => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'symbol'       => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( (string) get_woocommerce_currency_symbol() ) : '',
			'includes_tax' => function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : false,
			'decimals'     => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
			'position'           => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_currency_pos', 'left' ) : 'left',
			'decimal_separator'  => function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.',
			'thousand_separator' => function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',',
		];
	}

	private static function money( $value ): ?float {
		if ( $value === '' || $value === null || ! is_numeric( $value ) ) {
			return null;
		}

		return self::round_money( (float) $value );
	}

	private static function round_money( float $value ): float {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( $value, $decimals );
	}
}

