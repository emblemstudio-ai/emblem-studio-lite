<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Recent_Orders {

	const HOOK_READY = 'es_recent_orders_ready';

	const HOOK_PUSH = 'es_recent_orders_push';

	const QUEUE_OPTION = 'emblemstudio_recent_orders_queue';

	const QUEUE_CAP = 200;

	const MAX_AGE_HOURS = 24;

	public static function init(): void {
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'record' ], 30, 1 );
		add_action( 'woocommerce_trash_order', [ __CLASS__, 'record' ], 30, 1 );

		add_action( self::HOOK_PUSH, [ __CLASS__, 'fire_ready' ] );
	}

	public static function wanted(): bool {
		return (bool) apply_filters( 'es_recent_orders_wanted', false );
	}

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

		if ( ! wp_next_scheduled( self::HOOK_PUSH ) ) {
			wp_schedule_single_event( time(), self::HOOK_PUSH );
		}
	}

	public static function is_purchase( WC_Order $order ): bool {
		if ( ! Emblem_Studio_Purchases::counts( (string) $order->get_status(), (float) $order->get_total(), (float) $order->get_total_refunded() ) ) {
			return false;
		}
		if ( Emblem_Studio_Purchases::is_renewal( $order ) ) {
			return false;
		}
		$paid = self::paid_ts( $order );

		return $paid >= time() - self::MAX_AGE_HOURS * HOUR_IN_SECONDS;
	}

	public static function shape( WC_Order $order ): ?array {
		$items = [];
		$seen  = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
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

	public static function ref( int $order_id ): string {
		return substr( hash( 'sha256', self::salt() . '|recent-order|' . $order_id ), 0, 32 );
	}

	public static function buyer_ref( WC_Order $order ): string {
		$customer = (int) $order->get_customer_id();
		$who      = $customer > 0 ? 'u:' . $customer : 'e:' . strtolower( trim( (string) $order->get_billing_email() ) );
		if ( $who === 'e:' ) {
			$who = 'o:' . $order->get_id();
		}

		return substr( hash( 'sha256', self::salt() . '|buyer|' . $who ), 0, 32 );
	}

	private static function salt(): string {
		$salt = (string) get_option( Emblem_Studio_Purchases::SALT_OPTION, '' );
		if ( $salt === '' ) {
			$salt = wp_generate_password( 32, false );
			update_option( Emblem_Studio_Purchases::SALT_OPTION, $salt, false );
		}

		return $salt;
	}

	public static function enqueue( string $kind, string $ref, ?array $row ): void {
		$q = self::read_queue();
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

	private static function read_queue(): array {
		$q = get_option( self::QUEUE_OPTION );
		$q = is_array( $q ) ? $q : [];

		return [
			'send'   => is_array( $q['send'] ?? null ) ? $q['send'] : [],
			'forget' => is_array( $q['forget'] ?? null ) ? $q['forget'] : [],
		];
	}

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

	public static function clear(): void {
		delete_option( self::QUEUE_OPTION );
	}

	public static function fire_ready(): void {
		do_action( self::HOOK_READY );
	}
}
