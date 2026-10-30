<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'table_prefix'      => 'emblemstudio_',

	'secrets'           => [
		'ess_connection',
		'emblem_studio_fk_api_key',
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

	'cron_prefixes'     => [ 'emblem_studio_', 'es_saas_', 'es_live_values_', 'es_purchases_', 'es_recent_orders_' ],
];
