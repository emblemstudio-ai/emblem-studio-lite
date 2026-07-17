<?php
/**
 * Plugin Name: Emblem Studio SaaS Connector
 * Description: Bridge between a WordPress site and the Emblem Studio SaaS. Powers the Lite plugin's "Connect a free account" flow (pairing + embedded dashboard), injects the page's WordPress context so SaaS display rules enforce on this site, places the site's auto-shell forms, and relays leads to FunnelKit locally. The free standalone Emblem Studio plugin does NOT need this — it's bundled into the Lite edition only.
 * Version: 0.8.2
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
 * This file is maintained in the SaaS repo (wp-connector/) and folded into the
 * Lite build as includes/connector.php by bin/build-lite.sh.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Emblem_Studio_SaaS_Connector
{
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
    const DEFAULT_API_BASE = 'https://emblem-studio-beta.serenichron.agency';

    /** WP admin page slug → embedded SaaS path (under /app/s/<code>/…). */
    const PAGE_MAP = [
        'emblem-studio'              => 'assets',     // Forms & Assets
        'emblem-studio-new'          => 'assets/new', // Add New
        'emblem-studio-tests'        => 'tests',      // A/B Tests
        'emblem-studio-leads'        => 'leads',
        'emblem-studio-reports'      => 'reports',
        'emblem-studio-integrations' => 'integrations',
        'emblem-studio-design-system' => 'design-system',
        'emblem-studio-settings'     => 'settings',
    ];

    /** Set true when a shortcode places a SaaS slot, so render() loads embed.js. */
    private static $embed_requested = false;

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
        $code = $session['code'] ?: ($cfg['code'] ?: '');
        self::render_embed($cfg['api_base'], $code, $path, (string) $session['embed_token']);

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
    private static function render_embed(string $api, string $code, string $path, string $token): void
    {
        // Token rides the URL fragment (#est=…) — never sent to a server or logged;
        // the SaaS reads it, stores it in the iframe's sessionStorage, scrubs it.
        $src = $api.'/app/s/'.rawurlencode($code).'/'.$path.'?embed=1#est='.rawurlencode($token);
        // The SaaS origin we accept fullscreen messages from.
        $p = wp_parse_url($api);
        $origin = (isset($p['scheme']) ? $p['scheme'] : 'https').'://'.(isset($p['host']) ? $p['host'] : '')
            .(isset($p['port']) ? ':'.$p['port'] : '');
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
            .es-embed-frame { display: block; width: 100%; height: calc(100vh - 32px); border: 0; margin: 0; }
            @media screen and (max-width: 782px) { .es-embed-frame { height: calc(100vh - 46px); } }
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
        <iframe id="es-embed-frame" class="es-embed-frame" src="<?php echo esc_url($src); ?>" title="Emblem Studio" allow="clipboard-write"></iframe>
        <script>
        (function () {
            var ORIGIN = <?php echo wp_json_encode($origin); ?>;
            var frame = document.getElementById('es-embed-frame');
            window.addEventListener('message', function (e) {
                if (e.origin !== ORIGIN || !frame) { return; }
                var d = e.data || {};
                if (d.source !== 'emblem-studio' || d.type !== 'chrome') { return; }
                frame.classList.toggle('es-embed-fullscreen', !!d.fullscreen);
            });
        })();
        </script>
        <?php if (get_option('es_import_status') === 'running') : ?>
        <div id="es-import-overlay" class="es-import-overlay" role="status" aria-live="polite">
            <div class="es-import-card">
                <div class="es-import-spinner"></div>
                <h2><?php esc_html_e('Setting up your account…', 'emblem-studio'); ?></h2>
                <p><?php esc_html_e('Importing your assets, stats and leads from this site. This can take a moment on larger sites. You can leave this page — the import keeps running without you, and it’ll be done when you come back.', 'emblem-studio'); ?></p>
            </div>
        </div>
        <script>
        (function () {
            var overlay = document.getElementById('es-import-overlay');
            if (!overlay) { return; }
            var ajax  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('es_import_status')); ?>;
            var frame = document.getElementById('es-embed-frame');
            var tries = 0;
            function finish() {
                overlay.classList.add('es-hide');
                if (frame) { frame.src = frame.src; } // reload so the imported data shows
                setTimeout(function () { if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); } }, 450);
            }
            function poll() {
                // Safety: give up after ~90s so the screen never sticks (e.g. if WP-cron is off).
                if (tries++ > 45) { finish(); return; }
                fetch(ajax + '?action=es_import_status&nonce=' + encodeURIComponent(nonce), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success && j.data && j.data.status === 'done') { finish(); }
                        else { setTimeout(poll, 2000); }
                    })
                    .catch(function () { setTimeout(poll, 3000); });
            }
            setTimeout(poll, 1500);
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
            <h1><?php esc_html_e('Emblem Studio account', 'emblem-studio'); ?></h1>
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
                <?php if (self::connected_via() === 'constants') : ?>
                    <p style="color:#646970;font-size:12px;margin:0 0 4px;">
                        <?php esc_html_e('This connection is set in wp-config.php (ESS_API_BASE / ESS_SITE_ID / ESS_SITE_TOKEN). To disconnect, remove those constants.', 'emblem-studio'); ?>
                    </p>
                <?php else : ?>
                    <p style="color:#646970;font-size:12px;margin:0 0 20px;">
                        <?php esc_html_e('Decoupling disconnects this site and reverts the dashboard to the built-in Lite plugin. Your account and its assets stay safe in Emblem Studio — you can reconnect anytime.', 'emblem-studio'); ?>
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
                <h1 style="font-size:24px;margin:0 0 8px;"><?php esc_html_e('Connect to Emblem Studio', 'emblem-studio'); ?></h1>
                <p style="color:#50575e;font-size:14px;margin:0 0 8px;">
                    <?php esc_html_e('Sign in to your Emblem Studio account to manage this site’s forms, popups and reports right here in WordPress.', 'emblem-studio'); ?>
                </p>
                <?php // TODO (Multica SER-749): once the beta landing page is live, link "ask for beta access" to it (jump to the signup form) for people without an account. ?>
                <p style="color:#646970;font-size:12px;margin:0 0 22px;">
                    <?php esc_html_e('A secure Emblem Studio sign-in window will open. The beta is invite-only.', 'emblem-studio'); ?>
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
        $confirm = esc_js(__('Decouple this site? The dashboard will switch back to the WordPress Lite plugin. Your Emblem Studio account and assets are kept — you can reconnect anytime.', 'emblem-studio'));

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

        // Import (mirror up + copy down) runs in the background so the dashboard
        // shows a loading screen instead of blocking (see run_import + render_embed).
        update_option('es_import_status', 'running');
        wp_schedule_single_event(time(), 'es_saas_import');

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
        $rows  = ES_Form_Repo::all('all'); // every non-trashed form, all statuses
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

        wp_remote_post(rtrim($cfg['api_base'], '/').'/v1/embed/mirror', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'site_id' => $cfg['site_id'],
                'token'   => $cfg['site_token'],
                'forms'   => $forms,
            ]),
        ]);
    }

    /**
     * Stream this site's analytics events to the SaaS in id-ordered batches
     * (mirror-on-connect). Idempotent server-side by (form_id, wp_event_id), so a
     * re-connect is safe. Best-effort — a failure never blocks connecting, and a
     * timeout just leaves a gap the next connect / periodic sync fills.
     */
    private static function mirror_stats(array $cfg): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'emblemstudio_events';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $last = 0;
        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a prefixed constant name.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, form_id, event_type, type, click_target, variant, post_id, ua, ip, visitor, created_at
                 FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 500",
                $last
            ));
            if (empty($rows)) {
                return;
            }

            $events = [];
            $batch_last = $last;
            foreach ($rows as $row) {
                $events[] = [
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
                    'created_at'   => $row->created_at,
                ];
                $batch_last = (int) $row->id;
            }

            $ok = self::mirror_post(rtrim($cfg['api_base'], '/') . '/v1/embed/stats', [
                'site_id' => $cfg['site_id'],
                'token'   => $cfg['site_token'],
                'events'  => $events,
            ]);
            // Stop rather than skip: advancing past a refused batch would leave a
            // permanent hole. The next connect resumes from the start (idempotent).
            if (! $ok) {
                return;
            }
            $last = $batch_last;
        } while (count($rows) === 500);
    }

    /**
     * Stream this site's captured leads to the SaaS in id-ordered batches
     * (mirror-on-connect). Idempotent server-side by (form_id, wp_lead_id).
     * Best-effort, same as mirror_stats().
     */
    private static function mirror_leads(array $cfg): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'emblemstudio_leads';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $last = 0;
        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a prefixed constant name.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, form_id, email, extra_fields, funnelkit_contact_id, funnelkit_pushed, error, is_bot, ip, created_at
                 FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 500",
                $last
            ));
            if (empty($rows)) {
                return;
            }

            $leads = [];
            $batch_last = $last;
            foreach ($rows as $row) {
                $leads[] = [
                    'wp_lead_id'           => (int) $row->id,
                    'wp_form_id'           => (int) $row->form_id,
                    'email'                => $row->email,
                    'extra_fields'         => $row->extra_fields,
                    'funnelkit_contact_id' => $row->funnelkit_contact_id !== null ? (int) $row->funnelkit_contact_id : null,
                    'funnelkit_pushed'     => (int) $row->funnelkit_pushed,
                    'error'                => $row->error,
                    'is_bot'               => (int) $row->is_bot,
                    'ip'                   => $row->ip,
                    'created_at'           => $row->created_at,
                ];
                $batch_last = (int) $row->id;
            }

            $ok = self::mirror_post(rtrim($cfg['api_base'], '/') . '/v1/embed/leads', [
                'site_id' => $cfg['site_id'],
                'token'   => $cfg['site_token'],
                'leads'   => $leads,
            ]);
            if (! $ok) {
                return;
            }
            $last = $batch_last;
        } while (count($rows) === 500);
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
        self::pull_stats($cfg);
        self::pull_leads($cfg);
    }

    /** WP-cron tick — refresh the local copy while connected. */
    public static function cron_pull(): void
    {
        if (self::is_connected()) {
            self::pull_all();
        }
    }

    /**
     * The connect-time import, run in the background (es_saas_import single event):
     * mirror this site's forms + stats + leads UP, pull the SaaS state back DOWN,
     * arm the periodic refresh, then flag the import done so the loading screen lifts.
     */
    public static function run_import(): void
    {
        $cfg = self::config();
        try {
            if ($cfg) {
                self::mirror_forms($cfg);
                self::mirror_stats($cfg);
                self::mirror_leads($cfg);
                self::pull_all($cfg);
                if (! wp_next_scheduled('es_saas_pull')) {
                    wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'es_saas_pull');
                }
            }
        } finally {
            // Always resolve, even if a mirror threw — a stuck 'running' would
            // show the setup overlay on every dashboard load with no way out.
            update_option('es_import_status', 'done');
        }
    }

    /** Poll target for the dashboard loading screen: is the connect import done? */
    public static function ajax_import_status(): void
    {
        if (! current_user_can('manage_options') || ! check_ajax_referer('es_import_status', 'nonce', false)) {
            wp_send_json_error(['status' => 'forbidden'], 403);
        }
        wp_send_json_success(['status' => (string) get_option('es_import_status', 'done')]);
    }

    /**
     * POST one mirror batch, with backoff so a batch is never silently dropped.
     *
     * The caller advances its id cursor only when this returns true, so a refused
     * batch is retried instead of leaving a permanent hole in the imported history.
     * Retries on 429 (rate limited) and on transport/5xx errors, honouring
     * Retry-After when the server sends it. Returns false once retries run out —
     * the caller then stops, and the next connect resumes from id 0 (the upserts
     * are idempotent, so re-running is safe and fills any gap).
     */
    private static function mirror_post(string $url, array $payload): bool
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
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

            // Server's Retry-After wins; otherwise back off 2s, 4s, 8s, 16s.
            $wait = $retry_after > 0 ? min($retry_after, 60) : (2 ** ($attempt + 1));
            sleep($wait);
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
    }

    /** Pull SaaS-native events down, mapped by saas_form_id; dedupe by saas_event_id. */
    private static function pull_stats(array $cfg): void
    {
        global $wpdb;
        $events = $wpdb->prefix . 'emblemstudio_events';
        $map    = self::saas_form_map();
        if (empty($map)) {
            return;
        }
        $after = (int) get_option('es_saas_pull_stats_after', 0);
        do {
            $data = self::pull_post($cfg, '/v1/embed/export/stats', ['after_id' => $after]);
            if (empty($data['events']) || ! is_array($data['events'])) {
                return;
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
                    'created_at'    => (string) ($e['created_at'] ?? current_time('mysql')),
                ]);
            }
            $wpdb->suppress_errors(false);
            $after = (int) ($data['last_id'] ?? $after);
            update_option('es_saas_pull_stats_after', $after, false);
        } while (count($data['events']) === 500);
    }

    /** Pull SaaS-native leads down, mapped by saas_form_id; dedupe by saas_lead_id. */
    private static function pull_leads(array $cfg): void
    {
        global $wpdb;
        $leads = $wpdb->prefix . 'emblemstudio_leads';
        $map   = self::saas_form_map();
        if (empty($map)) {
            return;
        }
        $after = (int) get_option('es_saas_pull_leads_after', 0);
        do {
            $data = self::pull_post($cfg, '/v1/embed/export/leads', ['after_id' => $after]);
            if (empty($data['leads']) || ! is_array($data['leads'])) {
                return;
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
                $wpdb->insert($leads, [
                    'saas_lead_id'         => (int) $l['saas_lead_id'],
                    'form_id'              => $form_id,
                    'email'                => (string) $l['email'],
                    'extra_fields'         => $extra,
                    'funnelkit_contact_id' => isset($l['funnelkit_contact_id']) ? (int) $l['funnelkit_contact_id'] : null,
                    'funnelkit_pushed'     => ! empty($l['funnelkit_pushed']) ? 1 : 0,
                    'error'                => $l['error'] ?? null,
                    'is_bot'               => ! empty($l['is_bot']) ? 1 : 0,
                    'ip'                   => $l['ip'] ?? null,
                    'created_at'           => (string) ($l['created_at'] ?? current_time('mysql')),
                ]);
            }
            $wpdb->suppress_errors(false);
            $after = (int) ($data['last_id'] ?? $after);
            update_option('es_saas_pull_leads_after', $after, false);
        } while (count($data['leads']) === 500);
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

    /** Remove the stored connection — revert to the Lite plugin. */
    public static function handle_decouple(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'emblem-studio'), '', ['response' => 403]);
        }
        check_admin_referer('es_decouple');

        $cfg = self::config();

        // One last copy-down before going solo, so WordPress keeps the full,
        // current set of assets, stats and leads locally.
        self::pull_all($cfg);
        wp_clear_scheduled_hook('es_saas_pull');

        // Tell the SaaS to sever the connection (rotate the site secret) so this
        // site can't sign in again without reconnecting. Best-effort.
        self::revoke_connection($cfg);

        delete_option(self::OPTION);
        delete_transient(self::FORMS_TRANSIENT);

        wp_safe_redirect(add_query_arg('es_decoupled', '1', admin_url('admin.php?page=emblem-studio')));
        exit;
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
        // Load the embed runtime when there are auto-shells to place OR a shortcode
        // on this page placed a SaaS slot (inline / click) that embed.js must fill.
        if (empty($forms) && ! self::$embed_requested) {
            return;
        }

        $config = [
            'api'       => $cfg['api_base'].'/v1',
            'wpContext' => self::context(),
        ];

        echo '<script>window.EmblemStudio = Object.assign(window.EmblemStudio || {}, '
            .wp_json_encode($config).');</script>'."\n";

        foreach ($forms as $form) {
            $id = isset($form['id']) ? (int) $form['id'] : 0;
            if (! $id || empty($form['shells'])) {
                continue;
            }
            foreach ((array) $form['shells'] as $shell) {
                printf(
                    '<div data-es-form="%d" data-es-shell="%s" style="display:none"></div>'."\n",
                    $id,
                    esc_attr((string) $shell)
                );
            }
        }

        printf('<script src="%s" defer></script>'."\n", esc_url($cfg['api_base'].'/embed/embed.js'));
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

        $url = $cfg['api_base'].'/v1/sites/'.$cfg['site_id']
            .'/embed?token='.rawurlencode($cfg['site_token'])
            .'&funnelkit='.(self::funnelkit_available() ? '1' : '0');

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

    /**
     * A shortcode calls this when it places a SaaS slot, so render() loads the
     * embed runtime (window.EmblemStudio + embed.js) even on a page that has no
     * auto-shell forms of its own.
     */
    public static function request_embed_runtime(): void
    {
        self::$embed_requested = true;
    }

    /* ── Inbound lead relay → local FunnelKit ──────────────────────────── */

    public static function register_routes(): void
    {
        register_rest_route('emblem-studio-saas/v1', '/lead', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'receive_lead'],
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

    /**
     * Create the contact in FunnelKit in-process — mirrors the autonami-app
     * create-contact route, so the payload is identical to the SaaS REST push.
     */
    public static function receive_lead(WP_REST_Request $request)
    {
        if (! self::funnelkit_available()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'FunnelKit not installed'], 503);
        }

        $p     = (array) $request->get_json_params();
        $email = sanitize_email($p['email'] ?? '');
        if (! is_email($email)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid email'], 400);
        }

        $params = [
            'f_name'     => sanitize_text_field($p['f_name'] ?? ''),
            'l_name'     => sanitize_text_field($p['l_name'] ?? ''),
            'contact_no' => sanitize_text_field($p['contact_no'] ?? ''),
            'source'     => sanitize_text_field($p['source'] ?? 'emblem-studio'),
            'status'     => sanitize_text_field((string) ($p['status'] ?? '1')),
        ];
        foreach ($p as $k => $v) {
            if (is_numeric($k)) {
                $params[$k] = $v;
            }
        }

        $contact = new BWFCRM_Contact($email, true, $params);
        if (! empty($p['tags'])) {
            $contact->set_tags((array) $p['tags'], true, false);
        }
        if (! empty($p['lists'])) {
            $contact->set_lists(array_map('intval', (array) $p['lists']), true, false);
        }
        $contact->save();

        $id = method_exists($contact, 'get_id') ? (int) $contact->get_id() : null;

        if (! empty($contact->already_exists)) {
            return new WP_REST_Response(['ok' => true, 'already_exists' => true, 'contact_id' => $id], 422);
        }
        if ($contact->is_contact_exists()) {
            return new WP_REST_Response(['ok' => true, 'contact_id' => $id], 200);
        }

        return new WP_REST_Response(['ok' => false, 'error' => 'Unable to create contact'], 500);
    }
}

Emblem_Studio_SaaS_Connector::init();
