<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates Emblem Studio assets with FunnelKit Pro Form Feeds. */
final class ES_FunnelKit_Form_Feed {
	public const SOURCE = 'emblem-studio';

	public static function dispatch( string $asset_key, array $fields ): array {
		$feeds = self::active_feeds( $asset_key );
		if ( ! $feeds ) {
			return [ 'handled' => false, 'mode' => 'none', 'feed_ids' => [], 'contact_id' => null, 'error' => null ];
		}

		$controller = self::controller();
		$provider   = $controller && method_exists( $controller, 'get_form' )
			? $controller->get_form( self::SOURCE )
			: null;
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'capture_submission' ) ) {
			return [
				'handled'    => true,
				'mode'       => 'form_feed',
				'feed_ids'   => array_values( array_map( static fn( $feed ) => (int) $feed->get_id(), $feeds ) ),
				'contact_id' => null,
				'error'      => 'Emblem Studio Form Feed provider unavailable',
			];
		}

		try {
			$result = (array) $provider->capture_submission( $asset_key, $fields );
		} catch ( \Throwable $e ) {
			return [
				'handled'    => true,
				'mode'       => 'form_feed',
				'feed_ids'   => array_values( array_map( static fn( $feed ) => (int) $feed->get_id(), $feeds ) ),
				'contact_id' => null,
				'error'      => 'FunnelKit Form Feed exception: ' . $e->getMessage(),
			];
		}

		return [
			'handled'    => true,
			'mode'       => 'form_feed',
			'feed_ids'   => array_values( array_map( static fn( $feed ) => (int) $feed->get_id(), $feeds ) ),
			'contact_id' => ! empty( $result['contact_id'] ) ? (int) $result['contact_id'] : null,
			'error'      => $result['error'] ?? null,
		];
	}

	public static function status( string $asset_key ): array {
		$controller = self::controller();
		$feeds      = self::active_feeds( $asset_key );

		return [
			'available' => is_object( $controller ),
			'managed'   => ! empty( $feeds ),
			'feeds'     => array_values( array_map(
				static fn( $feed ) => [
					'id'    => (int) $feed->get_id(),
					'title' => method_exists( $feed, 'get_title' ) ? (string) $feed->get_title() : 'Feed #' . (int) $feed->get_id(),
					'edit_url' => function_exists( 'add_query_arg' ) && function_exists( 'admin_url' )
						? add_query_arg( [ 'page' => 'autonami', 'path' => '/forms/' . (int) $feed->get_id() ], admin_url( 'admin.php' ) )
						: '',
				],
				$feeds
			) ),
		];
	}

	public static function bootstrap(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'bwfcrm_forms_loaded', [ self::class, 'register_provider' ] );
			add_action( 'admin_init', [ self::class, 'maybe_refresh_catalog' ] );
		}
	}

	/**
	 * On a connected site, catalog() reads the LOCAL asset mirror — which
	 * only refreshes on connect, hourly (es_saas_pull), and at disconnect. A
	 * rename/edit made in the SaaS could sit stale in FunnelKit's own Forms
	 * picker for up to an hour. Rather than pulling inside catalog() itself
	 * (it's called several times per picker render — get_form_selection(),
	 * get_form_fields(), get_form_link() — which would fire that many HTTP
	 * round-trips), refresh once per admin request, throttled, and only when
	 * FunnelKit's own admin screen is the one being loaded.
	 */
	public static function maybe_refresh_catalog(): void {
		if ( ! class_exists( 'Emblem_Studio_SaaS_Connector' ) || ! Emblem_Studio_SaaS_Connector::is_connected() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		if ( ! in_array( $page, [ 'autonami', 'bwfan' ], true ) ) {
			return;
		}
		if ( get_transient( 'es_saas_forms_fresh' ) ) {
			return;
		}
		set_transient( 'es_saas_forms_fresh', 1, 60 );
		Emblem_Studio_SaaS_Connector::pull_assets_now();
	}

	public static function register_provider(): void {
		$controller = self::controller();
		if ( ! class_exists( 'BWFCRM_Form_Base' ) || ! $controller || ! method_exists( $controller, 'register' ) ) {
			return;
		}
		if ( ! class_exists( 'ES_FunnelKit_Form_Provider' ) ) {
			require_once __DIR__ . '/class-es-funnelkit-form-provider.php';
		}
		$controller->register(
			self::SOURCE,
			'ES_FunnelKit_Form_Provider',
			'Emblem Studio',
			[ 'emblem_studio_form_submit' ]
		);
	}

	/** @return array<string,array{key:string,name:string,fields:array,edit_url:string}> */
	/** The catalog for this request, or null. Cleared by ES_Form_Repo on every write. */
	private static ?array $catalog_memo = null;

	/**
	 * The catalog, built once per request (audit P8). Building it loads every
	 * asset and parses each one's HTML, and a FunnelKit screen asks for it from
	 * four places.
	 */
	public static function catalog(): array {
		return self::$catalog_memo ??= self::build_catalog();
	}

	public static function forget_catalog(): void {
		self::$catalog_memo = null;
	}

	private static function build_catalog(): array {
		$catalog = [];
		if ( class_exists( 'ES_Form_Repo' ) ) {
			foreach ( (array) ES_Form_Repo::all( 'all' ) as $form ) {
				if ( ! is_object( $form ) || empty( $form->id ) ) {
					continue;
				}
				$key    = ! empty( $form->saas_form_id ) ? 'saas:' . (int) $form->saas_form_id : 'wp:' . (int) $form->id;
				$fields = [];
				if ( class_exists( 'ES_Form_Parser' ) ) {
					foreach ( ES_Form_Parser::extract_fields( (string) ( $form->html ?? '' ) ) as $field ) {
						$name = (string) ( $field['name'] ?? '' );
						if ( $name !== '' ) {
							$fields[ $name ] = (string) ( $field['label'] ?? ucwords( str_replace( [ '_', '-' ], ' ', trim( $name, '[]' ) ) ) );
						}
					}
				}
				// Only assets that actually contain a form belong in FunnelKit's own
				// Forms picker — a click-through ribbon or announcement popup has
				// nothing to map (SER-929). form_types (shell/placement) can't be the
				// filter; a ribbon/popup can legitimately carry real fields too.
				if ( empty( $fields ) ) {
					continue;
				}
				$catalog[ $key ] = [
					'key'      => $key,
					'name'     => (string) ( $form->name ?? 'Asset #' . (int) $form->id ),
					'fields'   => $fields,
					'edit_url' => function_exists( 'admin_url' )
						? admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . (int) $form->id . '#connect' )
						: '',
				];
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$catalog = apply_filters( 'emblem_studio_funnelkit_form_catalog', $catalog );
		}
		return is_array( $catalog ) ? $catalog : [];
	}

	private static function active_feeds( string $asset_key ): array {
		$controller = self::controller();
		if ( ! $controller || ! method_exists( $controller, 'get_active_feeds_by_form' ) ) {
			return [];
		}
		$feeds = $controller->get_active_feeds_by_form( self::SOURCE );
		if ( ! is_array( $feeds ) ) {
			return [];
		}

		return array_values( array_filter(
			$feeds,
			static fn( $feed ) => is_object( $feed )
				&& method_exists( $feed, 'get_data' )
				&& (string) $feed->get_data( 'form_id' ) === $asset_key
		) );
	}

	private static function controller() {
		if ( ! function_exists( 'BWFCRM_Core' ) ) {
			return null;
		}
		$core = BWFCRM_Core();
		return is_object( $core ) && isset( $core->forms ) ? $core->forms : null;
	}
}

ES_FunnelKit_Form_Feed::bootstrap();
