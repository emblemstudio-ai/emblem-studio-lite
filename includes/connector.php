<?php

if (! defined('ABSPATH')) {
    exit;
}

final class Emblem_Studio_SaaS_Connector
{
    const VERSION = '1.3.35-beta';

    const FORMS_TRANSIENT = 'ess_connector_forms';

    const EMBED_COPY_OPTION = 'ess_embed_copy';
    const EMBED_REFRESH_HOOK = 'es_saas_refresh_embed';
    const EMBED_FRESH_SECONDS = 300;
    const EMBED_RETRY_SECONDS = 60;
    const OPTION = 'ess_connection';

    const DEFAULT_API_BASE = 'https://emblemstudio.ai';

    private static function connect_api(): string
    {
        return rtrim(defined('ESS_API_BASE') ? (string) ESS_API_BASE : self::DEFAULT_API_BASE, '/');
    }

    const POSTDECOUPLE_OPTION = 'es_postdecouple_cfg';
    const POSTDECOUPLE_STATE = 'es_postdecouple_state';
    const POSTDECOUPLE_HOOK = 'es_saas_postdecouple_pull';
    const FK_SYNC_HOOK = 'es_saas_funnelkit_sync';
    const FK_SYNC_SECONDS = 2 * MINUTE_IN_SECONDS;
    const POSTDECOUPLE_MAX_ATTEMPTS = 40;

    const PAGE_MAP = [
        'emblem-studio'              => 'assets',
        'emblem-studio-new'          => 'assets/new',
        'emblem-studio-surveys'      => 'surveys',
        'emblem-studio-sales-notifications' => 'sales-notifications',
        'emblem-studio-survey-results' => 'survey-results',
        'emblem-studio-schedule'     => 'schedule',
        'emblem-studio-tests'        => 'tests',
        'emblem-studio-leads'        => 'leads',
        'emblem-studio-reports'      => 'reports',
        'emblem-studio-integrations' => 'integrations',
        'emblem-studio-design-system' => 'design-system',
        'emblem-studio-settings'     => 'settings',
        'emblem-studio-tickets'      => 'tickets',
        'emblem-studio-team'         => 'team',
    ];

    public static function init(): void
    {
        add_action('wp_footer', [__CLASS__, 'render'], 99);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_es_connect', [__CLASS__, 'handle_connect']);
        add_action('wp_ajax_es_connect_complete', [__CLASS__, 'handle_connect_complete']);
        add_action('admin_post_es_decouple', [__CLASS__, 'handle_decouple']);
        add_action('admin_notices', [__CLASS__, 'connect_notice']);
        add_action('es_saas_pull', [__CLASS__, 'cron_pull']);
        add_action(self::EMBED_REFRESH_HOOK, [__CLASS__, 'refresh_embed']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::FK_SYNC_HOOK, [__CLASS__, 'sync_funnelkit']);
        add_action('init', [__CLASS__, 'schedule_funnelkit_sync']);
        add_action('es_saas_import', [__CLASS__, 'run_import']);
        add_action('wp_ajax_es_import_status', [__CLASS__, 'ajax_import_status']);
        add_action(self::POSTDECOUPLE_HOOK, [__CLASS__, 'run_postdecouple_pull']);
        add_action('update_option_timezone_string', [__CLASS__, 'push_timezone']);
        add_action('update_option_gmt_offset', [__CLASS__, 'push_timezone']);
        add_filter('es_live_values_watch_list', [__CLASS__, 'live_values_watch_list']);
        add_action('es_live_values_measured', [__CLASS__, 'push_live_values'], 10, 2);
        add_action('es_purchases_ready', [__CLASS__, 'push_purchases']);
        add_action('es_erasure_ready', [__CLASS__, 'push_erasures']);
        add_filter('es_recent_orders_wanted', [__CLASS__, 'recent_orders_wanted']);
        add_action('es_recent_orders_ready', [__CLASS__, 'push_recent_orders']);
        add_filter('es_no_delay_urls', [__CLASS__, 'no_delay_urls']);
    }

    public static function no_delay_urls($urls): array
    {
        $urls = is_array($urls) ? $urls : [];
        $cfg = self::config();
        if ($cfg && ! empty($cfg['api_base'])) {
            $urls[] = $cfg['api_base'].'/embed/embed.js';
        }

        return $urls;
    }

    public static function push_erasures(): void
    {
        $cfg = self::config();
        if (! $cfg || ! class_exists('Emblem_Studio_Privacy')) {
            return;
        }
        foreach (array_chunk(Emblem_Studio_Privacy::queued_saas_erasures(), 50) as $chunk) {
            $data = self::pull_post($cfg, '/v1/embed/erase', ['emails' => array_values($chunk)]);
            if (! is_array($data) || empty($data['ok'])) {
                return;
            }
            Emblem_Studio_Privacy::clear_saas_erasures($chunk);
        }
    }

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

            $stored = class_exists('Emblem_Studio_Live_Values') ? Emblem_Studio_Live_Values::stored() : [];
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

    public static function push_purchases(): void
    {
        $cfg = self::config();
        if (! $cfg || ! class_exists('Emblem_Studio_Purchases')) {
            return;
        }

        $forget = Emblem_Studio_Purchases::forgotten();
        if ($forget !== []) {
            foreach (array_chunk($forget, 200) as $chunk) {
                $data = self::pull_post($cfg, '/v1/embed/purchases', ['forget' => array_values($chunk)]);
                if (! is_array($data) || empty($data['ok'])) {
                    return;
                }
                Emblem_Studio_Purchases::clear_forgotten($chunk);
            }
        }

        for ($pass = 0; $pass < 10; $pass++) {
            $rows = Emblem_Studio_Purchases::pending(200);
            if ($rows === []) {
                return;
            }

            $ids = array_column($rows, 'id');
            foreach ($rows as $i => $row) {
                unset($rows[$i]['id']);
            }

            $data = self::pull_post($cfg, '/v1/embed/purchases', ['purchases' => array_values($rows)]);
            if (! is_array($data) || empty($data['ok'])) {
                return;
            }

            Emblem_Studio_Purchases::mark_sent($ids);
            if (count($rows) < 200) {
                return;
            }
        }
    }

    const RECENT_ORDERS_WANTED = 'ess_recent_orders_wanted';

    public static function recent_orders_wanted($wanted): bool
    {
        if (! self::is_connected()) {
            return false;
        }
        $state = get_option(self::RECENT_ORDERS_WANTED);

        return is_array($state) && ! empty($state['wanted']);
    }

    private static function set_recent_orders_wanted(bool $wanted): void
    {
        $state = get_option(self::RECENT_ORDERS_WANTED);
        $was   = is_array($state) && ! empty($state['wanted']);
        if ($was !== $wanted || ! is_array($state)) {
            update_option(self::RECENT_ORDERS_WANTED, ['wanted' => $wanted, 'at' => time()], false);
        }
        if (! $wanted && class_exists('Emblem_Studio_Recent_Orders')) {
            Emblem_Studio_Recent_Orders::clear();
        }
    }

    public static function push_recent_orders(): void
    {
        $cfg = self::config();
        if (! $cfg || ! class_exists('Emblem_Studio_Recent_Orders')) {
            return;
        }
        $pending = Emblem_Studio_Recent_Orders::pending();
        if ($pending['orders'] === [] && $pending['forget'] === []) {
            return;
        }

        $data = self::pull_post($cfg, '/v1/embed/recent-orders', $pending);
        if (! is_array($data) || empty($data['ok'])) {
            return;
        }
        Emblem_Studio_Recent_Orders::mark_sent(array_column($pending['orders'], 'ref'), $pending['forget']);
        if (array_key_exists('wanted', $data)) {
            self::set_recent_orders_wanted(! empty($data['wanted']));
        }
    }

    private static function stored(): array
    {
        $o = get_option(self::OPTION);

        return is_array($o) ? $o : [];
    }

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

    private static function connected_via(): ?string
    {
        if (defined('ESS_API_BASE') && defined('ESS_SITE_ID') && defined('ESS_SITE_TOKEN')) {
            return 'constants';
        }

        return self::is_connected() ? 'option' : null;
    }

    public static function maybe_render_page(string $slug): bool
    {
        $cfg = self::config();
        if (! $cfg) {
            return false;
        }

        $session = self::mint_session($cfg);
        if (! $session) {
            self::render_disconnected();

            return true;
        }

        $path = self::PAGE_MAP[$slug] ?? 'assets';

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

    public static function render_account_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $reconnect = ! empty($_GET['es_reconnect']); // phpcs:ignore WordPress.Security.NonceVerification
        if (self::is_connected() && ! $reconnect) {
            self::render_connected_panel();
        } else {
            self::render_connect_panel();
        }
    }

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

    private static function render_embed(string $api, string $code, string $path, string $token, bool $editor = false): void
    {
        $p = wp_parse_url($api);
        $origin = (isset($p['scheme']) ? $p['scheme'] : 'https').'://'.(isset($p['host']) ? $p['host'] : '')
            .(isset($p['port']) ? ':'.$p['port'] : '');

        $embed_base = $api.'/app/s/'.rawurlencode($code).'/'.$path;
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
                'surveys': 'emblem-studio-surveys',
                'sales-notifications': 'emblem-studio-sales-notifications',
                'survey-results': 'emblem-studio-survey-results',
                'team': 'emblem-studio-team',
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

    private static function render_connect_panel(): void
    {
        $api  = self::connect_api();
        $host = (string) wp_parse_url($api, PHP_URL_HOST);

        $origin = home_url('/');
        $name   = get_bloginfo('name');
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
                        /* translators: %s: link to the sign-up page */
                        esc_html__('A secure Emblem Studio sign-in window will open. No account yet? %s.', 'emblem-studio'),
                        '<a href="'.esc_url(rtrim($api, '/').'/signup').'" target="_blank" rel="noopener">'.esc_html__('Create one for free', 'emblem-studio').'</a>'
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
                <p style="color:#646970;font-size:12px;margin:6px 0 0;">
                    <?php
                    printf(
                        /* translators: %s: the Emblem Studio address this site connects to, e.g. emblemstudio.ai */
                        esc_html__('Connects to %s', 'emblem-studio'),
                        '<strong>'.esc_html($host).'</strong>'
                    );
                    ?>
                </p>
                <div id="es-connect-status" style="min-height:20px;margin-top:12px;color:#50575e;font-size:13px;"></div>
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
            var popup    = null;

            // The origins a grant may come from: Emblem Studio's own. In local
            // development the sign-in can bounce between localhost and 127.0.0.1,
            // so those two count as the same host.
            var ORIGINS = (function () {
                var o = new URL(API_BASE).origin, list = [o];
                if (o.indexOf('//localhost') !== -1) { list.push(o.replace('//localhost', '//127.0.0.1')); }
                if (o.indexOf('//127.0.0.1') !== -1) { list.push(o.replace('//127.0.0.1', '//localhost')); }
                return list;
            })();

            btn.addEventListener('click', function () {
                statusEl.textContent = '';
                popup = window.open(POPUP, 'es-connect', 'popup,width=480,height=720');
                if (! popup) { statusEl.textContent = <?php echo wp_json_encode(__('Please allow pop-ups for this site, then click again.', 'emblem-studio')); ?>; }
            });

            window.addEventListener('message', function (e) {
                var d = e.data || {};
                // Only the sign-in window this page opened, on Emblem Studio's own
                // address, may hand over a grant. The state nonce alone was not
                // enough: a popup on any server receives it in its URL (audit SEC-1).
                // The grant is then verified when WordPress pairs server-side.
                if (! popup || e.source !== popup || ORIGINS.indexOf(e.origin) === -1) { return; }
                if (! d || d.type !== 'es-connect' || d.state !== STATE || ! d.grant) { return; }

                statusEl.textContent = <?php echo wp_json_encode(__('Connecting…', 'emblem-studio')); ?>;
                var form = new FormData();
                form.append('action', 'es_connect_complete');
                form.append('_ajax_nonce', NONCE);
                form.append('grant', d.grant);

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

    private static function decouple_form_html(): string
    {
        $confirm = esc_js(__('Decouple this site? The dashboard will switch back to the WordPress Lite plugin. The leads, subscribers and stats already synced stay in your Emblem Studio account — decoupling does not delete them there. You can reconnect anytime.', 'emblem-studio'));

        return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0;" onsubmit="return confirm(\''.$confirm.'\');">'
            .'<input type="hidden" name="action" value="es_decouple" />'
            .wp_nonce_field('es_decouple', '_wpnonce', true, false)
            .'<button type="submit" class="button">'.esc_html__('Decouple account', 'emblem-studio').'</button>'
            .'</form>';
    }

    public static function handle_connect(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'emblem-studio'), '', ['response' => 403]);
        }
        check_admin_referer('es_connect');

        wp_safe_redirect(admin_url('admin.php?page=emblem-studio-account'));
        exit;
    }

    public static function handle_connect_complete(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'emblem-studio')], 403);
        }
        check_ajax_referer('es_connect_complete');

        $grant = isset($_POST['grant']) ? sanitize_text_field(wp_unslash((string) $_POST['grant'])) : '';
        $api = self::connect_api();

        if ($grant === '') {
            wp_send_json_error(['message' => __('Something went wrong signing in. Please try again.', 'emblem-studio')]);
        }

        $res = wp_remote_post($api.'/v1/embed/connect/pair', [
            'timeout' => 12,
            'body'    => [
                'grant'    => $grant,
                'name'     => get_bloginfo('name'),
                'platform' => 'wp',
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
        delete_option(self::EMBED_COPY_OPTION);

        self::start_import();

        wp_send_json_success(['redirect' => admin_url('admin.php?page=emblem-studio')]);
    }

    private static function mirror_forms(array $cfg): void
    {
        if (! class_exists('Emblem_Studio_Form_Repo')) {
            return;
        }
        $rows = array_merge(Emblem_Studio_Form_Repo::all('all'), Emblem_Studio_Form_Repo::all('archived'));
        $forms = [];
        foreach ((array) $rows as $row) {
            if (! is_object($row) || empty($row->id)) {
                continue;
            }
            if (! empty($row->saas_form_id)) {
                continue;
            }
            $export = Emblem_Studio_Form_Repo::to_export_array($row);
            $export['wp_form_id'] = (int) $row->id;
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
            'timezone' => self::wp_timezone(),
        ]);
    }

    private static function wp_timezone(): string
    {
        return function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
    }

    public static function push_timezone(): void
    {
        $cfg = self::config();
        if (empty($cfg['site_id']) || empty($cfg['site_token'])) {
            return;
        }

        $tz = self::wp_timezone();
        if ($tz === '') {
            return;
        }

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

    private static function mirror_presets(array $cfg): void
    {
        if (! class_exists('Emblem_Studio_Preset_Repo')) {
            return;
        }
        $rows    = Emblem_Studio_Preset_Repo::all();
        $presets = [];
        foreach ((array) $rows as $row) {
            if (! is_object($row) || empty($row->id)) {
                continue;
            }
            if (! empty($row->saas_preset_id)) {
                continue;
            }
            $export = Emblem_Studio_Preset_Repo::to_export_array($row);
            $export['wp_preset_id'] = (int) $row->id;
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

    private static function mirror_table(string $kind): string
    {
        global $wpdb;
        $table = $wpdb->prefix . ($kind === 'events' ? 'emblemstudio_events' : 'emblemstudio_leads');

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? $table : '';
    }

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

        $state['last_id'] = $last;
        $state['done']    = (int) $state['done'] + count($items);

        return $state;
    }

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
            'created_at'   => get_gmt_from_date((string) $row->created_at),
        ];
    }

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
            'bot_signals'          => $row->bot_signals ?? null,
            'ip'                   => $row->ip,
            'created_at'           => get_gmt_from_date((string) $row->created_at),
        ];
    }

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
        self::push_erasures();
    }

    public static function cron_pull(): void
    {
        if (self::is_connected()) {
            self::pull_all();
            self::report_timezone();
        }
    }

    public static function cron_schedules($schedules): array
    {
        $schedules = is_array($schedules) ? $schedules : [];
        $schedules['es_saas_fk_sync'] = [
            'interval' => self::FK_SYNC_SECONDS,
            'display'  => 'Emblem Studio: collect FunnelKit leads',
        ];

        return $schedules;
    }

    public static function schedule_funnelkit_sync(): void
    {
        if (self::is_connected() && ! wp_next_scheduled(self::FK_SYNC_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'es_saas_fk_sync', self::FK_SYNC_HOOK);
        }
    }

    public static function sync_funnelkit(): void
    {
        $cfg = self::config();
        if (! $cfg || ! self::is_connected()) {
            return;
        }

        $data = self::pull_post($cfg, '/v1/embed/funnelkit/pending', [
            'shop_data_local' => class_exists('Emblem_Studio_Purchases') && get_option(Emblem_Studio_Purchases::ENABLED_OPTION) === '1',
        ]);
        if (! $data) {
            return;
        }

        if (array_key_exists('shop_data', $data) && $data['shop_data'] !== null && class_exists('Emblem_Studio_Purchases')) {
            $want = ! empty($data['shop_data']) ? '1' : '0';
            if (get_option(Emblem_Studio_Purchases::ENABLED_OPTION) !== $want) {
                update_option(Emblem_Studio_Purchases::ENABLED_OPTION, $want);
            }
        }

        if (array_key_exists('orders_wanted', $data)) {
            self::set_recent_orders_wanted(! empty($data['orders_wanted']));
            if (! empty($data['orders_wanted'])) {
                self::push_recent_orders();
            }
        }

        if (! self::funnelkit_available()) {
            return;
        }
        if (! empty($data['want_meta'])) {
            self::pull_post($cfg, '/v1/embed/funnelkit/meta', ['meta' => self::funnelkit_meta_payload()]);
        }

        $results = [];
        foreach ((array) ($data['leads'] ?? []) as $lead) {
            $lead_id = (int) ($lead['lead_id'] ?? 0);
            if (! $lead_id || ! is_array($lead['dispatch'] ?? null)) {
                continue;
            }
            [$res] = self::run_dispatch($lead['dispatch']);
            if (($res['mode'] ?? '') === 'in_progress') {
                continue;
            }
            $results[] = [
                'lead_id'    => $lead_id,
                'ok'         => ! empty($res['ok']),
                'mode'       => isset($res['mode']) ? (string) $res['mode'] : null,
                'contact_id' => isset($res['contact_id']) && is_numeric($res['contact_id']) ? (int) $res['contact_id'] : null,
                'error'      => isset($res['error']) ? mb_substr((string) $res['error'], 0, 1000) : null,
            ];
        }
        if ($results) {
            self::pull_post($cfg, '/v1/embed/funnelkit/results', ['results' => $results]);
        }
    }

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
            return;
        }

        update_option('ess_timezone_reported', $marker, false);
    }

    public static function pull_assets_now(): void
    {
        $cfg = self::config();
        if ($cfg) {
            self::pull_assets($cfg);
        }
    }

    const IMPORT_BATCH = 500;

    const IMPORT_PASS_SECONDS = 15;

    const IMPORT_LOCK = 'es_import_lock';

    private static function import_state(): array
    {
        $state = get_option('es_import_state', []);
        if (! is_array($state) || empty($state['stage'])) {
            return ['stage' => 'forms', 'last_id' => 0, 'done' => 0, 'total' => 0, 'stalled' => 0];
        }

        return $state + ['stage' => 'forms', 'last_id' => 0, 'done' => 0, 'total' => 0, 'stalled' => 0];
    }

    private static function save_import_state(array $state): void
    {
        update_option('es_import_state', $state, false);
    }

    private static function start_import(): void
    {
        update_option('es_import_status', 'running');
        self::save_import_state([
            'stage'   => 'forms',
            'last_id' => 0,
            'done'    => 0,
            'total'   => self::mirror_remaining('events') + self::mirror_remaining('leads'),
            'stalled' => 0,
        ]);

        wp_schedule_single_event(time(), 'es_saas_import');
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    public static function run_import(): void
    {
        $progress = self::run_import_pass();
        if ($progress['status'] === 'done') {
            return;
        }
        $delay = ! empty($progress['stalled']) ? 60 : 1;
        if (! wp_next_scheduled('es_saas_import')) {
            wp_schedule_single_event(time() + $delay, 'es_saas_import');
        }
    }

    public static function run_import_pass(): array
    {
        if (get_transient(self::IMPORT_LOCK)) {
            return self::import_progress();
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
                if ($next === null) {
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
        } finally {
            delete_transient(self::IMPORT_LOCK);
        }

        return self::import_progress();
    }

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

    public static function ajax_import_status(): void
    {
        if (! current_user_can('manage_options') || ! check_ajax_referer('es_import_status', 'nonce', false)) {
            wp_send_json_error(['status' => 'forbidden'], 403);
        }
        wp_send_json_success(self::run_import_pass());
    }

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
                if ($code !== 429 && $code < 500) {
                    return false;
                }
                $retry_after = (int) wp_remote_retrieve_header($res, 'retry-after');
            } else {
                $retry_after = 0;
            }

            if ($attempt === 2) {
                break;
            }
            $wait = $retry_after > 0 ? $retry_after : (2 ** ($attempt + 1));
            sleep(min($wait, 5));
        }

        return false;
    }

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

    private static function pull_assets(array $cfg): void
    {
        if (! class_exists('Emblem_Studio_Form_Repo')) {
            return;
        }
        $data = self::pull_post($cfg, '/v1/embed/export/assets');
        if (! isset($data['assets']) || ! is_array($data['assets'])) {
            return;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'emblemstudio_forms';
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
                'funnelkit_contact_status' => (string) ($a['funnelkit_contact_status'] ?? '') === 'unsubscribed' ? 'unsubscribed' : 'subscribed',
                'webhooks'             => is_array($a['webhooks'] ?? null) ? $a['webhooks'] : [],
                'connections'          => is_array($a['connections'] ?? null) ? $a['connections'] : [],
                'active_from'          => $a['active_from'] ?? null,
                'active_until'         => $a['active_until'] ?? null,
            ];

            $new_id = (int) Emblem_Studio_Form_Repo::save($fields, $local_id ?: null);
            if ($new_id) {
                $wpdb->update($t, [
                    'saas_form_id'  => $saas_id,
                    'form_types'    => wp_json_encode($supported),
                    'lite_disabled' => wp_json_encode(['shells' => $disabled, 'asset_off' => $asset_off]),
                ], ['id' => $new_id]);
                $wp_ids[$saas_id] = $new_id;

                if (array_key_exists('archived_at', $a)) {
                    Emblem_Studio_Form_Repo::set_archived_at($new_id, empty($a['archived_at']) ? null : (string) $a['archived_at']);
                }
            }
        }

        $received = [];
        foreach ($data['assets'] as $a) {
            if (is_array($a) && ! empty($a['saas_form_id'])) {
                $received[] = (int) $a['saas_form_id'];
            }
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is a prefixed constant name.
        foreach ($received === [] ? [] : (array) $wpdb->get_results("SELECT id, saas_form_id FROM {$t} WHERE saas_form_id IS NOT NULL AND trashed_at IS NULL") as $row) {
            if (! in_array((int) $row->saas_form_id, $received, true)) {
                Emblem_Studio_Form_Repo::trash((int) $row->id);
            }
        }

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

    private static function pull_presets(array $cfg): void
    {
        if (! class_exists('Emblem_Studio_Preset_Repo')) {
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
            $new_id = Emblem_Studio_Preset_Repo::save($fields, $local_id ?: null);
            if ($new_id) {
                $wpdb->update($t, ['saas_preset_id' => $saas_id], ['id' => $new_id]);
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is a prefixed constant name.
        foreach ((array) $wpdb->get_results("SELECT id, saas_preset_id FROM {$t} WHERE saas_preset_id IS NOT NULL") as $row) {
            if (! in_array((int) $row->saas_preset_id, $received, true)) {
                Emblem_Studio_Preset_Repo::delete((int) $row->id);
            }
        }
    }

    private static function apply_stats_reset(array $data, int $after): int
    {
        $mark = isset($data['reset_at']) && is_string($data['reset_at']) ? $data['reset_at'] : '';
        if ($mark === '' || $mark === (string) get_option('es_saas_stats_reset_at', '')) {
            return $after;
        }

        if (class_exists('Emblem_Studio_Reports')) {
            Emblem_Studio_Reports::reset_stats();
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
                return false;
            }

            $after = self::apply_stats_reset($data, $after);

            if (empty($data['events']) || ! is_array($data['events'])) {
                return true;
            }
            $wpdb->suppress_errors(true);
            $earliest = [];
            foreach ($data['events'] as $e) {
                $form_id = $map[(int) ($e['saas_form_id'] ?? 0)] ?? 0;
                if (! $form_id || empty($e['saas_event_id'])) {
                    continue;
                }
                $created = isset($e['created_at'])
                    ? get_date_from_gmt((string) $e['created_at'])
                    : current_time('mysql');
                $added = $wpdb->insert($events, [
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
                    'created_at'    => $created,
                ]);
                if ($added && (! isset($earliest[$form_id]) || $created < $earliest[$form_id])) {
                    $earliest[$form_id] = $created;
                }
            }
            $wpdb->suppress_errors(false);
            if ($earliest && class_exists('Emblem_Studio_Stats_Summary')) {
                Emblem_Studio_Stats_Summary::rewind($earliest);
            }
            $after = (int) ($data['last_id'] ?? $after);
            update_option('es_saas_pull_stats_after', $after, false);
            if (count($data['events']) < 500) {
                return true;
            }
        } while ($deadline === null || time() < $deadline);

        return false;
    }

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
                    'created_at'           => isset($l['created_at'])
                        ? get_date_from_gmt((string) $l['created_at'])
                        : current_time('mysql'),
                ]);

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

    private static function pull_lead_deletions(array $cfg): void
    {
        if (! class_exists('Emblem_Studio_Leads')) {
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
                    Emblem_Studio_Leads::delete($wp_lead_id);
                }
                if ($saas_lead_id) {
                    Emblem_Studio_Leads::delete_by_saas_id($saas_lead_id);
                }
            }
            $after = (int) ($data['last_id'] ?? $after);
            update_option('es_saas_pull_lead_deletions_after', $after, false);
        } while (count($data['deletions']) >= 500);
    }

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

    private static function form_revisions(int $form_id): array
    {
        if (! class_exists('Emblem_Studio_Form_Revisions')) {
            return [];
        }
        $out = [];
        foreach ((array) Emblem_Studio_Form_Revisions::list_for_form($form_id) as $rev) {
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

    public static function handle_decouple(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'emblem-studio'), '', ['response' => 403]);
        }
        check_admin_referer('es_decouple');

        $cfg = self::config();

        wp_clear_scheduled_hook('es_saas_pull');
        wp_clear_scheduled_hook(self::FK_SYNC_HOOK);
        wp_clear_scheduled_hook('es_saas_import');

        delete_option(self::OPTION);
        delete_option('es_import_state');
        delete_option('es_import_status');
        delete_transient(self::FORMS_TRANSIENT);
        delete_option(self::EMBED_COPY_OPTION);
        delete_transient(self::IMPORT_LOCK);

        if ($cfg) {
            self::start_postdecouple_pull($cfg);
        }

        wp_safe_redirect(add_query_arg('es_decoupled', '1', admin_url('admin.php?page=emblem-studio')));
        exit;
    }

    private static function start_postdecouple_pull(array $cfg): void
    {
        update_option(self::POSTDECOUPLE_OPTION, $cfg, false);
        update_option(self::POSTDECOUPLE_STATE, ['stage' => 'assets', 'attempts' => 0], false);
        wp_schedule_single_event(time(), self::POSTDECOUPLE_HOOK);
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    public static function run_postdecouple_pull(): void
    {
        $cfg = get_option(self::POSTDECOUPLE_OPTION);
        if (! is_array($cfg) || empty($cfg['site_token'])) {
            return;
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
            return;
        }
        $url = admin_url('admin.php?page=emblem-studio-account');
        echo '<div class="notice notice-info"><p>'
            .esc_html__('Sign in to Emblem Studio to manage this site’s forms and reports right here in WordPress.', 'emblem-studio')
            .' <a href="'.esc_url($url).'">'.esc_html__('Sign in', 'emblem-studio').'</a></p></div>';
    }

    private const AUTO_SHELLS = ['popup-auto', 'ribbon', 'slide-in'];

    private static function funnelkit_available(): bool
    {
        return class_exists('BWFCRM_Contact');
    }

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
            return;
        }
        if (is_admin() || is_feed() || is_embed()) {
            return;
        }
        if (! empty($_GET['es_preview'])) {
            return;
        }

        $forms = self::forms($cfg);

        $admin_frame = class_exists('Emblem_Studio_Display_Rules') && Emblem_Studio_Display_Rules::is_admin_frame();

        $config = [
            'api'       => $cfg['api_base'].'/v1',
            'wpContext' => self::context(),
        ];

        if (class_exists('WooCommerce')) {
            $config['ajax'] = admin_url('admin-ajax.php');
        }

        if (! empty($cfg['code'])) {
            $config['site'] = (string) $cfg['code'];
        }

        $skip = class_exists('Emblem_Studio_No_Delay')
            ? ' '.Emblem_Studio_No_Delay::ATTRS
            : ' data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1"';

        echo '<script'.$skip.'>window.EmblemStudio = Object.assign(window.EmblemStudio || {}, '
            .wp_json_encode($config).');</script>'."\n";

        foreach ($forms as $form) {
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

        printf('<script%s src="%s" defer></script>'."\n", $skip, esc_url($cfg['api_base'].'/embed/embed.js'));
    }

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

    private static function fetch_embed(array $cfg): array
    {
        $copy = get_option(self::EMBED_COPY_OPTION);
        if (is_array($copy) && isset($copy['payload']['data'])) {
            if (time() - (int) ($copy['at'] ?? 0) >= self::EMBED_FRESH_SECONDS) {
                self::schedule_embed_refresh($copy);
            }

            return $copy['payload'];
        }

        $payload = self::download_embed($cfg);
        $failed = $payload === null;
        $payload ??= ['data' => [], 'wp_map' => [], 'wp_keys' => []];
        self::store_embed_copy($payload, $failed);

        return $payload;
    }

    public static function refresh_embed(): void
    {
        $cfg = self::config();
        if (! $cfg) {
            return;
        }
        $payload = self::download_embed($cfg);
        if ($payload !== null) {
            self::store_embed_copy($payload, false);

            return;
        }
        $copy = get_option(self::EMBED_COPY_OPTION);
        if (is_array($copy)) {
            $copy['tried_at'] = time();
            update_option(self::EMBED_COPY_OPTION, $copy, false);
        }
    }

    private static function schedule_embed_refresh(array $copy): void
    {
        if (time() - (int) ($copy['tried_at'] ?? 0) < self::EMBED_RETRY_SECONDS) {
            return;
        }
        if (wp_next_scheduled(self::EMBED_REFRESH_HOOK)) {
            return;
        }
        wp_schedule_single_event(time(), self::EMBED_REFRESH_HOOK);
    }

    private static function store_embed_copy(array $payload, bool $failed): void
    {
        $at = $failed ? time() - self::EMBED_FRESH_SECONDS + self::EMBED_RETRY_SECONDS : time();
        update_option(self::EMBED_COPY_OPTION, ['at' => $at, 'payload' => $payload], false);
    }

    private static function download_embed(array $cfg): ?array
    {
        $url = $cfg['api_base'].'/v1/sites/'.$cfg['site_id']
            .'/embed?funnelkit='.(self::funnelkit_available() ? '1' : '0')
            .'&v='.rawurlencode(self::VERSION);

        $res = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => [
                'X-ESS-Token' => $cfg['site_token'],
                'X-ESS-Roles' => wp_json_encode(self::roles()),
            ],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($res), true);

        return [
            'data'    => (is_array($body) && isset($body['data']) && is_array($body['data'])) ? $body['data'] : [],
            'wp_map'  => (is_array($body) && isset($body['wp_map']) && is_array($body['wp_map'])) ? $body['wp_map'] : [],
            'wp_keys' => (is_array($body) && isset($body['wp_keys']) && is_array($body['wp_keys'])) ? $body['wp_keys'] : [],
        ];
    }

    private static function forms(array $cfg): array
    {
        return self::fetch_embed($cfg)['data'];
    }

    public static function saas_form_id(int $wp_id): int
    {
        $cfg = self::config();
        if (! $cfg || $wp_id <= 0) {
            return 0;
        }
        $map = self::fetch_embed($cfg)['wp_map'];

        return isset($map[$wp_id]) ? (int) $map[$wp_id] : 0;
    }

    public static function saas_form_key(int $wp_id): string
    {
        $cfg = self::config();
        if (! $cfg || $wp_id <= 0) {
            return '';
        }
        $keys = self::fetch_embed($cfg)['wp_keys'] ?? [];
        $key  = isset($keys[$wp_id]) ? (string) $keys[$wp_id] : '';

        return preg_match('/^[A-Za-z0-9_-]{4,64}$/', $key) ? $key : '';
    }

    public static function mapped_wp_ids(): array
    {
        $cfg = self::config();
        if (! $cfg) {
            return [];
        }
        $embed = self::fetch_embed($cfg);

        return array_values(array_filter(array_map('intval', array_merge(
            array_keys((array) ($embed['wp_keys'] ?? [])),
            array_keys((array) ($embed['wp_map'] ?? []))
        ))));
    }

    public static function register_routes(): void
    {
        register_rest_route('emblem-studio-saas/v1', '/lead', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'receive_lead'],
        ]);

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

        register_rest_route('emblem-studio-saas/v1', '/wp/(?P<path>[a-z]+(?:/\d+)?)', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'read_wp'],
        ]);

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

        register_rest_route('emblem-studio-saas/v1', '/commerce/coupon', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'authorize'],
            'callback'            => [__CLASS__, 'read_coupon'],
        ]);
    }

    public static function authorize(WP_REST_Request $request): bool
    {
        $cfg = self::config();
        if (! $cfg) {
            return false;
        }
        $token = (string) $request->get_header('x_ess_token');

        return $token !== '' && hash_equals($cfg['site_token'], $token);
    }

    private const WP_READ_PATHS = [ 'types', 'categories', 'tags', 'pages', 'posts' ];

    public static function read_wp(WP_REST_Request $request)
    {
        $path = (string) $request->get_param('path');
        $base = strtok($path, '/');

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

    private static function commerce_status(array $result): int
    {
        if ($result['ok'] ?? false) {
            return 200;
        }

        return ($result['error'] ?? '') === 'not_found' ? 404 : 200;
    }

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

    public static function read_products(WP_REST_Request $request)
    {
        if (! class_exists('Emblem_Studio_Commerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unsupported', 'message' => 'This site runs an older Emblem Studio that cannot read the store.'], 200);
        }

        $term  = sanitize_text_field((string) $request->get_param('search'));
        $limit = (int) ($request->get_param('limit') ?: 10);

        return self::commerce_response(fn () => Emblem_Studio_Commerce::search($term, $limit));
    }

    public static function read_coupon(WP_REST_Request $request)
    {
        if (! class_exists('Emblem_Studio_Commerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unsupported', 'message' => 'This site runs an older Emblem Studio that cannot read the store.'], 200);
        }

        $code     = sanitize_text_field((string) $request->get_param('code'));
        $product  = (int) $request->get_param('product_id');
        $quantity = (int) ($request->get_param('quantity') ?: 1);

        return self::commerce_response(fn () => Emblem_Studio_Commerce::coupon_value($code, $product, $quantity));
    }

    public static function read_product(WP_REST_Request $request)
    {
        if (! class_exists('Emblem_Studio_Commerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unsupported', 'message' => 'This site runs an older Emblem Studio that cannot read the store.'], 200);
        }

        $id = (int) $request->get_param('id');

        return self::commerce_response(fn () => Emblem_Studio_Commerce::product($id));
    }

    public static function funnelkit_meta(WP_REST_Request $request)
    {
        return new WP_REST_Response(self::funnelkit_meta_payload(), 200);
    }

    private static function funnelkit_meta_payload(): array
    {
        $catalog = class_exists('Emblem_Studio_FunnelKit_Form_Feed') ? Emblem_Studio_FunnelKit_Form_Feed::catalog() : [];
        $assets  = [];
        foreach ($catalog as $asset) {
            $key = (string) ($asset['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $assets[] = array_merge($asset, [
                'feed' => Emblem_Studio_FunnelKit_Form_Feed::status($key),
            ]);
        }
        $fields = [];
        foreach (Emblem_Studio_FunnelKit::get_all_fields_grouped() as $group) {
            foreach ((array) ($group['fields'] ?? []) as $field) {
                $fields[] = $field;
            }
        }

        return [
            'ok'               => true,
            'contract_version' => 1,
            'connector_version'=> self::VERSION,
            'funnelkit'        => [
                'available' => self::funnelkit_available(),
                'pro'       => defined('BWFAN_PRO_VERSION') || class_exists('BWFAN_Pro'),
            ],
            'capabilities'     => [
                'direct_upsert' => class_exists('Emblem_Studio_FunnelKit_Upsert'),
                'form_feeds'    => class_exists('Emblem_Studio_FunnelKit_Form_Feed'),
                'idempotency'   => true,
            ],
            'lists'            => Emblem_Studio_FunnelKit::get_lists(),
            'tags'             => Emblem_Studio_FunnelKit::get_tags(),
            'fields'           => $fields,
            'assets'           => $assets,
        ];
    }

    public static function dispatch_funnelkit(WP_REST_Request $request)
    {
        [$body, $status] = self::run_dispatch((array) $request->get_json_params());

        return new WP_REST_Response($body, $status);
    }

    private static function run_dispatch(array $p): array
    {
        if (! self::funnelkit_available() || ! class_exists('Emblem_Studio_FunnelKit_Delivery')) {
            return [['ok' => false, 'error' => 'FunnelKit not installed'], 503];
        }

        $submission_id = sanitize_key((string) ($p['submission_id'] ?? ''));
        $asset_key     = sanitize_text_field((string) ($p['asset_key'] ?? ''));
        if ($submission_id === '' || ! preg_match('/^(?:saas|wp):\d+$/', $asset_key)) {
            return [['ok' => false, 'error' => 'Invalid dispatch identity'], 400];
        }

        $idempotency_key = 'ess_fk_' . md5($submission_id . '|' . $asset_key);
        $cached = get_transient($idempotency_key);
        if (is_array($cached)) {
            $cached['idempotent_replay'] = true;
            $cached_status = ! empty($cached['ok']) || ($cached['mode'] ?? '') === 'disabled' ? 200 : 500;
            return [$cached, $cached_status];
        }

        $direct = self::normalize_direct_lead((array) ($p['direct'] ?? []));
        if (empty($direct['email']) || ! is_email($direct['email'])) {
            return [['ok' => false, 'error' => 'Invalid email'], 400];
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
                return [$cached, $cached_status];
            }
            return [[
                'ok' => false,
                'mode' => 'in_progress',
                'contact_id' => null,
                'error' => 'This submission is already being processed. Retry shortly.',
            ], 409];
        }

        try {
            $result = Emblem_Studio_FunnelKit_Delivery::dispatch(
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

            return [$result, ! empty($result['ok']) || $result['mode'] === 'disabled' ? 200 : 500];
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

    private static function sanitize_field_value($value)
    {
        return Emblem_Studio_Ajax::sanitize_field_value($value);
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
            'contact_status' => (string) ($p['contact_status'] ?? '') === 'unsubscribed' ? 'unsubscribed' : 'subscribed',
        ];
    }

    public static function receive_lead(WP_REST_Request $request)
    {
        if (! self::funnelkit_available() || ! class_exists('Emblem_Studio_FunnelKit_Upsert')) {
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

        $result = Emblem_Studio_FunnelKit_Upsert::run([
            'email'      => $email,
            'first_name' => sanitize_text_field($p['f_name'] ?? ''),
            'last_name'  => sanitize_text_field($p['l_name'] ?? ''),
            'phone'      => sanitize_text_field($p['contact_no'] ?? ''),
            'tags'       => array_values(array_filter(array_map('sanitize_text_field', (array) ($p['tags'] ?? [])))),
            'lists'      => array_values(array_filter(array_map('absint', (array) ($p['lists'] ?? [])))),
            'custom'     => $custom,
            'contact_status' => (string) ($p['contact_status'] ?? '') === 'unsubscribed' ? 'unsubscribed' : 'subscribed',
        ]);

        return new WP_REST_Response($result, ! empty($result['ok']) ? 200 : 500);
    }

    public static function handle_lead_delete(WP_REST_Request $request)
    {
        if (! class_exists('Emblem_Studio_Leads')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Leads not available'], 503);
        }

        $p = (array) $request->get_json_params();
        $wp_lead_id = isset($p['wp_lead_id']) ? (int) $p['wp_lead_id'] : 0;
        $saas_lead_id = isset($p['saas_lead_id']) ? (int) $p['saas_lead_id'] : 0;

        $deleted = false;
        if ($wp_lead_id) {
            $deleted = Emblem_Studio_Leads::delete($wp_lead_id) || $deleted;
        }
        if ($saas_lead_id) {
            $deleted = Emblem_Studio_Leads::delete_by_saas_id($saas_lead_id) || $deleted;
        }

        return new WP_REST_Response(['ok' => true, 'deleted' => $deleted], 200);
    }
}

Emblem_Studio_SaaS_Connector::init();
