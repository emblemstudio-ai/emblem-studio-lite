<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Purchases {

	const HOOK_READY = 'es_purchases_ready';

	const MAX_WINDOW_DAYS = 30;

	const SALT_OPTION = 'emblemstudio_purchase_salt';

	const ENABLED_OPTION = 'emblem_studio_track_purchases';

	const BACKFILL_HOOK = 'es_purchases_backfill';

	const HOOK_PUSH = 'es_purchases_push';

	public static function init(): void {
		add_action( self::BACKFILL_HOOK, [ __CLASS__, 'run_backfill' ] );
		add_action( self::HOOK_PUSH, [ __CLASS__, 'fire_ready' ] );
		add_action( 'update_option_' . self::ENABLED_OPTION, [ __CLASS__, 'on_switch' ], 10, 2 );
		add_action( 'add_option_' . self::ENABLED_OPTION, [ __CLASS__, 'on_switch' ], 10, 2 );

		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'record' ], 20, 1 );
		add_action( 'woocommerce_trash_order', [ __CLASS__, 'record' ], 20, 1 );
	}

	public static function enabled(): bool {
		if ( get_option( self::ENABLED_OPTION ) !== '1' ) {
			return false;
		}

		return class_exists( 'ES_Commerce' ) && ES_Commerce::available();
	}

	public static function on_switch( $old, $new ): void {
		if ( (string) $new === '1' && ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK );
		}
	}

	public static function run_backfill(): void {
		self::backfill();
	}

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
			return;
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

		if ( ! wp_next_scheduled( self::HOOK_PUSH ) ) {
			wp_schedule_single_event( time(), self::HOOK_PUSH );
		}
	}

	public static function fire_ready(): void {
		do_action( self::HOOK_READY );
	}

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

	private static function normalize( string $email ): string {
		return sanitize_email( strtolower( trim( $email ) ) );
	}

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

	public static function ref( int $order_id ): string {
		$salt = (string) get_option( self::SALT_OPTION, '' );
		if ( $salt === '' ) {
			$salt = wp_generate_password( 32, false );
			update_option( self::SALT_OPTION, $salt, false );
		}

		return substr( hash( 'sha256', $salt . '|' . $order_id ), 0, 32 );
	}

	public static function pending( int $limit = 200 ): array {
		global $wpdb;
		$table = ES_Install::table( 'purchases' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE needs_push = 1 ORDER BY id ASC LIMIT %d", max( 1, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( [ __CLASS__, 'shape' ], $rows );
	}

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

	public static function counts( string $status, float $total, float $refunded ): bool {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : [ 'processing', 'completed' ];
		if ( ! in_array( $status, $paid, true ) ) {
			return false;
		}

		return ( $total - $refunded ) > 0;
	}

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

	const FORGET_OPTION = 'emblemstudio_purchases_forget';

	public static function queue_forget( string $ref ): void {
		if ( $ref === '' ) {
			return;
		}
		$queue = (array) get_option( self::FORGET_OPTION, [] );
		$queue = array_values( array_unique( array_merge( array_map( 'strval', $queue ), [ $ref ] ) ) );
		update_option( self::FORGET_OPTION, array_slice( $queue, -500 ), false );
	}

	public static function forgotten(): array {
		return array_values( array_filter( array_map( 'strval', (array) get_option( self::FORGET_OPTION, [] ) ) ) );
	}

	public static function clear_forgotten( array $refs ): void {
		$done  = array_map( 'strval', $refs );
		$queue = array_values( array_diff( self::forgotten(), $done ) );
		update_option( self::FORGET_OPTION, $queue, false );
	}
}
