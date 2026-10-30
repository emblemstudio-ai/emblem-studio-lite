<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which asset sent somebody to the shop (SER-1348).
 *
 * A click has no email, so a person cannot be matched the way a sign-up is. The
 * ASSET is what gets tagged, never the visitor, and nothing is stored on anyone's
 * device.
 *
 * HOW THE TAG TRAVELS. The asset's link ends in `#es=<asset>.<when>`. Everything
 * after a `#` stays in the browser: the server never receives it, and no cache can
 * ever make a second copy of a page because of it. Each page reads it, tidies our
 * part out of the address, holds it for that page, and writes it into the links to
 * the same site, so the next page arrives with it too. The browser half lives in
 * the plugin's assets/frontend.js and in the SaaS's public/embed/embed.js, which
 * are twins of each other.
 *
 * WHAT THIS HALF DOES. When a product goes in the basket, the browser hands the tag
 * over and this stamps it on the basket line. WooCommerce copies it onto the order
 * line at checkout, hidden from the customer, and ES_Purchases reads it there.
 *
 * TWO RULES LEARNED BY MEASURING, both of which look like details and are not:
 *
 *  - STAMP AFTER THE ADD, never as part of the data going in. WooCommerce decides
 *    what counts as one basket row by that data, so a tag carried in on the way in
 *    turns the same product into a second row in the shopper's basket. Stamped
 *    afterwards, there is one row per product.
 *  - THE NEWEST CLICK WINS. Somebody who left a class in their basket and came back
 *    through a slide-in was brought back by the slide-in, so the row takes the newer
 *    asset (Alex, 2026-09-16).
 */
class ES_Click_Tag {

	/** The key on the basket line and on the order line. A leading underscore hides it from the customer. */
	const META = '_es_source';

	/** A tag longer than this is not one of ours. */
	const MAX_LENGTH = 120;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'stamp_line' ], 20, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'copy_to_order' ], 10, 3 );
		// The safety net: a theme or a funnel plugin can add to the basket by a path
		// this does not know about, and a missing tag is silent. The browser says
		// "the thing I just added belongs to this asset", and the newest line takes it.
		add_action( 'wp_ajax_es_click_tag', [ __CLASS__, 'stamp_last_line' ] );
		add_action( 'wp_ajax_nopriv_es_click_tag', [ __CLASS__, 'stamp_last_line' ] );
	}


	/**
	 * The browser half, on every page of the site, and only where it is wanted.
	 *
	 * It has to be every page: somebody clicks a ribbon on the home page, reads two
	 * more pages, and adds the product on a third. The tag has to survive that, so
	 * the script cannot ride along with an asset the way the rest of the front end
	 * does. It is small, it does nothing at all without a tag in the address, and a
	 * site that has not asked for any of this never loads it.
	 */
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

	/**
	 * Two questions, and both have to be yes.
	 *
	 * Is this site measuring purchases at all, and does any asset on it actually ask
	 * for it? A connected site is the exception: there the SaaS's own embed.js is on
	 * every page already and carries the same code, so loading this as well would
	 * put two tags in one address.
	 */
	private static function wanted(): bool {
		if ( class_exists( 'Emblem_Studio_SaaS_Connector' ) && Emblem_Studio_SaaS_Connector::is_connected() ) {
			return false;
		}
		if ( ! class_exists( 'ES_Purchases' ) || ! ES_Purchases::enabled() ) {
			return false;
		}

		return self::any_asset_tracks();
	}

	/** Does one live asset ask for this? Cached, because it is asked on every page. */
	private static function any_asset_tracks(): bool {
		$cached = get_transient( 'emblem_studio_buy_assets' );
		if ( $cached !== false ) {
			return $cached === '1';
		}

		global $wpdb;
		$table = ES_Install::table( 'forms' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE track_purchases = 1 AND trashed_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		set_transient( 'emblem_studio_buy_assets', $count > 0 ? '1' : '0', 5 * MINUTE_IN_SECONDS );

		return $count > 0;
	}
	/** The tag the browser sent with this request, if it sent one. */
	public static function from_request(): string {
		$raw = $_REQUEST['es_source'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification

		return is_string( $raw ) ? self::clean( wp_unslash( $raw ) ) : '';
	}

	/** Trim to what a tag may contain, so nothing else can ride in on this. */
	public static function clean( string $tag ): string {
		$tag = sanitize_text_field( $tag );
		$tag = (string) preg_replace( '/[^A-Za-z0-9_\-.:]/', '', $tag );

		return strlen( $tag ) > self::MAX_LENGTH ? '' : $tag;
	}

	/**
	 * An asset's tag is its name and the moment of the click, joined by a dot, so
	 * the delay between clicking and buying can be measured without anything about
	 * the person being kept: "emb-1a2b3c.1789483200".
	 *
	 * @return array{asset: string, at: int}
	 */
	public static function parse( string $tag ): array {
		$tag = self::clean( $tag );
		$at  = 0;
		if ( preg_match( '/^(.*)\.(\d{9,12})$/', $tag, $m ) ) {
			$tag = $m[1];
			$at  = (int) $m[2];
		}

		return [ 'asset' => $tag, 'at' => $at ];
	}

	/**
	 * The basket for this request, or null when there is not one.
	 *
	 * Read the roundabout way on purpose. WooCommerce's own type says the basket is
	 * always there, and on an admin or REST request it is not, so a plain read would
	 * have the null check proven away by the analyser and then hit it at runtime.
	 */
	private static function cart(): ?WC_Cart {
		$wc   = function_exists( 'WC' ) ? call_user_func( 'WC' ) : null;
		$vars = is_object( $wc ) ? get_object_vars( $wc ) : [];
		$cart = $vars['cart'] ?? null;

		return $cart instanceof WC_Cart ? $cart : null;
	}

	/** @param string $cart_item_key */
	public static function stamp_line( $cart_item_key ): void {
		$cart = self::cart();
		if ( ! $cart || ! class_exists( 'ES_Purchases' ) || ! ES_Purchases::enabled() ) {
			return;
		}
		$tag = self::from_request();
		if ( $tag === '' || ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}
		$cart->cart_contents[ $cart_item_key ][ self::META ] = $tag;
		$cart->set_session();
	}

	/**
	 * The browser's fallback for an add this could not see.
	 *
	 * The browser names the product where it can, because "the last line" is only
	 * the right line when the add made one. Adding something already in the basket
	 * raises its quantity instead, and that row keeps its old place in the list.
	 */
	public static function stamp_last_line(): void {
		$tag = self::from_request();
		if ( $tag === '' || ! class_exists( 'ES_Purchases' ) || ! ES_Purchases::enabled() || ! function_exists( 'wc_load_cart' ) ) {
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

	/**
	 * @param mixed                $item
	 * @param string               $cart_item_key
	 * @param array<string, mixed> $values
	 */
	public static function copy_to_order( $item, $cart_item_key, $values ): void {
		if ( ! empty( $values[ self::META ] ) && $item instanceof WC_Order_Item ) {
			$item->add_meta_data( self::META, self::clean( (string) $values[ self::META ] ), true );
		}
	}
}
