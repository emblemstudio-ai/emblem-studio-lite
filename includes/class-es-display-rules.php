<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-form display targeting for auto-injected shells (popup-auto today;
 * ribbon / slide-in / etc. will reuse the same rules when they ship).
 *
 * Storage shape (JSON in emblemstudio_forms.display_rules):
 *
 *   {
 *     "show_mode": "all" | "selected",
 *     "show_filters": {
 *       "specials":   ["front_page" | "blog" | "404" | "search" | "archive"],
 *       "post_types": ["post", "page", "<cpt>"],
 *       "categories": [int],
 *       "tags":       [int]
 *     },
 *     "hide_filters": { ...same shape... }
 *   }
 *
 * Decision at render time:
 *   show_candidate = show_mode === 'all' OR any show_filter matches
 *   vetoed         = any hide_filter matches
 *   render         = show_candidate && !vetoed
 *
 * Within each filter set, matches are OR — selecting "Front page" plus
 * "Category: Recipes" means show on the front page OR on Recipes posts.
 */
class ES_Display_Rules {

	/**
	 * Is this front-end request being rendered INSIDE a wp-admin screen?
	 *
	 * A page builder's canvas is not an admin URL, which is why is_admin() never
	 * catches it. Elementor's editor lives at wp-admin/post.php?action=elementor,
	 * but the canvas you design in is an <iframe> pointing at the post's own
	 * permalink. That inner request is an ordinary front-end request: is_admin()
	 * is false, wp_footer fires, and every self-placing shell on the site drops
	 * itself over the widgets the author is trying to click.
	 *
	 * So the question to ask is not "which builder is this", it is "who opened
	 * me". The browser answers both halves of that: Sec-Fetch-Dest says the
	 * request is for a frame, and the referrer says the frame lives in wp-admin.
	 * One check, and it covers every builder, the Customizer, and whatever ships
	 * next, with no list to keep current.
	 *
	 * BOTH halves are required. A logged-in admin who clicks from wp-admin
	 * through to the site also arrives carrying an admin referrer, and that is a
	 * real page view we must not suppress.
	 */
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

	/**
	 * Fallback for the check above, used when the browser is too old to send
	 * Sec-Fetch-Dest or a referrer policy stripped the URL. These are the query
	 * args a builder puts on the front-end URL when it opens its canvas. Only
	 * presence is tested, never the value, so a builder that renames et_fb=1 to
	 * something else next year is still caught.
	 */
	private static function has_builder_canvas_arg(): bool {
		$args = [
			'elementor-preview',             // Elementor
			'fl_builder',                    // Beaver Builder
			'fl_builder_preview',
			'et_fb',                         // Divi
			'et_pb_preview',
			'ct_builder',                    // Oxygen
			'oxygen_iframe',
			'bricks',                        // Bricks
			'brizy-edit',                    // Brizy
			'brizy-edit-iframe',
			'vc_editable',                   // WPBakery Page Builder
			'vcv-editable',                  // Visual Composer Website Builder
			'tve',                           // Thrive Architect
			'siteorigin_panels_live_editor', // SiteOrigin Page Builder
			'zionbuilder-preview',           // Zion Builder
			'breakdance',                    // Breakdance
			'cs_preview_state',              // Cornerstone
		];

		foreach ( $args as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}

		return false;
	}

	public const SPECIALS = [ 'front_page', 'blog', '404', 'search', 'archive' ];

	/**
	 * WooCommerce page keys we can target. Only meaningful when WC is active;
	 * sanitize accepts them regardless so toggling WC off doesn't drop the
	 * saved rules, and toggling it back on resumes targeting.
	 */
	public const WC_PAGES = [
		'cart', 'checkout', 'account', 'shop',
		'product', 'product_archive',
		'product_category', 'product_tag',
	];

	/**
	 * Localised labels for the WC page keys — used by the admin UI.
	 */
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

	/**
	 * Defaults: show on every front-end page, hide nothing. Equivalent to
	 * the pre-v0.15.0 behavior — so existing forms keep firing everywhere.
	 */
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

	/**
	 * Cookie name used by the visitor-state gate. Set on the first AJAX
	 * event (page_view / view / trigger_view / submit) and re-read on
	 * subsequent page renders to decide "new vs returning."
	 */
	public const VISITOR_COOKIE = 'esf_visitor';

	/**
	 * Empty schedule: no day-of-week constraint, no time-of-day constraint.
	 * Each piece is independently empty so admins can constrain by day only,
	 * by hours only, or both. days[] uses 0=Sun … 6=Sat (PHP date('w') shape).
	 */
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

	/**
	 * Sanitize just the WHERE fields — show_mode + show_filters + hide_filters.
	 * Shared by sanitize() (a shell's full rule set) and ES_Preset_Repo (SER-899),
	 * since a preset is exactly this subset. Never touches schedule or visitor
	 * targeting. Mirrors the SaaS port's DisplayRules::sanitizeWhere().
	 */
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
		// Roles only have meaning when targeting logged-in visitors.
		$role_keys = ( $logged_in === 'in' && isset( $raw['visitor_roles'] ) && is_array( $raw['visitor_roles'] ) )
			? self::sanitize_roles( $raw['visitor_roles'] )
			: [];
		$visitor_state = isset( $raw['visitor_state'] ) ? (string) $raw['visitor_state'] : 'any';
		if ( ! in_array( $visitor_state, [ 'any', 'new', 'returning' ], true ) ) {
			$visitor_state = 'any';
		}
		// A shell "following" a preset (SER-899) — a UI/authoring pointer only. The
		// show_mode/show_filters/hide_filters below are always the resolved values
		// (kept in sync with the preset on save); enforcement never reads
		// preset_id, so nothing downstream (rendering, mirroring) needs to change.
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
		// Accept HH:MM (HTML5 time input) and HH:MM:SS.
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
			return $keys; // No role registry available — fall back to sanitised keys.
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

	/**
	 * Normalize one URL pattern entry. Trims, drops query/fragment, leading
	 * slash kept optional (caller-friendly), trailing slash dropped, lowercased.
	 * Returns empty string for nothing-meaningful — caller filters those.
	 */
	private static function normalize_url_pattern( $raw ): string {
		$s = trim( (string) $raw );
		if ( $s === '' ) {
			return '';
		}
		// Drop everything from a '?' or '#' onward.
		$q = strpos( $s, '?' );
		if ( $q !== false ) {
			$s = substr( $s, 0, $q );
		}
		$h = strpos( $s, '#' );
		if ( $h !== false ) {
			$s = substr( $s, 0, $h );
		}
		// Strip protocol + host if the admin pasted a full URL.
		$s = preg_replace( '#^https?://[^/]+#i', '', $s );
		// Force a leading slash so patterns match the way visitors see paths.
		if ( $s === '' ) {
			return '';
		}
		if ( $s[0] !== '/' ) {
			$s = '/' . $s;
		}
		// Strip trailing slash unless the pattern is just "/".
		if ( strlen( $s ) > 1 && substr( $s, -1 ) === '/' ) {
			$s = rtrim( $s, '/' );
		}
		return strtolower( $s );
	}

	/**
	 * Auto-injected shells that each get their own slice of the display_rules
	 * JSON column. Mirrors ES_Form_Repo::auto_shells(). Currently popup-auto
	 * only; ribbon + slide-in join in v0.17.x.
	 */
	public static function auto_shells(): array {
		return [ 'popup-auto', 'ribbon', 'slide-in' ];
	}

	/**
	 * @deprecated since v0.17.0 — kept as a thin compat shim. Returns the
	 * popup-auto slice of the per-shell rules map.
	 */
	public static function get_for_form( object $form ): array {
		return self::get_for_form_shell( $form, 'popup-auto' );
	}

	/**
	 * Read ONE shell's rules (sanitised single-set shape — same as defaults()).
	 */
	public static function get_for_form_shell( object $form, string $shell ): array {
		$all = self::get_all_for_form( $form );
		return $all[ $shell ] ?? self::defaults();
	}

	/**
	 * Read the full per-shell rules map for a form (always normalised).
	 */
	public static function get_all_for_form( object $form ): array {
		$raw     = $form->display_rules ?? '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return self::sanitize_all_shells( is_array( $decoded ) ? $decoded : [] );
	}

	/**
	 * Sanitize the per-shell display-rules map.
	 *
	 * Accepts both shapes for backwards-compat:
	 *   - Legacy single-set: { show_mode, show_filters, hide_filters }   (pre v0.17)
	 *   - Per-shell map:     { "popup-auto": { show_mode, ... }, ... }    (v0.17+)
	 *
	 * Always emits the per-shell map. Saves with the legacy single-set input
	 * migrate the row to the new shape; reads handle both transparently.
	 */
	public static function sanitize_all_shells( array $raw ): array {
		// Legacy single-set shape → wrap under popup-auto.
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

	/**
	 * The decision: should this form render for the current request?
	 */
	public static function should_render( array $rules ): bool {
		$rules = self::sanitize( $rules );
		// Visitor gate ANDs with everything else — must pass before page-matching.
		if ( ! self::visitor_matches( $rules ) ) {
			return false;
		}
		// Schedule gate (day-of-week + time-of-day) ANDs too.
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

	/**
	 * Day-of-week + time-of-day gate. Empty pieces are no-ops:
	 *   - days[] empty       → any day matches
	 *   - start_time empty   → no lower bound
	 *   - end_time empty     → no upper bound
	 *   - all empty          → always passes
	 *
	 * Across-midnight windows (start > end, e.g. 22:00 → 02:00) are
	 * interpreted as a wrap: current must be ≥ start OR ≤ end.
	 *
	 * Times come from WP site timezone via current_time(), so the admin's
	 * "between 9 and 5" reads as their site's local clock.
	 */
	private static function schedule_matches( array $schedule ): bool {
		// Read "now" from the WP site timezone, then hand the pure
		// comparison to schedule_allows() — which is unit-tested.
		return self::schedule_allows(
			$schedule,
			(int) current_time( 'w' ),
			(string) current_time( 'H:i' )
		);
	}

	/**
	 * Pure core of the schedule gate, with "now" injected so it can be
	 * exhaustively unit-tested without freezing the clock.
	 *
	 * @param array  $schedule { days[], start_time, end_time }
	 * @param int    $now_dow  Current day of week, 0=Sun … 6=Sat (date('w')).
	 * @param string $now_hm   Current time as "HH:MM", 24-hour.
	 *
	 * See schedule_matches()'s doc block above for the wrap-around rule.
	 */
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

		// Single-bound cases.
		if ( $start_time !== '' && $end_time === '' ) {
			return strcmp( $now_hm, $start_time ) >= 0;
		}
		if ( $start_time === '' ) {
			// Reached here only when end_time is non-empty (the both-empty
			// and start-only cases returned above).
			return strcmp( $now_hm, $end_time ) <= 0;
		}

		// Two-bound case: normal window if start <= end, wrap-around otherwise.
		if ( strcmp( $start_time, $end_time ) <= 0 ) {
			return strcmp( $now_hm, $start_time ) >= 0 && strcmp( $now_hm, $end_time ) <= 0;
		}
		return strcmp( $now_hm, $start_time ) >= 0 || strcmp( $now_hm, $end_time ) <= 0;
	}

	/**
	 * Visitor-state gate (logged-in / logged-out / user role).
	 * Returns true if the current request's visitor satisfies the per-shell
	 * visitor constraints; false to suppress.
	 */
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
			// Roles are only enforced when targeting logged-in users — sanitize
			// already drops them otherwise, this is a belt-and-suspenders check.
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

	/**
	 * The esf_visitor cookie is written by frontend.js alone (audit B3). It is
	 * the visitor id the reports count people by, and it is what the
	 * new-vs-returning gate reads: frontend.js writes it on the first beacon,
	 * so any later page sees a returning visitor, as before.
	 *
	 * This used to write the cookie here too, as an HttpOnly '1'. frontend.js
	 * cannot read an HttpOnly cookie or overwrite it, so on every page it saw
	 * no id and minted a new one, and one person counted as a new visitor on
	 * every page. It also skipped the consent check frontend.js applies.
	 *
	 * What is left is the clean-up: a '1' still in a visitor's browser is that
	 * old server cookie, so it is expired, and the next page's script writes a
	 * real id it can read.
	 */
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

	/**
	 * Transient key cached for ~1 hour — flushed by Form_Repo on every save,
	 * trash, restore, or delete via `delete_transient(...)`. Recomputed on
	 * next read by scanning every active, non-trashed form's display_rules
	 * for a non-default visitor_state value.
	 */
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
			// Decoded shape is per-shell map: { 'popup-auto' => { ..., visitor_state }, ... }
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

	/**
	 * True when ANY of the filters in the set matches the current request.
	 */
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

	/**
	 * Match the current request against any of the given WooCommerce page
	 * keys. No-op when WC isn't active — saved rules persist but never fire.
	 */
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

	/**
	 * Match the current request URI against any of the given glob-style
	 * patterns. `*` matches any sequence, `?` matches any single char.
	 * Patterns are pre-normalised by normalize_url_pattern; the current
	 * URI is normalised the same way before comparison.
	 */
	private static function url_matches( array $patterns ): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		return self::url_pattern_matches( $patterns, $uri );
	}

	/**
	 * Pure core of the URL gate, with the request path injected so it
	 * can be unit-tested. $uri is run through the same normalisation as
	 * the stored patterns before the glob comparison.
	 */
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
