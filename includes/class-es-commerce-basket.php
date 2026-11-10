<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Commerce_Basket extends WC_Cart {

	private WC_Customer $shopper;

	public function __construct( WC_Product $product, int $quantity ) {
		$this->shopper = new WC_Customer( 0, false );

		$is_variation = $product instanceof WC_Product_Variation;
		$product_id   = $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $is_variation ? (int) $product->get_id() : 0;
		$line_total   = (float) $product->get_price() * $quantity;
		$key          = md5( 'es-commerce-basket|' . $product_id . '|' . $variation_id );

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

		$total = wc_format_decimal( $line_total );
		$zero  = wc_format_decimal( 0 );

		$this->set_subtotal( $total );
		$this->set_subtotal_tax( $zero );
		$this->set_cart_contents_total( $total );
		$this->set_cart_contents_tax( $zero );
		$this->set_total( $total );
	}

	public function get_cart() {
		return array_filter( $this->get_cart_contents() );
	}

	public function get_customer() {
		return $this->shopper;
	}
}
