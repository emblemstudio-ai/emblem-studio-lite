<?php
/**
 * Keep speed plugins from holding our front-end scripts back.
 *
 * Many speed plugins "delay JavaScript": they rewrite a script so it only runs
 * once the visitor scrolls, clicks or moves the mouse. For a form that is
 * already on screen, that is an empty space until the visitor touches
 * something. Search engines never touch anything, so for them the form never
 * appears at all. On TST Prep's free-resource pages the form IS the page, and
 * Google was indexing a page with no heading and no content (found 2026-09-30,
 * FlyingPress, `data-loading-method="user-interaction"` on our embed.js).
 *
 * Each plugin has its own way to opt out, and none of them reads the others'.
 * This class is the one list of them all, used by both our front-end script
 * (standalone plugin) and embed.js (the SaaS connector). Every entry below was
 * checked against that plugin's own source on 2026-09-30, except Perfmatters
 * and NitroPack, which publish no source and were taken from their docs.
 *
 * Two kinds of opt-out:
 *  - attributes on the tag (ATTRS / inline_attributes()), read by plugins that
 *    look at markup;
 *  - filters (register()), for plugins that only exclude by a list of strings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ES_No_Delay {

	/**
	 * Attributes for a <script> tag, in this order.
	 *
	 * data-cfasync comes first on purpose: Cloudflare's Rocket Loader only
	 * honours it when it sits before src.
	 *
	 * data-loading-method is FlyingPress's own marker. FlyingPress leaves any
	 * tag that already has one alone, and its loader only acts on tags that
	 * also carry data-src, which ours never do. "eager" means nothing to it,
	 * so the browser simply loads the script. FlyingPress has no filter for
	 * this; its exclusions are a settings list only.
	 *
	 *  data-cfasync="false"             Cloudflare Rocket Loader, LiteSpeed, Autoptimize
	 *  nowprocket                       WP Rocket (delay)
	 *  data-no-optimize / -no-defer     LiteSpeed Cache
	 *  data-no-minify                   minifiers in general
	 *  data-no-delay-js                 WP-Optimize
	 *  data-wpmeteor-nooptimize="true"  WP Meteor (exact value required)
	 *  data-jetpack-boost="ignore"      Jetpack Boost
	 *  data-dont-merge                  Swift Performance
	 *  nitro-exclude                    NitroPack
	 *  data-loading-method="eager"      FlyingPress
	 */
	const ATTRS = 'data-cfasync="false" nowprocket data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-no-delay-js data-wpmeteor-nooptimize="true" data-jetpack-boost="ignore" data-dont-merge nitro-exclude data-loading-method="eager"';

	/**
	 * Strings that pick out our inline config scripts by their content. The
	 * connector writes `window.EmblemStudio = ...`; wp_localize_script() writes
	 * `var EmblemStudio = ...`.
	 */
	const INLINE_NEEDLES = [ 'window.EmblemStudio', 'var EmblemStudio' ];

	/**
	 * Characters a needle may contain. Several of the filters below are
	 * regular expressions (WP Rocket, Hummingbird), and WP Rocket joins its
	 * lists with `|`, so one bad pattern breaks exclusions for the whole page.
	 * Letters, digits, dot, slash, dash, underscore and space are safe both as
	 * a plain substring and as a regex. A dot matching "any character" is
	 * harmless here.
	 */
	const SAFE_NEEDLE = '#^[A-Za-z0-9./_ -]+$#';

	/** Registered once. */
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// WP Rocket. `nowprocket` covers delay already, but only because it
		// sits in a list WP Rocket refreshes from its own servers. Naming it
		// here too removes that dependency.
		add_filter( 'rocket_delay_js_exclusions', [ __CLASS__, 'with_nowprocket' ] );
		// WP Rocket copies outside scripts into its own combined file unless
		// told not to. A copy of embed.js would go stale.
		add_filter( 'rocket_minify_excluded_external_js', [ __CLASS__, 'with_url_needles' ] );
		add_filter( 'rocket_excluded_inline_js_content', [ __CLASS__, 'with_inline_needles' ] );

		// Perfmatters (docs only: https://perfmatters.io/docs/filters/).
		add_filter( 'perfmatters_delay_js_exclusions', [ __CLASS__, 'with_all_needles' ] );

		// Hummingbird. Regex fragments, matched against the whole tag.
		add_filter( 'wphb_delay_js_exclusions', [ __CLASS__, 'with_all_needles' ] );

		// Debloat. Plain text, matched against the whole tag.
		add_filter( 'debloat/delay_js_excludes', [ __CLASS__, 'with_all_needles' ] );

		// SiteGround Optimizer has no delay, but its "combine" downloads
		// outside scripts into one file, which would freeze embed.js.
		add_filter( 'sgo_javascript_combine_excluded_external_paths', [ __CLASS__, 'with_url_needles' ] );
		add_filter( 'sgo_javascript_combine_excluded_inline_content', [ __CLASS__, 'with_inline_needles' ] );

		// WP Meteor. The attribute already covers it; this catches a tag that
		// lost its attributes to some other optimizer first.
		add_filter( 'wpmeteor_exclude', [ __CLASS__, 'wpmeteor_exclude' ], 10, 2 );

		// Our own localized config (`var EmblemStudio = ...`) is printed by
		// WordPress, not by us, so it gets the attributes here.
		add_filter( 'wp_inline_script_attributes', [ __CLASS__, 'inline_attributes' ] );
	}

	/**
	 * Strings that pick out our external scripts by URL: the standalone
	 * plugin's frontend.js and, through the `es_no_delay_urls` filter, the
	 * connector's embed.js. Host and path only, no scheme.
	 *
	 * @return string[]
	 */
	public static function url_needles(): array {
		$urls = [];
		if ( defined( 'EMBLEM_STUDIO_URL' ) ) {
			$urls[] = EMBLEM_STUDIO_URL . 'assets/frontend.js';
		}
		$urls = (array) apply_filters( 'es_no_delay_urls', $urls );

		$needles = [];
		foreach ( $urls as $url ) {
			$needle = preg_replace( '#^(https?:)?//#i', '', (string) $url );
			$needle = strtok( (string) $needle, '?#' );
			if ( is_string( $needle ) ) {
				$needles[] = $needle;
			}
		}
		return self::safe( $needles );
	}

	/** @return string[] */
	public static function all_needles(): array {
		return array_merge( self::url_needles(), self::safe( self::INLINE_NEEDLES ) );
	}

	/**
	 * Drop anything empty or with characters that would change meaning in a
	 * regex. An empty pattern is the dangerous one: `##i` matches every tag,
	 * which would exempt the whole site's JavaScript from delay.
	 *
	 * @param array<mixed> $needles
	 * @return string[]
	 */
	public static function safe( array $needles ): array {
		$out = [];
		foreach ( $needles as $needle ) {
			$needle = trim( (string) $needle );
			if ( strlen( $needle ) >= 6 && preg_match( self::SAFE_NEEDLE, $needle ) ) {
				$out[] = $needle;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed $list
	 * @param string[] $add
	 * @return array<mixed>
	 */
	private static function merged( $list, array $add ): array {
		$list = is_array( $list ) ? $list : [];
		foreach ( $add as $item ) {
			if ( ! in_array( $item, $list, true ) ) {
				$list[] = $item;
			}
		}
		return $list;
	}

	/**
	 * @param mixed $list
	 * @return array<mixed>
	 */
	public static function with_nowprocket( $list ): array {
		return self::merged( $list, array_merge( [ 'nowprocket' ], self::all_needles() ) );
	}

	/**
	 * @param mixed $list
	 * @return array<mixed>
	 */
	public static function with_all_needles( $list ): array {
		return self::merged( $list, self::all_needles() );
	}

	/**
	 * @param mixed $list
	 * @return array<mixed>
	 */
	public static function with_url_needles( $list ): array {
		return self::merged( $list, self::url_needles() );
	}

	/**
	 * @param mixed $list
	 * @return array<mixed>
	 */
	public static function with_inline_needles( $list ): array {
		return self::merged( $list, self::safe( self::INLINE_NEEDLES ) );
	}

	/**
	 * @param mixed $exclude
	 * @param mixed $subject The script's content, src or id.
	 */
	public static function wpmeteor_exclude( $exclude, $subject = '' ): bool {
		if ( $exclude ) {
			return true;
		}
		$subject = (string) $subject;
		foreach ( self::all_needles() as $needle ) {
			if ( stripos( $subject, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The same opt-outs as ATTRS, for an inline tag WordPress prints itself.
	 * Only our localized config is touched.
	 *
	 * @param mixed $attributes
	 * @return array<mixed>
	 */
	public static function inline_attributes( $attributes ): array {
		$attributes = is_array( $attributes ) ? $attributes : [];
		if ( ( $attributes['id'] ?? '' ) !== 'emblem-studio-frontend-js-extra' ) {
			return $attributes;
		}
		return $attributes + self::attribute_map();
	}

	/**
	 * ATTRS as a name => value map. A bare attribute maps to true, which
	 * WordPress prints as the name alone.
	 *
	 * @return array<string, string|true>
	 */
	public static function attribute_map(): array {
		preg_match_all( '/([a-z-]+)(?:="([^"]*)")?/', self::ATTRS, $m, PREG_SET_ORDER );
		$map = [];
		foreach ( $m as $pair ) {
			$map[ $pair[1] ] = isset( $pair[2] ) ? $pair[2] : true;
		}
		return $map;
	}
}
