<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Did the people an asset brought in actually buy? (SER-1346)
 *
 * The question nobody could answer before this: of the people who signed up through
 * a popup, or clicked a ribbon, how many went on to pay.
 *
 * NO EMAIL EVER LEAVES WORDPRESS. The buyer and the lead are matched HERE, where
 * both already live, by the address the shop already holds. What goes to Emblem
 * Studio is the lead's id, the order's id and numbers. Nothing else about a person
 * travels, and nothing new about a person is collected.
 *
 * WHAT COUNTS, AND WHAT DOES NOT:
 *
 *  - Paid orders only. A pending, failed or cancelled order is not a sale, and a
 *    refund takes the money back out of the numbers rather than leaving a sale that
 *    never really happened.
 *  - Orders placed AFTER the person's action. An order they had already made is not
 *    this asset's doing.
 *  - NOT renewals. WooCommerce Subscriptions copies a line's hidden data onto every
 *    renewal order, so without this rule a monthly subscription would credit the same
 *    asset every month, forever. Only the order somebody actually decided to place.
 *  - Every asset that touched the order is recorded, and the SaaS decides which one
 *    is credited, because deciding it twice in two places is how two products end up
 *    claiming the same sale.
 *
 * A row is written for an order that no asset touched only when it has to be: it is
 * not, so a shop with a thousand unrelated orders a day stores nothing here.
 *
 * Read-only toward the shop. Nothing in this file writes to a product, a coupon or
 * an order.
 */
class ES_Purchases {

	/** Fired when there is something to send. The connector listens; standalone nothing does. */
	const HOOK_READY = 'es_purchases_ready';

	/** Orders older than this at the moment of the match cannot be this asset's doing. */
	const MAX_WINDOW_DAYS = 30;

	/** Where the private key that turns an order id into a reference is kept. */
	const SALT_OPTION = 'emblemstudio_purchase_salt';

	/** The site's own switch. Off until somebody turns it on, on every site. */
	const ENABLED_OPTION = 'emblem_studio_track_purchases';

	/** The one-off catch-up on the orders that were already there. */
	const BACKFILL_HOOK = 'es_purchases_backfill';

	public static function init(): void {
		add_action( self::BACKFILL_HOOK, [ __CLASS__, 'run_backfill' ] );
		// Turning the switch on asks for the recent past as well, so the first
		// screen somebody looks at is not empty. In the background: a shop can have
		// thousands of orders in a month and nobody should wait for them.
		add_action( 'update_option_' . self::ENABLED_OPTION, [ __CLASS__, 'on_switch' ], 10, 2 );
		add_action( 'add_option_' . self::ENABLED_OPTION, [ __CLASS__, 'on_switch' ], 10, 2 );

		// Paid. Three hooks rather than one, because a gateway may call any of them,
		// and recording twice is harmless: the row is keyed by the order.
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'record' ], 20, 1 );
		// Money coming back out, in full or in part, and an order that stops being one.
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_trash_order', [ __CLASS__, 'record' ], 20, 1 );
	}

	/**
	 * Is this site measuring purchases at all?
	 *
	 * Off on every site until somebody turns it on. A shop's takings are not a thing
	 * to start measuring on the owner's behalf, and an update must never switch a
	 * new kind of tracking on for a site that never asked (SER-1348).
	 */
	public static function enabled(): bool {
		if ( get_option( self::ENABLED_OPTION ) !== '1' ) {
			return false;
		}

		return class_exists( 'ES_Commerce' ) && ES_Commerce::available();
	}

	/**
	 * @param mixed $old
	 * @param mixed $new
	 */
	public static function on_switch( $old, $new ): void {
		if ( (string) $new === '1' && ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK );
		}
	}

	/**
	 * The orders that were already there when the switch went on.
	 *
	 * Only sign-ups can be found this way. A click leaves nothing behind on an order
	 * placed before the tagging existed, so those orders are simply not this asset's
	 * doing as far as anyone can tell, and record() writes nothing for them.
	 */
	public static function run_backfill(): void {
		self::backfill();
	}

	/** @see run_backfill() for the scheduled version, which an action can call. */
	public static function backfill( int $days = self::MAX_WINDOW_DAYS ): int {
		if ( ! self::enabled() || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders( [
			'limit'        => 500,
			'status'       => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : [ 'processing', 'completed' ],
			'date_created' => '>' . ( time() - ( max( 1, $days ) * DAY_IN_SECONDS ) ),
			'return'       => 'ids',
			'orderby'      => 'date',
			'order'        => 'DESC',
		] );

		$done = 0;
		foreach ( (array) $orders as $order_id ) {
			self::record( (int) $order_id );
			++$done;
		}

		return $done;
	}
	/* ── Recording ───────────────────────────────────────────────────────── */

	/**
	 * Write down what this order says, and say there is something to send.
	 *
	 * @param int|string $order_id
	 */
	public static function record( $order_id ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$touches = self::touches( $order );
		if ( $touches === [] ) {
			return; // No asset had anything to do with this order.
		}

		$ordered_at = $order->get_date_paid() ?: $order->get_date_created();
		$row        = [
			'order_id'   => $order->get_id(),
			'ordered_at' => $ordered_at ? gmdate( 'Y-m-d H:i:s', $ordered_at->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' ),
			'currency'   => (string) $order->get_currency(),
			'total'      => (float) $order->get_total(),
			'refunded'   => (float) $order->get_total_refunded(),
			'is_renewal' => self::is_renewal( $order ) ? 1 : 0,
			'status'     => (string) $order->get_status(),
			'touches'    => (string) wp_json_encode( $touches ),
			'needs_push' => 1,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		];

		global $wpdb;
		$table    = ES_Install::table( 'purchases' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			$wpdb->update( $table, $row, [ 'id' => (int) $existing ] );
		} else {
			$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert( $table, $row );
		}

		/**
		 * Something is waiting to go to Emblem Studio. The connector sends it; a
		 * site running without the SaaS has nowhere to send it and does nothing.
		 */
		do_action( self::HOOK_READY );
	}

	/**
	 * Every asset that had a hand in this order, newest first.
	 *
	 * Two kinds, and they are found in completely different ways. A CLICK is written
	 * on the order's own lines, because the asset tagged the basket. A SIGN-UP is
	 * found here, by asking this site's own leads table for the buyer's address.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function touches( WC_Order $order ): array {
		$paid_at = $order->get_date_paid() ?: $order->get_date_created();
		$paid_ts = $paid_at ? $paid_at->getTimestamp() : time();
		$touches = [];

		foreach ( $order->get_items() as $item ) {
			$tag = ES_Click_Tag::clean( (string) $item->get_meta( ES_Click_Tag::META, true ) );
			if ( $tag === '' ) {
				continue;
			}
			$parsed = ES_Click_Tag::parse( $tag );
			if ( $parsed['asset'] === '' ) {
				continue;
			}
			$touches[] = [
				'kind'  => 'click',
				'asset' => $parsed['asset'],
				'at'    => $parsed['at'] > 0 ? gmdate( 'Y-m-d H:i:s', $parsed['at'] ) : null,
				'product_id' => $item instanceof WC_Order_Item_Product ? (int) $item->get_product_id() : 0,
			];
		}

		foreach ( self::leads_for( $order, $paid_ts ) as $lead ) {
			$touches[] = [
				'kind'         => 'signup',
				'wp_lead_id'   => (int) $lead->id,
				'saas_lead_id' => $lead->saas_lead_id !== null ? (int) $lead->saas_lead_id : null,
				'at'           => get_gmt_from_date( (string) $lead->created_at ),
			];
		}

		return $touches;
	}

	/**
	 * The buyer's own sign-ups on this site, inside the window, newest first.
	 *
	 * Matched on the address the shop holds for the order, and on the account's own
	 * address when the buyer was signed in and used a different one at checkout. Bot
	 * submissions are left out: they are not people and must not flatter a rate.
	 *
	 * @return array<int, object>
	 */
	private static function leads_for( WC_Order $order, int $paid_ts ): array {
		global $wpdb;

		$emails = [ self::normalize( (string) $order->get_billing_email() ) ];
		$user   = $order->get_customer_id() ? get_userdata( $order->get_customer_id() ) : null;
		if ( $user && ! empty( $user->user_email ) ) {
			$emails[] = self::normalize( (string) $user->user_email );
		}
		$emails = array_values( array_unique( array_filter( $emails ) ) );
		if ( $emails === [] ) {
			return [];
		}

		// The window is applied on the SITE's clock, because that is the clock the
		// leads table is written in (current_time('mysql')), and the order's paid
		// date reads in the same one.
		$oldest      = gmdate( 'Y-m-d H:i:s', $paid_ts - ( self::MAX_WINDOW_DAYS * DAY_IN_SECONDS ) );
		$oldest      = get_date_from_gmt( $oldest );
		$before      = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $paid_ts ) );
		$table       = ES_Install::table( 'leads' );
		$placeholder = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		$args        = array_merge( $emails, [ $oldest, $before ] );

		$sql = "SELECT id, saas_lead_id, created_at FROM {$table}
			WHERE email IN ({$placeholder}) AND is_bot = 0 AND created_at >= %s AND created_at <= %s
			ORDER BY created_at DESC LIMIT 20";

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** The same rule ES_Ajax uses when it stores one, so a match is exact. */
	private static function normalize( string $email ): string {
		return sanitize_email( strtolower( trim( $email ) ) );
	}

	/**
	 * A renewal, a switch or a resubscribe is not somebody deciding to buy.
	 *
	 * WooCommerce Subscriptions is a separate plugin, so every helper is guarded and
	 * the meta is read directly as the cheap answer that works without it.
	 */
	public static function is_renewal( WC_Order $order ): bool {
		foreach ( [ '_subscription_renewal', '_subscription_switch', '_subscription_resubscribe' ] as $meta ) {
			if ( (string) $order->get_meta( $meta, true ) !== '' ) {
				return true;
			}
		}
		foreach ( [ 'wcs_order_contains_renewal', 'wcs_order_contains_switch', 'wcs_order_contains_resubscribe' ] as $fn ) {
			if ( function_exists( $fn ) && $fn( $order ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The order, named in a way that says nothing about the shop.
	 *
	 * Emblem Studio needs to recognise the same order twice, so a refund updates the
	 * sale rather than adding one. It does NOT need the order's number, and the
	 * number is worth withholding: order ids run in sequence, so two of them a month
	 * apart tell anybody holding them how much the shop sells. This is a one-way
	 * reference, made with a key that never leaves the site, so it is steady here and
	 * meaningless anywhere else.
	 */
	public static function ref( int $order_id ): string {
		$salt = (string) get_option( self::SALT_OPTION, '' );
		if ( $salt === '' ) {
			$salt = wp_generate_password( 32, false );
			update_option( self::SALT_OPTION, $salt, false );
		}

		return substr( hash( 'sha256', $salt . '|' . $order_id ), 0, 32 );
	}
	/* ── Sending ─────────────────────────────────────────────────────────── */

	/**
	 * Rows waiting to go up, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending( int $limit = 200 ): array {
		global $wpdb;
		$table = ES_Install::table( 'purchases' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE needs_push = 1 ORDER BY id ASC LIMIT %d", max( 1, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( [ __CLASS__, 'shape' ], $rows );
	}

	/**
	 * One row as Emblem Studio receives it. Numbers and ids, and the ids are the ones
	 * it already holds. Read this list when you want to know what leaves the site.
	 *
	 * @param object $row
	 * @return array<string, mixed>
	 */
	public static function shape( $row ): array {
		$touches = json_decode( (string) $row->touches, true );

		return [
			'id'           => (int) $row->id,
			'ref'          => self::ref( (int) $row->order_id ),
			'ordered_at'   => (string) $row->ordered_at,
			'currency'     => (string) $row->currency,
			'total'        => (float) $row->total,
			'refunded'     => (float) $row->refunded,
			'is_renewal'   => (bool) (int) $row->is_renewal,
			'status'       => (string) $row->status,
			'counts'       => self::counts( (string) $row->status, (float) $row->total, (float) $row->refunded ),
			'touches'      => is_array( $touches ) ? $touches : [],
		];
	}

	/**
	 * Is this a sale right now?
	 *
	 * Kept here rather than in the SaaS because WooCommerce is the thing that knows
	 * what its own statuses mean, including the ones a payment gateway adds.
	 */
	public static function counts( string $status, float $total, float $refunded ): bool {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : [ 'processing', 'completed' ];
		if ( ! in_array( $status, $paid, true ) ) {
			return false;
		}

		// Refunded down to nothing is not a sale any more.
		return ( $total - $refunded ) > 0;
	}

	/** @param array<int, int> $ids */
	public static function mark_sent( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( $ids === [] ) {
			return;
		}
		global $wpdb;
		$table = ES_Install::table( 'purchases' );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET needs_push = 0 WHERE id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ── One person's own record ─────────────────────────────────────────── */

	/**
	 * What this site holds about one lead's purchases, for the privacy export.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function for_lead( int $lead_id ) {
		global $wpdb;
		$table = ES_Install::table( 'purchases' );
		$like  = '%' . $wpdb->esc_like( '"wp_lead_id":' . $lead_id . ',' ) . '%';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE touches LIKE %s ORDER BY ordered_at DESC", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { return new WP_Error( 'es_purchase_read_failed', __( 'Purchase data could not be read. Export completeness cannot be confirmed.', 'emblem-studio' ) ); }

		$out = [];
		foreach ( $rows as $row ) {
			foreach ( (array) json_decode( (string) $row->touches, true ) as $touch ) {
				if ( ( $touch['kind'] ?? '' ) === 'signup' && (int) ( $touch['wp_lead_id'] ?? 0 ) === $lead_id ) {
					$out[] = [
						'order_id'   => (int) $row->order_id,
						'ordered_at' => (string) $row->ordered_at,
						'total'      => (float) $row->total,
						'currency'   => (string) $row->currency,
					];
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Erasing a person takes their sign-up out of every purchase that names it, and
	 * removes the row entirely when nothing is left pointing at anybody.
	 *
	 * The order itself is untouched. It belongs to the shop, not to us, and the shop
	 * has its own rules about keeping it.
	 */
	public static function forget_lead( int $lead_id, bool $notify = true ) {
		global $wpdb;
		$table   = ES_Install::table( 'purchases' );
		$like    = '%' . $wpdb->esc_like( '"wp_lead_id":' . $lead_id . ',' ) . '%';
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, touches FROM {$table} WHERE touches LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { return false; }
		$removed = 0;

		foreach ( $rows as $row ) {
			$touches = (array) json_decode( (string) $row->touches, true );
			$kept    = array_values( array_filter( $touches, static function ( $touch ) use ( $lead_id ) {
				return ! ( ( $touch['kind'] ?? '' ) === 'signup' && (int) ( $touch['wp_lead_id'] ?? 0 ) === $lead_id );
			} ) );
			if ( count( $kept ) === count( $touches ) ) {
				continue;
			}
			++$removed;
			if ( $kept === [] ) {
				// Nothing left pointing at anybody, so the row itself goes. Emblem
				// Studio is told to drop its copy on the next send; erasing somebody
				// here and leaving them counted there would be erasing nothing.
				if ( $notify ) { self::queue_forget( self::ref( (int) $row->order_id ) ); }
				if ( $wpdb->delete( $table, [ 'id' => (int) $row->id ] ) === false ) { return false; }
				continue;
			}
			$result = $wpdb->update(
				$table,
				[ 'touches' => (string) wp_json_encode( $kept ), 'needs_push' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
				[ 'id' => (int) $row->id ]
			);
			if ( $result === false ) { return false; }
		}

		if ( $notify && $removed > 0 ) {
			do_action( self::HOOK_READY );
		}

		return $removed;
	}

	/* ── Orders Emblem Studio must forget ────────────────────────────────── */

	/** Where the list of orders waiting to be forgotten is kept. */
	const FORGET_OPTION = 'emblemstudio_purchases_forget';

	/**
	 * An erasure here has to reach Emblem Studio, or the person is only half erased.
	 * The row is gone from this site immediately; the order's id waits here until the
	 * SaaS confirms it has dropped its own copy.
	 */
	public static function queue_forget( string $ref ): void {
		if ( $ref === '' ) {
			return;
		}
		$queue = (array) get_option( self::FORGET_OPTION, [] );
		$queue = array_values( array_unique( array_merge( array_map( 'strval', $queue ), [ $ref ] ) ) );
		update_option( self::FORGET_OPTION, array_slice( $queue, -500 ), false );
	}

	/**
	 * @return array<int, string>
	 */
	public static function forgotten(): array {
		return array_values( array_filter( array_map( 'strval', (array) get_option( self::FORGET_OPTION, [] ) ) ) );
	}

	/** @param array<int, string> $refs */
	public static function clear_forgotten( array $refs ): void {
		$done  = array_map( 'strval', $refs );
		$queue = array_values( array_diff( self::forgotten(), $done ) );
		update_option( self::FORGET_OPTION, $queue, false );
	}
}
