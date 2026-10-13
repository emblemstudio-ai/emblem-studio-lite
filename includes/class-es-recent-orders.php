<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Real orders, for the "after an order" trigger (SER-1689).
 *
 * An asset can tell visitors that people really bought something: "Someone
 * purchased TOEFL Course 5 minutes ago". This class is the WordPress half. It
 * notices a paid order and queues what the web app needs to say that, and no more.
 *
 * WHAT TRAVELS, AND WHAT NEVER DOES:
 *
 *  - The product names, each product's public page address, and the moment the
 *    order was paid. All three are already public on the shop.
 *  - A one-way reference for the order, made with a key that never leaves the site,
 *    so the order number (which would give away how much the shop sells) never
 *    travels.
 *  - A one-way reference for the BUYER, so two orders from one person read as one
 *    person, never as "2 people". It stays on the web app's server; no page ever
 *    sees it.
 *  - Never a name, an email address, a postal address, an amount or an order number.
 *
 * HONESTY RULES, enforced here at the source:
 *
 *  - Paid orders only. Pending and failed orders are never sent.
 *  - An order that is refunded in full, cancelled, failed or trashed is withdrawn,
 *    so the web app stops counting it.
 *  - Renewals of a subscription are not new purchases and are never sent.
 *
 * Nothing is recorded unless the connector says the web app wants orders: a
 * connected site with no asset using the trigger sends nothing at all.
 *
 * Nothing is stored in the visitor's browser either: no cookie, no storage (Alex,
 * 2026-10-09). The web app's page rules keep a buyer from being told about their
 * own order; see the SaaS repo's docs/RECENT-ORDERS.md.
 */
class ES_Recent_Orders {

	/** Fired when something is waiting. The connector listens; standalone nothing does. */
	const HOOK_READY = 'es_recent_orders_ready';

	/** A one-off event so the send happens on the next request, not inside checkout. */
	const HOOK_PUSH = 'es_recent_orders_push';

	/** Orders waiting to be sent, and references waiting to be withdrawn. */
	const QUEUE_OPTION = 'emblemstudio_recent_orders_queue';

	/** A queue never grows past this, even on a site that cannot reach the web app. */
	const QUEUE_CAP = 200;

	/** An order older than this is not "recent" for anyone, so it is not worth sending. */
	const MAX_AGE_HOURS = 24;

	public static function init(): void {
		// Paid. Three hooks because a gateway may call any of them; queueing twice is
		// harmless, the web app keys an order by its reference.
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'record' ], 30, 1 );
		// No longer a purchase.
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_trash_order', [ __CLASS__, 'record' ], 30, 1 );

		add_action( self::HOOK_PUSH, [ __CLASS__, 'fire_ready' ] );
	}

	/**
	 * Does anything want orders? The connector answers, from what the web app last
	 * said. Standalone, nothing does, and nothing is recorded.
	 */
	public static function wanted(): bool {
		return (bool) apply_filters( 'es_recent_orders_wanted', false );
	}

	/**
	 * Queue what this order means now: send it, or withdraw it.
	 *
	 * @param int|string $order_id
	 */
	public static function record( $order_id ): void {
		if ( ! self::wanted() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$ref = self::ref( $order->get_id() );
		if ( self::is_purchase( $order ) ) {
			$row = self::shape( $order );
			if ( $row === null ) {
				return;
			}
			self::enqueue( 'send', $ref, $row );
		} else {
			self::enqueue( 'forget', $ref, null );
		}

		// Sent on the next request, so a slow web app never holds up a checkout.
		if ( ! wp_next_scheduled( self::HOOK_PUSH ) ) {
			wp_schedule_single_event( time(), self::HOOK_PUSH );
		}
	}

	/**
	 * Paid, not refunded down to nothing, not a renewal, and not old. The first two
	 * are ES_Purchases::counts(), the one rule both features share, so a free order
	 * or a full refund is never "a purchase" here while not being one there.
	 */
	public static function is_purchase( WC_Order $order ): bool {
		if ( ! ES_Purchases::counts( (string) $order->get_status(), (float) $order->get_total(), (float) $order->get_total_refunded() ) ) {
			return false;
		}
		if ( ES_Purchases::is_renewal( $order ) ) {
			return false;
		}
		$paid = self::paid_ts( $order );

		return $paid >= time() - self::MAX_AGE_HOURS * HOUR_IN_SECONDS;
	}

	/**
	 * What the web app gets for one order. Null when the order holds no product.
	 *
	 * @return array{ref:string,paid_at:string,buyer:string,items:array<int,array{product_id:int,name:string,url:string}>}|null
	 */
	public static function shape( WC_Order $order ): ?array {
		$items = [];
		$seen  = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			// A variation is named and linked by its parent: "TOEFL Course", not
			// "TOEFL Course - Live, 4 weeks", and the parent has the public page.
			$product_id = (int) $item->get_product_id();
			if ( $product_id <= 0 || isset( $seen[ $product_id ] ) ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$seen[ $product_id ] = true;
			$url                 = (string) get_permalink( $product_id );
			$items[]             = [
				'product_id' => $product_id,
				'name'       => wp_strip_all_tags( (string) $product->get_name() ),
				// A product nobody can open (private, hidden) gets no link.
				'url'        => ( $product->get_status() === 'publish' && $product->is_visible() ) ? esc_url_raw( $url ) : '',
			];
		}
		if ( $items === [] ) {
			return null;
		}

		return [
			'ref'     => self::ref( $order->get_id() ),
			'paid_at' => gmdate( 'Y-m-d\TH:i:s\Z', self::paid_ts( $order ) ),
			'buyer'   => self::buyer_ref( $order ),
			'items'   => $items,
		];
	}

	private static function paid_ts( WC_Order $order ): int {
		$paid = $order->get_date_paid() ?: $order->get_date_created();

		return $paid ? $paid->getTimestamp() : time();
	}

	/**
	 * A one-way reference for an order. Steady on this site, meaningless anywhere
	 * else, and different from the one purchase attribution uses, so the two can
	 * never be joined by somebody who sees both.
	 */
	public static function ref( int $order_id ): string {
		return substr( hash( 'sha256', self::salt() . '|recent-order|' . $order_id ), 0, 32 );
	}

	/**
	 * A one-way reference for whoever placed the order: the account if there is one,
	 * else the billing address, lowercased. Only ever compared for equality.
	 */
	public static function buyer_ref( WC_Order $order ): string {
		$customer = (int) $order->get_customer_id();
		$who      = $customer > 0 ? 'u:' . $customer : 'e:' . strtolower( trim( (string) $order->get_billing_email() ) );
		if ( $who === 'e:' ) {
			$who = 'o:' . $order->get_id(); // nobody to match, so this order stands alone
		}

		return substr( hash( 'sha256', self::salt() . '|buyer|' . $who ), 0, 32 );
	}

	private static function salt(): string {
		$salt = (string) get_option( ES_Purchases::SALT_OPTION, '' );
		if ( $salt === '' ) {
			$salt = wp_generate_password( 32, false );
			update_option( ES_Purchases::SALT_OPTION, $salt, false );
		}

		return $salt;
	}

	/* ── The queue ───────────────────────────────────────────────────────── */

	/**
	 * Queue an instruction for one order. Public so the queue rules can be tested
	 * without a shop; record() is the only caller in the plugin.
	 *
	 * @param array<string, mixed>|null $row
	 */
	public static function enqueue( string $kind, string $ref, ?array $row ): void {
		$q = self::read_queue();
		// The newest instruction for an order wins: paid then refunded is a withdrawal.
		unset( $q['send'][ $ref ], $q['forget'][ $ref ] );
		if ( $kind === 'send' && $row !== null ) {
			$q['send'][ $ref ] = $row;
		} else {
			$q['forget'][ $ref ] = 1;
		}
		$q['send']   = array_slice( $q['send'], -self::QUEUE_CAP, null, true );
		$q['forget'] = array_slice( $q['forget'], -self::QUEUE_CAP, null, true );
		update_option( self::QUEUE_OPTION, $q, false );
	}

	/** @return array{send: array<string, array<string, mixed>>, forget: array<string, int>} */
	private static function read_queue(): array {
		$q = get_option( self::QUEUE_OPTION );
		$q = is_array( $q ) ? $q : [];

		return [
			'send'   => is_array( $q['send'] ?? null ) ? $q['send'] : [],
			'forget' => is_array( $q['forget'] ?? null ) ? $q['forget'] : [],
		];
	}

	/**
	 * What is waiting, in the shape the web app takes. Orders that have grown too old
	 * while waiting are dropped rather than sent late as if they were recent.
	 *
	 * @return array{orders: array<int, array<string, mixed>>, forget: array<int, string>}
	 */
	public static function pending(): array {
		$q      = self::read_queue();
		$cutoff = time() - self::MAX_AGE_HOURS * HOUR_IN_SECONDS;
		$orders = [];
		foreach ( $q['send'] as $row ) {
			$ts = strtotime( (string) ( $row['paid_at'] ?? '' ) );
			if ( $ts !== false && $ts >= $cutoff ) {
				$orders[] = $row;
			}
		}

		return [
			'orders' => $orders,
			'forget' => array_map( 'strval', array_keys( $q['forget'] ) ),
		];
	}

	/**
	 * Take sent entries off the queue. Only the ones that were sent: an order queued
	 * while the request was out stays for the next run.
	 *
	 * @param array<int, string> $sent_refs
	 * @param array<int, string> $forgot_refs
	 */
	public static function mark_sent( array $sent_refs, array $forgot_refs ): void {
		$q = self::read_queue();
		foreach ( $sent_refs as $ref ) {
			unset( $q['send'][ $ref ] );
		}
		foreach ( $forgot_refs as $ref ) {
			unset( $q['forget'][ $ref ] );
		}
		update_option( self::QUEUE_OPTION, $q, false );
	}

	/** Empty the queue, when the web app says nothing on this site uses orders. */
	public static function clear(): void {
		delete_option( self::QUEUE_OPTION );
	}

	public static function fire_ready(): void {
		do_action( self::HOOK_READY );
	}
}
