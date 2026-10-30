<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Event_Recorder {

	public static function record( int $form_id, string $event_type, ?int $post_id = null, string $shell = 'inline', ?string $click_target = null, ?string $variant = null, ?int $lead_id = null, ?string $step = null ): bool {
		// Only a drop-off has a "where they left". Kept off every other row.
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
				// A step label is the author's own text about the asset, not
				// anything about the visitor, so strict mode keeps it too.
				if ( $step !== '' ) {
					$row['step'] = $step;
				}
				// No raw UA, posted visitor id or arbitrary click text in strict
				// mode. An explicitly authorized host may supply minimized values.
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
		if ( $step !== '' ) {
			$row['step'] = $step;
		}
		return $wpdb->insert( ES_Install::table( 'events' ), $row ) === 1;
	}

	/**
	 * A drop-off's step label (the author's data-es-step), cleaned the same way
	 * as the SaaS EventController and ES_Reports::step_markers, so a stored
	 * step matches its marker: control characters folded to a space, trimmed,
	 * capped at 64. Author text, only ever shown escaped.
	 */
	public static function clean_step( ?string $step ): string {
		if ( $step === null ) {
			return '';
		}
		$clean = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $step );
		return trim( mb_substr( trim( $clean ), 0, 64 ) );
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
	 * CF-Connecting-IP is preferred, but only on a request that came through
	 * Cloudflare (which overwrites it); X-Real-IP only from our own proxy. Shared by the
	 * AJAX rate-limiter, the stored bot IP, and the exclusion check.
	 *
	 * X-Forwarded-For is read from the RIGHT. Each proxy appends the address it
	 * saw, so the left end is whatever the client sent and can be forged. The
	 * first public hop from the right that is not REMOTE_ADDR itself is the
	 * address our nearest proxy actually received the request from.
	 */
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

		// CF-Connecting-IP only counts when the request really came through
		// Cloudflare: either Cloudflare is the peer, or our own proxy is the
		// peer and the last public hop before it is Cloudflare. Anyone can send
		// the header, and taking it on trust let a visitor pick their address,
		// dodging the rate limits and the excluded-address list.
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

		// X-Real-IP is a single address our own proxy sets, so it only counts
		// when that proxy (a private or loopback address) is the peer, and only
		// as a last resort: X-Forwarded-For above says the same thing when the
		// proxy sends both.
		if ( $peer_is_local && ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$candidate = trim( (string) $_SERVER['HTTP_X_REAL_IP'] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return preg_replace( '/[^0-9a-f:.]/i', '', $candidate );
			}
		}
		return preg_replace( '/[^0-9a-f:.]/i', '', (string) $ip );
	}

	/** A routable address: not private, loopback or reserved. */
	private static function is_public_ip( string $ip ): bool {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Cloudflare's published edge ranges (cloudflare.com/ips). They change
	 * rarely; a site can add to them with the filter.
	 *
	 * @return array<int, string>
	 */
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

	/** IPv4 or IPv6 membership test. False for a malformed address or range. */
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

	/**
	 * Walk an X-Forwarded-For list from the right and return the first hop
	 * that is a valid, public IP and is not the immediate peer. Private and
	 * reserved ranges are our own proxies, so they are skipped. '' if none.
	 */
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

	/**
	 * Option flag — when on (default), a "browser" hit whose real IP is in a
	 * known cloud datacenter range is treated as a bot. Few real visitors browse
	 * from an Azure/AWS/GCP/DigitalOcean VM (some VPNs and cloud desktops do),
	 * so this catches browser-spoofing crawlers that rotate IPs, which the UA
	 * filter can't. Ported from Bot Tracker.
	 */
	public const OPT_DETECT_DATACENTER = 'emblem_studio_detect_datacenter';

	public static function detect_datacenter_enabled(): bool {
		return get_option( self::OPT_DETECT_DATACENTER, '1' ) !== '0';
	}

	/**
	 * Provider names that map to the "Datacenter" category: the published
	 * lists plus the hand-kept ones in datacenter_ranges().
	 */
	public static function datacenter_providers(): array {
		return array_values( array_unique( array_merge( self::published_ranges()['providers'], array_keys( self::datacenter_ranges() ) ) ) );
	}

	/**
	 * provider => [ CIDR, … ] for providers that publish no list of their own,
	 * so these stay hand-maintained. Everything else comes from
	 * includes/data/datacenter-ranges.php (see published_ranges()).
	 *
	 * The filter is for site owners adding their own ranges. IPv4 and IPv6
	 * CIDRs both work.
	 */
	public static function datacenter_ranges(): array {
		$ranges = [
			'Hetzner' => [
				'5.9.0.0/16', '49.12.0.0/15', '65.108.0.0/15', '78.46.0.0/15', '88.99.0.0/16',
				'94.130.0.0/16', '95.216.0.0/15', '116.202.0.0/15', '136.243.0.0/16', '138.201.0.0/16',
				'144.76.0.0/16', '148.251.0.0/16', '159.69.0.0/16', '168.119.0.0/16', '176.9.0.0/16',
				'178.63.0.0/16', '188.40.0.0/16', '195.201.0.0/16',
				'2a01:4f8::/32', '2a01:4f9::/32',
			],
			'OVH' => [
				'5.135.0.0/16', '37.59.0.0/16', '37.187.0.0/16', '46.105.0.0/16', '51.68.0.0/14',
				'51.75.0.0/16', '51.83.0.0/16', '51.89.0.0/16', '51.91.0.0/16', '51.195.0.0/16',
				'54.36.0.0/16', '91.121.0.0/16', '92.222.0.0/16', '94.23.0.0/16', '137.74.0.0/16',
				'145.239.0.0/16', '147.135.0.0/16', '151.80.0.0/16', '158.69.0.0/16', '167.114.0.0/16',
				'178.32.0.0/15', '188.165.0.0/16',
				'2001:41d0::/32',
			],
		];
		return (array) apply_filters( 'emblem_studio_datacenter_ranges', $ranges );
	}

	/**
	 * The address lists AWS, Azure, Google, DigitalOcean, Linode and Oracle
	 * publish, packed by bin/update-datacenter-ranges.php (audit S8). The hand
	 * list this replaced leaned on whole /8 blocks and filed real visitors as
	 * cloud servers; these are exact, and they cover IPv6.
	 *
	 * @return array{providers: string[], v4: string, v6: string}
	 */
	public static function published_ranges(): array {
		static $data = null;
		if ( $data === null ) {
			$raw  = is_readable( __DIR__ . '/data/datacenter-ranges.php' ) ? (array) require __DIR__ . '/data/datacenter-ranges.php' : [];
			$data = [
				'providers' => (array) ( $raw['providers'] ?? [] ),
				'v4'        => (string) base64_decode( (string) ( $raw['v4'] ?? '' ) ),
				'v6'        => (string) base64_decode( (string) ( $raw['v6'] ?? '' ) ),
			];
		}
		return $data;
	}

	/** Provider name if the IP (v4 or v6) falls in a known datacenter range, else ''. */
	public static function datacenter_provider( string $ip ): string {
		$addr = $ip === '' ? false : @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $addr === false ) {
			return '';
		}
		$data  = self::published_ranges();
		$width = strlen( $addr );
		$table = $width === 4 ? $data['v4'] : $data['v6'];
		$size  = $width * 2 + 1;
		// Binary search for the last record starting at or before the address.
		$lo = 0;
		$hi = intdiv( strlen( $table ), $size ) - 1;
		$at = -1;
		while ( $lo <= $hi ) {
			$mid = ( $lo + $hi ) >> 1;
			if ( strcmp( substr( $table, $mid * $size, $width ), $addr ) <= 0 ) {
				$at = $mid;
				$lo = $mid + 1;
			} else {
				$hi = $mid - 1;
			}
		}
		if ( $at >= 0 && strcmp( $addr, substr( $table, $at * $size + $width, $width ) ) <= 0 ) {
			return (string) ( $data['providers'][ ord( $table[ $at * $size + $width * 2 ] ) ] ?? '' );
		}
		foreach ( self::datacenter_ranges() as $provider => $cidrs ) {
			foreach ( (array) $cidrs as $cidr ) {
				if ( self::ip_in_cidr( trim( $ip ), (string) $cidr ) ) {
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

	/**
	 * Words in real browsers' user-agents that contain a bot needle. Cubot is
	 * a phone brand, and "CUBOT X30" matched the generic `bot` (audit S8).
	 * Each is removed from the UA before matching, so a phone named for one
	 * is still caught if it ALSO names a real crawler.
	 */
	public static function human_ua_words(): array {
		return (array) apply_filters( 'emblem_studio_human_user_agent_words', [ 'Cubot' ] );
	}

	/** The UA with every human_ua_words() entry taken out, ready for matching. */
	private static function ua_for_matching( string $ua ): string {
		foreach ( self::human_ua_words() as $word ) {
			if ( $word !== '' ) {
				$ua = str_ireplace( (string) $word, ' ', $ua );
			}
		}
		return $ua;
	}

	public static function is_bot_request(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( $ua === '' ) {
			// Empty UA is itself a strong bot signal — almost no real browser sends none.
			return true;
		}
		$ua = self::ua_for_matching( $ua );
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
		$ua = self::ua_for_matching( $ua );
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

	/**
	 * Delete bot rows (filtered views and clicks, blocked submissions) older
	 * than $days. They only feed the bot breakdown, and without this they are
	 * the one part of the events table that grows with attack traffic rather
	 * than with real visitors.
	 *
	 * One asset at a time, so each delete runs on the (form_id, event_type,
	 * created_at) index instead of scanning the whole table, and in chunks so a
	 * big backlog doesn't hold one long lock on a live site.
	 */
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
