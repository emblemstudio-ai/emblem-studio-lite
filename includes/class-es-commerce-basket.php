<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A cart with one product in it, that exists only in memory (SER-1345).
 *
 * ES_Commerce measures what a coupon really takes off by asking WooCommerce to
 * apply it. It has to be a CART that WooCommerce applies it to, not an order, and
 * the reason is specific and was checked against WooCommerce 11.1's own source:
 *
 *   - WC_Discounts only fires `woocommerce_coupon_get_discount_amount`, the filter
 *     themes use to compute their own discounts, when the thing being discounted
 *     `is_a( 'WC_Cart' )`. Given an order, it skips that filter silently and
 *     reports the coupon's stored amount instead of the theme's. TST Prep's
 *     `tstprep-basic` coupon is exactly such a filter, so an order would have
 *     reported the wrong discount without any error at all.
 *   - Theme code written for that filter reads the product out of
 *     `$cart_item['data']`, which only a cart line provides.
 *   - WooCommerce's own error text for an excluded product reads `WC()->cart`
 *     directly, so ES_Commerce installs this object there while it measures and
 *     puts the real one back straight after.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. The real constructor opens a session and
 * registers hooks that save the cart on shutdown, recalculate it when a coupon is
 * applied, and check it at checkout. None of that may happen for a price check,
 * so the parent constructor is never called. Nothing here is saved, and nothing
 * here is visible to any visitor.
 *
 * Loaded only by ES_Commerce::coupon_value(), after it has confirmed WooCommerce
 * is running. Declaring a class that extends WC_Cart before WooCommerce loads is a
 * fatal error, and Emblem Studio loads before WooCommerce alphabetically.
 */
class ES_Commerce_Basket extends WC_Cart {

	/** A customer with no id and no session, so nothing is read or written for one. */
	private WC_Customer $shopper;

	public function __construct( WC_Product $product, int $quantity ) {
		// Parent constructor NOT called, on purpose. See the class comment.
		$this->shopper = new WC_Customer( 0, false );

		$is_variation = $product instanceof WC_Product_Variation;
		$product_id   = $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $is_variation ? (int) $product->get_id() : 0;
		$line_total   = (float) $product->get_price() * $quantity;
		$key          = md5( 'es-commerce-basket|' . $product_id . '|' . $variation_id );

		// The same keys WC_Cart::add_to_cart() and calculate_totals() leave on a
		// line, so theme code that reads any of them finds what it expects. Tax is
		// zero because every price ES_Commerce reports is before tax, and the
		// currency block in its answer says so.
		$this->cart_contents = [
			$key => [
				'key'               => $key,
				'product_id'        => $product_id,
				'variation_id'      => $variation_id,
				'variation'         => $is_variation ? (array) $product->get_variation_attributes() : [],
				'quantity'          => $quantity,
				'data'              => $product,
				'data_hash'         => function_exists( 'wc_get_cart_item_data_hash' ) ? wc_get_cart_item_data_hash( $product ) : '',
				'line_tax_data'     => [ 'subtotal' => [], 'total' => [] ],
				'line_subtotal'     => $line_total,
				'line_subtotal_tax' => 0,
				'line_total'        => $line_total,
				'line_tax'          => 0,
			],
		];

		// Cart totals are decimal STRINGS in WooCommerce, formatted the way it formats
		// them itself. A float here would compare differently from the shop's own.
		$total = wc_format_decimal( $line_total );
		$zero  = wc_format_decimal( 0 );

		$this->set_subtotal( $total );
		$this->set_subtotal_tax( $zero );
		$this->set_cart_contents_total( $total );
		$this->set_cart_contents_tax( $zero );
		$this->set_total( $total );
	}

	/**
	 * The one line, without asking the session for it. The real method loads the
	 * visitor's cart from their session the first time it is called, and there is
	 * neither a visitor nor a session here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_cart() {
		return array_filter( $this->get_cart_contents() );
	}

	/** A shopper with no history, which is the basis every answer states. */
	public function get_customer() {
		return $this->shopper;
	}
}
