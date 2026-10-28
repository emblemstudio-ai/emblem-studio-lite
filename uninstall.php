<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$timestamp = wp_next_scheduled( 'emblem_studio_daily_purge' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'emblem_studio_daily_purge' );
}

if ( get_option( 'emblem_studio_delete_data_on_uninstall' ) !== '1' ) {
	return;
}

$prefix = $wpdb->prefix;

$tables = [
	"{$prefix}emblemstudio_forms",
	"{$prefix}emblemstudio_events",
	"{$prefix}emblemstudio_leads",
	"{$prefix}emblemstudio_logs",
	"{$prefix}emblemstudio_form_revisions",
	"{$prefix}emblemstudio_variants",
	"{$prefix}emblemstudio_tests",
	"{$prefix}emblemstudio_rate_limits",
];

foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
}

delete_option( 'emblem_studio_db_version' );
delete_option( 'emblem_studio_delete_data_on_uninstall' );
