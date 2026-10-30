<?php
/**
 * Plugin Name:       Emblem Studio Lite
 * Description:       On-site forms, popups and ribbons that convert — hand-authored HTML/CSS, with FunnelKit Automations integration.
 * Version:           1.3.113-beta
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Emblem Studio
 * License:           GPL-2.0-or-later
 * Text Domain:       emblem-studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMBLEM_STUDIO_VERSION', '1.3.113-beta' );
define( 'EMBLEM_STUDIO_DB_VERSION', '48' );
define( 'EMBLEM_STUDIO_FILE', __FILE__ );
define( 'EMBLEM_STUDIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'EMBLEM_STUDIO_URL', plugin_dir_url( __FILE__ ) );

require_once EMBLEM_STUDIO_PATH . 'includes/class-es-caps.php';
if ( ! defined( 'EMBLEM_STUDIO_EDITION' ) ) {
	define( 'EMBLEM_STUDIO_EDITION', 'lite' );
}

require_once EMBLEM_STUDIO_PATH . 'includes/class-es-install.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-locks.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-logger.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-repo.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-preset-repo.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-preset-ajax.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-revisions.php';
if ( ES_Caps::has( 'revisions' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-scheduled-revisions.php';
}
if ( ES_Caps::has( 'schedule_window' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-schedule.php';
}
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-consent-log.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-templates.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-event-recorder.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-rate-limit.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-stamp.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-funnelkit-upsert.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-funnelkit.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-funnelkit-form-feed.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-funnelkit-delivery.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-field-matcher.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-ajax.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-reports.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-leads.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-parser.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-html-sanitizer.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-measurement-transaction.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-measurement.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-privacy.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-display-rules.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-commerce.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-live-values.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-click-tag.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-purchases.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-recent-orders.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-rest.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-no-delay.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-shortcode.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-preview.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-plugin.php';

if ( ES_Caps::is_lite() && file_exists( EMBLEM_STUDIO_PATH . 'includes/connector.php' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/connector.php';
}

if ( ES_Caps::has( 'ab' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-ab.php';
}
if ( ES_Caps::has( 'webhooks' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-webhooks.php';
}
if ( ES_Caps::has( 'linked_forms' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-connections.php';
}
if ( ES_Caps::has( 'auto_shells' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-auto-shells.php';
}
if ( ES_Caps::has( 'design_system' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-design-system-revisions.php';
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-design-system.php';
}

if ( is_admin() ) {
	require_once EMBLEM_STUDIO_PATH . 'admin/class-es-admin.php';
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-authoring.php';
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-asset-checks.php';
}

register_activation_hook( __FILE__, [ 'ES_Install', 'activate' ] );

add_action( 'plugins_loaded', [ 'ES_Install', 'maybe_migrate' ], 5 );
add_action( 'admin_notices', [ 'ES_Install', 'migration_notice' ] );
add_action( 'plugins_loaded', static function () { ES_Plugin::instance(); } );
