<?php
/**
 * Plugin Name:       Emblem Studio Lite
 * Description:       On-site forms, popups and ribbons that convert — hand-authored HTML/CSS, with FunnelKit Automations integration.
 * Version:           1.0.9-beta
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Emblem Studio
 * License:           GPL-2.0-or-later
 * Text Domain:       emblem-studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMBLEM_STUDIO_VERSION', '1.0.9-beta' );
define( 'EMBLEM_STUDIO_DB_VERSION', '31' );
define( 'EMBLEM_STUDIO_FILE', __FILE__ );
define( 'EMBLEM_STUDIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'EMBLEM_STUDIO_URL', plugin_dir_url( __FILE__ ) );

// Edition gate (P5). The full plugin is 'full' (everything on); the generated
// Lite build sets this to 'lite' in its own main file and ships without the
// gated module files below. Loaded first so the requires can consult it.
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-caps.php';
if ( ! defined( 'EMBLEM_STUDIO_EDITION' ) ) {
	define( 'EMBLEM_STUDIO_EDITION', 'lite' );
}

require_once EMBLEM_STUDIO_PATH . 'includes/class-es-install.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-locks.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-logger.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-repo.php';
// Revisions: loaded in EVERY edition. Lite RECORDS revisions (they quietly
// accumulate in the DB, ready the moment a free account is connected) but gates
// the VIEW — the editor shows a locked Revisions tab with a connect upsell. The
// viewing/restore admin handlers stay behind the 'revisions' capability.
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-revisions.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-templates.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-event-recorder.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-funnelkit.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-ajax.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-reports.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-leads.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-form-parser.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-html-sanitizer.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-privacy.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-display-rules.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-rest.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-shortcode.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-preview.php';
require_once EMBLEM_STUDIO_PATH . 'includes/class-es-plugin.php';

// Lite edition bundles the Emblem Studio SaaS connector (built in), dormant
// until the site is connected to a free account. The full plugin never ships
// this file, so the guard is doubly safe.
if ( ES_Caps::is_lite() && file_exists( EMBLEM_STUDIO_PATH . 'includes/connector.php' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/connector.php';
}

// Account-gated modules (Free/Paid). The Lite build omits these files; the
// `ES_Caps` checks here skip the require so Lite never looks for them, and the
// matching class_exists() guards at the call sites keep the core safe.
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
// Design System (Pro/full only) — shared per-install CSS every form inherits.
// Lite omits these files; class_exists() guards at the render call sites keep
// the core safe when they're absent.
if ( ES_Caps::has( 'design_system' ) ) {
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-design-system-revisions.php';
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-design-system.php';
}

// Admin-only classes — skip the parse + load cost on front-end page
// views. is_admin() is true for wp-admin, admin-ajax and admin-post,
// which covers everywhere ES_Admin's handlers actually run. ES_Admin
// (the plugin's largest file) is never touched by the front end, so a
// normal page view no longer loads it.
if ( is_admin() ) {
	require_once EMBLEM_STUDIO_PATH . 'admin/class-es-admin.php';
	require_once EMBLEM_STUDIO_PATH . 'includes/class-es-authoring.php';
}

register_activation_hook( __FILE__, [ 'ES_Install', 'activate' ] );

add_action( 'plugins_loaded', [ 'ES_Install', 'maybe_migrate' ], 5 );
// Wrapped in a closure so nothing is returned to the action dispatcher
// — ES_Plugin::instance() hands back the singleton, which an action
// callback shouldn't do.
add_action( 'plugins_loaded', static function () { ES_Plugin::instance(); } );
