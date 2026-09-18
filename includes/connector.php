<?php
/**
 * Plugin Name: Emblem Studio SaaS Connector
 * Description: Bridge between a WordPress site and the Emblem Studio SaaS. Powers the Lite plugin's "Connect a free account" flow (pairing + embedded dashboard), injects the page's WordPress context so SaaS display rules enforce on this site, places the site's auto-shell forms, and relays leads to FunnelKit locally. The free standalone Emblem Studio plugin does NOT need this — it's bundled into the Lite edition only.
 * Version: 1.3.25-beta
 * Requires PHP: 7.4
 *
 * CONNECTION CONFIG — two sources, constants win (so an existing manual setup is
 * untouched), else the stored option written by the in-WP Connect button:
 *   1. wp-config.php constants (manual / legacy):
 *        define('ESS_API_BASE',  'https://app.example.com');  // no trailing slash
 *        define('ESS_SITE_ID',   2);
 *        define('ESS_SITE_TOKEN','xxxxxxxxxxxxxxxxxxxx');
 *   2. Option `ess_connection` — set by Emblem Studio → Connect (one-click pairing).
 *
 * WHERE THIS FILE LIVES: right here, in the PLUGIN repo, at connector/. There is no
 * copy in the SaaS repo and no cross-repo path — an older header here claimed it was
 * maintained in the SaaS repo under wp-connector/, which was never true. It is
 * export-ignored from the full plugin release and folded into the Lite build as
 * includes/connector.php by bin/build-lite.sh.
 *
 * VERSION: tracks the PLUGIN version — bump both together. (It sat at 0.8.3 through
 * four connector-touching releases, which made it useless for support and debugging.)
 * Once folded into Lite this header is informational; Lite's real version comes from
 * the plugin header.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Emblem_Studio_SaaS_Connector
{
    /**
     * Reported to the SaaS on every embed heartbeat, so it can tell which
     * connector build a site is running (SER-1128 stage 4 gates on it).
     * MUST track the `Version:` header above — bump both together.
     */
    const VERSION = '1.3.25-beta';

    const FORMS_TRANSIENT = 'ess_connector_forms';
    /** Stored connection written by the Connect button. */
    const OPTION = 'ess_connection';

    /**
     * Default SaaS base used to prefill the Connect screen when nothing is set.
     * This is what every shipped copy connects to, so it must stay production.
     * For local development, define ESS_API_BASE in wp-config.php instead — the
     * constant wins over this, and on its own only changes the prefill (a manual
     * connection needs ESS_SITE_ID and ESS_SITE_TOKEN too).
     */
    const DEFAULT_API_BASE = 'https://emblemstudio.ai';

    /** Post-decouple background catch-up (SER-950) — see run_postdecouple_pull(). */
    const POSTDECOUPLE_OPTION = 'es_postdecouple_cfg';
    const POSTDECOUPLE_STATE = 'es_postdecouple_state';
    const POSTDECOUPLE_HOOK = 'es_saas_postdecouple_pull';
    const POSTDECOUPLE_MAX_ATTEMPTS = 40; // ~13 min of real effort before giving up and revoking anyway

    /** WP admin page slug → embedded SaaS path (under /app/s/<code>/…). */
    const PAGE_MAP = [
        'emblem-studio'              => 'assets',     // Forms & Assets
        'emblem-studio-new'          => 'assets/new', // Add New
        // The Schedule board (SER-1328). SaaS-only: it reads every asset's
        // periods and queued changes from the account, which a standalone
        // install does not have. Must be mapped, because maybe_embed() on an
        // unmapped slug quietly embeds the Assets page instead.
        'emblem-studio-schedule'     => 'schedule',
        'emblem-studio-tests'        => 'tests',      // A/B Tests
        'emblem-studio-leads'        => 'leads',
        'emblem-studio-reports'      => 'reports',
        'emblem-studio-integrations' => 'integrations',
        'emblem-studio-design-system' => 'design-system',
        'emblem-studio-settings'     => 'settings',
        // Support (SER-1049) — site-scoped like everything else here. A ticket
        // filed from wp-admin is always about THIS site; there's no "general
        // account" concept reachable from wp-admin to begin with.
        'emblem-studio-tickets'      => 'tickets',
    ];

    public static function init(): void
    {
        // Front-end: inject context + place form slots + load embed.js (no-ops
        // until the site is connected).
        add_action('wp_footer', [__CLASS__, 'render'], 99);
        // Inbound lead relay (token-authed) → local FunnelKit.
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Connect flow. Login-gated (P6): the framed SaaS login hands back a grant
        // over postMessage; this AJAX handler spends it server-side to pair the site
        // with the signed-in account. (admin_post_es_connect is the DEPRECATED
        // account-less one-click path, kept only for backward compatibility.)
        add_action('admin_post_es_connect', [__CLASS__, 'handle_connect']);
        add_action('wp_ajax_es_connect_complete', [__CLASS__, 'handle_connect_complete']);
        add_action('admin_post_es_decouple', [__CLASS__, 'handle_decouple']);
        add_action('admin_notices', [__CLASS__, 'connect_notice']);
        // Periodic copy-down: refresh WP's local copy of assets/stats/leads.
        add_action('es_saas_pull', [__CLASS__, 'cron_pull']);
        // Background import (mirror up + copy down) kicked off at connect, plus the
        // status endpoint the dashboard's loading screen polls.
        add_action('es_saas_import', [__CLASS__, 'run_import']);
        add_action('wp_ajax_es_import_status', [__CLASS__, 'ajax_import_status']);
        // Post-decouple background catch-up (SER-950) — runs after the site has
        // already reverted to Lite, so it never blocks or crashes the admin.
        add_action(self::POSTDECOUPLE_HOOK, [__CLASS__, 'run_postdecouple_pull']);
        // Tell the SaaS the moment WordPress's timezone changes. Both options,
        // because a site either names a zone ("America/New_York") or sets a
        // manual offset, and WordPress clears one when you set the other.
        add_action('update_option_timezone_string', [__CLASS__, 'push_timezone']);
        add_action('update_option_gmt_offset', [__CLASS__, 'push_timezone']);
        // Live values (SER-1351): ES_Live_Values measures, the connector supplies
        // the SaaS's watch list and sends what changed.
        add_filter('es_live_values_watch_list', [__CLASS__, 'live_values_watch_list']);
        add_action('es_live_values_measured', [__CLASS__, 'push_live_values'], 10, 2);
        // Purchases after an asset brought somebody in (SER-1346). Sent the
        // moment the shop records one, and again on every periodic pull, so a
        // site that was unreachable catches up without anybody noticing.
        add_action('es_purchases_ready', [__CLASS__, 'push_purchases']);
    }

    /* ── Live values (SER-1351) ─────────────────────────────────────────── */

    /**
     * Which prices and coupons this site's assets show, from Emblem Studio.
     *
     * Connected, the assets live in the SaaS, variants and queued changes
     * included, so the SaaS is the one that knows. The SaaS parses the HTML;
     * WordPress never has to. When the SaaS cannot be reached this passes the
     * value through untouched, and ES_Live_Values falls back to the local copy
     * of the assets, which pull_assets() keeps.
     *
     * @param mixed $current
     * @return mixed
     */
    public static function live_values_watch_list($current)
    {
        $cfg = self::config();
        if (! $cfg) {
            return $current;
        }
        $data = self::pull_post($cfg, '/v1/embed/live-values/watch');
        if (! is_array($data) || empty($data['ok']) || ! isset($data['keys']) || ! is_array($data['keys'])) {
            return $current;
        }

        $keys = [];
        foreach ($data['keys'] as $row) {
            if (! is_array($row) || empty($row['key']) || empty($row['product_id'])) {
                continue;
            }
            $keys[(string) $row['key']] = [
                'key'        => (string) $row['key'],
                'product_id' => (int) $row['product_id'],
                'coupon'     => isset($row['coupon']) && $row['coupon'] !== '' ? (string) $row['coupon'] : null,
            ];
        }

        return $keys;
    }

    /**
     * Send the readings that changed, and the keys that were measured again and
     * found the same. The SaaS answers with any unchanged key it does not hold
     * (a fresh install, a lost row), and those are sent again in full at once
     * rather than waiting for them to change.
     *
     * @param array<int, array<string, mixed>> $changed
     * @param array<int, string>               $unchanged
     */
    public static function push_live_values($changed, $unchanged): void
    {
        $cfg = self::config();
        $changed = is_array($changed) ? array_values($changed) : [];
        $unchanged = is_array($unchanged) ? array_values($unchanged) : [];
        if (! $cfg || ($changed === [] && $unchanged === [])) {
            return;
        }

        foreach (array_chunk($changed, 100) ?: [[]] as $i => $chunk) {
            $data = self::pull_post($cfg, '/v1/embed/live-values', [
                'values'    => $chunk,
                'unchanged' => $i === 0 ? $unchanged : [],
            ]);
            if ($i !== 0 || ! is_array($data) || empty($data['missing']) || ! is_array($data['missing'])) {
                continue;
            }

            $stored = class_exists('ES_Live_Values') ? ES_Live_Values::stored() : [];
            $resend = [];
            foreach ($data['missing'] as $key) {
                if (is_string($key) && isset($stored[$key])) {
                    $resend[] = [
                        'key'        => $key,
                        'product_id' => (int) $stored[$key]['product_id'],
                        'coupon'     => $stored[$key]['coupon'],
                        'answer'     => $stored[$key]['answer'],
                    ];
                }
            }
            if ($resend !== []) {
                self::pull_post($cfg, '/v1/embed/live-values', ['values' => $resend, 'unchanged' => []]);
            }
        }
    }

    /* ── Purchases after an asset (SER-1346) ───────────────────────────── */

    /**
     * Send what the shop said, and nothing else.
     *
     * The buyer was matched to their sign-up HERE, by ES_Purchases, using the address
     * WooCommerce already holds. What travels is the lead's id, the order's id and
     * numbers. No email address, no name, no address of any kind.
     *
     * A row is only marked as sent once the SaaS has taken it, so a site that was
     * offline sends it again later rather than losing it. A refund rewrites the row
     * and marks it unsent, which is how money coming back out reaches the numbers.
     */
    public static function push_purchases(): void
    {
        $cfg = self::config();
        if (! $cfg || ! class_exists('ES_Purchases')) {
            return;
        }

        // Somebody asked to be erased and their row has already gone from this site.
        // Emblem Studio is told first, so an interrupted run leaves the erasure
        // outstanding rather than quietly finished.
        $forget = ES_Purchases::forgotten();
        if ($forget !== []) {
            foreach (array_chunk($forget, 200) as $chunk) {
                $data = self::pull_post($cfg, '/v1/embed/purchases', ['forget' => array_values($chunk)]);
                if (! is_array($data) || empty($data['ok'])) {
                    return;
                }
                ES_Purchases::clear_forgotten($chunk);
            }
        }

        // Ten passes of 200 is a deliberate ceiling. A site coming back after a long
        // silence catches up over a few runs instead of holding one request open.
        for ($pass = 0; $pass < 10; $pass++) {
            $rows = ES_Purchases::pending(200);
            if ($rows === []) {
                return;
            }

            $ids = array_column($rows, 'id');
            foreach ($rows as $i => $row) {
                unset($rows[$i]['id']);
            }

            $data = self::pull_post($cfg, '/v1/embed/purchases', ['purchases' => array_values($rows)]);
            if (! is_array($data) || empty($data['ok'])) {
                return; // Leave them unsent; the next run tries again.
            }

            ES_Purchases::mark_sent($ids);
            if (count($rows) < 200) {
                return;
            }
        }
    }

    /* ── Connection state ──────────────────────────────────────────────── */

    /** The stored connection array, or [] if none. */
    private static function stored(): array
    {
        $o = get_option(self::OPTION);

        return is_array($o) ? $o : [];
    }

    /**
     * Unified connection config, or null when not connected. wp-config constants
     * win (manual/legacy), else the stored option from the Connect button.
     *
     * @return array{api_base:string,site_id:int,site_token:string,code:?string}|null
     */
    public static function config(): ?array
    {
        if (defined('ESS_API_BASE') && defined('ESS_SITE_ID') && defined('ESS_SITE_TOKEN')) {
            $s = self::stored();

            return [
                'api_base'   => rtrim((string) ESS_API_BASE, '/'),
                'site_id'    => (int) ESS_SITE_ID,
                'site_token' => (string) ESS_SITE_TOKEN,
                'code'       => isset($s['code']) ? (string) $s['code'] : null,
            ];
        }

        $s = self::stored();
        if (! empty($s['api_base']) && ! empty($s['site_id']) && ! empty($s['site_token'])) {
            return [
                'api_base'   => rtrim((string) $s['api_base'], '/'),
                'site_id'    => (int) $s['site_id'],
                'site_token' => (string) $s['site_token'],
                'code'       => isset($s['code']) ? (string) $s['code'] : null,
            ];
        }

        return null;
    }

    public static function is_connected(): bool
    {
        return self::config() !== null;
    }

    /**
     * The product name shown in the WP admin sidebar, by edition/tier:
     *   • not connected → "Emblem Studio Lite" (the stripped standalone)
     *   • connected, free account → "Emblem Studio Beta" (pre-sale; becomes "Free"
     *     once we actually start selling)
     *   • connected, paid account → "Emblem Studio Pro"
     * The account plan isn't enforced yet (P6); until the pairing response carries
     * a plan, a connected site reads as Beta. Stored as `plan` on the connection
     * so this flips to Pro automatically once tiers land.
     */
    public static function edition_label(): string
    {
        if (! self::is_connected()) {
            return __('Emblem Studio Lite', 'emblem-studio');
        }
        $s    = self::stored();
        $plan = isset($s['plan']) ? strtolower((string) $s['plan']) : '';

        return in_array($plan, ['paid', 'pro', 'agency'], true)
            ? __('Emblem Studio Pro', 'emblem-studio')
            : __('Emblem Studio Beta', 'emblem-studio');
    }

    /** How the connection is configured: 'constants' (wp-config) or 'option', or null. */
    private static function connected_via(): ?string
    {
        if (defined('ESS_API_BASE') && defined('ESS_SITE_ID') && defined('ESS_SITE_TOKEN')) {
            return 'constants';
        }

        return self::is_connected() ? 'option' : null;
    }

    /* ── Admin: embedded SaaS pages + the three states ─────────────────── */

    /**
     * Called by ES_Admin's route_* methods. Returns true if it produced the
     * page's output (so the caller skips the built-in Lite view):
     *   • not connected → false  (the Lite screen shows; user can hit Connect)
     *   • connected + sign-in OK → embed the matching SaaS page
     *   • connected + sign-in FAILS → the "You've been disconnected" screen
     *     (never silently falls back to the Lite pages).
     */
    public static function maybe_render_page(string $slug): bool
    {
        $cfg = self::config();
        if (! $cfg) {
            return false; // not connected — let the Lite view render.
        }

        $session = self::mint_session($cfg);
        if (! $session) {
            self::render_disconnected();

            return true;
        }

        $path = self::PAGE_MAP[$slug] ?? 'assets';

        // Deep-link into a specific asset from the native ?action=edit&id=N (the
        // same URL the built-in editor uses — parity, so a shared link opens the
        // same place whether the site runs the plugin or the embedded dashboard).
        // A GET navigation, not a mutation, so no nonce. The tab lives in the URL
        // #hash, which the server can't see — render_embed reads it client-side.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ($slug === 'emblem-studio' && ($_GET['action'] ?? '') === 'edit' && ! empty($_GET['id'])) {
            $path = 'assets/'.(int) $_GET['id'];
        }
        $editor = ($_GET['es-inspect'] ?? '') === 'open';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $code = $session['code'] ?: ($cfg['code'] ?: '');
        self::render_embed($cfg['api_base'], $code, $path, (string) $session['embed_token'], $editor);

        return true;
    }

    /** The Connect / Account screen (the dedicated submenu). */
    public static function render_account_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        // `es_reconnect` forces the sign-in screen even while a stale connection is
        // still stored (used by the "Reconnect" button on the disconnected screen).
        $reconnect = ! empty($_GET['es_reconnect']); // phpcs:ignore WordPress.Security.NonceVerification
        if (self::is_connected() && ! $reconnect) {
            self::render_connected_panel();
        } else {
            self::render_connect_panel();
        }
    }

    /** Mint a short-lived embed sign-in token for the current WP user. */
    private static function mint_session(array $cfg): ?array
    {
        $u    = wp_get_current_user();
        $res  = wp_remote_post($cfg['api_base'].'/v1/embed/session', [
            'timeout' => 8,
            'body'    => [
                'site_id'    => $cfg['site_id'],
                'token'      => $cfg['site_token'],
                'wp_user_id' => $u ? (int) $u->ID : 0,
                'wp_login'   => $u ? (string) $u->user_login : '',
                'wp_name'    => $u ? (string) $u->display_name : '',
                'wp_roles'   => $u ? array_values((array) $u->roles) : [],
            ],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (! is_array($body) || empty($body['embed_token'])) {
            return null;
        }

        return [
            'embed_token' => (string) $body['embed_token'],
            'code'        => isset($body['site']['code']) ? (string) $body['site']['code'] : '',
        ];
    }

    /** Full-bleed iframe of the embedded SaaS page, chrome-less + auto sign-in. */
    private static function render_embed(string $api, string $code, string $path, string $token, bool $editor = false): void
    {
        // The SaaS origin we accept fullscreen messages from.
        $p = wp_parse_url($api);
        $origin = (isset($p['scheme']) ? $p['scheme'] : 'https').'://'.(isset($p['host']) ? $p['host'] : '')
            .(isset($p['port']) ? ':'.$p['port'] : '');

        // The iframe src is assembled in JS, not here, for one reason: the active
        // editor TAB lives in the WP page's URL #hash (e.g. …&action=edit&id=7#stats),
        // and a #fragment is never sent to the server — PHP can't see it. The inline
        // builder reads location.hash, so a shared tab-deep-link actually opens that
        // tab. The pieces PHP knows (base path, editor) are handed over as data; the
        // sign-in token rides the src fragment (#est=…), never logged.
        $embed_base = $api.'/app/s/'.rawurlencode($code).'/'.$path;
        // Let WordPress position the content column (it already sits right of the
        // admin menu and auto-adjusts for folded/responsive states). We just strip
        // the column's padding so the iframe is flush, and fill the height. This
        // avoids hardcoding the menu width (which slid the page under the sidebar).
        ?>
        <style>
            #wpcontent { padding-left: 0 !important; }
            #wpbody-content { padding-bottom: 0 !important; }
            #wpfooter { display: none; }
            html.wp-toolbar { background: #fff; }
            /* min-height, not height: a fixed viewport height clipped the dashboard
               into its OWN inner scrollbar whenever its content ran taller than one
               screen — on top of the WP admin page's own outer scrollbar once the
               sidebar menu itself is taller than the viewport. Two independent,
               confusing scroll containers. The SaaS reports its real content height
               (see the 'resize' message below) and JS sets that as the actual
               height, inline — min-height here is only the floor before the first
               report arrives, and for short pages that don't need more than a screen. */
            .es-embed-frame { display: block; width: 100%; min-height: calc(100vh - 32px); border: 0; margin: 0; }
            @media screen and (max-width: 782px) { .es-embed-frame { min-height: calc(100vh - 46px); } }
            /* Fullscreen editing — cover the WP toolbar + admin menu (admin bar is
               z-index 99999, so go above it). Toggled by the SaaS via postMessage. */
            .es-embed-frame.es-embed-fullscreen { position: fixed; inset: 0; height: 100vh; z-index: 100000; }
            /* Connect-time import loading screen (covers the dashboard until done). */
            .es-import-overlay { position: fixed; inset: 0; z-index: 100001; display: flex; align-items: center; justify-content: center; text-align: center; background: rgba(255,255,255,.95); transition: opacity .35s ease; }
            .es-import-overlay.es-hide { opacity: 0; pointer-events: none; }
            .es-import-card { max-width: 380px; padding: 0 24px; }
            .es-import-card h2 { font-size: 20px; margin: 18px 0 6px; color: #1e293b; }
            .es-import-card p { color: #50575e; font-size: 13px; margin: 0; line-height: 1.5; }
            .es-import-spinner { width: 42px; height: 42px; margin: 0 auto; border-radius: 50%; border: 3px solid #e2e8f0; border-top-color: #e27d40; animation: es-import-spin .8s linear infinite; }
            @keyframes es-import-spin { to { transform: rotate(360deg); } }
        </style>
        <iframe id="es-embed-frame" class="es-embed-frame" title="Emblem Studio" allow="clipboard-write"></iframe>
        <script>
        (function () {
            var ORIGIN   = <?php echo wp_json_encode($origin); ?>;
            var BASE     = <?php echo wp_json_encode($embed_base); ?>;
            var TOKEN    = <?php echo wp_json_encode($token); ?>;
            var EDITOR   = <?php echo $editor ? 'true' : 'false'; ?>;
            // This page's own admin.php. Handed to the dashboard so a link it
            // opens in a NEW TAB can point at the WordPress page for that thing
            // rather than at the bare dashboard. The frame cannot read it: a
            // different origin, and the referrer is cut down to the origin.
            var WP_ADMIN = <?php echo wp_json_encode(admin_url('admin.php')); ?>;
            var frame    = document.getElementById('es-embed-frame');

            // The SaaS asset-editor tabs. The tab travels as ?tab= (not a fragment)
            // because the iframe's own #fragment already carries the sign-in token.
            var TABS = ['design', 'shells', 'stats', 'connect', 'revisions', 'settings'];

            // SaaS view -> WP admin page slug. The inverse of the connector's
            // PAGE_MAP; used to rewrite the WP address bar as the SPA navigates.
            var VIEW_SLUG = {
                'assets': 'emblem-studio',
                'assets-new': 'emblem-studio-new',
                'schedule': 'emblem-studio-schedule',
                'tests': 'emblem-studio-tests',
                'leads': 'emblem-studio-leads',
                'reports': 'emblem-studio-reports',
                'integrations': 'emblem-studio-integrations',
                'design-system': 'emblem-studio-design-system',
                'settings': 'emblem-studio-settings',
                'tickets': 'emblem-studio-tickets'
            };

            // Build the src from what PHP knew (base path, editor) plus the tab read
            // from THIS page's #hash, then load the iframe once.
            (function boot() {
                var hash = (window.location.hash || '').replace(/^#/, '');
                var tab  = TABS.indexOf(hash) !== -1 ? hash : '';
                var q = '?embed=1';
                if (EDITOR) { q += '&editor=open'; }
                if (tab)    { q += '&tab=' + encodeURIComponent(tab); }
                q += '&wp_admin=' + encodeURIComponent(WP_ADMIN);
                frame.src = BASE + q + '#est=' + encodeURIComponent(TOKEN);
            })();

            // Browser-tab title parity with the native edit screen's own
            // filter_admin_title() ("ES <id> - <name> ‹ Site — WordPress"). The
            // native version re-runs server-side on every page load because
            // ?action=edit&id= is a real navigation; here the SPA never reloads
            // the WP page, so nothing re-fires that PHP filter — this is the
            // client-side equivalent, keyed off the same postMessage route data.
            var baseTitle = document.title;
            var titleSuffixIdx = baseTitle.indexOf(' ‹ '); // ' ‹ '
            var titleSuffix = titleSuffixIdx !== -1 ? baseTitle.slice(titleSuffixIdx) : '';
            function syncWpTitle(d) {
                if (d.view === 'assets' && d.assetId && d.assetName) {
                    document.title = 'ES ' + d.assetId + ' - ' + d.assetName + titleSuffix;
                } else {
                    document.title = baseTitle;
                }
            }

            // Rewrite the WP address bar to a native-looking URL as the SPA moves,
            // so refresh / bookmark / share / Back all land on the same place.
            function syncWpUrl(d) {
                syncWpTitle(d);
                var slug = VIEW_SLUG[d.view];
                if (!slug) {
                    // SaaS-only view (team/account/…): no native page to name; leave
                    // the address bar as-is rather than fake one.
                    return;
                }
                var url = window.location.pathname + '?page=' + slug;
                if (d.assetId) { url += '&action=edit&id=' + encodeURIComponent(d.assetId); }
                if (d.editor)  { url += '&es-inspect=open'; }
                if (d.tab && TABS.indexOf(d.tab) !== -1) { url += '#' + d.tab; }
                try { history.replaceState(null, '', url); } catch (err) {}
            }

            // Last content height the SaaS reported, re-applied when leaving
            // fullscreen (which pins the iframe to 100vh via CSS while active —
            // an inline height would otherwise fight that, inline styles beat
            // any class selector regardless of specificity).
            var lastHeight = 0;
            function applyHeight(px) {
                lastHeight = px;
                if (!frame.classList.contains('es-embed-fullscreen')) {
                    frame.style.height = px + 'px';
                }
            }

            window.addEventListener('message', function (e) {
                if (e.origin !== ORIGIN || !frame) { return; }
                var d = e.data || {};
                if (d.source !== 'emblem-studio') { return; }
                if (d.type === 'chrome') {
                    frame.classList.toggle('es-embed-fullscreen', !!d.fullscreen);
                    if (!d.fullscreen) {
                        frame.style.height = lastHeight ? lastHeight + 'px' : '';
                    } else {
                        frame.style.height = '';
                    }
                } else if (d.type === 'route') {
                    syncWpUrl(d);
                } else if (d.type === 'resize' && typeof d.height === 'number' && d.height > 0) {
                    applyHeight(d.height);
                }
            });
        })();
        </script>
        <?php if (get_option('es_import_status') === 'running') : ?>
        <div id="es-import-overlay" class="es-import-overlay" role="status" aria-live="polite">
            <div class="es-import-card">
                <div class="es-import-spinner"></div>
                <h2><?php esc_html_e('Setting up your account…', 'emblem-studio'); ?></h2>
                <p><?php esc_html_e('Importing your assets, stats and leads from this site. This can take a while on sites with a lot of history. You can leave this page — the import keeps running without you, and it’ll be done when you come back.', 'emblem-studio'); ?></p>
                <p id="es-import-progress" style="font-variant-numeric:tabular-nums;color:#50575e;font-size:13px;min-height:18px;margin:8px 0 0;"></p>
            </div>
        </div>
        <script>
        (function () {
            var overlay = document.getElementById('es-import-overlay');
            if (!overlay) { return; }
            var ajax  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('es_import_status')); ?>;
            var frame = document.getElementById('es-embed-frame');
            var out   = document.getElementById('es-import-progress');
            var I18N  = <?php echo wp_json_encode([
                'counting' => __('Working…', 'emblem-studio'),
                /* translators: 1: rows imported so far, 2: total rows. */
                'progress' => __('%1$s of %2$s records imported', 'emblem-studio'),
                'finishing' => __('Finishing up…', 'emblem-studio'),
                'waiting'  => __('Emblem Studio is busy — waiting to retry. Nothing is lost; this picks up where it left off.', 'emblem-studio'),
            ]); ?>;

            function num(n) { try { return n.toLocaleString(); } catch (e) { return String(n); } }

            function finish() {
                overlay.classList.add('es-hide');
                if (frame) { frame.src = frame.src; } // reload so the imported data shows
                setTimeout(function () { if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); } }, 450);
            }

            function paint(d) {
                if (!out) { return; }
                if (d.stalled) { out.textContent = I18N.waiting; return; }
                if (d.stage === 'pull') { out.textContent = I18N.finishing; return; }
                if (d.total > 0) {
                    out.textContent = I18N.progress
                        .replace('%1$s', num(Math.min(d.done, d.total)))
                        .replace('%2$s', num(d.total));
                } else {
                    out.textContent = I18N.counting;
                }
            }

            // Each request also DRIVES a slice of the import server-side, so this
            // finishes even on hosts where WP-Cron never fires. Sequential by design:
            // the next call goes out only once the last one returns.
            function poll() {
                fetch(ajax + '?action=es_import_status&nonce=' + encodeURIComponent(nonce), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (!j || !j.success || !j.data) { setTimeout(poll, 3000); return; }
                        if (j.data.status === 'done') { finish(); return; }
                        paint(j.data);
                        setTimeout(poll, j.data.stalled ? 5000 : 250);
                    })
                    .catch(function () { setTimeout(poll, 3000); });
            }
            poll();
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /** "You've been disconnected" — Try again / Sign in again / Decouple (no silent Lite fallback). */
    private static function render_disconnected(): void
    {
        $retry_url   = admin_url('admin.php?page=emblem-studio');
        $sign_in_url = admin_url('admin.php?page=emblem-studio-account&es_reconnect=1');
        $decouple    = self::decouple_form_html();
        ?>
        <div class="wrap">
            <div style="max-width:560px;margin:8vh auto;text-align:center;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:40px 32px;">
                <div style="font-size:40px;line-height:1;margin-bottom:12px;">🔌</div>
                <h1 style="font-size:22px;margin:0 0 8px;"><?php esc_html_e('The dashboard is paused', 'emblem-studio'); ?></h1>
                <p style="color:#50575e;font-size:14px;margin:0 0 24px;">
                    <?php esc_html_e('We couldn’t reach your Emblem Studio account just now. If Emblem Studio was briefly unavailable, Try again picks up where you left off. If you signed out or were removed, sign in again to reconnect — or decouple to switch this site back to the Lite plugin.', 'emblem-studio'); ?>
                </p>
                <div style="display:flex;gap:12px;justify-content:center;align-items:center;flex-wrap:wrap;">
                    <a href="<?php echo esc_url($retry_url); ?>" class="button button-primary button-hero"><?php esc_html_e('Try again', 'emblem-studio'); ?></a>
                    <a href="<?php echo esc_url($sign_in_url); ?>" class="button button-hero"><?php esc_html_e('Sign in again', 'emblem-studio'); ?></a>
                    <?php echo $decouple; // phpcs:ignore ?>
                </div>
            </div>
        </div>
        <?php
    }

    /** Connected → show status + a Decouple button. */
    private static function render_connected_panel(): void
    {
        $cfg  = self::config();
        $name = get_bloginfo('name');
        ?>
        <div class="wrap">
            <h1 style="font-family:'Titillium Web','Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;"><?php esc_html_e('Emblem Studio account', 'emblem-studio'); ?></h1>
            <div style="max-width:620px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px;margin-top:16px;">
                <p style="font-size:14px;margin:0 0 4px;">
                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#46b450;margin-right:8px;"></span>
                    <strong><?php esc_html_e('Connected', 'emblem-studio'); ?></strong>
                </p>
                <p style="color:#50575e;font-size:13px;margin:0 0 16px;">
                    <?php
                    printf(
                        /* translators: 1: site name, 2: SaaS base URL */
                        esc_html__('“%1$s” is connected to Emblem Studio at %2$s. Your sidebar pages now show your live Emblem Studio dashboard.', 'emblem-studio'),
                        esc_html($name),
                        '<code>'.esc_html($cfg['api_base']).'</code>'
                    );
                    ?>
                </p>
                <p style="color:#646970;font-size:12px;margin:0 0 16px;">
                    <?php esc_html_e('Your leads, subscribers and stats stay in sync with your Emblem Studio account for as long as this site is connected.', 'emblem-studio'); ?>
                </p>
                <?php if (self::connected_via() === 'constants') : ?>
                    <p style="color:#646970;font-size:12px;margin:0 0 4px;">
                        <?php esc_html_e('This connection is set in wp-config.php (ESS_API_BASE / ESS_SITE_ID / ESS_SITE_TOKEN). To disconnect, remove those constants.', 'emblem-studio'); ?>
                    </p>
                <?php else : ?>
                    <p style="color:#646970;font-size:12px;margin:0 0 20px;">
                        <?php esc_html_e('Decoupling disconnects this site and reverts the dashboard to the built-in Lite plugin. The leads, subscribers and stats already synced stay in your Emblem Studio account — disconnecting does not delete them there. You can reconnect anytime.', 'emblem-studio'); ?>
                    </p>
                    <?php echo self::decouple_form_html(); // phpcs:ignore ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Not connected → sign in to a real Emblem Studio account (P6). Clicking "Sign
     * in to connect" opens Emblem Studio's WorkOS login in a POPUP (not an iframe),
     * so the password goes browser → WorkOS directly and never touches any server
     * of ours. On success the popup hands us a short-lived grant over postMessage
     * (verified by a one-time state nonce), which we spend server-side
     * (handle_connect_complete) to pair the site with that account.
     */
    private static function render_connect_panel(): void
    {
        // Which SaaS to talk to: constant > stored > ?es_api override > default.
        $api = defined('ESS_API_BASE') ? (string) ESS_API_BASE : self::DEFAULT_API_BASE;
        $s   = self::stored();
        if (! empty($s['api_base'])) {
            $api = (string) $s['api_base'];
        }
        if (! empty($_GET['es_api'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $api = esc_url_raw(wp_unslash((string) $_GET['es_api'])); // phpcs:ignore WordPress.Security.NonceVerification
        }
        $api = rtrim($api, '/');

        $origin = home_url('/');
        $name   = get_bloginfo('name');
        // One-time nonce echoed back by the popup, so we trust the message even if
        // WorkOS's canonical-host redirect changes the popup's origin (localhost vs
        // 127.0.0.1 in dev). The grant itself is cryptographically verified on pair.
        $state = wp_generate_password(24, false);
        $popup = $api.'/embed/connect?origin='.rawurlencode($origin).'&name='.rawurlencode($name).'&state='.rawurlencode($state);
        ?>
        <div class="wrap">
            <div style="max-width:520px;margin:6vh auto;text-align:center;background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:40px 32px;">
                <h1 style="font-family:'Titillium Web','Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;font-size:24px;margin:0 0 8px;"><?php esc_html_e('Connect to Emblem Studio', 'emblem-studio'); ?></h1>
                <p style="color:#50575e;font-size:14px;margin:0 0 8px;">
                    <?php esc_html_e('Sign in to your Emblem Studio account to manage this site’s forms, popups and reports right here in WordPress.', 'emblem-studio'); ?>
                </p>
                <p style="color:#646970;font-size:12px;margin:0 0 8px;">
                    <?php
                    printf(
                        /* translators: %s: link to the beta application page */
                        esc_html__('A secure Emblem Studio sign-in window will open. The beta is invite-only — %s if you don\'t have access yet.', 'emblem-studio'),
                        '<a href="'.esc_url(rtrim($api, '/').'/#apply').'" target="_blank" rel="noopener">'.esc_html__('ask for beta access', 'emblem-studio').'</a>'
                    );
                    ?>
                </p>
                <p style="color:#646970;font-size:12px;margin:0 0 22px;">
                    <?php esc_html_e('Connecting copies this site’s existing leads, subscribers and stats into your Emblem Studio account, and keeps them in sync from then on. You’ll confirm this on the next screen.', 'emblem-studio'); ?>
                </p>
                <p>
                    <button id="es-connect-btn" type="button" class="button button-primary button-hero">
                        <?php esc_html_e('Sign in to connect', 'emblem-studio'); ?>
                    </button>
                </p>
                <div id="es-connect-status" style="min-height:20px;margin-top:12px;color:#50575e;font-size:13px;"></div>
                <details style="max-width:460px;margin:14px auto 0;text-align:left;">
                    <summary style="cursor:pointer;color:#646970;font-size:12px;"><?php esc_html_e('Advanced: Emblem Studio address', 'emblem-studio'); ?></summary>
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:10px 0 0;display:flex;gap:8px;">
                        <input type="hidden" name="page" value="emblem-studio-account" />
                        <input type="url" name="es_api" value="<?php echo esc_attr($api); ?>" class="regular-text" style="flex:1;" />
                        <button type="submit" class="button"><?php esc_html_e('Use', 'emblem-studio'); ?></button>
                    </form>
                </details>
            </div>
        </div>
        <script>
        (function () {
            var POPUP    = <?php echo wp_json_encode($popup); ?>;
            var STATE    = <?php echo wp_json_encode($state); ?>;
            var API_BASE = <?php echo wp_json_encode($api); ?>;
            var AJAX     = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE    = <?php echo wp_json_encode(wp_create_nonce('es_connect_complete')); ?>;
            var btn      = document.getElementById('es-connect-btn');
            var statusEl = document.getElementById('es-connect-status');

            btn.addEventListener('click', function () {
                statusEl.textContent = '';
                var w = window.open(POPUP, 'es-connect', 'popup,width=480,height=720');
                if (! w) { statusEl.textContent = <?php echo wp_json_encode(__('Please allow pop-ups for this site, then click again.', 'emblem-studio')); ?>; }
            });

            window.addEventListener('message', function (e) {
                var d = e.data || {};
                // The state nonce is the trust anchor (the popup's origin can differ
                // from API_BASE after WorkOS's canonical-host redirect); the grant is
                // then verified cryptographically when WP pairs server-side.
                if (! d || d.type !== 'es-connect' || d.state !== STATE || ! d.grant) { return; }

                statusEl.textContent = <?php echo wp_json_encode(__('Connecting…', 'emblem-studio')); ?>;
                var form = new FormData();
                form.append('action', 'es_connect_complete');
                form.append('_ajax_nonce', NONCE);
                form.append('grant', d.grant);
                form.append('api_base', API_BASE);

                fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: form })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success && j.data && j.data.redirect) {
                            window.location.href = j.data.redirect;
                        } else {
                            statusEl.textContent = (j && j.data && j.data.message) || <?php echo wp_json_encode(__('Connecting failed. Please try again.', 'emblem-studio')); ?>;
                        }
                    })
                    .catch(function () {
                        statusEl.textContent = <?php echo wp_json_encode(__('Couldn’t reach WordPress to finish connecting. Please try again.', 'emblem-studio')); ?>;
                    });
            });
        })();
        </script>
        <?php
    }

    /** A Decouple form with a JS confirm that spells out the revert-to-Lite effect. */
    private static function decouple_form_html(): string
    {
        $confirm = esc_js(__('Decouple this site? The dashboard will switch back to the WordPress Lite plugin. The leads, subscribers and stats already synced stay in your Emblem Studio account — decoupling does not delete them there. You can reconnect anytime.', 'emblem-studio'));

        return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0;" onsubmit="return confirm(\''.$confirm.'\');">'
            .'<input type="hidden" name="action" value="es_decouple" />'
            .wp_nonce_field('es_decouple', '_wpnonce', true, false)
            .'<button type="submit" class="button">'.esc_html__('Decouple account', 'emblem-studio').'</button>'
            .'</form>';
    }

    /* ── Connect / decouple handlers ───────────────────────────────────── */

    /**
     * DEPRECATED (pre-P6 account-less one-click pairing). Connecting now requires
     * signing in to a real account, so this legacy admin-post handler no longer
     * pairs — it just sends the user to the sign-in screen. Kept so any old
     * bookmarked link degrades gracefully.
     */
    public static function handle_connect(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'emblem-studio'), '', ['response' => 403]);
        }
        check_admin_referer('es_connect');

        wp_safe_redirect(admin_url('admin.php?page=emblem-studio-account'));
        exit;
    }

    /**
     * Finish a login-gated connect (P6). The WorkOS popup verified the account
     * owner (password never touched us) and handed the browser a short-lived grant;
     * here we spend it SERVER-SIDE at /v1/embed/connect/pair to attach this site to
     * that real account and store the durable connection (the site secret never
     * touches the browser). Called over admin-ajax by the connect screen.
     */
    public static function handle_connect_complete(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'emblem-studio')], 403);
        }
        check_ajax_referer('es_connect_complete');

        $grant = isset($_POST['grant']) ? sanitize_text_field(wp_unslash((string) $_POST['grant'])) : '';
        $api   = isset($_POST['api_base']) ? esc_url_raw(wp_unslash((string) $_POST['api_base'])) : '';
        if ($api === '') {
            $api = defined('ESS_API_BASE') ? (string) ESS_API_BASE : self::DEFAULT_API_BASE;
        }
        $api = rtrim($api, '/');

        if ($grant === '') {
            wp_send_json_error(['message' => __('Something went wrong signing in. Please try again.', 'emblem-studio')]);
        }

        $res = wp_remote_post($api.'/v1/embed/connect/pair', [
            'timeout' => 12,
            'body'    => [
                'grant'    => $grant,
                'name'     => get_bloginfo('name'),
                'platform' => 'wp',
                // Hand over WordPress's OWN timezone so the user never has to retype
                // something this site already knows (the SaaS uses it for scheduling
                // + report days). An IANA name, or a "+03:00" offset on sites that
                // use a manual offset; the SaaS validates and falls back to UTC.
                'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : '',
            ],
        ]);

        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);

        if ($code !== 200 || ! is_array($body) || empty($body['site_id']) || empty($body['embed_token'])) {
            $msg = is_array($body) && ! empty($body['message'])
                ? (string) $body['message']
                : __('Couldn’t reach Emblem Studio to connect. Please try again.', 'emblem-studio');
            wp_send_json_error(['message' => $msg]);
        }

        update_option(self::OPTION, [
            'api_base'     => $api,
            'site_id'      => (int) $body['site_id'],
            'site_token'   => (string) $body['embed_token'],
            'code'         => isset($body['code']) ? (string) $body['code'] : null,
            'name'         => isset($body['name']) ? (string) $body['name'] : get_bloginfo('name'),
            'connected_at' => time(),
        ]);
        delete_transient(self::FORMS_TRANSIENT);

        // Import (mirror up + copy down) runs in bounded background passes so the
        // dashboard shows a progress screen instead of blocking (see start_import +
        // run_import_pass + render_embed).
        self::start_import();

        wp_send_json_success(['redirect' => admin_url('admin.php?page=emblem-studio')]);
    }

    /**
     * Send this site's local forms to the SaaS to be upserted (mirror-on-connect).
     * Best-effort — a failure here never blocks connecting (the dashboard just
     * starts empty and the user can re-run by reconnecting).
     */
    private static function mirror_forms(array $cfg): void
    {
        if (! class_exists('ES_Form_Repo')) {
            return;
        }
        // Every non-trashed form, all statuses — including archived, since archived
        // assets still need to sync up (they just don't show in the admin's "All"
        // tab, which is what all('all') now means).
        $rows = array_merge(ES_Form_Repo::all('all'), ES_Form_Repo::all('archived'));
        $forms = [];
        foreach ((array) $rows as $row) {
            if (! is_object($row) || empty($row->id)) {
                continue;
            }
            // NEVER mirror a SaaS-origin asset back up. It was copied DOWN from the
            // SaaS (so it carries a saas_form_id); sending it up would be seen as a
            // new asset (it has no matching wp_form_id there) and duplicate it on
            // every connect. Only WP-authored forms (no saas_form_id) mirror up.
            if (! empty($row->saas_form_id)) {
                continue;
            }
            $export = ES_Form_Repo::to_export_array($row);
            $export['wp_form_id'] = (int) $row->id; // stable key → idempotent upsert
            $export['revisions']  = self::form_revisions((int) $row->id);
            $forms[] = $export;
        }
        if (empty($forms)) {
            return;
        }

        self::mirror_post(rtrim($cfg['api_base'], '/').'/v1/embed/mirror', [
            'site_id' => $cfg['site_id'],
            'token'   => $cfg['site_token'],
            'forms'   => $forms,
            // Rides along free, as the safety net for a timezone push that never
            // arrived: the site was offline, the SaaS was down, or the option
            // changed while this plugin was inactive. See push_timezone().
            'timezone' => self::wp_timezone(),
        ]);
    }

    /**
     * WordPress's own timezone, in whatever shape it reports.
     *
     * An IANA name like "America/New_York", or "-04:00" on a site using a manual
     * offset. The SaaS normalises whatever arrives.
     */
    private static function wp_timezone(): string
    {
        return function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
    }

    /**
     * Push the timezone the moment somebody changes it in WordPress.
     *
     * A WordPress site's timezone is WordPress's to decide. The SaaS dashboard
     * has no field to disagree with it, so this side is the only source, and it
     * used to speak exactly once, at pairing. That left two problems: a change
     * made here never reached the SaaS, and a bad value there could never be
     * corrected by anything.
     *
     * Hooked to the option itself rather than polled, so it costs nothing on
     * every other day of the year and is instant on the day it matters.
     */
    public static function push_timezone(): void
    {
        $cfg = self::config();
        if (empty($cfg['site_id']) || empty($cfg['site_token'])) {
            return; // not connected — nobody to tell
        }

        $tz = self::wp_timezone();
        if ($tz === '') {
            return;
        }

        // Fire and forget. This runs inside an admin save, so it must never make
        // somebody wait and must never fail the save it hangs off. A push that
        // does not land is picked up within the hour by report_timezone(), which
        // keeps sending until the SaaS confirms. (This comment used to say "the
        // next mirror pass", which only ever ran once, at connect.)
        wp_remote_post(rtrim($cfg['api_base'], '/').'/v1/embed/site-timezone', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode([
                'site_id'  => $cfg['site_id'],
                'token'    => $cfg['site_token'],
                'timezone' => $tz,
            ]),
        ]);
    }

    /**
     * Send this site's local display-rule presets to the SaaS to be upserted
     * (SER-899 phase 2) — same shape and same rule as mirror_forms(): a preset
     * already pulled DOWN from the SaaS (carries a saas_preset_id) never mirrors
     * back up, or it would look like a new one and duplicate on every connect.
     */
    private static function mirror_presets(array $cfg): void
    {
        if (! class_exists('ES_Preset_Repo')) {
            return;
        }
        $rows    = ES_Preset_Repo::all();
        $presets = [];
        foreach ((array) $rows as $row) {
            if (! is_object($row) || empty($row->id)) {
                continue;
            }
            if (! empty($row->saas_preset_id)) {
                continue;
            }
            $export = ES_Preset_Repo::to_export_array($row);
            $export['wp_preset_id'] = (int) $row->id; // stable key → idempotent upsert
            $presets[] = $export;
        }
        if (empty($presets)) {
            return;
        }

        self::mirror_post(rtrim($cfg['api_base'], '/').'/v1/embed/mirror/presets', [
            'site_id' => $cfg['site_id'],
            'token'   => $cfg['site_token'],
            'presets' => $presets,
        ]);
    }

    /** The wpdb table for a mirror kind, or '' when it doesn't exist. */
    private static function mirror_table(string $kind): string
    {
        global $wpdb;
        $table = $wpdb->prefix . ($kind === 'events' ? 'emblemstudio_events' : 'emblemstudio_leads');

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? $table : '';
    }

    /** Rows still to mirror for a kind (id > cursor). Drives the progress figure. */
    private static function mirror_remaining(string $kind, int $after = 0): int
    {
        global $wpdb;
        $table = self::mirror_table($kind);
        if ($table === '') {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a prefixed constant name.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id > %d", $after));
    }

    /**
     * Mirror ONE id-ordered batch of a kind ('events' | 'leads') and return the
     * advanced state; or null when the batch was refused (caller stops and retries
     * this same cursor on a later pass — never skips, so no holes).
     *
     * Idempotent server-side by (form_id, wp_event_id / wp_lead_id), so replaying a
     * batch after a crash is harmless.
     *
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>|null
     */
    private static function mirror_batch(array $cfg, array $state, string $kind, string $path, string $next_stage): ?array
    {
        global $wpdb;

        $table = self::mirror_table($kind);
        if ($table === '') {
            $state['stage']   = $next_stage;
            $state['last_id'] = 0;

            return $state;
        }

        $cols = $kind === 'events'
            ? 'id, form_id, event_type, type, click_target, variant, post_id, ua, ip, visitor, created_at'
            : 'id, form_id, email, extra_fields, funnelkit_contact_id, funnelkit_pushed, error, is_bot, bot_signals, ip, created_at';

        // NEVER mirror a SaaS-origin row back up — the same rule mirror_forms()
        // applies to assets, which this had been missing.
        //
        // These tables hold BOTH our own history and the copies pull_stats() /
        // pull_leads() bring DOWN from the SaaS. Sending a pulled row back up
        // hands it over as WP-authored: it arrives under a fresh wp_event_id, so
        // the SaaS's insert-or-ignore (keyed on form_id + wp_event_id) cannot
        // recognise it as the row it sent us, and stores it a second time. Every
        // re-connect inflated the stats a little more.
        //
        // The SaaS already guards the other direction — its export sends only
        // rows with no wp_event_id, precisely so nothing round-trips. This is the
        // matching half. Only rows we originated travel up.
        $origin_col = $kind === 'events' ? 'saas_event_id' : 'saas_lead_id';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table/$cols/$origin_col are constant names.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols} FROM {$table} WHERE id > %d AND {$origin_col} IS NULL ORDER BY id ASC LIMIT %d",
            (int) $state['last_id'],
            self::IMPORT_BATCH
        ));

        if (empty($rows)) {
            $state['stage']   = $next_stage;
            $state['last_id'] = 0;

            return $state;
        }

        $items = [];
        $last  = (int) $state['last_id'];
        foreach ($rows as $row) {
            $items[] = $kind === 'events' ? self::map_event($row) : self::map_lead($row);
            $last    = (int) $row->id;
        }

        $ok = self::mirror_post(rtrim($cfg['api_base'], '/') . $path, [
            'site_id'                            => $cfg['site_id'],
            'token'                              => $cfg['site_token'],
            ($kind === 'events' ? 'events' : 'leads') => $items,
        ]);
        if (! $ok) {
            return null;
        }

        // Cursor advances ONLY after the batch actually landed.
        $state['last_id'] = $last;
        $state['done']    = (int) $state['done'] + count($items);

        return $state;
    }

    /** @return array<string,mixed> */
    private static function map_event(object $row): array
    {
        return [
            'wp_event_id'  => (int) $row->id,
            'wp_form_id'   => (int) $row->form_id,
            'event_type'   => $row->event_type,
            'type'         => $row->type,
            'click_target' => $row->click_target,
            'variant'      => $row->variant,
            'post_id'      => $row->post_id !== null ? (int) $row->post_id : null,
            'ua'           => $row->ua,
            'ip'           => $row->ip,
            'visitor'      => $row->visitor,
            // Sent as UTC, because that is what the SaaS stores. This column is WP
            // local time, so handing it over raw filed every mirrored event under
            // the site's offset — the matching half of the conversion pull_stats()
            // does coming the other way.
            'created_at'   => get_gmt_from_date((string) $row->created_at),
        ];
    }

    /** @return array<string,mixed> */
    private static function map_lead(object $row): array
    {
        return [
            'wp_lead_id'           => (int) $row->id,
            'wp_form_id'           => (int) $row->form_id,
            'email'                => $row->email,
            'extra_fields'         => $row->extra_fields,
            'funnelkit_contact_id' => $row->funnelkit_contact_id !== null ? (int) $row->funnelkit_contact_id : null,
            'funnelkit_pushed'     => (int) $row->funnelkit_pushed,
            'error'                => $row->error,
            'is_bot'               => (int) $row->is_bot,
            // Travels with the verdict, in both directions. A WordPress-captured
            // lead was flagged HERE, so this side is the only place the evidence
            // exists — without it the dashboard shows "Bot" and cannot say why,
            // which is the same blind spot in mirror image.
            'bot_signals'          => $row->bot_signals ?? null,
            'ip'                   => $row->ip,
            // Sent as UTC — see map_event(). Same column, same conversion, so a
            // lead reads at the same moment on both sides.
            'created_at'           => get_gmt_from_date((string) $row->created_at),
        ];
    }

    /* ── Copy-down (Phase 2): pull the SaaS state back into WordPress ──────── */

    /**
     * Pull the SaaS's assets, stats and leads into this WordPress so it always
     * holds an up-to-date local copy. Runs on connect, on the es_saas_pull cron,
     * and one final time at decouple. Best-effort — a failure is retried next run.
     */
    public static function pull_all(?array $cfg = null): void
    {
        $cfg = $cfg ?: self::config();
        if (! $cfg) {
            return;
        }
        self::pull_assets($cfg);
        self::pull_presets($cfg);
        self::pull_stats($cfg);
        self::pull_leads($cfg);
        self::pull_lead_deletions($cfg);
        self::push_purchases();
    }

    /** WP-cron tick — refresh the local copy while connected. */
    public static function cron_pull(): void
    {
        if (self::is_connected()) {
            self::pull_all();
            self::report_timezone();
        }
    }

    /**
     * Make sure the SaaS knows this site's clock, and keep checking until it does.
     *
     * Every schedule on a connected site runs on the timezone the SaaS holds for
     * it, and WordPress is the only place that timezone is set. It used to reach
     * the SaaS by three routes, and all three could miss: pairing sent it and the
     * SaaS dropped it, the mirror at connect carried it but skipped a site with
     * no assets in WordPress, and push_timezone() only fires when the setting
     * changes. A site that missed all three stayed on UTC with nothing to correct
     * it, and its schedules ran hours off.
     *
     * So this runs hourly and costs nothing once it has landed: it remembers the
     * last value the SaaS CONFIRMED, for this connection, and only speaks when the
     * current one differs. Blocking on purpose, because the point is to know it
     * arrived. Keyed by site id, so connecting to a different site reports again.
     */
    public static function report_timezone(): void
    {
        $cfg = self::config();
        if (! $cfg || empty($cfg['site_id']) || empty($cfg['site_token'])) {
            return;
        }

        $tz = self::wp_timezone();
        if ($tz === '') {
            return;
        }

        $marker = $cfg['site_id'].'|'.$tz;
        if ((string) get_option('ess_timezone_reported', '') === $marker) {
            return;
        }

        $res = wp_remote_post(rtrim($cfg['api_base'], '/').'/v1/embed/site-timezone', [
            'timeout' => 8,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'site_id'  => $cfg['site_id'],
                'token'    => $cfg['site_token'],
                'timezone' => $tz,
            ]),
        ]);

        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return; // Not confirmed. The next hour tries again.
        }

        update_option('ess_timezone_reported', $marker, false);
    }

    /**
     * Assets only, on demand — a lighter refresh than pull_all() (skips
     * presets/stats/leads) for callers that just need the local asset mirror
     * fresh right now, e.g. FunnelKit's own Forms picker (see
     * ES_FunnelKit_Form_Feed::maybe_refresh_catalog()), which otherwise only
     * sees whatever the last hourly es_saas_pull happened to pull down.
     */
    public static function pull_assets_now(): void
    {
        $cfg = self::config();
        if ($cfg) {
            self::pull_assets($cfg);
        }
    }

    /* ── The connect-time import: resumable, chunked, cron-independent ────────
     *
     * A real site's history is tens of thousands of rows, so this CANNOT be one
     * long job:
     *   - PHP's max_execution_time kills a long run mid-way, and a run that
     *     restarts from zero each time may never finish on a big enough table.
     *   - It was queued behind WP-Cron with no fallback. On a cached / CDN-fronted
     *     host (DISABLE_WP_CRON, or blocked loopback) nothing spawns it, so connect
     *     looked like a hang for minutes.
     *
     * So: state lives in an option (`es_import_state`) and every pass does a
     * bounded slice of work, then reschedules while work remains. A killed pass
     * costs at most one batch — the next resumes from the stored cursor.
     *
     * TWO drivers, either alone is enough, and a transient lock keeps them from
     * overlapping:
     *   1. the `es_saas_import` cron event (works with the tab closed), and
     *   2. the dashboard overlay, which drives passes over AJAX while it's open —
     *      so the import runs even where WP-Cron never fires.
     */

    /** Rows per mirror batch (one HTTP POST). */
    const IMPORT_BATCH = 500;

    /** Work budget for a single pass — comfortably inside a default max_execution_time. */
    const IMPORT_PASS_SECONDS = 15;

    /** Only one import worker at a time (cron vs the overlay's AJAX ticks). */
    const IMPORT_LOCK = 'es_import_lock';

    /** Resumable cursor + progress. @return array<string,mixed> */
    private static function import_state(): array
    {
        $state = get_option('es_import_state', []);
        if (! is_array($state) || empty($state['stage'])) {
            return ['stage' => 'forms', 'last_id' => 0, 'done' => 0, 'total' => 0, 'stalled' => 0];
        }

        return $state + ['stage' => 'forms', 'last_id' => 0, 'done' => 0, 'total' => 0, 'stalled' => 0];
    }

    /** @param array<string,mixed> $state */
    private static function save_import_state(array $state): void
    {
        update_option('es_import_state', $state, false);
    }

    /**
     * Arm a fresh import and kick the first pass immediately (don't just queue it
     * and hope something triggers WP-Cron). Called right after a successful pair.
     */
    private static function start_import(): void
    {
        update_option('es_import_status', 'running');
        self::save_import_state([
            'stage'   => 'forms',
            'last_id' => 0,
            'done'    => 0,
            // Best-effort total for the progress line; the overlay copes if it's off.
            'total'   => self::mirror_remaining('events') + self::mirror_remaining('leads'),
            'stalled' => 0,
        ]);

        wp_schedule_single_event(time(), 'es_saas_import');
        // Best-effort nudge; the overlay drives passes itself if this never fires.
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /** WP-cron entry (`es_saas_import`): run one pass, reschedule while work remains. */
    public static function run_import(): void
    {
        $progress = self::run_import_pass();
        if ($progress['status'] === 'done') {
            return;
        }
        // Stalled (rate-limited / server down) → wait before leaning on it again.
        $delay = ! empty($progress['stalled']) ? 60 : 1;
        if (! wp_next_scheduled('es_saas_import')) {
            wp_schedule_single_event(time() + $delay, 'es_saas_import');
        }
    }

    /**
     * One bounded slice of the import. Safe to call from cron OR from the overlay's
     * AJAX tick — the lock means whoever gets there first does the work and the
     * other just reads progress.
     *
     * @return array<string,mixed>
     */
    public static function run_import_pass(): array
    {
        if (get_transient(self::IMPORT_LOCK)) {
            return self::import_progress(); // another worker is mid-pass
        }
        set_transient(self::IMPORT_LOCK, 1, 120);

        try {
            $cfg = self::config();
            if (! $cfg) {
                self::finish_import();

                return self::import_progress();
            }

            $state    = self::import_state();
            $deadline = time() + self::IMPORT_PASS_SECONDS;

            while ($state['stage'] !== 'done' && time() < $deadline) {
                $next = self::import_step($cfg, $state);
                if ($next === null) { // batch refused — keep the cursor, retry later
                    $state['stalled'] = time();
                    self::save_import_state($state);
                    break;
                }
                $state            = $next;
                $state['stalled'] = 0;
                self::save_import_state($state);
            }

            if ($state['stage'] === 'done') {
                self::finish_import($cfg);
            }
        } catch (\Throwable $e) {
            // Never let a thrown pass wedge the import: the cursor is already saved,
            // so the next pass resumes. Fall through and report progress.
        } finally {
            delete_transient(self::IMPORT_LOCK);
        }

        return self::import_progress();
    }

    /**
     * Advance the import by one unit of work. Returns the new state, or null when a
     * batch was refused (cursor unchanged so the next pass retries it).
     *
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>|null
     */
    private static function import_step(array $cfg, array $state): ?array
    {
        switch ($state['stage']) {
            case 'forms':
                self::mirror_forms($cfg);
                $state['stage']   = 'presets';
                $state['last_id'] = 0;

                return $state;

            case 'presets':
                self::mirror_presets($cfg);
                $state['stage']   = 'stats';
                $state['last_id'] = 0;

                return $state;

            case 'stats':
                return self::mirror_batch($cfg, $state, 'events', '/v1/embed/stats', 'leads');

            case 'leads':
                return self::mirror_batch($cfg, $state, 'leads', '/v1/embed/leads', 'pull');

            case 'pull':
                self::pull_all($cfg);
                $state['stage'] = 'done';

                return $state;
        }

        $state['stage'] = 'done';

        return $state;
    }

    /** Import finished: arm the periodic refresh and lift the overlay. */
    private static function finish_import(?array $cfg = null): void
    {
        if ($cfg && ! wp_next_scheduled('es_saas_pull')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'es_saas_pull');
        }
        if ($cfg) {
            self::report_timezone();
        }
        $state          = self::import_state();
        $state['stage'] = 'done';
        self::save_import_state($state);
        update_option('es_import_status', 'done');
    }

    /** What the overlay shows. @return array<string,mixed> */
    private static function import_progress(): array
    {
        $state = self::import_state();

        return [
            'status'  => (string) get_option('es_import_status', 'done'),
            'stage'   => (string) $state['stage'],
            'done'    => (int) $state['done'],
            'total'   => (int) $state['total'],
            'stalled' => (int) $state['stalled'],
        ];
    }

    /**
     * Poll target for the setup overlay. Also DRIVES a pass, so the import proceeds
     * while the page is open even if WP-Cron never fires on this host. Returns real
     * progress (done/total), not just a running/done flag.
     */
    public static function ajax_import_status(): void
    {
        if (! current_user_can('manage_options') || ! check_ajax_referer('es_import_status', 'nonce', false)) {
            wp_send_json_error(['status' => 'forbidden'], 403);
        }
        wp_send_json_success(self::run_import_pass());
    }

    /**
     * POST one mirror batch, with backoff so a batch is never silently dropped.
     *
     * The caller advances its id cursor only when this returns true, so a refused
     * batch is retried instead of leaving a permanent hole in the imported history.
     * Retries on 429 (rate limited) and on transport/5xx errors, honouring
     * Retry-After when the server sends it.
     *
     * Retries are deliberately few and short: they must fit inside one import pass
     * (IMPORT_PASS_SECONDS). Returning false doesn't lose anything — the pass marks
     * the import stalled, keeps the cursor, and a later pass retries the SAME batch.
     * Long waits belong to the scheduler, not to a blocking sleep here.
     */
    private static function mirror_post(string $url, array $payload): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $res = wp_remote_post($url, [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode($payload),
            ]);

            if (! is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                if ($code >= 200 && $code < 300) {
                    return true;
                }
                // 4xx other than 429 is a permanent refusal — retrying won't help.
                if ($code !== 429 && $code < 500) {
                    return false;
                }
                $retry_after = (int) wp_remote_retrieve_header($res, 'retry-after');
            } else {
                $retry_after = 0;
            }

            // Don't sleep after the final attempt — the pass reschedules instead.
            if ($attempt === 2) {
                break;
            }
            // Server's Retry-After wins; otherwise back off 2s then 4s. Capped so a
            // long Retry-After can't blow the pass budget — that's the scheduler's job.
            $wait = $retry_after > 0 ? $retry_after : (2 ** ($attempt + 1));
            sleep(min($wait, 5));
        }

        return false;
    }

    /** POST a proven request to a SaaS export endpoint; decoded body or null. */
    private static function pull_post(array $cfg, string $path, array $extra = []): ?array
    {
        $res = wp_remote_post(rtrim($cfg['api_base'], '/') . $path, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(array_merge([
                'site_id' => $cfg['site_id'],
                'token'   => $cfg['site_token'],
            ], $extra)),
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Upsert every SaaS asset into the local forms table (match by the wp_form_id
     * it was mirrored up as, else by the SaaS id stored on a prior pull, else
     * create). Assets Lite can't run are copied but forced INACTIVE; supported
     * ones keep the SaaS status. ES_Form_Repo::save() enforces Lite's own caps.
     */
    private static function pull_assets(array $cfg): void
    {
        if (! class_exists('ES_Form_Repo')) {
            return;
        }
        $data = self::pull_post($cfg, '/v1/embed/export/assets');
        if (! isset($data['assets']) || ! is_array($data['assets'])) {
            return;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'emblemstudio_forms';
        // saas_form_id => local WordPress id, reported back to the SaaS after the
        // loop so the dashboard can show a real, working [es-inline-N] shortcode
        // for assets authored there (it has no way to know this id otherwise).
        $wp_ids = [];
        foreach ($data['assets'] as $a) {
            if (! is_array($a) || empty($a['saas_form_id'])) {
                continue;
            }
            $saas_id    = (int) $a['saas_form_id'];
            $wp_form_id = isset($a['wp_form_id']) ? (int) $a['wp_form_id'] : 0;

            $local_id = 0;
            if ($wp_form_id && (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE id = %d", $wp_form_id)) === $wp_form_id) {
                $local_id = $wp_form_id;
            } else {
                $local_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE saas_form_id = %d", $saas_id));
            }

            // Per-shell Lite support: keep the asset active on the shells Lite can
            // run and drop the ones it can't (auto popup / ribbon / slide-in). If
            // NO shell is runnable the whole asset is Lite-deactivated. The disabled
            // set is stored so the forms list can strike it through.
            $auto       = ['popup-auto', 'ribbon', 'slide-in'];
            $saas_types = is_array($a['form_types'] ?? null) ? array_values($a['form_types']) : ['inline'];
            $disabled   = array_values(array_intersect($saas_types, $auto));
            $supported  = array_values(array_diff($saas_types, $auto));
            $asset_off  = empty($supported);
            $status     = (! $asset_off && ($a['status'] ?? '') === 'active') ? 'active' : 'inactive';

            $fields = [
                'name'                 => $a['name'] ?? '',
                'slug'                 => self::unique_slug((string) ($a['slug'] ?? ''), $local_id),
                'html'                 => (string) ($a['html'] ?? ''),
                'css'                  => (string) ($a['css'] ?? ''),
                'status'               => $status,
                'success_mode'         => $a['success_mode'] ?? 'inline_state',
                'success_message'      => $a['success_message'] ?? null,
                'success_redirect_url' => $a['success_redirect_url'] ?? null,
                'funnelkit_lists'      => is_array($a['funnelkit_lists'] ?? null) ? $a['funnelkit_lists'] : [],
                'funnelkit_tags'       => is_array($a['funnelkit_tags'] ?? null) ? $a['funnelkit_tags'] : [],
                'field_mapping'        => is_array($a['field_mapping'] ?? null) ? $a['field_mapping'] : [],
                'form_types'           => $supported ?: ['inline'],
                'auto_trigger_config'  => is_array($a['auto_trigger_config'] ?? null) ? $a['auto_trigger_config'] : [],
                'display_rules'        => is_array($a['display_rules'] ?? null) ? $a['display_rules'] : [],
                'use_shadow_dom'       => ! empty($a['use_shadow_dom']),
                'use_design_system'    => ! empty($a['use_design_system']),
                'popup_max_width'      => (int) ($a['popup_max_width'] ?? 720),
                'popup_fullscreen'     => ! empty($a['popup_fullscreen']),
                'popup_close_theme'    => $a['popup_close_theme'] ?? 'light',
                'track_clicks'         => ! empty($a['track_clicks']),
                'track_purchases'      => ! empty($a['track_purchases']),
                'funnelkit_enabled'    => ! empty($a['funnelkit_enabled']),
                'webhooks'             => is_array($a['webhooks'] ?? null) ? $a['webhooks'] : [],
                'connections'          => is_array($a['connections'] ?? null) ? $a['connections'] : [],
                'active_from'          => $a['active_from'] ?? null,
                'active_until'         => $a['active_until'] ?? null,
            ];

            $new_id = (int) ES_Form_Repo::save($fields, $local_id ?: null);
            if ($new_id) {
                // Authoritative post-save: store only the Lite-runnable shells (may be
                // empty → renders nowhere) + the disabled set for the strikethrough.
                $wpdb->update($t, [
                    'saas_form_id'  => $saas_id,
                    'form_types'    => wp_json_encode($supported),
                    'lite_disabled' => wp_json_encode(['shells' => $disabled, 'asset_off' => $asset_off]),
                ], ['id' => $new_id]);
                $wp_ids[$saas_id] = $new_id;

                // Archive is a separate flag from status (like trash), so it isn't
                // part of ES_Form_Repo::save()'s field set — apply it explicitly.
                // Only touch it when the key is present, so an older SaaS build
                // (no archived_at in the payload yet) never un-archives on pull.
                //
                // set_archived_at(), NOT archive()/restore_from_archive(). Those two
                // are the wp-admin user action and switch the form off as part of
                // the deal, which is right when a person clicks Archive and wrong
                // here: $status above is the answer the SaaS just gave us, and the
                // flag must not overwrite it. The SaaS sends archived_at as null
                // rather than omitting it, so the old code ran the unarchive path on
                // every ordinary asset and left the whole mirror switched off.
                if (array_key_exists('archived_at', $a)) {
                    ES_Form_Repo::set_archived_at($new_id, empty($a['archived_at']) ? null : (string) $a['archived_at']);
                }
            }
        }

        // Delete-sync: a locally-synced asset whose SaaS counterpart is gone
        // (deleted in the SaaS → absent from this full list) is trashed here too.
        $received = [];
        foreach ($data['assets'] as $a) {
            if (is_array($a) && ! empty($a['saas_form_id'])) {
                $received[] = (int) $a['saas_form_id'];
            }
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is a prefixed constant name.
        foreach ((array) $wpdb->get_results("SELECT id, saas_form_id FROM {$t} WHERE saas_form_id IS NOT NULL AND trashed_at IS NULL") as $row) {
            if (! in_array((int) $row->saas_form_id, $received, true)) {
                ES_Form_Repo::trash((int) $row->id);
            }
        }

        // Report this pass's id mapping back — pure bookkeeping, never a content
        // mirror-up (mirror_forms() still refuses to send a SaaS-origin row's
        // content back). Sent every pull so a first-ever local id, or a changed
        // one after a trash/restore reassigns rows, always reaches the SaaS.
        if ($wp_ids) {
            $mappings = [];
            foreach ($wp_ids as $saas_form_id => $wp_form_id) {
                $mappings[] = ['saas_form_id' => $saas_form_id, 'wp_form_id' => $wp_form_id];
            }
            self::mirror_post(rtrim($cfg['api_base'], '/') . '/v1/embed/mirror/wp-ids', [
                'site_id'  => $cfg['site_id'],
                'token'    => $cfg['site_token'],
                'mappings' => $mappings,
            ]);
        }
    }

    /**
     * Upsert every SaaS display-rule preset into the local presets table
     * (SER-899 phase 2) — match by the wp_preset_id it was mirrored up as
     * (so a preset just pushed up in this same pass gets its saas_preset_id
     * written back), else by the saas_preset_id stored on a prior pull, else
     * create. Same convergence pattern as pull_assets(). Full set every time,
     * no cursor — a site's preset catalog is small.
     */
    private static function pull_presets(array $cfg): void
    {
        if (! class_exists('ES_Preset_Repo')) {
            return;
        }
        $data = self::pull_post($cfg, '/v1/embed/export/presets');
        if (! isset($data['presets']) || ! is_array($data['presets'])) {
            return;
        }

        global $wpdb;
        $t = $wpdb->prefix.'emblemstudio_display_rule_presets';
        $received = [];
        foreach ($data['presets'] as $p) {
            if (! is_array($p) || empty($p['saas_preset_id'])) {
                continue;
            }
            $saas_id = (int) $p['saas_preset_id'];
            $received[] = $saas_id;
            $wp_preset_id = isset($p['wp_preset_id']) ? (int) $p['wp_preset_id'] : 0;

            $local_id = 0;
            if ($wp_preset_id && (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE id = %d", $wp_preset_id)) === $wp_preset_id) {
                $local_id = $wp_preset_id;
            } else {
                $local_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE saas_preset_id = %d", $saas_id));
            }

            $fields = [
                'name'         => $p['name'] ?? '',
                'show_mode'    => $p['show_mode'] ?? 'all',
                'show_filters' => is_array($p['show_filters'] ?? null) ? $p['show_filters'] : [],
                'hide_filters' => is_array($p['hide_filters'] ?? null) ? $p['hide_filters'] : [],
            ];
            $new_id = ES_Preset_Repo::save($fields, $local_id ?: null);
            if ($new_id) {
                $wpdb->update($t, ['saas_preset_id' => $saas_id], ['id' => $new_id]);
            }
        }

        // Delete-sync: a locally-synced preset whose SaaS counterpart is gone
        // (deleted in the SaaS → absent from this full list) is deleted here
        // too — un-linking any shell still following it, same as a manual delete.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is a prefixed constant name.
        foreach ((array) $wpdb->get_results("SELECT id, saas_preset_id FROM {$t} WHERE saas_preset_id IS NOT NULL") as $row) {
            if (! in_array((int) $row->saas_preset_id, $received, true)) {
                ES_Preset_Repo::delete((int) $row->id);
            }
        }
    }

    /**
     * Honour a stats wipe performed in the SaaS.
     *
     * The SaaS stamps `reset_at` when someone wipes a site's stats, and hands it
     * back on every stats export. If it is newer than the one we last acted on,
     * the wipe has not reached us yet: drop our events, rewind the pull cursor to
     * zero so anything recorded AFTER the wipe comes back down, and record the
     * mark so we only ever do this once per wipe.
     *
     * We clear the whole table, not just the rows we pulled. A reset means the
     * numbers start again on both sides; keeping our own pre-connect history here
     * would leave WordPress reporting figures the SaaS no longer has, which is the
     * disagreement the wipe was meant to end. (The SaaS deletes its copy of that
     * same history in the same operation.)
     *
     * Returns the cursor to continue from — rewound to 0 on a fresh wipe.
     */
    private static function apply_stats_reset(array $data, int $after): int
    {
        $mark = isset($data['reset_at']) && is_string($data['reset_at']) ? $data['reset_at'] : '';
        if ($mark === '' || $mark === (string) get_option('es_saas_stats_reset_at', '')) {
            return $after;
        }

        // The same call the plugin's own "reset stats" button makes — one wipe
        // path, not two that could drift.
        if (class_exists('ES_Reports')) {
            ES_Reports::reset_stats();
        } else {
            global $wpdb;
            $events = $wpdb->prefix . 'emblemstudio_events';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant table name.
            $wpdb->query("TRUNCATE TABLE {$events}");
        }

        update_option('es_saas_stats_reset_at', $mark, false);
        update_option('es_saas_pull_stats_after', 0, false);

        return 0;
    }

    /**
     * Pull SaaS-native events down, mapped by saas_form_id; dedupe by saas_event_id.
     *
     * @param  int|null  $deadline  When given (SER-950's post-decouple catch-up),
     *                              stop looping once time() reaches it, leaving the
     *                              cursor exactly where it is for the next pass to
     *                              resume from. Omitted (the normal hourly refresh)
     *                              behaves exactly as before — loops until caught up.
     * @return bool  True once fully caught up; false if it stopped early because
     *               the deadline hit while a full batch (more likely pending) remained.
     */
    private static function pull_stats(array $cfg, ?int $deadline = null): bool
    {
        global $wpdb;
        $events = $wpdb->prefix . 'emblemstudio_events';
        $map    = self::saas_form_map();
        if (empty($map)) {
            return true;
        }
        $after = (int) get_option('es_saas_pull_stats_after', 0);
        do {
            $data = self::pull_post($cfg, '/v1/embed/export/stats', ['after_id' => $after]);
            if (! is_array($data)) {
                // A refused or failed export is incomplete work, not an empty
                // successful batch. Keep the cursor unchanged and retry later.
                return false;
            }

            // A wipe over there cannot reach us as rows — this pull only ever
            // inserts, so an emptied SaaS and a SaaS with nothing new look exactly
            // the same from here. The mark is what tells them apart, and it has to
            // be read BEFORE the empty-events return below: after a wipe, the
            // empty response is the only response there is.
            $after = self::apply_stats_reset($data, $after);

            if (empty($data['events']) || ! is_array($data['events'])) {
                return true;
            }
            $wpdb->suppress_errors(true);
            foreach ($data['events'] as $e) {
                $form_id = $map[(int) ($e['saas_form_id'] ?? 0)] ?? 0;
                if (! $form_id || empty($e['saas_event_id'])) {
                    continue;
                }
                $wpdb->insert($events, [
                    'saas_event_id' => (int) $e['saas_event_id'],
                    'form_id'       => $form_id,
                    'event_type'    => (string) ($e['event_type'] ?? ''),
                    'type'          => (string) ($e['type'] ?? 'inline'),
                    'click_target'  => $e['click_target'] ?? null,
                    'variant'       => $e['variant'] ?? null,
                    'post_id'       => isset($e['post_id']) ? (int) $e['post_id'] : null,
                    'ua'            => $e['ua'] ?? null,
                    'ip'            => $e['ip'] ?? null,
                    'visitor'       => $e['visitor'] ?? null,
                    // Same conversion as pull_leads(): the export sends UTC, and
                    // every locally recorded event in this table is WP local time.
                    // Mixing the two puts mirrored stats in the wrong day for any
                    // site that isn't on UTC, which quietly skews the reports.
                    'created_at'    => isset($e['created_at'])
                        ? get_date_from_gmt((string) $e['created_at'])
                        : current_time('mysql'),
                ]);
            }
            $wpdb->suppress_errors(false);
            $after = (int) ($data['last_id'] ?? $after);
            update_option('es_saas_pull_stats_after', $after, false);
            if (count($data['events']) < 500) {
                return true;
            }
        } while ($deadline === null || time() < $deadline);

        return false;
    }

    /**
     * Pull SaaS-native leads down, mapped by saas_form_id; dedupe by saas_lead_id.
     * See pull_stats() for the $deadline / return-value contract — identical here.
     */
    private static function pull_leads(array $cfg, ?int $deadline = null): bool
    {
        global $wpdb;
        $leads = $wpdb->prefix . 'emblemstudio_leads';
        $map   = self::saas_form_map();
        if (empty($map)) {
            return true;
        }
        $after = (int) get_option('es_saas_pull_leads_after', 0);
        do {
            $data = self::pull_post($cfg, '/v1/embed/export/leads', ['after_id' => $after]);
            if (empty($data['leads']) || ! is_array($data['leads'])) {
                return true;
            }
            $wpdb->suppress_errors(true);
            foreach ($data['leads'] as $l) {
                $form_id = $map[(int) ($l['saas_form_id'] ?? 0)] ?? 0;
                if (! $form_id || empty($l['saas_lead_id']) || empty($l['email'])) {
                    continue;
                }
                $extra = $l['extra_fields'] ?? null;
                if (is_array($extra)) {
                    $extra = wp_json_encode($extra);
                }
                $signals = $l['bot_signals'] ?? null;
                if (is_array($signals)) {
                    $signals = wp_json_encode($signals);
                }

                $inserted = $wpdb->insert($leads, [
                    'saas_lead_id'         => (int) $l['saas_lead_id'],
                    'form_id'              => $form_id,
                    'email'                => (string) $l['email'],
                    'extra_fields'         => $extra,
                    'funnelkit_contact_id' => isset($l['funnelkit_contact_id']) ? (int) $l['funnelkit_contact_id'] : null,
                    'funnelkit_pushed'     => ! empty($l['funnelkit_pushed']) ? 1 : 0,
                    'error'                => $l['error'] ?? null,
                    'is_bot'               => ! empty($l['is_bot']) ? 1 : 0,
                    'bot_signals'          => $signals,
                    'ip'                   => $l['ip'] ?? null,
                    // The export sends UTC. Everything else in this table is WP
                    // local time (a WordPress-captured lead uses current_time),
                    // and the Leads screen renders the column as local either
                    // way. Storing the raw UTC put every mirrored lead out by the
                    // site's offset, sitting next to native rows that were right.
                    'created_at'           => isset($l['created_at'])
                        ? get_date_from_gmt((string) $l['created_at'])
                        : current_time('mysql'),
                ]);

                // The row is already here from a pull that ran before we carried
                // the bot-check evidence down (the DB v37 upgrade rewinds the
                // cursor once so those rows come past again). Fill ONLY that one
                // column, and only while it is still empty. A plain upsert would
                // be simpler and wrong: it would clobber a lead someone
                // reclassified here, which is a local decision the SaaS copy
                // does not know about.
                if ($inserted === false && $signals !== null) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $leads SET bot_signals = %s WHERE saas_lead_id = %d AND bot_signals IS NULL",
                        $signals,
                        (int) $l['saas_lead_id']
                    ));
                }
            }
            $wpdb->suppress_errors(false);
            $after = (int) ($data['last_id'] ?? $after);
            update_option('es_saas_pull_leads_after', $after, false);
            if (count($data['leads']) < 500) {
                return true;
            }
        } while ($deadline === null || time() < $deadline);

        return false;
    }

    /**
     * Periodic safety net for lead-deletion sync — the instant delete route
     * (handle_lead_delete()) is the primary path; this catches whatever it
     * missed (site was offline, the SaaS-side job exhausted its retries, or
     * this site wasn't even connected yet when the lead was deleted). Same
     * cursor pattern as pull_leads(), over the tombstone log instead of the
     * leads themselves — each row deletes at most one local lead, matched the
     * same way the instant route matches it.
     */
    private static function pull_lead_deletions(array $cfg): void
    {
        if (! class_exists('ES_Leads')) {
            return;
        }
        $after = (int) get_option('es_saas_pull_lead_deletions_after', 0);
        do {
            $data = self::pull_post($cfg, '/v1/embed/export/lead-deletions', ['after_id' => $after]);
            if (empty($data['deletions']) || ! is_array($data['deletions'])) {
                return;
            }
            foreach ($data['deletions'] as $d) {
                $wp_lead_id = isset($d['wp_lead_id']) ? (int) $d['wp_lead_id'] : 0;
                $saas_lead_id = isset($d['saas_lead_id']) ? (int) $d['saas_lead_id'] : 0;
                if ($wp_lead_id) {
                    ES_Leads::delete($wp_lead_id);
                }
                if ($saas_lead_id) {
                    ES_Leads::delete_by_saas_id($saas_lead_id);
                }
            }
            $after = (int) ($data['last_id'] ?? $after);
            update_option('es_saas_pull_lead_deletions_after', $after, false);
        } while (count($data['deletions']) >= 500);
    }

    /** saas_form_id → local form id (only rows we've mapped on a prior asset pull). */
    private static function saas_form_map(): array
    {
        global $wpdb;
        $t   = $wpdb->prefix . 'emblemstudio_forms';
        $map = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is a prefixed constant name.
        foreach ((array) $wpdb->get_results("SELECT id, saas_form_id FROM {$t} WHERE saas_form_id IS NOT NULL") as $row) {
            $map[(int) $row->saas_form_id] = (int) $row->id;
        }

        return $map;
    }

    /** A locally-unique slug (keeps $desired if free, else suffixes; ignores $own_id). */
    private static function unique_slug(string $desired, int $own_id): string
    {
        global $wpdb;
        $t    = $wpdb->prefix . 'emblemstudio_forms';
        $base = sanitize_title($desired);
        if ($base === '') {
            $base = 'form';
        }
        $slug = $base;
        $n    = 2;
        while (true) {
            $hit = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE slug = %s LIMIT 1", $slug));
            if ($hit === 0 || $hit === $own_id) {
                return $slug;
            }
            $slug = $base . '-' . $n;
            ++$n;
        }
    }

    /** A form's revision history (newest first) for the mirror payload. */
    private static function form_revisions(int $form_id): array
    {
        if (! class_exists('ES_Form_Revisions')) {
            return [];
        }
        $out = [];
        foreach ((array) ES_Form_Revisions::list_for_form($form_id) as $rev) {
            if (! is_object($rev) || empty($rev->id)) {
                continue;
            }
            $author = '';
            if (! empty($rev->created_by) && function_exists('get_userdata')) {
                $u = get_userdata((int) $rev->created_by);
                $author = $u ? ($u->display_name ?: $u->user_login) : '';
            }
            $out[] = [
                'wp_revision_id' => (int) $rev->id,
                'html'           => (string) $rev->html,
                'css'            => (string) $rev->css,
                'note'           => isset($rev->note) && $rev->note !== null ? (string) $rev->note : null,
                'author'         => (string) $author,
                'created_at'     => (string) $rev->created_at,
            ];
        }

        return $out;
    }

    /**
     * Remove the stored connection — revert to the Lite plugin, immediately.
     *
     * SER-950: this used to do "one last copy-down" (pull_all — assets, presets,
     * stats, leads, all in one request) synchronously, before severing anything.
     * On a site with enough queued history that single request could outrun PHP's
     * execution limit and fatal — leaving WordPress stuck holding a connection
     * config that could no longer sign in or cleanly finish decoupling either
     * (exactly what happened on tstprep.com, 2026-07-27).
     *
     * Now: sever the LOCAL side first — clearing cron hooks and deleting one
     * option can't meaningfully fail or time out, so the admin reverts to Lite
     * reliably, every time. The copy-down (and revoking the SaaS-side token)
     * moves to a resumable background job (run_postdecouple_pull()) that starts
     * right after, using the same bounded-pass pattern as the connect-time
     * import — safe to take several passes on a site with real history, and it
     * never blocks this action from completing.
     */
    public static function handle_decouple(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'emblem-studio'), '', ['response' => 403]);
        }
        check_admin_referer('es_decouple');

        $cfg = self::config();

        wp_clear_scheduled_hook('es_saas_pull');
        // Stop any in-flight import and forget its cursor, so a later reconnect
        // starts a clean run instead of resuming into a severed connection.
        wp_clear_scheduled_hook('es_saas_import');

        delete_option(self::OPTION);
        delete_option('es_import_state');
        delete_option('es_import_status');
        delete_transient(self::FORMS_TRANSIENT);
        delete_transient(self::IMPORT_LOCK);

        // The SaaS-side token stays valid until the background job below either
        // finishes or gives up — deliberately, so it can keep authenticating
        // across passes even though the local connection config is already gone.
        if ($cfg) {
            self::start_postdecouple_pull($cfg);
        }

        wp_safe_redirect(add_query_arg('es_decoupled', '1', admin_url('admin.php?page=emblem-studio')));
        exit;
    }

    /** Arm the post-decouple background catch-up and kick its first pass immediately. */
    private static function start_postdecouple_pull(array $cfg): void
    {
        update_option(self::POSTDECOUPLE_OPTION, $cfg, false);
        update_option(self::POSTDECOUPLE_STATE, ['stage' => 'assets', 'attempts' => 0], false);
        wp_schedule_single_event(time(), self::POSTDECOUPLE_HOOK);
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Best-effort, resumable: finish copying the site's history down to
     * WordPress after decoupling, then revoke the SaaS-side token. Runs
     * entirely in the background — the admin already saw the disconnected/Lite
     * screen before this ever starts, so nothing here can block or crash it.
     * Bounded to IMPORT_PASS_SECONDS per pass, same as the connect-time import,
     * and gives up (revoking anyway) after POSTDECOUPLE_MAX_ATTEMPTS passes so a
     * stuck pull can't leave the token valid indefinitely.
     */
    public static function run_postdecouple_pull(): void
    {
        $cfg = get_option(self::POSTDECOUPLE_OPTION);
        if (! is_array($cfg) || empty($cfg['site_token'])) {
            return; // nothing queued, or a prior pass already finished + cleaned up
        }
        $state = get_option(self::POSTDECOUPLE_STATE);
        if (! is_array($state) || empty($state['stage'])) {
            $state = ['stage' => 'assets', 'attempts' => 0];
        }
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;

        $deadline = time() + self::IMPORT_PASS_SECONDS;
        try {
            while ($state['stage'] !== 'done' && time() < $deadline) {
                switch ($state['stage']) {
                    case 'assets':
                        self::pull_assets($cfg);
                        $state['stage'] = 'presets';
                        break;
                    case 'presets':
                        self::pull_presets($cfg);
                        $state['stage'] = 'stats';
                        break;
                    case 'stats':
                        $state['stage'] = self::pull_stats($cfg, $deadline) ? 'leads' : 'stats';
                        break;
                    case 'leads':
                        $state['stage'] = self::pull_leads($cfg, $deadline) ? 'done' : 'leads';
                        break;
                    default:
                        $state['stage'] = 'done';
                }
            }
        } catch (\Throwable $e) {
            // Best-effort only — fall through; a later pass (or the attempts cap
            // below) resolves it either way, so nothing here can wedge forever.
        }

        if ($state['stage'] === 'done' || $state['attempts'] >= self::POSTDECOUPLE_MAX_ATTEMPTS) {
            self::revoke_connection($cfg);
            delete_option(self::POSTDECOUPLE_OPTION);
            delete_option(self::POSTDECOUPLE_STATE);

            return;
        }

        update_option(self::POSTDECOUPLE_STATE, $state, false);
        wp_schedule_single_event(time() + 5, self::POSTDECOUPLE_HOOK);
    }

    /** Ask the SaaS to sever this site's connection (rotate its secret). Best-effort. */
    private static function revoke_connection(?array $cfg): void
    {
        if (! $cfg || empty($cfg['api_base']) || empty($cfg['site_id']) || empty($cfg['site_token'])) {
            return;
        }
        wp_remote_post($cfg['api_base'].'/v1/embed/connect/disconnect', [
            'timeout' => 8,
            'body'    => [
                'site_id' => $cfg['site_id'],
                'token'   => $cfg['site_token'],
            ],
        ]);
    }

    /** A gentle nudge to connect, shown on Emblem Studio admin pages when not connected. */
    public static function connect_notice(): void
    {
        if (self::is_connected() || ! current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || strpos((string) $screen->id, 'emblem-studio') === false) {
            return;
        }
        if (isset($_GET['page']) && $_GET['page'] === 'emblem-studio-account') {
            return; // already on the connect screen
        }
        $url = admin_url('admin.php?page=emblem-studio-account');
        echo '<div class="notice notice-info"><p>'
            .esc_html__('Sign in to Emblem Studio to manage this site’s forms and reports right here in WordPress.', 'emblem-studio')
            .' <a href="'.esc_url($url).'">'.esc_html__('Sign in', 'emblem-studio').'</a></p></div>';
    }

    /* ── Front-end: context + form slots + embed.js ────────────────────── */

    /** Shells that place themselves on the page instead of sitting where an author put them. */
    private const AUTO_SHELLS = ['popup-auto', 'ribbon', 'slide-in'];

    /** Is FunnelKit Automations available to write contacts in-process? */
    private static function funnelkit_available(): bool
    {
        return class_exists('BWFCRM_Contact');
    }

    /** The site's live role registry — [{slug,label}, …], custom roles included. */
    private static function roles(): array
    {
        if (! function_exists('wp_roles')) {
            return [];
        }
        $out = [];
        foreach (wp_roles()->get_names() as $slug => $label) {
            $out[] = ['slug' => (string) $slug, 'label' => (string) translate_user_role($label)];
        }

        return $out;
    }

    public static function render(): void
    {
        $cfg = self::config();
        if (! $cfg) {
            return; // not connected — nothing to inject.
        }
        if (is_admin() || is_feed() || is_embed()) {
            return;
        }
        // Don't interfere with the free plugin's own form-preview page.
        if (! empty($_GET['es_preview'])) {
            return;
        }

        $forms = self::forms($cfg);

        // A page builder opens its canvas as an <iframe> pointing at the real
        // page, so is_admin() above is false inside it and the self-placing
        // shells would land on top of the widgets the author is editing. See
        // ES_Display_Rules::is_admin_frame() for how that is spotted without a
        // list of builders. Inline placements still go out: the author put those
        // on the page, and they are part of what is being laid out.
        $admin_frame = class_exists('ES_Display_Rules') && ES_Display_Rules::is_admin_frame();

        $config = [
            'api'       => $cfg['api_base'].'/v1',
            'wpContext' => self::context(),
        ];

        // Where the shop half listens (SER-1348). Only on a site with a shop, and
        // only used when a visitor is actually carrying a tag: it is the safety net
        // for a basket filled by a path the page could not see.
        if (class_exists('WooCommerce')) {
            $config['ajax'] = admin_url('admin-ajax.php');
        }

        // The site's public code, which is what lets the SaaS runtime ask for the
        // whole page in one request instead of downloading every asset and only
        // then deciding which ones belong (SaaS SER-1230). Without it that runtime
        // falls back to the old path, so WordPress would have been the one
        // platform still paying seven requests to show three assets — and it is
        // the platform the problem was reported on.
        //
        // Not a secret: it is this site's segment in every dashboard URL, and it
        // already sits in the page source of any plain site using the script tag.
        //
        // The placements printed below ride along as the page's own keys, so an
        // asset already in the page is matched rather than added a second time.
        // Paired with wpContext above it also lets the server decide the
        // WordPress-specific rules, which no plain site can give it.
        if (! empty($cfg['code'])) {
            $config['site'] = (string) $cfg['code'];
        }

        // Same opt-out on the config: an optimizer that encodes this inline
        // script leaves embed.js with no api base to read.
        echo '<script data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1">window.EmblemStudio = Object.assign(window.EmblemStudio || {}, '
            .wp_json_encode($config).');</script>'."\n";

        foreach ($forms as $form) {
            // Name the asset by its opaque public code, not the SaaS row id: the
            // id is one counter shared across every account, so printing it into
            // live page source leaks how much exists platform-wide and can be
            // walked to enumerate a site (SER-1128). Falls back to the id when
            // the SaaS hasn't sent a code — an older SaaS, or a cached embed
            // payload from before it started sending one. The SaaS accepts both
            // until stage 4, which only stops accepting the id for sites whose
            // reported connector version proves they no longer emit it.
            $key = isset($form['public_key']) && $form['public_key'] !== ''
                ? (string) $form['public_key']
                : (string) (isset($form['id']) ? (int) $form['id'] : 0);

            if ($key === '' || $key === '0' || empty($form['shells'])) {
                continue;
            }
            foreach ((array) $form['shells'] as $shell) {
                if ($admin_frame && in_array($shell, self::AUTO_SHELLS, true)) {
                    continue;
                }
                printf(
                    '<div data-es-form="%s" data-es-shell="%s" style="display:none"></div>'."\n",
                    esc_attr($key),
                    esc_attr((string) $shell)
                );
            }
        }

        // Speed plugins delay or defer third-party JavaScript by default, and
        // several of them rewrite this tag's src to data-src and hold the load
        // until the visitor scrolls or moves the mouse. On a page whose asset is
        // above the fold, that is the asset not loading at all until the visitor
        // touches something. These attributes are the opt-out that Cloudflare's
        // Rocket Loader, Autoptimize, WP Rocket and their relatives honour.
        //
        // They do NOT cover every plugin: some exclude by URL from a settings
        // list and ignore markup entirely. The durable fix is not needing this
        // script for first paint at all, which means WordPress rendering the
        // inline asset itself from the local copy pull_assets() already keeps.
        $skip = ' data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1"';

        printf('<script%s src="%s" defer></script>'."\n", $skip, esc_url($cfg['api_base'].'/embed/embed.js'));
    }

    /**
     * The page's WordPress context — a direct mirror of the matchers in the free
     * plugin's ES_Display_Rules (specials / post type / terms / WooCommerce /
     * logged-in + roles). embed.js evaluates the WP-context rules against this.
     */
    private static function context(): array
    {
        $singular = is_singular();
        $qid      = (int) get_queried_object_id();

        $ctx = [
            'is_front_page' => is_front_page(),
            'is_home'       => is_home(),
            'is_404'        => is_404(),
            'is_search'     => is_search(),
            'is_archive'    => is_archive(),
            'is_singular'   => $singular,
            'post_type'     => $singular ? (string) get_post_type() : null,
            'post_id'       => $singular ? $qid : 0,
            'is_category'   => is_category(),
            'is_tag'        => is_tag(),
            'term_id'       => (is_category() || is_tag()) ? $qid : 0,
            'category_ids'  => is_singular('post') ? array_map('intval', (array) wp_get_post_categories($qid)) : [],
            'tag_ids'       => is_singular('post') ? array_map('intval', (array) wp_get_post_tags($qid, ['fields' => 'ids'])) : [],
            'logged_in'     => is_user_logged_in(),
            'roles'         => is_user_logged_in() ? array_values((array) wp_get_current_user()->roles) : [],
        ];

        if (class_exists('WooCommerce')) {
            $ctx['wc'] = [
                'cart'             => function_exists('is_cart') && is_cart(),
                'checkout'         => function_exists('is_checkout') && is_checkout(),
                'account'          => function_exists('is_account_page') && is_account_page(),
                'shop'             => function_exists('is_shop') && is_shop(),
                'product'          => function_exists('is_product') && is_product(),
                'product_archive'  => function_exists('is_product_taxonomy') && is_product_taxonomy(),
                'product_category' => function_exists('is_product_category') && is_product_category(),
                'product_tag'      => function_exists('is_product_tag') && is_product_tag(),
            ];
        }

        return $ctx;
    }

    /**
     * The site's embed payload from the SaaS — active auto-shell forms (`data`)
     * plus the WP-id→SaaS-id `wp_map` (so shortcodes render from the SaaS). Cached
     * ~5 minutes; the token-authed call doubles as the connector heartbeat.
     * Returns ['data'=>[], 'wp_map'=>[]] on any failure (page still renders).
     */
    private static function fetch_embed(array $cfg): array
    {
        $cached = get_transient(self::FORMS_TRANSIENT);
        if (is_array($cached) && isset($cached['data'])) {
            return $cached;
        }

        // `v` rides the heartbeat this call already carries, so the SaaS knows
        // which connector build a site is actually running. That's what lets
        // SER-1128 stage 4 stop accepting the SaaS row id per-site, for sites
        // proven to have stopped emitting it, instead of one global switch that
        // would take down every site that hasn't updated yet.
        $url = $cfg['api_base'].'/v1/sites/'.$cfg['site_id']
            .'/embed?token='.rawurlencode($cfg['site_token'])
            .'&funnelkit='.(self::funnelkit_available() ? '1' : '0')
            .'&v='.rawurlencode(self::VERSION);

        $res = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => ['X-ESS-Roles' => wp_json_encode(self::roles())],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            $empty = ['data' => [], 'wp_map' => []];
            set_transient(self::FORMS_TRANSIENT, $empty, 60); // brief negative cache

            return $empty;
        }

        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        $out  = [
            'data'   => (is_array($body) && isset($body['data']) && is_array($body['data'])) ? $body['data'] : [],
            'wp_map' => (is_array($body) && isset($body['wp_map']) && is_array($body['wp_map'])) ? $body['wp_map'] : [],
        ];
        set_transient(self::FORMS_TRANSIENT, $out, 5 * MINUTE_IN_SECONDS);

        return $out;
    }

    /** Active auto-shell forms to place as slots. */
    private static function forms(array $cfg): array
    {
        return self::fetch_embed($cfg)['data'];
    }

    /**
     * Resolve a WordPress form id to its SaaS id, or 0 if not connected / unmapped.
     * Used by ES_Shortcode so an `[es-inline-N]` placed on a page renders the SaaS
     * form (live dashboard edits) instead of the local copy.
     */
    public static function saas_form_id(int $wp_id): int
    {
        $cfg = self::config();
        if (! $cfg || $wp_id <= 0) {
            return 0;
        }
        $map = self::fetch_embed($cfg)['wp_map'];

        return isset($map[$wp_id]) ? (int) $map[$wp_id] : 0;
    }

    /* ── Inbound lead relay → local FunnelKit ──────────────────────────── */

    public static function register_routes(): void
    {
        register_rest_route('emblem-studio-saas/v1', '/lead', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'receive_lead'],
        ]);

        // Instant half of lead-deletion sync — fired the moment a lead is
        // deleted in the SaaS (see Lead::booted() there). The periodic
        // pull_lead_deletions() reconciliation is the fallback if this never
        // arrives.
        register_rest_route('emblem-studio-saas/v1', '/lead/delete', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'handle_lead_delete'],
        ]);

        register_rest_route('emblem-studio-saas/v1', '/funnelkit/meta', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'funnelkit_meta'],
        ]);

        register_rest_route('emblem-studio-saas/v1', '/funnelkit/dispatch', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'dispatch_funnelkit'],
        ]);

        // Read this site's own content (post types, terms, pages) for the SaaS's
        // display-rule pickers and strategist. See read_wp() for why this exists.
        register_rest_route('emblem-studio-saas/v1', '/wp/(?P<path>[a-z]+(?:/\d+)?)', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'read_wp'],
        ]);

        // Read the store's prices (SER-1345). Two routes, because "which product
        // is that" and "what does that one cost" are different questions, and the
        // first is asked by name.
        register_rest_route('emblem-studio-saas/v1', '/commerce/products', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'read_products'],
        ]);

        register_rest_route('emblem-studio-saas/v1', '/commerce/product/(?P<id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'read_product'],
        ]);

        // What a coupon really takes off one product. Measured by applying it,
        // never by reading its settings. See ES_Commerce::coupon_value().
        register_rest_route('emblem-studio-saas/v1', '/commerce/coupon', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'read_coupon'],
        ]);
    }

    /** Authenticate the SaaS by the shared site token (constant-time compare). */
    public static function authorize(WP_REST_Request $request): bool
    {
        $cfg = self::config();
        if (! $cfg) {
            return false;
        }
        $token = (string) $request->get_header('x_ess_token');

        return $token !== '' && hash_equals($cfg['site_token'], $token);
    }

    /* ── Inbound content read → this site's own wp/v2 ──────────────────── */

    /**
     * The only wp/v2 routes the SaaS reads, and the only ones this will serve.
     * An allowlist, not a proxy: the site token authenticates Emblem Studio, and
     * Emblem Studio has no business reading anything else through it.
     */
    private const WP_READ_PATHS = [ 'types', 'categories', 'tags', 'pages', 'posts' ];

    /**
     * Serve this site's own content to the SaaS, authenticated by the site token.
     *
     * Why this route exists at all, when wp/v2 already exists: the SaaS used to
     * read wp/v2 over HTTP with a WordPress Application Password, which meant a
     * paired site STILL had to be handed a password before its display-rule
     * pickers or the strategist could see a single page. That is a credential the
     * plugin makes unnecessary — it is already inside WordPress, already
     * authenticated by the site token it was paired with.
     *
     * So: dispatch to wp/v2 IN-PROCESS via rest_do_request() and hand back its
     * response verbatim. No password, and the SaaS keeps parsing the exact wp/v2
     * shapes it always did — the transport changes, the contract doesn't. Reading
     * internally also survives the security plugins that block /wp-json to the
     * outside world, which is the other half of why the password was there.
     *
     * Runs as no user, so WordPress applies public visibility: published content
     * only. That is all the SaaS ever asked for (every call passes status=publish)
     * and it means this route cannot leak a draft.
     */
    public static function read_wp(WP_REST_Request $request)
    {
        $path = (string) $request->get_param('path');
        $base = strtok($path, '/'); // 'pages/12' → 'pages'

        if (! in_array($base, self::WP_READ_PATHS, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Unsupported path'], 404);
        }

        $sub = new WP_REST_Request('GET', '/wp/v2/' . $path);
        foreach ((array) $request->get_query_params() as $k => $v) {
            if ($k !== 'path') {
                $sub->set_param($k, $v);
            }
        }

        $res = rest_do_request($sub);
        if ($res->is_error()) {
            $err = $res->as_error();

            return new WP_REST_Response(
                ['ok' => false, 'error' => $err->get_error_message()],
                (int) ($res->get_status() ?: 500)
            );
        }

        return new WP_REST_Response($res->get_data(), (int) $res->get_status());
    }

    /* ── Inbound store read → WooCommerce (SER-1345) ───────────────────── */

    /**
     * HTTP status here answers "did the call work", not "is there a shop".
     *
     * A site with no WooCommerce is a perfectly good answer to the question, and
     * the SaaS needs to be able to say so in plain words. If that came back as a
     * 404, the relay would throw away the body and report a transport failure, so
     * the dashboard would blame the connection instead of the missing shop. A
     * product id that does not exist IS a 404, because that one is a bad ask.
     */
    private static function commerce_status(array $result): int
    {
        if ($result['ok'] ?? false) {
            return 200;
        }

        return ($result['error'] ?? '') === 'not_found' ? 404 : 200;
    }

    /**
     * Run one store read and turn it into a response nobody may cache.
     *
     * NO CACHING, because a cached price is a wrong price. TST Prep runs Cloudflare
     * and a page-caching plugin, and a CDN rule that caches GET under /wp-json
     * would hand the SaaS yesterday's tier with today's timestamp missing. The
     * site token rides in a header most caches do not key on, so the risk is real
     * even though the route is authenticated.
     *
     * GUARDED, because reading a price runs every price filter every plugin on
     * the site registered, and one of them failing outside a checkout must not
     * become a 500 that the dashboard reports as a broken connection. Measuring a
     * coupon has its own guard inside ES_Commerce; this covers the plain reads.
     */
    private static function commerce_response(callable $read): WP_REST_Response
    {
        try {
            $result = (array) $read();
        } catch (\Throwable $e) {
            $result = [
                'ok'      => false,
                'error'   => 'cannot_read',
                'message' => 'Code on this site failed while the shop was being read, so there is nothing reliable to report.',
            ];
        }

        $response = new WP_REST_Response($result, self::commerce_status($result));
        $response->header('Cache-Control', 'no-store, private, max-age=0');

        return $response;
    }

    /**
     * Find products by name, so the caller can turn "Group Classes" into an id.
     *
     * An empty search lists the shop's products, which is what somebody meeting a
     * new site does first.
     */
    public static function read_products(WP_REST_Request $request)
    {
        if (! class_exists('ES_Commerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unsupported', 'message' => 'This site runs an older Emblem Studio that cannot read the store.'], 200);
        }

        $term  = sanitize_text_field((string) $request->get_param('search'));
        $limit = (int) ($request->get_param('limit') ?: 10);

        return self::commerce_response(fn () => ES_Commerce::search($term, $limit));
    }

    /**
     * What a coupon code takes off a product. The code is passed through as
     * typed: WooCommerce normalises coupon codes itself, and doing it twice is
     * how a code with a capital letter stops matching.
     *
     * There is deliberately no route that LISTS coupons. A shop's codes include
     * ones meant for staff, partners and apologies, and the caller has to already
     * know a code to ask about it.
     */
    public static function read_coupon(WP_REST_Request $request)
    {
        if (! class_exists('ES_Commerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unsupported', 'message' => 'This site runs an older Emblem Studio that cannot read the store.'], 200);
        }

        $code     = sanitize_text_field((string) $request->get_param('code'));
        $product  = (int) $request->get_param('product_id');
        $quantity = (int) ($request->get_param('quantity') ?: 1);

        return self::commerce_response(fn () => ES_Commerce::coupon_value($code, $product, $quantity));
    }

    /** One product's prices, its sale window, and its variations if it has any. */
    public static function read_product(WP_REST_Request $request)
    {
        if (! class_exists('ES_Commerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unsupported', 'message' => 'This site runs an older Emblem Studio that cannot read the store.'], 200);
        }

        $id = (int) $request->get_param('id');

        return self::commerce_response(fn () => ES_Commerce::product($id));
    }

    /** Versioned local FunnelKit capabilities and runtime Form Feed state. */
    public static function funnelkit_meta(WP_REST_Request $request)
    {
        $catalog = class_exists('ES_FunnelKit_Form_Feed') ? ES_FunnelKit_Form_Feed::catalog() : [];
        $assets  = [];
        foreach ($catalog as $asset) {
            $key = (string) ($asset['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $assets[] = array_merge($asset, [
                'feed' => ES_FunnelKit_Form_Feed::status($key),
            ]);
        }
        $fields = [];
        foreach (ES_FunnelKit::get_all_fields_grouped() as $group) {
            foreach ((array) ($group['fields'] ?? []) as $field) {
                $fields[] = $field;
            }
        }

        return new WP_REST_Response([
            'ok'               => true,
            'contract_version' => 1,
            'connector_version'=> '1.0.24-beta',
            'funnelkit'        => [
                'available' => self::funnelkit_available(),
                'pro'       => defined('BWFAN_PRO_VERSION') || class_exists('BWFAN_Pro'),
            ],
            'capabilities'     => [
                'direct_upsert' => class_exists('ES_FunnelKit_Upsert'),
                'form_feeds'    => class_exists('ES_FunnelKit_Form_Feed'),
                'idempotency'   => true,
            ],
            'lists'            => ES_FunnelKit::get_lists(),
            'tags'             => ES_FunnelKit::get_tags(),
            'fields'           => $fields,
            'assets'           => $assets,
        ], 200);
    }

    /** Unified local dispatch: an active native feed wins; direct sync is fallback. */
    public static function dispatch_funnelkit(WP_REST_Request $request)
    {
        if (! self::funnelkit_available() || ! class_exists('ES_FunnelKit_Delivery')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'FunnelKit not installed'], 503);
        }

        $p             = (array) $request->get_json_params();
        $submission_id = sanitize_key((string) ($p['submission_id'] ?? ''));
        $asset_key     = sanitize_text_field((string) ($p['asset_key'] ?? ''));
        if ($submission_id === '' || ! preg_match('/^(?:saas|wp):\d+$/', $asset_key)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid dispatch identity'], 400);
        }

        $idempotency_key = 'ess_fk_' . md5($submission_id . '|' . $asset_key);
        $cached = get_transient($idempotency_key);
        if (is_array($cached)) {
            $cached['idempotent_replay'] = true;
            $cached_status = ! empty($cached['ok']) || ($cached['mode'] ?? '') === 'disabled' ? 200 : 500;
            return new WP_REST_Response($cached, $cached_status);
        }

        $direct = self::normalize_direct_lead((array) ($p['direct'] ?? []));
        if (empty($direct['email']) || ! is_email($direct['email'])) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid email'], 400);
        }
        $fields = self::sanitize_submission_fields((array) ($p['fields'] ?? []));
        if (! isset($fields['email'])) {
            $fields['email'] = $direct['email'];
        }

        $lock_key = 'ess_fk_lock_' . md5($submission_id . '|' . $asset_key);
        $locked = add_option($lock_key, time(), '', false);
        if (! $locked && time() - (int) get_option($lock_key, 0) > 120) {
            delete_option($lock_key);
            $locked = add_option($lock_key, time(), '', false);
        }
        if (! $locked) {
            $cached = get_transient($idempotency_key);
            if (is_array($cached)) {
                $cached['idempotent_replay'] = true;
                $cached_status = ! empty($cached['ok']) || ($cached['mode'] ?? '') === 'disabled' ? 200 : 500;
                return new WP_REST_Response($cached, $cached_status);
            }
            return new WP_REST_Response([
                'ok' => false,
                'mode' => 'in_progress',
                'contact_id' => null,
                'error' => 'This submission is already being processed. Retry shortly.',
            ], 409);
        }

        try {
            $result = ES_FunnelKit_Delivery::dispatch(
                $asset_key,
                $fields,
                $direct,
                ! empty($p['direct_enabled'])
            );
            $result['contract_version'] = 1;
            $result['submission_id']    = $submission_id;
            $result['asset_key']        = $asset_key;
            $result['idempotent_replay']= false;
            set_transient($idempotency_key, $result, DAY_IN_SECONDS);

            return new WP_REST_Response($result, ! empty($result['ok']) || $result['mode'] === 'disabled' ? 200 : 500);
        } finally {
            delete_option($lock_key);
        }
    }

    private static function sanitize_submission_fields(array $fields): array
    {
        $clean = [];
        foreach ($fields as $key => $value) {
            $key = sanitize_text_field((string) $key);
            if ($key === '') {
                continue;
            }
            $clean[$key] = self::sanitize_field_value($value);
        }
        return $clean;
    }

    /**
     * One answer the SaaS forwards for FunnelKit. A ticked checkbox group is a
     * LIST and must stay one all the way to ES_FunnelKit_Upsert, which turns it
     * into what FunnelKit stores (SER-1370). Same rule and caps as a submission
     * taken on this site: each item cleaned, 100 items, 5000 characters each,
     * and its line breaks kept (SER-1384), so a long answer written in paragraphs
     * reaches FunnelKit in paragraphs.
     *
     * @param mixed $value
     * @return string|array<int, string>
     */
    private static function sanitize_field_value($value)
    {
        return ES_Ajax::sanitize_field_value($value);
    }

    private static function normalize_direct_lead(array $p): array
    {
        $custom = [];
        foreach ((array) ($p['custom'] ?? $p['custom_fields'] ?? []) as $key => $value) {
            if (is_numeric($key) && $value !== '' && $value !== null) {
                $custom[(int) $key] = self::sanitize_field_value($value);
            }
        }

        return [
            'email'      => sanitize_email($p['email'] ?? ''),
            'first_name' => sanitize_text_field($p['first_name'] ?? $p['f_name'] ?? ''),
            'last_name'  => sanitize_text_field($p['last_name'] ?? $p['l_name'] ?? ''),
            'phone'      => sanitize_text_field($p['phone'] ?? $p['contact_no'] ?? ''),
            'country'    => sanitize_text_field($p['country'] ?? ''),
            'state'      => sanitize_text_field($p['state'] ?? ''),
            'timezone'   => sanitize_text_field($p['timezone'] ?? ''),
            'tags'       => array_values(array_filter(array_map('sanitize_text_field', (array) ($p['tags'] ?? [])))),
            'lists'      => array_values(array_filter(array_map('absint', (array) ($p['lists'] ?? [])))),
            'slugs'      => self::sanitize_submission_fields((array) ($p['slugs'] ?? [])),
            'custom'     => $custom,
        ];
    }

    /**
     * Create the contact in FunnelKit in-process — mirrors the autonami-app
     * create-contact route, so the payload is identical to the SaaS REST push.
     */
    public static function receive_lead(WP_REST_Request $request)
    {
        if (! self::funnelkit_available() || ! class_exists('ES_FunnelKit_Upsert')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'FunnelKit not installed'], 503);
        }

        $p     = (array) $request->get_json_params();
        $email = sanitize_email($p['email'] ?? '');
        if (! is_email($email)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid email'], 400);
        }

        $custom = [];
        foreach ($p as $k => $v) {
            if (is_numeric($k)) {
                $custom[(int) $k] = self::sanitize_field_value($v);
            }
        }

        $result = ES_FunnelKit_Upsert::run([
            'email'      => $email,
            'first_name' => sanitize_text_field($p['f_name'] ?? ''),
            'last_name'  => sanitize_text_field($p['l_name'] ?? ''),
            'phone'      => sanitize_text_field($p['contact_no'] ?? ''),
            'tags'       => array_values(array_filter(array_map('sanitize_text_field', (array) ($p['tags'] ?? [])))),
            'lists'      => array_values(array_filter(array_map('absint', (array) ($p['lists'] ?? [])))),
            'custom'     => $custom,
        ]);

        return new WP_REST_Response($result, ! empty($result['ok']) ? 200 : 500);
    }

    /**
     * Delete the local copy of one lead, pushed instantly from the SaaS the
     * moment it's deleted there. Whichever id is present is matched: wp_lead_id
     * when this lead originated in WordPress (its own local id), saas_lead_id
     * when it didn't but was mirrored down here by pull_leads(). Idempotent —
     * deleting an already-gone or never-mirrored row is a harmless no-op, since
     * the periodic pull_lead_deletions() reconciliation may also reach the same
     * row independently.
     */
    public static function handle_lead_delete(WP_REST_Request $request)
    {
        if (! class_exists('ES_Leads')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Leads not available'], 503);
        }

        $p = (array) $request->get_json_params();
        $wp_lead_id = isset($p['wp_lead_id']) ? (int) $p['wp_lead_id'] : 0;
        $saas_lead_id = isset($p['saas_lead_id']) ? (int) $p['saas_lead_id'] : 0;

        $deleted = false;
        if ($wp_lead_id) {
            $deleted = ES_Leads::delete($wp_lead_id) || $deleted;
        }
        if ($saas_lead_id) {
            $deleted = ES_Leads::delete_by_saas_id($saas_lead_id) || $deleted;
        }

        return new WP_REST_Response(['ok' => true, 'deleted' => $deleted], 200);
    }
}

Emblem_Studio_SaaS_Connector::init();
