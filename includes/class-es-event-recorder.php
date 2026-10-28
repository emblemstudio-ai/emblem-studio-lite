<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Event_Recorder {

	public static function record( int $form_id, string $event_type, ?int $post_id = null, string $shell = 'inline', ?string $click_target = null, ?string $variant = null, ?int $lead_id = null, ?string $step = null ): bool {
		$step    = $event_type === 'dropoff' ? self::clean_step( $step ) : '';
		$context = [ 'form_id' => $form_id, 'event_type' => $event_type, 'lead_id' => $lead_id ];
		if ( ES_Measurement::policy( $context ) === 'strict' ) {
			return ES_Measurement::authorized_write( $context, static function ( array $decision ) use ( $form_id, $event_type, $post_id, $shell, $variant, $lead_id, $step ): bool {
				global $wpdb;
				$personal = $decision['personal'];
				$row = [
					'form_id' => $form_id, 'event_type' => $event_type, 'type' => $shell,
					'post_id' => $post_id, 'created_at' => current_time( 'mysql' ),
					'measurement_mode' => $personal ? 'personal' : 'aggregate',
					'lead_id' => $personal ? $lead_id : null,
				];
				if ( $variant !== null && in_array( strtoupper( $variant ), [ 'A', 'B' ], true ) ) {
					$row['variant'] = strtoupper( $variant );
				}
				if ( $step !== '' ) {
					$row['step'] = $step;
				}
				if ( $personal ) {
					foreach ( [ 'visitor' => 40, 'ua' => 255 ] as $key => $limit ) {
						if ( isset( $decision[ $key ] ) && is_string( $decision[ $key ] ) && $decision[ $key ] !== '' ) {
							$row[ $key ] = mb_substr( sanitize_text_field( $decision[ $key ] ), 0, $limit );
						}
					}
				}
				return $wpdb->insert( ES_Install::table( 'events' ), $row ) === 1;
			} );
		}
		global $wpdb;
		$row = [
			'form_id'    => $form_id,
			'event_type' => $event_type,
			'type'       => $shell,
			'post_id'    => $post_id,
			'created_at' => current_time( 'mysql' ),
			'measurement_mode' => 'legacy',
		];
		if ( in_array( $event_type, [ 'click', 'honeypot_blocked' ], true ) && $click_target !== null && $click_target !== '' ) {
			$row['click_target'] = mb_substr( $click_target, 0, 191 );
		}
		if ( $variant !== null ) {
			$v = strtoupper( $variant );
			if ( in_array( $v, [ 'A', 'B' ], true ) ) {
				$row['variant'] = $v;
			}
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( $ua !== '' ) {
			$row['ua'] = mb_substr( $ua, 0, 255 );
		}
		$vid = isset( $_POST['vid'] ) ? preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $_POST['vid'] ) : '';
		if ( $vid !== '' ) {
			$row['visitor'] = mb_substr( $vid, 0, 40 );
		}
		if ( $step !== '' ) {
			$row['step'] = $step;
		}
		return $wpdb->insert( ES_Install::table( 'events' ), $row ) === 1;
	}

	public static function clean_step( ?string $step ): string {
		if ( $step === null ) {
			return '';
		}
		$clean = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $step );
		return trim( mb_substr( trim( $clean ), 0, 64 ) );
	}

	public static function filter_bots_enabled(): bool {
		return true;
	}

	public const OPT_TRUST_PROXY = 'emblem_studio_trust_proxy';

	public static function trust_proxy_enabled(): bool {
		if ( defined( 'EMBLEM_STUDIO_TRUST_PROXY' ) && EMBLEM_STUDIO_TRUST_PROXY ) {
			return true;
		}
		return get_option( self::OPT_TRUST_PROXY, '0' ) === '1';
	}

	public const OPT_EXCLUDE_IPS = 'emblem_studio_exclude_ips';

	public static function excluded_ips(): array {
		$v = get_option( self::OPT_EXCLUDE_IPS, [] );
		return is_array( $v ) ? $v : [];
	}

	public static function is_excluded_ip( string $ip ): bool {
		if ( $ip === '' ) {
			return false;
		}
		return in_array( $ip, self::excluded_ips(), true );
	}

	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
		if ( ! self::trust_proxy_enabled() ) {
			return preg_replace( '/[^0-9a-f:.]/i', '', (string) $ip );
		}

		$forwarded = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? self::rightmost_public_hop( (string) $_SERVER['HTTP_X_FORWARDED_FOR'], $remote )
			: '';
		$peer_is_local = $ip !== '0.0.0.0' && ! self::is_public_ip( $ip );

		$via_cloudflare = self::is_cloudflare_ip( $ip )
			|| ( $peer_is_local && $forwarded !== '' && self::is_cloudflare_ip( $forwarded ) );
		if ( $via_cloudflare && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidate = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return preg_replace( '/[^0-9a-f:.]/i', '', $candidate );
			}
		}

		if ( $forwarded !== '' ) {
			return preg_replace( '/[^0-9a-f:.]/i', '', $forwarded );
		}

		if ( $peer_is_local && ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$candidate = trim( (string) $_SERVER['HTTP_X_REAL_IP'] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return preg_replace( '/[^0-9a-f:.]/i', '', $candidate );
			}
		}
		return preg_replace( '/[^0-9a-f:.]/i', '', (string) $ip );
	}

	private static function is_public_ip( string $ip ): bool {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	public static function cloudflare_ranges(): array {
		$ranges = [
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
			'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
			'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
			'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
		];
		return (array) apply_filters( 'emblem_studio_cloudflare_ranges', $ranges );
	}

	public static function is_cloudflare_ip( string $ip ): bool {
		foreach ( self::cloudflare_ranges() as $cidr ) {
			if ( self::ip_in_cidr( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	public static function ip_in_cidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		$addr  = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $addr === false || $net === false || strlen( $addr ) !== strlen( $net ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $addr ) * 8;
		$full = intdiv( $bits, 8 );
		if ( strncmp( $addr, $net, $full ) !== 0 ) {
			return false;
		}
		$rest = $bits % 8;
		if ( $rest === 0 ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $addr[ $full ] ) & $mask ) === ( ord( $net[ $full ] ) & $mask );
	}

	public static function rightmost_public_hop( string $header, string $remote ): string {
		$hops = array_reverse( array_map( 'trim', explode( ',', $header ) ) );
		foreach ( $hops as $hop ) {
			if ( $hop === '' || $hop === $remote ) {
				continue;
			}
			if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! filter_var( $hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				continue;
			}
			return $hop;
		}
		return '';
	}

	public const OPT_DETECT_DATACENTER = 'emblem_studio_detect_datacenter';

	public static function detect_datacenter_enabled(): bool {
		return get_option( self::OPT_DETECT_DATACENTER, '1' ) !== '0';
	}

	public static function datacenter_providers(): array {
		return array_keys( self::datacenter_ranges() );
	}

	public static function datacenter_ranges(): array {
		$ranges = [
			'DigitalOcean' => [
				'45.55.0.0/16', '46.101.0.0/16', '64.225.0.0/16', '64.226.0.0/16', '64.227.0.0/16',
				'68.183.0.0/16', '95.85.0.0/16', '104.131.0.0/16', '104.236.0.0/16', '128.199.0.0/16',
				'134.122.0.0/16', '134.209.0.0/16', '137.184.0.0/16', '138.68.0.0/16', '138.197.0.0/16',
				'139.59.0.0/16', '142.93.0.0/16', '143.110.0.0/16', '143.198.0.0/16', '146.190.0.0/16',
				'157.230.0.0/16', '157.245.0.0/16', '159.65.0.0/16', '159.89.0.0/16', '159.203.0.0/16',
				'159.223.0.0/16', '161.35.0.0/16', '164.90.0.0/16', '164.92.0.0/16', '165.22.0.0/16',
				'165.227.0.0/16', '167.71.0.0/16', '167.99.0.0/16', '167.172.0.0/16', '170.64.0.0/16',
				'174.138.0.0/16', '178.62.0.0/16', '178.128.0.0/16', '188.166.0.0/16', '192.241.0.0/16',
				'198.199.0.0/16', '206.189.0.0/16', '209.38.0.0/16', '209.97.0.0/16',
			],
			'GCP' => [
				'34.64.0.0/10', '34.128.0.0/10', '35.184.0.0/13', '35.192.0.0/13', '35.208.0.0/12',
				'104.196.0.0/14', '130.211.0.0/16', '146.148.0.0/17',
			],
			'Azure' => [
				'20.0.0.0/8', '4.128.0.0/9', '40.64.0.0/10', '13.64.0.0/11', '52.224.0.0/11',
				'104.40.0.0/13', '23.96.0.0/13', '65.52.0.0/14', '137.116.0.0/15', '168.61.0.0/16',
				'191.232.0.0/13',
			],
			'AWS' => [
				'3.0.0.0/8', '13.32.0.0/15', '15.156.0.0/14', '18.32.0.0/11', '18.64.0.0/10',
				'18.128.0.0/9', '34.192.0.0/10', '35.152.0.0/13', '44.192.0.0/10', '50.16.0.0/14',
				'52.0.0.0/8', '54.64.0.0/11', '54.144.0.0/12', '54.160.0.0/11', '54.192.0.0/11',
				'54.224.0.0/11', '99.77.0.0/16', '100.20.0.0/14', '107.20.0.0/14', '184.72.0.0/15',
			],
			'Linode' => [
				'45.33.0.0/16', '45.56.0.0/16', '45.79.0.0/16', '50.116.0.0/16', '66.228.32.0/19',
				'69.164.192.0/19', '96.126.96.0/19', '139.144.0.0/16', '139.162.0.0/16', '172.104.0.0/15',
				'172.232.0.0/14', '178.79.128.0/18', '198.58.96.0/19',
			],
			'Hetzner' => [
				'5.9.0.0/16', '49.12.0.0/15', '65.108.0.0/15', '78.46.0.0/15', '88.99.0.0/16',
				'94.130.0.0/16', '95.216.0.0/15', '116.202.0.0/15', '136.243.0.0/16', '138.201.0.0/16',
				'144.76.0.0/16', '148.251.0.0/16', '159.69.0.0/16', '168.119.0.0/16', '176.9.0.0/16',
				'178.63.0.0/16', '188.40.0.0/16', '195.201.0.0/16',
			],
			'OVH' => [
				'5.135.0.0/16', '37.59.0.0/16', '37.187.0.0/16', '46.105.0.0/16', '51.68.0.0/14',
				'51.75.0.0/16', '51.83.0.0/16', '51.89.0.0/16', '51.91.0.0/16', '51.195.0.0/16',
				'54.36.0.0/16', '91.121.0.0/16', '92.222.0.0/16', '94.23.0.0/16', '137.74.0.0/16',
				'145.239.0.0/16', '147.135.0.0/16', '151.80.0.0/16', '158.69.0.0/16', '167.114.0.0/16',
				'178.32.0.0/15', '188.165.0.0/16',
			],
			'Oracle Cloud' => [
				'129.146.0.0/16', '129.213.0.0/16', '132.145.0.0/16', '140.238.0.0/16', '150.136.0.0/16',
				'152.67.0.0/16', '152.69.0.0/16', '158.101.0.0/16', '158.178.0.0/16', '168.138.0.0/16',
				'193.122.0.0/16', '193.123.0.0/16',
			],
		];
		return (array) apply_filters( 'emblem_studio_datacenter_ranges', $ranges );
	}

	public static function datacenter_provider( string $ip ): string {
		if ( $ip === '' || strpos( $ip, ':' ) !== false ) {
			return '';
		}
		$ipl = ip2long( $ip );
		if ( $ipl === false ) {
			return '';
		}
		$ipl &= 0xFFFFFFFF;
		foreach ( self::datacenter_ranges() as $provider => $cidrs ) {
			foreach ( $cidrs as $cidr ) {
				$parts = explode( '/', $cidr );
				$base  = ip2long( $parts[0] );
				if ( $base === false ) {
					continue;
				}
				$bits = isset( $parts[1] ) ? (int) $parts[1] : 32;
				$mask = ( $bits >= 32 ) ? 0xFFFFFFFF : ( ( 0xFFFFFFFF << ( 32 - $bits ) ) & 0xFFFFFFFF );
				if ( ( $ipl & $mask ) === ( ( $base & 0xFFFFFFFF ) & $mask ) ) {
					return (string) $provider;
				}
			}
		}
		return '';
	}

	public static function bot_catalog(): array {
		return [
			[ 'PhantomJS', 'PhantomJS', 'Automation' ],
			[ 'Selenium', 'Selenium', 'Automation' ],
			[ 'Puppeteer', 'Puppeteer', 'Automation' ],
			[ 'Playwright', 'Playwright', 'Automation' ],
			[ 'Cypress', 'Cypress', 'Automation' ],
			[ 'Chrome-Lighthouse', 'Lighthouse', 'Automation' ],
			[ 'Lighthouse', 'Lighthouse', 'Automation' ],
			[ 'PageSpeed', 'PageSpeed', 'Automation' ],

			[ 'curl/', 'curl', 'HTTP libraries' ],
			[ 'wget/', 'wget', 'HTTP libraries' ],
			[ 'HTTPie', 'HTTPie', 'HTTP libraries' ],
			[ 'python-requests', 'python-requests', 'HTTP libraries' ],
			[ 'python-urllib', 'python-urllib', 'HTTP libraries' ],
			[ 'Go-http-client', 'Go HTTP client', 'HTTP libraries' ],
			[ 'Java/', 'Java', 'HTTP libraries' ],
			[ 'axios/', 'axios', 'HTTP libraries' ],
			[ 'Apache-HttpClient', 'Apache HttpClient', 'HTTP libraries' ],
			[ 'okhttp', 'OkHttp', 'HTTP libraries' ],

			[ 'GPTBot', 'GPTBot', 'AI scrapers' ],
			[ 'ChatGPT-User', 'ChatGPT-User', 'AI scrapers' ],
			[ 'OAI-SearchBot', 'OAI-SearchBot', 'AI scrapers' ],
			[ 'ClaudeBot', 'ClaudeBot', 'AI scrapers' ],
			[ 'anthropic-ai', 'anthropic-ai', 'AI scrapers' ],
			[ 'PerplexityBot', 'PerplexityBot', 'AI scrapers' ],
			[ 'Perplexity-User', 'Perplexity-User', 'AI scrapers' ],
			[ 'Google-Extended', 'Google-Extended', 'AI scrapers' ],
			[ 'CCBot', 'CCBot (Common Crawl)', 'AI scrapers' ],
			[ 'Bytespider', 'Bytespider', 'AI scrapers' ],
			[ 'YouBot', 'YouBot', 'AI scrapers' ],
			[ 'Diffbot', 'Diffbot', 'AI scrapers' ],
			[ 'cohere-ai', 'cohere-ai', 'AI scrapers' ],
			[ 'meta-externalagent', 'Meta external agent', 'AI scrapers' ],
			[ 'Applebot-Extended', 'Applebot-Extended', 'AI scrapers' ],
			[ 'Amazonbot', 'Amazonbot', 'AI scrapers' ],
			[ 'omgilibot', 'omgilibot', 'AI scrapers' ],

			[ 'Googlebot', 'Googlebot', 'Search engines' ],
			[ 'Bingbot', 'Bingbot', 'Search engines' ],
			[ 'Slurp', 'Yahoo! Slurp', 'Search engines' ],
			[ 'DuckDuckBot', 'DuckDuckBot', 'Search engines' ],
			[ 'Baiduspider', 'Baiduspider', 'Search engines' ],
			[ 'YandexBot', 'YandexBot', 'Search engines' ],
			[ 'Sogou', 'Sogou', 'Search engines' ],
			[ 'NaverBot', 'NaverBot', 'Search engines' ],
			[ 'Applebot', 'Applebot', 'Search engines' ],
			[ 'PetalBot', 'PetalBot', 'Search engines' ],

			[ 'AhrefsBot', 'AhrefsBot', 'SEO & marketing' ],
			[ 'semrush', 'SemrushBot', 'SEO & marketing' ],
			[ 'MJ12bot', 'MJ12bot', 'SEO & marketing' ],
			[ 'DotBot', 'DotBot', 'SEO & marketing' ],
			[ 'SerpstatBot', 'SerpstatBot', 'SEO & marketing' ],
			[ 'AwarioBot', 'AwarioBot', 'SEO & marketing' ],
			[ 'MegaIndex', 'MegaIndex', 'SEO & marketing' ],
			[ 'DataForSeoBot', 'DataForSeoBot', 'SEO & marketing' ],
			[ 'BLEXBot', 'BLEXBot', 'SEO & marketing' ],
			[ 'Pinterestbot', 'Pinterestbot', 'SEO & marketing' ],

			[ 'archive.org_bot', 'archive.org', 'Archive' ],
			[ 'ia_archiver', 'Internet Archive', 'Archive' ],
			[ 'Wayback', 'Wayback Machine', 'Archive' ],

			[ 'facebookexternalhit', 'Facebook (link preview)', 'Social previewers' ],
			[ 'Twitterbot', 'Twitterbot', 'Social previewers' ],
			[ 'LinkedInBot', 'LinkedInBot', 'Social previewers' ],
			[ 'Slackbot-LinkExpanding', 'Slack (link preview)', 'Social previewers' ],
			[ 'WhatsApp', 'WhatsApp', 'Social previewers' ],
			[ 'Discordbot', 'Discordbot', 'Social previewers' ],
			[ 'TelegramBot', 'TelegramBot', 'Social previewers' ],
			[ 'SkypeUriPreview', 'Skype (link preview)', 'Social previewers' ],

			[ 'headless', 'Headless browser', 'Generic' ],
			[ 'crawl', 'Generic crawler', 'Generic' ],
			[ 'spider', 'Generic spider', 'Generic' ],
			[ 'bot', 'Generic bot', 'Generic' ],
		];
	}

	public static function bot_category_order(): array {
		return [
			'Generic', 'Datacenter', 'Automation', 'HTTP libraries', 'AI scrapers',
			'Search engines', 'SEO & marketing', 'Archive',
			'Social previewers', 'Other',
		];
	}

	public static function bot_patterns(): array {
		$patterns = array_map( static fn( $e ) => $e[0], self::bot_catalog() );
		return (array) apply_filters( 'emblem_studio_bot_user_agents', $patterns );
	}

	public static function is_bot_request(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( $ua === '' ) {
			return true;
		}
		foreach ( self::bot_patterns() as $needle ) {
			if ( $needle !== '' && stripos( $ua, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	public static function matched_bot_label( ?string $ua = null ): string {
		if ( $ua === null ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		}
		if ( $ua === '' ) {
			return '(no user-agent)';
		}
		foreach ( self::bot_catalog() as $entry ) {
			if ( stripos( $ua, $entry[0] ) !== false ) {
				return $entry[1];
			}
		}
		foreach ( self::bot_patterns() as $needle ) {
			if ( $needle !== '' && stripos( $ua, $needle ) !== false ) {
				return $needle;
			}
		}
		return '';
	}

	public static function category_for_label( string $label ): string {
		if ( $label === '(no user-agent)' ) {
			return 'Generic';
		}
		if ( in_array( $label, self::datacenter_providers(), true ) ) {
			return 'Datacenter';
		}
		foreach ( self::bot_catalog() as $entry ) {
			if ( $entry[1] === $label ) {
				return $entry[2];
			}
		}
		return 'Other';
	}

	public static function anonymize_ip( string $ip ): string {
		$ip = trim( $ip );
		if ( $ip === '' ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts      = explode( '.', $ip );
			$parts[3]   = '0';
			return implode( '.', $parts );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = @inet_pton( $ip );
			if ( $bin !== false ) {
				$masked = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
				$out    = @inet_ntop( $masked );
				if ( $out !== false ) {
					return $out;
				}
			}
		}
		return '';
	}

	public static function record_bot( int $form_id, string $shell, ?int $post_id, string $label, string $ua, string $ip ): void {
		if ( ES_Measurement::policy( [ 'form_id' => $form_id, 'event_type' => 'bot_filtered', 'lead_id' => null ] ) === 'strict' ) {
			self::record( $form_id, 'bot_filtered', $post_id, $shell );
			return;
		}
		global $wpdb;
		$wpdb->insert( ES_Install::table( 'events' ), [
			'form_id'      => $form_id,
			'event_type'   => 'bot_filtered',
			'type'         => $shell,
			'post_id'      => $post_id,
			'click_target' => $label !== '' ? mb_substr( $label, 0, 191 ) : null,
			'ua'           => $ua !== '' ? mb_substr( $ua, 0, 255 ) : null,
			'ip'           => $ip !== '' ? mb_substr( $ip, 0, 45 ) : null,
			'created_at'   => current_time( 'mysql' ),
		] );
	}

	public static function purge_bot_events( int $days ): int {
		global $wpdb;
		$t        = ES_Install::table( 'events' );
		$cutoff   = wp_date( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$form_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT form_id FROM $t" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted  = 0;
		foreach ( $form_ids as $form_id ) {
			for ( $i = 0; $i < 20; $i++ ) {
				$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE form_id = %d AND event_type IN ('bot_filtered', 'honeypot_blocked') AND created_at < %s LIMIT 5000", $form_id, $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted += $n;
				if ( $n < 5000 ) {
					break;
				}
			}
		}
		return $deleted;
	}
}
