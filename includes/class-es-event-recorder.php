<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Event_Recorder {

	public static function record( int $form_id, string $event_type, ?int $post_id = null, string $shell = 'inline', ?string $click_target = null, ?string $variant = null ): void {
		global $wpdb;
		$row = [
			'form_id'    => $form_id,
			'event_type' => $event_type,
			'type'       => $shell,
			'post_id'    => $post_id,
			'created_at' => current_time( 'mysql' ),
		];
		// Only persist a click_target on click events and on honeypot_blocked,
		// where it carries the matched bot label — the same column, and the same
		// labels, a filtered VIEW already uses. Every other row keeps it NULL, so
		// it stays an unambiguous signal in queries.
		if ( in_array( $event_type, [ 'click', 'honeypot_blocked' ], true ) && $click_target !== null && $click_target !== '' ) {
			$row['click_target'] = mb_substr( $click_target, 0, 191 );
		}
		// Variant tagging — only persist 'A' / 'B'. Anything else (including
		// bot events that never went through A/B assignment) stays NULL so
		// per-variant rollups don't get polluted.
		if ( $variant !== null ) {
			$v = strtoupper( $variant );
			if ( in_array( $v, [ 'A', 'B' ], true ) ) {
				$row['variant'] = $v;
			}
		}
		// Stamp the user-agent so visits can be split into coarse device buckets
		// (mobile / tablet / desktop) in reports. UA only — no IP is stored on a
		// real-visitor row (privacy stance: see anonymize_ip / the bot track).
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( $ua !== '' ) {
			$row['ua'] = mb_substr( $ua, 0, 255 );
		}
		// Anonymous per-visitor id (a random first-party cookie value sent by the
		// frontend) so reports can count unique visitors + new-vs-returning. No
		// PII, no IP. Absent => the visit rolls up as "Unknown".
		$vid = isset( $_POST['vid'] ) ? preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $_POST['vid'] ) : '';
		if ( $vid !== '' ) {
			$row['visitor'] = mb_substr( $vid, 0, 40 );
		}
		$wpdb->insert( ES_Install::table( 'events' ), $row );
	}

	/**
	 * Always on — there is no toggle. Known bots are always counted separately
	 * (under `bot_filtered`) so they never pollute real visitor metrics. Kept as
	 * a method (rather than inlining `true` at every call site) so the bot-gate
	 * intent stays readable and a future policy change has one home.
	 */
	public static function filter_bots_enabled(): bool {
		return true;
	}

	/**
	 * Option flag — when on, the recorder resolves the real client IP from
	 * proxy headers (CF-Connecting-IP / X-Real-IP / X-Forwarded-For) instead of
	 * trusting REMOTE_ADDR. Needed behind Cloudflare / a reverse proxy, where
	 * REMOTE_ADDR is the edge IP and every visitor would otherwise look the same.
	 */
	public const OPT_TRUST_PROXY = 'emblem_studio_trust_proxy';

	/**
	 * Default-off, so directly-exposed sites can't be spoofed via a forged
	 * header. The legacy `EMBLEM_STUDIO_TRUST_PROXY` wp-config constant still
	 * force-enables it for setups that prefer config over a UI toggle.
	 */
	public static function trust_proxy_enabled(): bool {
		if ( defined( 'EMBLEM_STUDIO_TRUST_PROXY' ) && EMBLEM_STUDIO_TRUST_PROXY ) {
			return true;
		}
		return get_option( self::OPT_TRUST_PROXY, '0' ) === '1';
	}

	/**
	 * Option holding IPs to drop from stats entirely — e.g. the site's own
	 * uptime / synthetic monitors, which run headless browsers and would
	 * otherwise inflate page-view / impression counts with a real-browser UA
	 * the bot filter can't catch. Stored as an array of exact IP strings.
	 */
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

	/**
	 * The real client IP. Behind a CDN/proxy, REMOTE_ADDR is the edge, so the
	 * true visitor only appears in a forwarded header — honored only when
	 * trust_proxy is on (else a directly-exposed site could be spoofed).
	 * CF-Connecting-IP is preferred (Cloudflare overwrites it). Shared by the
	 * AJAX rate-limiter, the stored bot IP, and the exclusion check.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		if ( self::trust_proxy_enabled() ) {
			foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ] as $h ) {
				if ( empty( $_SERVER[ $h ] ) ) {
					continue;
				}
				$candidate = trim( explode( ',', (string) $_SERVER[ $h ] )[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}
		return preg_replace( '/[^0-9a-f:.]/i', '', (string) $ip );
	}

	/**
	 * Option flag — when on (default), a "browser" hit whose real IP is in a
	 * known cloud datacenter range is treated as a bot. No real visitor browses
	 * from an Azure/AWS/GCP/DigitalOcean VM, so this catches browser-spoofing
	 * crawlers that rotate IPs, which the UA filter can't. Ported from Bot Tracker.
	 */
	public const OPT_DETECT_DATACENTER = 'emblem_studio_detect_datacenter';

	public static function detect_datacenter_enabled(): bool {
		return get_option( self::OPT_DETECT_DATACENTER, '1' ) !== '0';
	}

	/** Provider names that map to the "Datacenter" category. */
	public static function datacenter_providers(): array {
		return array_keys( self::datacenter_ranges() );
	}

	/** provider => [ CIDR, … ]. Specific providers before broad /8s (first match wins). */
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

	/** Provider name if the IPv4 falls in a known datacenter range, else ''. */
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

	/**
	 * Master bot catalog. Each entry is [ needle, label, category ]:
	 *   - `needle`   — matched case-insensitively as a substring of the UA.
	 *   - `label`    — what we persist (in click_target) and show in the
	 *                  breakdown report. Several needles may share a label
	 *                  so close variants group into one row.
	 *   - `category` — groups labels in the report's foldable tree.
	 *
	 * ORDER MATTERS. The four generic substrings (`bot`/`crawl`/`spider`/
	 * `headless`) sit LAST so that a self-identifying crawler like
	 * "Googlebot" attributes to "Googlebot" rather than collapsing into the
	 * catch-all "Generic bot". matched_bot_label() walks this list top-down.
	 */
	public static function bot_catalog(): array {
		return [
			// Automation / headless tooling.
			[ 'PhantomJS', 'PhantomJS', 'Automation' ],
			[ 'Selenium', 'Selenium', 'Automation' ],
			[ 'Puppeteer', 'Puppeteer', 'Automation' ],
			[ 'Playwright', 'Playwright', 'Automation' ],
			[ 'Cypress', 'Cypress', 'Automation' ],
			[ 'Chrome-Lighthouse', 'Lighthouse', 'Automation' ],
			[ 'Lighthouse', 'Lighthouse', 'Automation' ],
			[ 'PageSpeed', 'PageSpeed', 'Automation' ],

			// HTTP libraries and scripted clients.
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

			// AI scrapers / LLM trainers.
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

			// Search engine crawlers.
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

			// SEO / marketing crawlers.
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

			// Archive / academic.
			[ 'archive.org_bot', 'archive.org', 'Archive' ],
			[ 'ia_archiver', 'Internet Archive', 'Archive' ],
			[ 'Wayback', 'Wayback Machine', 'Archive' ],

			// Social link previewers — they fetch pages to render unfurls.
			[ 'facebookexternalhit', 'Facebook (link preview)', 'Social previewers' ],
			[ 'Twitterbot', 'Twitterbot', 'Social previewers' ],
			[ 'LinkedInBot', 'LinkedInBot', 'Social previewers' ],
			[ 'Slackbot-LinkExpanding', 'Slack (link preview)', 'Social previewers' ],
			[ 'WhatsApp', 'WhatsApp', 'Social previewers' ],
			[ 'Discordbot', 'Discordbot', 'Social previewers' ],
			[ 'TelegramBot', 'TelegramBot', 'Social previewers' ],
			[ 'SkypeUriPreview', 'Skype (link preview)', 'Social previewers' ],

			// Generic signatures — MUST stay last (see method doc).
			[ 'headless', 'Headless browser', 'Generic' ],
			[ 'crawl', 'Generic crawler', 'Generic' ],
			[ 'spider', 'Generic spider', 'Generic' ],
			[ 'bot', 'Generic bot', 'Generic' ],
		];
	}

	/**
	 * Display order for the breakdown report's category groups. Anything
	 * the catalog doesn't classify (custom filter needles) falls under
	 * "Other"; '(no user-agent)' rolls up under "Generic".
	 */
	public static function bot_category_order(): array {
		return [
			'Generic', 'Datacenter', 'Automation', 'HTTP libraries', 'AI scrapers',
			'Search engines', 'SEO & marketing', 'Archive',
			'Social previewers', 'Other',
		];
	}

	/**
	 * Flat list of substring needles for the is_bot_request() gate. Derived
	 * from the catalog and still filterable so admins can extend it:
	 *   add_filter( 'emblem_studio_bot_user_agents', function ( $list ) {
	 *       $list[] = 'MyCustomCrawler';
	 *       return $list;
	 *   } );
	 * Custom needles aren't in the catalog, so matched_bot_label() attributes
	 * them to their own label under the "Other" category.
	 */
	public static function bot_patterns(): array {
		$patterns = array_map( static fn( $e ) => $e[0], self::bot_catalog() );
		return (array) apply_filters( 'emblem_studio_bot_user_agents', $patterns );
	}

	public static function is_bot_request(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( $ua === '' ) {
			// Empty UA is itself a strong bot signal — almost no real browser sends none.
			return true;
		}
		foreach ( self::bot_patterns() as $needle ) {
			if ( $needle !== '' && stripos( $ua, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which bot a UA attributes to — the label we persist for the breakdown.
	 * Walks the catalog (specific-before-generic), then any custom filter
	 * needles. Empty UA → '(no user-agent)'. Returns '' when nothing matches
	 * (in which case the caller should not record a bot row).
	 */
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
		// Custom needles added via the filter aren't catalogued — attribute
		// them to the needle itself so they still show up in the breakdown.
		foreach ( self::bot_patterns() as $needle ) {
			if ( $needle !== '' && stripos( $ua, $needle ) !== false ) {
				return $needle;
			}
		}
		return '';
	}

	/**
	 * Map a persisted bot label back to its display category. '(no
	 * user-agent)' rolls up under "Generic"; unknown labels (custom
	 * needles) fall under "Other".
	 */
	public static function category_for_label( string $label ): string {
		if ( $label === '(no user-agent)' ) {
			return 'Generic';
		}
		// Cloud-provider labels (recorded by datacenter detection) → Datacenter.
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

	/**
	 * GDPR-friendly IP truncation: zero the last octet of an IPv4 address
	 * and the last 80 bits (everything past the /48) of an IPv6 address.
	 * Returns '' for anything that isn't a valid IP.
	 */
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
				// Keep the first 48 bits (3 hextets / 6 bytes); zero the rest.
				$masked = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
				$out    = @inet_ntop( $masked );
				if ( $out !== false ) {
					return $out;
				}
			}
		}
		return '';
	}

	/**
	 * Record a filtered bot hit. Distinct from record() because bot rows
	 * carry detail no real-visitor event does: the matched label (parked in
	 * click_target — unused on bot rows otherwise), the raw UA, and an
	 * already-anonymized IP. post_id captures which page the bot hit.
	 */
	public static function record_bot( int $form_id, string $shell, ?int $post_id, string $label, string $ua, string $ip ): void {
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
}
