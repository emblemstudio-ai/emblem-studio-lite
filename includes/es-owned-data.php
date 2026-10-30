<?php
/**
 * Everything Emblem Studio stores in WordPress, in one list (audit S4).
 *
 * uninstall.php reads this. It runs without the plugin loaded, so this file
 * is plain data with no dependencies. tests/unit/OwnedDataTest.php scans the
 * source for every option, transient and scheduled job the code uses and
 * fails if one is not covered here, so a new one cannot be forgotten again.
 *
 * Prefixes are used only where they are ours alone. `es_` and `ess_` are not
 * (other plugins use them), so those names are listed one by one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	// Every table is {$wpdb->prefix}emblemstudio_<name>.
	'table_prefix'      => 'emblemstudio_',

	// Credentials. Removed on EVERY uninstall, even when the data is kept: a
	// key left on a site without the plugin is a loose password to another
	// system. Reconnecting after a reinstall puts them back.
	'secrets'           => [
		'ess_connection',                // the web app connection key (Lite)
		'emblem_studio_fk_api_key',      // a remote FunnelKit site's login
		'emblem_studio_fk_api_secret',
	],

	'option_prefixes'   => [ 'emblem_studio_', 'emblemstudio_', 'optin_studio_' ],
	'options'           => [
		'es_import_state',
		'es_import_status',
		'es_live_values',
		'es_live_values_sent',
		'es_postdecouple_cfg',
		'es_postdecouple_state',
		'es_saas_pull_lead_deletions_after',
		'es_saas_pull_leads_after',
		'es_saas_pull_stats_after',
		'es_saas_stats_reset_at',
		'ess_connection',
		'ess_embed_copy',
		'ess_recent_orders_wanted',
		'ess_timezone_reported',
	],

	'transient_prefixes' => [ 'emblem_studio_', 'esf_rl_', 'ess_fk_' ],
	'transients'         => [
		'es_import_lock',
		'es_live_values_lock',
		'es_saas_forms_fresh',
		'ess_connector_forms',
	],

	// Scheduled jobs. Always cleared: their code is gone with the plugin, so
	// they can only fail.
	'cron_prefixes'     => [ 'emblem_studio_', 'es_saas_', 'es_live_values_', 'es_purchases_', 'es_recent_orders_' ],
];
