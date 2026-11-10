<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Click_Tag {

	const META = '_es_source';

	const MAX_LENGTH = 120;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'stamp_line' ], 20, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'copy_to_order' ], 10, 3 );
		add_action( 'wp_ajax_es_click_tag', [ __CLASS__, 'stamp_last_line' ] );
		add_action( 'wp_ajax_nopriv_es_click_tag', [ __CLASS__, 'stamp_last_line' ] );
	}


	public static function enqueue(): void {
		if ( is_admin() || ! self::wanted() ) {
			return;
		}
		wp_enqueue_script(
			'emblem-studio-purchase-tag',
			EMBLEM_STUDIO_URL . 'assets/purchase-tag.js',
			[],
			EMBLEM_STUDIO_VERSION,
			true
		);
		wp_localize_script( 'emblem-studio-purchase-tag', 'EmblemStudioBuy', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		] );
	}

	private static function wanted(): bool {
		if ( class_exists( 'Emblem_Studio_SaaS_Connector' ) && Emblem_Studio_SaaS_Connector::is_connected() ) {
			return false;
		}
		if ( ! class_exists( 'Emblem_Studio_Purchases' ) || ! Emblem_Studio_Purchases::enabled() ) {
			return false;
		}

		return self::any_asset_tracks();
	}

	private static function any_asset_tracks(): bool {
		$cached = get_transient( 'emblem_studio_buy_assets' );
		if ( $cached !== false ) {
			return $cached === '1';
		}

		global $wpdb;
		$table = Emblem_Studio_Install::table( 'forms' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE track_purchases = 1 AND trashed_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		set_transient( 'emblem_studio_buy_assets', $count > 0 ? '1' : '0', 5 * MINUTE_IN_SECONDS );

		return $count > 0;
	}
	public static function from_request(): string {
		$raw = $_REQUEST['es_source'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification

		return is_string( $raw ) ? self::clean( wp_unslash( $raw ) ) : '';
	}

	public static function clean( string $tag ): string {
		$tag = sanitize_text_field( $tag );
		$tag = (string) preg_replace( '/[^A-Za-z0-9_\-.:]/', '', $tag );

		return strlen( $tag ) > self::MAX_LENGTH ? '' : $tag;
	}

	public static function parse( string $tag ): array {
		$tag = self::clean( $tag );
		$at  = 0;
		if ( preg_match( '/^(.*)\.(\d{9,12})$/', $tag, $m ) ) {
			$tag = $m[1];
			$at  = (int) $m[2];
		}

		return [ 'asset' => $tag, 'at' => $at ];
	}

	private static function cart(): ?WC_Cart {
		$wc   = function_exists( 'WC' ) ? call_user_func( 'WC' ) : null;
		$vars = is_object( $wc ) ? get_object_vars( $wc ) : [];
		$cart = $vars['cart'] ?? null;

		return $cart instanceof WC_Cart ? $cart : null;
	}

	public static function stamp_line( $cart_item_key ): void {
		$cart = self::cart();
		if ( ! $cart || ! class_exists( 'Emblem_Studio_Purchases' ) || ! Emblem_Studio_Purchases::enabled() ) {
			return;
		}
		$tag = self::from_request();
		if ( $tag === '' || ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}
		$cart->cart_contents[ $cart_item_key ][ self::META ] = $tag;
		$cart->set_session();
	}

	public static function stamp_last_line(): void {
		$tag = self::from_request();
		if ( $tag === '' || ! class_exists( 'Emblem_Studio_Purchases' ) || ! Emblem_Studio_Purchases::enabled() || ! function_exists( 'wc_load_cart' ) ) {
			wp_send_json( [ 'ok' => false ] );
		}
		wc_load_cart();
		$cart = self::cart();
		if ( ! $cart ) {
			wp_send_json( [ 'ok' => false ] );
		}
		$product = isset( $_REQUEST['product_id'] ) ? (int) $_REQUEST['product_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$target  = '';
		foreach ( $cart->get_cart() as $key => $line ) {
			if ( $product > 0 && (int) ( $line['product_id'] ?? 0 ) !== $product && (int) ( $line['variation_id'] ?? 0 ) !== $product ) {
				continue;
			}
			$target = (string) $key;
		}
		if ( $target === '' ) {
			wp_send_json( [ 'ok' => false ] );
		}
		$cart->cart_contents[ $target ][ self::META ] = $tag;
		$cart->set_session();
		wp_send_json( [ 'ok' => true ] );
	}

	public static function copy_to_order( $item, $cart_item_key, $values ): void {
		if ( ! empty( $values[ self::META ] ) && $item instanceof WC_Order_Item ) {
			$item->add_meta_data( self::META, self::clean( (string) $values[ self::META ] ), true );
		}
	}
}
