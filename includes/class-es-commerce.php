<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the store behind the site (SER-1344, SER-1345) — what a product costs,
 * what it costs today, and when a sale on it starts and ends.
 *
 * READ-ONLY, ALWAYS. Nothing here writes to a product, a price or a sale.
 *
 * WHY THIS EXISTS. An asset that names a price is written once and then goes
 * stale on its own. TST Prep's Group Classes rise through four prices as seats
 * sell, and the campaign that hits send on a $123.50 email after the tier closed
 * has no way of knowing. Until now nothing in Emblem Studio could see a price at
 * all, so every number in every promo asset was typed in by a person from memory.
 *
 * THE RULE: ask WooCommerce, never the database, and never a plugin's settings.
 * `get_price()` has been through every filter a theme registered, which is the
 * whole reason it can differ from both the regular and the sale price. A
 * customer's tier logic, their currency switcher, their member pricing: all of it
 * is in that number and none of it is in the stored one.
 *
 * Everything is guarded by class_exists( 'WooCommerce' ), the same test
 * ES_Display_Rules::wc_active() already makes. A site with no shop gets a clear
 * "no store here", never a fatal. The WC_Product type hints below are safe on
 * such a site for the same reason: PHP only resolves them when a method is
 * called, and nothing here is called until available() has said yes.
 */
class ES_Commerce {

	/** How many variations of one product we will describe before stopping. */
	private const MAX_VARIATIONS = 25;

	/** Is there a store on this site at all? */
	public static function available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * The shape every "there is no shop here" answer takes, so the SaaS can tell
	 * it apart from a product that simply was not found.
	 *
	 * @return array{ok: false, error: string, message: string}
	 */
	private static function no_store(): array {
		return [
			'ok'      => false,
			'error'   => 'no_store',
			'message' => 'This site has no WooCommerce store.',
		];
	}

	/**
	 * What one product costs: the normal price, the sale price if a sale is set,
	 * the price a visitor would pay right now, and the sale's start and end.
	 *
	 * @return array<string, mixed>
	 */
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

	/**
	 * Find products by name, because whoever is asking knows "Group Classes" and
	 * not the number WordPress gave it. Published products only.
	 *
	 * Variations are left out of a search result on purpose. Twenty products each
	 * carrying twenty variations is unreadable, and whoever wants that detail asks
	 * for the one product.
	 *
	 * @return array<string, mixed>
	 */
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
			// WC_Product_Query hands an argument it does not recognise straight to
			// WP_Query, which is how a plain title search gets done here.
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

	/**
	 * What a coupon code really takes off one product, in money and as a
	 * percentage (SER-1345).
	 *
	 * THE RULE: never work out a coupon's value from its settings. Put the product
	 * in a basket and ask WooCommerce to apply the coupon, so every filter a theme
	 * or plugin registered runs exactly as it would at checkout. TST Prep's tiered
	 * coupon changes its amount with how many times it has been used, through a
	 * filter in their theme, and this reads the right tier without knowing that.
	 *
	 * The basket is ES_Commerce_Basket, a cart that only exists in memory. Why a
	 * cart and not an order is written down in that class, and it matters: an
	 * order silently skips the filter themes use.
	 *
	 * Three answers are kept apart, because each sends somebody somewhere else:
	 * the coupon does not exist, the coupon exists and WooCommerce refuses it for
	 * this product (with WooCommerce's own reason), and the coupon is accepted and
	 * takes nothing off. Reporting any of them as "0% off" is how a promo goes out
	 * with a code nobody can use.
	 *
	 * WHAT THIS CANNOT SEE, and says so: a shopper with a history, a basket with
	 * other things in it, and price changes a site makes to the cart itself rather
	 * than through the coupon.
	 *
	 * @return array<string, mixed>
	 */
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

		// A variable product cannot go in a basket, only one of its options can.
		// Naming the options saves the caller a second trip to find them.
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

		// Said first, whatever the coupon does: a discount on something nobody can
		// buy is not an offer. An agent reported a coupon "applying" to an
		// out-of-stock product, which is true and useless.
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
			$basket       = new ES_Commerce_Basket( $product, $quantity );
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

			// How the coupon behaves with more than one item, MEASURED, never
			// assumed. An earlier version divided the total by the quantity and
			// called that the per-item value. A blind test agent then found
			// tstprep-basic taking $30 off one course and $30 off two, reported as
			// "$15 per item", which any copy built on it would get wrong. So the shop
			// is asked twice: at the quantity given, and at one other quantity.
			$other_qty   = $quantity === 1 ? 2 : 1;
			$other_value = self::measure( $wc, $product, $code, $other_qty );
		} catch ( \Throwable $e ) {
			// A theme's coupon code that fails when measured from the server must
			// not become a 500 on the shop. It becomes an answer that says the
			// value could not be measured, and why, which is true.
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

		// Of THIS basket, for THIS product. It only holds at other quantities when
		// the coupon takes something off each item, and never holds for another
		// product unless the coupon is a percentage. See best_described_as below.
		$answer['percent_off'] = $before > 0 ? round( $discount / $before * 100, 2 ) : 0.0;

		// The sale and the code TOGETHER, against the normal price, and only when
		// both are actually taking something off. Named for what it is, because an
		// earlier version called this "saving_from_normal_price" and told agents to
		// use it for "save X". A blind test agent then found SAVE10 on a half-price
		// class reading as 55% off, when the code is worth 10%, and TIERSDONE, which
		// takes off nothing, reading as 50%. The code's own value is `discount` and
		// `percent_off`, always.
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

		// Whether the shop computes this value for itself, beyond what the
		// coupon's own settings would give. The number above never comes from the
		// settings; they are read only to notice that the shop overrides them,
		// which is the single most useful thing to know about a coupon whose value
		// can move without anyone editing it. TST Prep's tiered coupon stores $1
		// and gives $40, so it reads as the shop's.
		$expected = self::expected_from_settings(
			(string) $coupon->get_discount_type( 'edit' ),
			(float) $coupon->get_amount( 'edit' ),
			$unit,
			$quantity
		);
		$answer['value_set_by'] = self::value_set_by( $expected, $discount );

		// Money or a percentage, for copy. A $25 coupon reads "64% off" on a $39
		// pack and is false on every other product, which an agent pointed out.
		// The coupon's type is trusted ONLY when the measurement has just shown the
		// shop gives exactly what the settings say. Otherwise the one thing known to
		// be true is the amount, for this product.
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
			// Honest about the limit, because an agent asked "why, and when?". The
			// shop's rule is in its own code, which this reads the RESULT of and
			// never the rule, by design. So it cannot say when the value changes,
			// only that it can. And "no use limit" is about WooCommerce's setting,
			// which says nothing about a rule that drops the value as it is used.
			$answer['warnings'][] = 'The shop works this coupon\'s value out with a rule of its own. Emblem Studio sees the result, not the rule, so it cannot say why or when the value will change. It can fall, even to nothing, without anyone editing the coupon, and the coupon having no use limit does not make it stable. Write "currently", and check again right before publishing.';
		}

		// A code that refuses sale items works on this product TODAY, and stops
		// the moment its scheduled sale starts. An agent spotted NOSALE20 working
		// on a product whose sale began three days later, with no warning, which
		// would have left a month-long ribbon wrong within the week.
		if ( $coupon->get_exclude_sale_items() && self::sale_status_of( $product ) === 'scheduled' ) {
			$starts               = $product->get_date_on_sale_from();
			$answer['warnings'][] = sprintf(
				'This code does not work on products that are on sale, and this product\'s sale starts %s shop time. From then, the shop will refuse this code on it.',
				$starts ? $starts->date( 'Y-m-d H:i' ) : 'soon'
			);
		}

		return $answer;
	}

	/**
	 * Apply a code to a fresh in-memory basket of one product and return what came
	 * off, or null when WooCommerce refuses it at that quantity. The caller owns
	 * swapping WC()->cart back; this only ever points it at a new basket.
	 */
	private static function measure( WooCommerce $wc, WC_Product $product, string $code, int $quantity ): ?float {
		$basket   = new ES_Commerce_Basket( $product, $quantity );
		$wc->cart = $basket;

		$discounts = new WC_Discounts( $basket );
		if ( is_wp_error( $discounts->apply_coupon( new WC_Coupon( $code ) ) ) ) {
			return null;
		}

		return self::total_of( $discounts );
	}

	/** Everything the applied coupons took off, in the shop's money. */
	private static function total_of( WC_Discounts $discounts ): float {
		$total = 0.0;
		foreach ( (array) $discounts->get_discounts_by_coupon() as $amount ) {
			$total += (float) $amount;
		}

		return self::round_money( $total );
	}

	/**
	 * How a discount grows with the number of items, from two real measurements:
	 * "each_item" when n items take off n times one item's discount,
	 * "once_per_basket" when n items take off the same as one, "varies" for
	 * anything else, and "none" when one item takes off nothing. A cent of slack
	 * per item, because WooCommerce rounds per item. Pure, so it is unit tested
	 * without WooCommerce.
	 */
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

	/** An amount in the shop's currency symbol, for a sentence. */
	private static function money_text( float $amount ): string {
		$currency = self::currency();

		return $currency['symbol'] . number_format( $amount, (int) $currency['decimals'] );
	}

	/**
	 * The facts WooCommerce keeps on EVERY coupon, whatever built it: when it
	 * expires, how often it may be used, how often it has been, and the spend it
	 * needs. Reported so an agent can tell a visitor "until Friday" or "while
	 * codes last" truthfully, instead of implying the offer is permanent.
	 *
	 * @return array<string, mixed>
	 */
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

	/**
	 * Our best reading of WHY WooCommerce refused a code, from the restrictions
	 * WooCommerce keeps on every coupon.
	 *
	 * WooCommerce's own sentence is often no help: a coupon that excludes sale
	 * items, and one meant for a different product, both come back as "not
	 * applicable to selected products". The verdict is always WooCommerce's. This
	 * only names the most likely standard cause, and answers null when none of
	 * them fits, which means the shop refused it by a rule of its own.
	 */
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

	/**
	 * What a coupon's own settings would take off one product in a basket, by
	 * WooCommerce's core rules, or null for a discount type we do not know.
	 *
	 * Never reported as the coupon's value. It exists only so value_set_by() can
	 * notice when the shop gives something different. Pure, so it is unit tested
	 * without WooCommerce.
	 */
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

	/**
	 * "coupon" when the measured discount is what the settings give, "shop" when
	 * the shop gave something else, "unknown" for a discount type a plugin added.
	 * A cent of slack, because WooCommerce rounds per item.
	 */
	public static function value_set_by( ?float $expected, float $measured ): string {
		if ( $expected === null ) {
			return 'unknown';
		}

		return abs( $expected - $measured ) <= 0.011 ? 'coupon' : 'shop';
	}

	/** WooCommerce's messages are escaped for HTML. Ours travel as plain text. */
	private static function plain( string $text ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * One product's prices, and its sale window if it has one.
	 *
	 * A variable product has no single price, so it reports the range and, when
	 * asked in full, each variation. Collapsing that to one number is how a promo
	 * ends up advertising $30 for a $247 class.
	 *
	 * @param  bool $deep Include variations. Off for search results.
	 * @return array<string, mixed>
	 */
	private static function price_shape( WC_Product $product, bool $deep ): array {
		$shape = [
			'id'            => (int) $product->get_id(),
			'name'          => (string) $product->get_name(),
			'type'          => (string) $product->get_type(),
			'sku'           => (string) $product->get_sku(),
			'url'           => (string) $product->get_permalink(),
			'purchasable'   => (bool) $product->is_purchasable(),
			'in_stock'      => (bool) $product->is_in_stock(),
			// WooCommerce's "purchasable" ignores stock, so an out-of-stock product
			// reads purchasable: true. An agent noticed. This is the one to check.
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

		// "On sale" on a product with options means ANY option is, which is how
		// WooCommerce answers it. An agent read a three-option course as on sale
		// when only one option was. So the count travels with it, from the same
		// price list WooCommerce itself uses to decide.
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

		// A variable product has no price of its own. WooCommerce still answers
		// get_price() on the parent, with its CHEAPEST variation, and passing that
		// on as "the price today" invites the one mistake this class exists to
		// prevent. Caught on the local test shop, not in theory: a course sold at
		// $79, $199 and $299 read back as costing $79. Absent here, and the range
		// below says what is true.
		$shape['price_today'] = null;

		// Taxes are deliberately left out of the range, because get_price() above
		// leaves them out too. A range that included tax while the single prices
		// did not would put the cheapest variation ABOVE the parent's own price,
		// and nobody reading that could tell which number was wrong.
		$shape['price_range'] = [
			'min' => self::money( $product->get_variation_price( 'min' ) ),
			'max' => self::money( $product->get_variation_price( 'max' ) ),
		];

		if ( ! $deep ) {
			return $shape;
		}

		// A variable product keeps its sale DATES on each variation, not on the
		// parent, so the parent's two date fields above are usually empty even
		// while a sale is running. Reading only the parent is how you conclude
		// there is no sale on a product that is visibly on sale.
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

		// The list stops at MAX_VARIATIONS. Without the total, a reader takes a
		// cut-off list for the whole product and names the wrong cheapest option.
		$shape['variations_total']    = count( $children );
		$shape['variations_complete'] = count( $children ) <= self::MAX_VARIATIONS;

		return $shape;
	}

	/** One product's sale, in the words sale_status() uses. */
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

	/**
	 * Where a sale stands, in one word, because the raw fields mislead.
	 *
	 * A sale that ended keeps its sale price in the data until WooCommerce's
	 * nightly clean-up runs, so a product can show `sale_price: 120` and cost
	 * $180. An agent reading the fields had to work out from three of them that
	 * the sale was over. This says it: running, scheduled, ended, or none.
	 *
	 * "running" is WooCommerce's own verdict (is_on_sale), never ours. The dates
	 * only explain a sale that is NOT running. Pure, so it is unit tested without
	 * WooCommerce.
	 */
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

		// A sale price that is set but not running and not dated: usually one that
		// is not lower than the normal price. There is no sale to speak of.
		return 'none';
	}

	/** A product with options: none, some, or all of them on sale right now. */
	public static function options_sale_status( int $on_sale, int $options ): string {
		if ( $on_sale <= 0 || $options <= 0 ) {
			return 'none';
		}

		return $on_sale >= $options ? 'all_options' : 'some_options';
	}

	/**
	 * The shop's timezone by name. The dates below carry the shop's wall clock and
	 * the real instant, and an agent reading both could not tell which zone the
	 * first one was in. "America/New_York", or an offset like "-04:00" on a site
	 * set to a manual offset.
	 */
	private static function shop_timezone(): string {
		return function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
	}

	/**
	 * A sale date as the shop itself means it.
	 *
	 * WooCommerce keeps these in the site's own timezone, and a sale that "ends on
	 * the 18th" ends at the shop's midnight, not at ours. So both go over: the
	 * local wall clock the shop owner set, and the real instant, which is the only
	 * one two machines can agree on. Getting this wrong by a few hours is the same
	 * mistake the Schedule board made, and it is worth not making twice.
	 *
	 * WooCommerce also stores the end of a sale as the LAST SECOND of that day, so
	 * a sale ending "on the 18th" reads back as the 18th at 23:59:59 and is still
	 * running all day on the 18th.
	 *
	 * @return array<string, string>|null
	 */
	private static function when( ?WC_DateTime $date ): ?array {
		if ( ! $date ) {
			return null;
		}

		return [
			'local' => (string) $date->date( 'Y-m-d H:i' ),
			'utc'   => gmdate( 'c', (int) $date->getTimestamp() ),
		];
	}

	/**
	 * The store's currency, and whether the numbers above already have tax in
	 * them. A price means nothing without both.
	 *
	 * @return array<string, mixed>
	 */
	private static function currency(): array {
		return [
			'code'         => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'symbol'       => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( (string) get_woocommerce_currency_symbol() ) : '',
			'includes_tax' => function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : false,
			'decimals'     => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
			// How the shop writes an amount, so a live value in an asset reads the way
			// the shop's own price does (SER-1352): $73.50, 73,50 € and so on.
			'position'           => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_currency_pos', 'left' ) : 'left',
			'decimal_separator'  => function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.',
			'thousand_separator' => function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',',
		];
	}

	/**
	 * An empty price is absent, not zero. A product with no sale price is not
	 * free, and a reader who sees 0 has no way to tell those apart.
	 *
	 * @param mixed $value A WooCommerce price, which is a numeric string or ''.
	 */
	private static function money( $value ): ?float {
		if ( $value === '' || $value === null || ! is_numeric( $value ) ) {
			return null;
		}

		return self::round_money( (float) $value );
	}

	/** Round the way the shop displays money, so our number matches the shop's. */
	private static function round_money( float $value ): float {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( $value, $decimals );
	}
}

