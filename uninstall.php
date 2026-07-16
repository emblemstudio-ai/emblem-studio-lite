<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Always clear the scheduled purge — its hook no longer exists after uninstall.
$timestamp = wp_next_scheduled( 'emblem_studio_daily_purge' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'emblem_studio_daily_purge' );
}

/*
 * Data is KEPT by default. We only wipe the database when the admin has
 * explicitly opted in beforehand, via Settings → "When the plugin is deleted →
 * Also delete all Emblem Studio data". WordPress runs uninstall.php
 * non-interactively, so there's no prompt at delete time — this stored
 * preference is the "ask". It guards against an accidental delete (or a
 * delete-then-reinstall) silently destroying every form, lead, and stat.
 *
 * NOTE: keep this option key in sync with admin/class-es-admin.php +
 * admin/views/settings.php (uninstall.php runs without the plugin loaded, so it
 * can't share a class constant).
 */
if ( get_option( 'emblem_studio_delete_data_on_uninstall' ) !== '1' ) {
	return; // keep tables + settings intact for a possible reinstall
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
];

foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
}

delete_option( 'emblem_studio_db_version' );
delete_option( 'emblem_studio_delete_data_on_uninstall' );
