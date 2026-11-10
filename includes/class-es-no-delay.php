<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Emblem_Studio_No_Delay {

	const ATTRS = 'data-cfasync="false" nowprocket data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-no-delay-js data-wpmeteor-nooptimize="true" data-jetpack-boost="ignore" data-dont-merge nitro-exclude data-loading-method="eager"';

	const INLINE_NEEDLES = [ 'window.EmblemStudio', 'var EmblemStudio' ];

	const SAFE_NEEDLE = '#^[A-Za-z0-9./_ -]+$#';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'rocket_delay_js_exclusions', [ __CLASS__, 'with_nowprocket' ] );
		add_filter( 'rocket_minify_excluded_external_js', [ __CLASS__, 'with_url_needles' ] );
		add_filter( 'rocket_excluded_inline_js_content', [ __CLASS__, 'with_inline_needles' ] );

		add_filter( 'perfmatters_delay_js_exclusions', [ __CLASS__, 'with_all_needles' ] );

		add_filter( 'wphb_delay_js_exclusions', [ __CLASS__, 'with_all_needles' ] );

		add_filter( 'debloat/delay_js_excludes', [ __CLASS__, 'with_all_needles' ] );

		add_filter( 'sgo_javascript_combine_excluded_external_paths', [ __CLASS__, 'with_url_needles' ] );
		add_filter( 'sgo_javascript_combine_excluded_inline_content', [ __CLASS__, 'with_inline_needles' ] );

		add_filter( 'wpmeteor_exclude', [ __CLASS__, 'wpmeteor_exclude' ], 10, 2 );

		add_filter( 'wp_inline_script_attributes', [ __CLASS__, 'inline_attributes' ] );
	}

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

	public static function all_needles(): array {
		return array_merge( self::url_needles(), self::safe( self::INLINE_NEEDLES ) );
	}

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

	private static function merged( $list, array $add ): array {
		$list = is_array( $list ) ? $list : [];
		foreach ( $add as $item ) {
			if ( ! in_array( $item, $list, true ) ) {
				$list[] = $item;
			}
		}
		return $list;
	}

	public static function with_nowprocket( $list ): array {
		return self::merged( $list, array_merge( [ 'nowprocket' ], self::all_needles() ) );
	}

	public static function with_all_needles( $list ): array {
		return self::merged( $list, self::all_needles() );
	}

	public static function with_url_needles( $list ): array {
		return self::merged( $list, self::url_needles() );
	}

	public static function with_inline_needles( $list ): array {
		return self::merged( $list, self::safe( self::INLINE_NEEDLES ) );
	}

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

	public static function inline_attributes( $attributes ): array {
		$attributes = is_array( $attributes ) ? $attributes : [];
		if ( ( $attributes['id'] ?? '' ) !== 'emblem-studio-frontend-js-extra' ) {
			return $attributes;
		}
		return $attributes + self::attribute_map();
	}

	public static function attribute_map(): array {
		preg_match_all( '/([a-z-]+)(?:="([^"]*)")?/', self::ATTRS, $m, PREG_SET_ORDER );
		$map = [];
		foreach ( $m as $pair ) {
			$map[ $pair[1] ] = isset( $pair[2] ) ? $pair[2] : true;
		}
		return $map;
	}
}
