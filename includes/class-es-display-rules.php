<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Display_Rules {

	public static function is_admin_frame(): bool {
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true;
		}

		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] )
			? strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) )
			: '';
		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) ) )
			: '';

		if ( ( 'iframe' === $dest || 'frame' === $dest ) && '' !== $referer ) {
			if ( 0 === strpos( $referer, strtolower( admin_url() ) ) ) {
				return true;
			}
		}

		return self::has_builder_canvas_arg();
	}

	private static function has_builder_canvas_arg(): bool {
		$args = [
			'elementor-preview',
			'fl_builder',
			'fl_builder_preview',
			'et_fb',
			'et_pb_preview',
			'ct_builder',
			'oxygen_iframe',
			'bricks',
			'brizy-edit',
			'brizy-edit-iframe',
			'vc_editable',
			'vcv-editable',
			'tve',
			'siteorigin_panels_live_editor',
			'zionbuilder-preview',
			'breakdance',
			'cs_preview_state',
		];

		foreach ( $args as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}

		return false;
	}

	public const SPECIALS = [ 'front_page', 'blog', '404', 'search', 'archive' ];

	public const WC_PAGES = [
		'cart', 'checkout', 'account', 'shop',
		'product', 'product_archive',
		'product_category', 'product_tag',
	];

	public static function wc_page_labels(): array {
		return [
			'cart'             => __( 'Cart',             'emblem-studio' ),
			'checkout'         => __( 'Checkout',         'emblem-studio' ),
			'account'          => __( 'My account',       'emblem-studio' ),
			'shop'             => __( 'Shop',             'emblem-studio' ),
			'product'          => __( 'Product page',     'emblem-studio' ),
			'product_archive'  => __( 'Product taxonomy archive', 'emblem-studio' ),
			'product_category' => __( 'Product category', 'emblem-studio' ),
			'product_tag'      => __( 'Product tag',      'emblem-studio' ),
		];
	}

	public static function wc_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	public static function defaults(): array {
		return [
			'show_mode'         => 'all',
			'show_filters'      => self::empty_filters(),
			'hide_filters'      => self::empty_filters(),
			'preset_id'         => null,
			'visitor_logged_in' => 'any',
			'visitor_roles'     => [],
			'visitor_state'     => 'any',
			'schedule'          => self::empty_schedule(),
		];
	}

	public const VISITOR_COOKIE = 'esf_visitor';

	private static function empty_schedule(): array {
		return [
			'days'       => [],
			'start_time' => '',
			'end_time'   => '',
		];
	}

	private static function empty_filters(): array {
		return [
			'specials'   => [],
			'post_types' => [],
			'categories' => [],
			'tags'       => [],
			'posts'      => [],
			'pages'      => [],
			'urls'       => [],
			'wc_pages'   => [],
		];
	}

	public static function sanitize_where( array $raw ): array {
		$show_mode = ( ( $raw['show_mode'] ?? '' ) === 'selected' ) ? 'selected' : 'all';
		return [
			'show_mode'    => $show_mode,
			'show_filters' => self::sanitize_filters( $raw['show_filters'] ?? [] ),
			'hide_filters' => self::sanitize_filters( $raw['hide_filters'] ?? [] ),
		];
	}

	public static function sanitize( array $raw ): array {
		$logged_in = isset( $raw['visitor_logged_in'] ) ? (string) $raw['visitor_logged_in'] : 'any';
		if ( ! in_array( $logged_in, [ 'any', 'in', 'out' ], true ) ) {
			$logged_in = 'any';
		}
		$role_keys = ( $logged_in === 'in' && isset( $raw['visitor_roles'] ) && is_array( $raw['visitor_roles'] ) )
			? self::sanitize_roles( $raw['visitor_roles'] )
			: [];
		$visitor_state = isset( $raw['visitor_state'] ) ? (string) $raw['visitor_state'] : 'any';
		if ( ! in_array( $visitor_state, [ 'any', 'new', 'returning' ], true ) ) {
			$visitor_state = 'any';
		}
		$preset_id = isset( $raw['preset_id'] ) && is_numeric( $raw['preset_id'] ) ? (int) $raw['preset_id'] : null;
		return [
			...self::sanitize_where( $raw ),
			'preset_id'         => $preset_id,
			'visitor_logged_in' => $logged_in,
			'visitor_roles'     => $role_keys,
			'visitor_state'     => $visitor_state,
			'schedule'          => self::sanitize_schedule( $raw['schedule'] ?? [] ),
		];
	}

	private static function sanitize_schedule( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		$days = isset( $raw['days'] ) && is_array( $raw['days'] )
			? array_values( array_unique( array_filter( array_map( 'intval', $raw['days'] ), function ( $d ) {
				return $d >= 0 && $d <= 6;
			} ) ) )
			: [];
		sort( $days );
		return [
			'days'       => $days,
			'start_time' => self::normalize_time( $raw['start_time'] ?? '' ),
			'end_time'   => self::normalize_time( $raw['end_time']   ?? '' ),
		];
	}

	private static function normalize_time( $raw ): string {
		$s = trim( (string) $raw );
		if ( $s === '' ) {
			return '';
		}
		if ( preg_match( '/^(\d{2}):(\d{2})(?::\d{2})?$/', $s, $m ) ) {
			$h = (int) $m[1];
			$mn = (int) $m[2];
			if ( $h >= 0 && $h <= 23 && $mn >= 0 && $mn <= 59 ) {
				return sprintf( '%02d:%02d', $h, $mn );
			}
		}
		return '';
	}

	private static function sanitize_roles( array $raw ): array {
		$known = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : [];
		$keys  = array_values( array_map( 'sanitize_key', $raw ) );
		if ( empty( $known ) ) {
			return $keys;
		}
		return array_values( array_intersect( $keys, $known ) );
	}

	private static function sanitize_filters( array $raw ): array {
		$valid_specials = self::SPECIALS;
		$specials = isset( $raw['specials'] ) && is_array( $raw['specials'] )
			? array_values( array_intersect( array_map( 'sanitize_key', $raw['specials'] ), $valid_specials ) )
			: [];
		$post_types = isset( $raw['post_types'] ) && is_array( $raw['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', $raw['post_types'] ) ) )
			: [];
		$categories = isset( $raw['categories'] ) && is_array( $raw['categories'] )
			? array_values( array_filter( array_map( 'intval', $raw['categories'] ) ) )
			: [];
		$tags = isset( $raw['tags'] ) && is_array( $raw['tags'] )
			? array_values( array_filter( array_map( 'intval', $raw['tags'] ) ) )
			: [];
		$posts = isset( $raw['posts'] ) && is_array( $raw['posts'] )
			? array_values( array_filter( array_map( 'intval', $raw['posts'] ) ) )
			: [];
		$pages = isset( $raw['pages'] ) && is_array( $raw['pages'] )
			? array_values( array_filter( array_map( 'intval', $raw['pages'] ) ) )
			: [];
		$urls = isset( $raw['urls'] ) && is_array( $raw['urls'] )
			? array_values( array_filter( array_map( [ self::class, 'normalize_url_pattern' ], $raw['urls'] ) ) )
			: [];
		$wc_pages = isset( $raw['wc_pages'] ) && is_array( $raw['wc_pages'] )
			? array_values( array_intersect( array_map( 'sanitize_key', $raw['wc_pages'] ), self::WC_PAGES ) )
			: [];
		return [
			'specials'   => $specials,
			'post_types' => $post_types,
			'categories' => $categories,
			'tags'       => $tags,
			'posts'      => $posts,
			'pages'      => $pages,
			'urls'       => $urls,
			'wc_pages'   => $wc_pages,
		];
	}

	private static function normalize_url_pattern( $raw ): string {
		$s = trim( (string) $raw );
		if ( $s === '' ) {
			return '';
		}
		$q = strpos( $s, '?' );
		if ( $q !== false ) {
			$s = substr( $s, 0, $q );
		}
		$h = strpos( $s, '#' );
		if ( $h !== false ) {
			$s = substr( $s, 0, $h );
		}
		$s = preg_replace( '#^https?://[^/]+#i', '', $s );
		if ( $s === '' ) {
			return '';
		}
		if ( $s[0] !== '/' ) {
			$s = '/' . $s;
		}
		if ( strlen( $s ) > 1 && substr( $s, -1 ) === '/' ) {
			$s = rtrim( $s, '/' );
		}
		return strtolower( $s );
	}

	public static function auto_shells(): array {
		return [ 'popup-auto', 'ribbon', 'slide-in' ];
	}

	public static function get_for_form( object $form ): array {
		return self::get_for_form_shell( $form, 'popup-auto' );
	}

	public static function get_for_form_shell( object $form, string $shell ): array {
		$all = self::get_all_for_form( $form );
		return $all[ $shell ] ?? self::defaults();
	}

	public static function get_all_for_form( object $form ): array {
		$raw     = $form->display_rules ?? '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return self::sanitize_all_shells( is_array( $decoded ) ? $decoded : [] );
	}

	public static function sanitize_all_shells( array $raw ): array {
		if ( isset( $raw['show_mode'] ) || isset( $raw['show_filters'] ) || isset( $raw['hide_filters'] ) ) {
			$raw = [ 'popup-auto' => $raw ];
		}
		$out = [];
		foreach ( self::auto_shells() as $shell ) {
			$shell_raw     = isset( $raw[ $shell ] ) && is_array( $raw[ $shell ] ) ? $raw[ $shell ] : [];
			$out[ $shell ] = self::sanitize( $shell_raw );
		}
		return $out;
	}

	public static function should_render( array $rules ): bool {
		$rules = self::sanitize( $rules );
		if ( ! self::visitor_matches( $rules ) ) {
			return false;
		}
		if ( ! self::schedule_matches( $rules['schedule'] ?? [] ) ) {
			return false;
		}
		$show = ( $rules['show_mode'] === 'all' )
			? true
			: self::filters_match_current( $rules['show_filters'] );
		if ( ! $show ) {
			return false;
		}
		if ( self::filters_match_current( $rules['hide_filters'] ) ) {
			return false;
		}
		return true;
	}

	private static function schedule_matches( array $schedule ): bool {
		return self::schedule_allows(
			$schedule,
			(int) current_time( 'w' ),
			(string) current_time( 'H:i' )
		);
	}

	public static function schedule_allows( array $schedule, int $now_dow, string $now_hm ): bool {
		$days       = (array) ( $schedule['days']       ?? [] );
		$start_time = (string) ( $schedule['start_time'] ?? '' );
		$end_time   = (string) ( $schedule['end_time']   ?? '' );

		if ( ! empty( $days ) && ! in_array( $now_dow, array_map( 'intval', $days ), true ) ) {
			return false;
		}

		if ( $start_time === '' && $end_time === '' ) {
			return true;
		}

		if ( $start_time !== '' && $end_time === '' ) {
			return strcmp( $now_hm, $start_time ) >= 0;
		}
		if ( $start_time === '' ) {
			return strcmp( $now_hm, $end_time ) <= 0;
		}

		if ( strcmp( $start_time, $end_time ) <= 0 ) {
			return strcmp( $now_hm, $start_time ) >= 0 && strcmp( $now_hm, $end_time ) <= 0;
		}
		return strcmp( $now_hm, $start_time ) >= 0 || strcmp( $now_hm, $end_time ) <= 0;
	}

	private static function visitor_matches( array $rules ): bool {
		$logged_in_rule = $rules['visitor_logged_in'] ?? 'any';
		$is_logged_in   = is_user_logged_in();

		if ( $logged_in_rule === 'in' && ! $is_logged_in ) {
			return false;
		}
		if ( $logged_in_rule === 'out' && $is_logged_in ) {
			return false;
		}

		$roles = (array) ( $rules['visitor_roles'] ?? [] );
		if ( ! empty( $roles ) ) {
			if ( ! $is_logged_in ) {
				return false;
			}
			$user = wp_get_current_user();
			if ( empty( array_intersect( $roles, (array) $user->roles ) ) ) {
				return false;
			}
		}

		$state = $rules['visitor_state'] ?? 'any';
		if ( $state !== 'any' ) {
			$has_cookie = ! empty( $_COOKIE[ self::VISITOR_COOKIE ] );
			if ( $state === 'new' && $has_cookie ) {
				return false;
			}
			if ( $state === 'returning' && ! $has_cookie ) {
				return false;
			}
		}

		return true;
	}

	public static function ensure_visitor_cookie(): void {
		if ( ( $_COOKIE[ self::VISITOR_COOKIE ] ?? '' ) !== '1' || headers_sent() ) {
			return;
		}
		setcookie( self::VISITOR_COOKIE, '', [
			'expires'  => time() - YEAR_IN_SECONDS,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}

	private const VISITOR_STATE_USED_TRANSIENT = 'emblem_studio_uses_visitor_cookie';

	public static function any_form_uses_visitor_state(): bool {
		$cached = get_transient( self::VISITOR_STATE_USED_TRANSIENT );
		if ( $cached !== false ) {
			return $cached === '1';
		}

		global $wpdb;
		$t = ES_Install::table( 'forms' );
		$rows = $wpdb->get_col(
			"SELECT display_rules FROM $t WHERE trashed_at IS NULL AND archived_at IS NULL AND status = 'active' AND display_rules IS NOT NULL AND display_rules <> ''"
		);
		$any = false;
		foreach ( (array) $rows as $json ) {
			$decoded = json_decode( (string) $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $shell_rules ) {
				if ( is_array( $shell_rules ) && ( $shell_rules['visitor_state'] ?? 'any' ) !== 'any' ) {
					$any = true;
					break 2;
				}
			}
		}
		set_transient( self::VISITOR_STATE_USED_TRANSIENT, $any ? '1' : '0', HOUR_IN_SECONDS );
		return $any;
	}

	public static function flush_visitor_state_cache(): void {
		delete_transient( self::VISITOR_STATE_USED_TRANSIENT );
	}

	private static function filters_match_current( array $filters ): bool {
		if ( ! empty( $filters['specials'] ) && self::specials_match( $filters['specials'] ) ) {
			return true;
		}
		if ( ! empty( $filters['post_types'] ) && self::post_type_matches( $filters['post_types'] ) ) {
			return true;
		}
		if ( ! empty( $filters['categories'] ) && self::category_matches( $filters['categories'] ) ) {
			return true;
		}
		if ( ! empty( $filters['tags'] ) && self::tag_matches( $filters['tags'] ) ) {
			return true;
		}
		if ( ! empty( $filters['posts'] ) && self::singular_id_matches( 'post', $filters['posts'] ) ) {
			return true;
		}
		if ( ! empty( $filters['pages'] ) && self::singular_id_matches( 'page', $filters['pages'] ) ) {
			return true;
		}
		if ( ! empty( $filters['urls'] ) && self::url_matches( $filters['urls'] ) ) {
			return true;
		}
		if ( ! empty( $filters['wc_pages'] ) && self::wc_pages_match( $filters['wc_pages'] ) ) {
			return true;
		}
		return false;
	}

	private static function wc_pages_match( array $picks ): bool {
		if ( ! self::wc_active() ) {
			return false;
		}
		foreach ( $picks as $page ) {
			switch ( $page ) {
				case 'cart':             if ( function_exists( 'is_cart' )            && is_cart() )            return true; break;
				case 'checkout':         if ( function_exists( 'is_checkout' )        && is_checkout() )        return true; break;
				case 'account':          if ( function_exists( 'is_account_page' )    && is_account_page() )    return true; break;
				case 'shop':             if ( function_exists( 'is_shop' )            && is_shop() )            return true; break;
				case 'product':          if ( function_exists( 'is_product' )         && is_product() )         return true; break;
				case 'product_archive':  if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) return true; break;
				case 'product_category': if ( function_exists( 'is_product_category' ) && is_product_category() ) return true; break;
				case 'product_tag':      if ( function_exists( 'is_product_tag' )     && is_product_tag() )     return true; break;
			}
		}
		return false;
	}

	private static function url_matches( array $patterns ): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		return self::url_pattern_matches( $patterns, $uri );
	}

	public static function url_pattern_matches( array $patterns, string $uri ): bool {
		$uri = self::normalize_url_pattern( $uri );
		if ( $uri === '' ) {
			$uri = '/';
		}
		foreach ( $patterns as $pat ) {
			if ( $pat === '' ) {
				continue;
			}
			if ( fnmatch( $pat, $uri ) ) {
				return true;
			}
		}
		return false;
	}

	private static function singular_id_matches( string $post_type, array $picks ): bool {
		if ( ! is_singular( $post_type ) ) {
			return false;
		}
		return in_array( (int) get_queried_object_id(), array_map( 'intval', $picks ), true );
	}

	private static function specials_match( array $picks ): bool {
		foreach ( $picks as $special ) {
			switch ( $special ) {
				case 'front_page':
					if ( is_front_page() ) return true;
					break;
				case 'blog':
					if ( is_home() ) return true;
					break;
				case '404':
					if ( is_404() ) return true;
					break;
				case 'search':
					if ( is_search() ) return true;
					break;
				case 'archive':
					if ( is_archive() ) return true;
					break;
			}
		}
		return false;
	}

	private static function post_type_matches( array $picks ): bool {
		if ( ! is_singular() ) {
			return false;
		}
		return in_array( get_post_type(), $picks, true );
	}

	private static function category_matches( array $picks ): bool {
		if ( is_category() ) {
			$obj = get_queried_object();
			if ( $obj && in_array( (int) $obj->term_id, $picks, true ) ) {
				return true;
			}
		}
		if ( ! is_singular( 'post' ) ) {
			return false;
		}
		$ids = wp_get_post_categories( get_queried_object_id() );
		foreach ( $ids as $id ) {
			if ( in_array( (int) $id, $picks, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function tag_matches( array $picks ): bool {
		if ( is_tag() ) {
			$obj = get_queried_object();
			if ( $obj && in_array( (int) $obj->term_id, $picks, true ) ) {
				return true;
			}
		}
		if ( ! is_singular( 'post' ) ) {
			return false;
		}
		$ids = wp_get_post_tags( get_queried_object_id(), [ 'fields' => 'ids' ] );
		foreach ( (array) $ids as $id ) {
			if ( in_array( (int) $id, $picks, true ) ) {
				return true;
			}
		}
		return false;
	}
}
