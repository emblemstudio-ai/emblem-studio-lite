<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Visit_Source {

	public const KEYS = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'ref', 'referrer', 'landing' ];

	public const CLICKS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id' ];

	public const CHANNELS = [ 'paid', 'email', 'social', 'ai', 'search', 'campaign', 'referral', 'direct' ];

	private const SOCIAL = [ 'facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 'x.com', 't.co', 'linkedin.com', 'lnkd.in', 'pinterest.com', 'tiktok.com', 'youtube.com', 'reddit.com', 'threads.net', 'bsky.app' ];

	private const AI = [ 'chatgpt.com', 'chat.openai.com', 'claude.ai', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com' ];

	private const SEARCH = [ 'bing.com', 'duckduckgo.com', 'yahoo.com', 'ecosia.org', 'search.brave.com', 'baidu.com', 'yandex.ru', 'yandex.com' ];

	public const CHANNEL_NAMES = [
		'paid'     => 'Paid ads',
		'email'    => 'Email',
		'social'   => 'Social media',
		'ai'       => 'AI assistant',
		'search'   => 'Search engine',
		'campaign' => 'Tagged link',
		'referral' => 'Other website',
		'direct'   => 'Direct',
	];

	public static function from_request( $raw ): ?array {
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return null;
		}

		$out = [];
		foreach ( self::KEYS as $key ) {
			$value = $data[ $key ] ?? null;
			if ( is_scalar( $value ) ) {
				$value = mb_substr( trim( (string) $value ), 0, 100 );
				if ( $value !== '' ) {
					$out[ $key ] = $value;
				}
			}
		}
		if ( in_array( $data['click'] ?? null, self::CLICKS, true ) ) {
			$out['click'] = (string) $data['click'];
		}
		if ( isset( $out['referrer'] ) ) {
			$out['referrer'] = strtolower( $out['referrer'] );
		}

		$out['channel'] = self::channel( $out );

		return $out;
	}

	public static function channel( array $s ): string {
		$medium = strtolower( (string) ( $s['utm_medium'] ?? '' ) );
		$source = strtolower( (string) ( $s['utm_source'] ?? '' ) );
		$ref    = strtolower( (string) ( $s['referrer'] ?? '' ) );
		$on     = static function ( array $domains ) use ( $ref ): bool {
			foreach ( $domains as $d ) {
				if ( $ref === $d || str_ends_with( $ref, '.' . $d ) ) {
					return true;
				}
			}
			return false;
		};

		if ( ! empty( $s['click'] ) || preg_match( '/^(cpc|ppc|paid|paidsearch|paid_search|paid_social|paidsocial|display|cpm|cpv|banner)$/', $medium ) ) {
			return 'paid';
		}
		if ( preg_match( '/e-?mail|newsletter|^mail/', $medium ) || preg_match( '/e-?mail|newsletter/', $source ) ) {
			return 'email';
		}
		if ( $medium === 'social' || $on( self::SOCIAL ) ) {
			return 'social';
		}
		if ( $on( self::AI ) ) {
			return 'ai';
		}
		if ( preg_match( '/(^|\.)google\.[a-z.]+$/', $ref ) || $on( self::SEARCH ) ) {
			return 'search';
		}
		foreach ( [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'ref' ] as $tag ) {
			if ( ! empty( $s[ $tag ] ) ) {
				return 'campaign';
			}
		}

		return $ref !== '' ? 'referral' : 'direct';
	}

	public static function event_channel( $raw ): ?string {
		return is_string( $raw ) && in_array( $raw, self::CHANNELS, true ) ? $raw : null;
	}

	public static function decode( $json ): ?array {
		if ( ! is_string( $json ) || $json === '' ) {
			return null;
		}
		$s = json_decode( $json, true );
		return is_array( $s ) ? $s : null;
	}

	public static function label( ?array $s ): string {
		if ( ! $s ) {
			return '';
		}
		$detail = array_filter( [ $s['utm_source'] ?? ( $s['referrer'] ?? null ), $s['utm_campaign'] ?? null ] );

		return ( self::CHANNEL_NAMES[ $s['channel'] ?? 'direct' ] ?? 'Direct' ) . ( $detail ? ' · ' . implode( ' / ', $detail ) : '' );
	}

	public const CSV_HEADERS = [ 'Source channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ref', 'Referring site', 'First page' ];

	public static function csv_cells( ?array $s ): array {
		$s     = (array) $s;
		$cells = [];
		foreach ( [ 'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ref', 'referrer', 'landing' ] as $k ) {
			$cells[] = is_scalar( $s[ $k ] ?? null ) ? (string) $s[ $k ] : '';
		}
		return $cells;
	}
}
