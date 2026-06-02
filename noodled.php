<?php
/**
 * Plugin Name: Noodled
 * Plugin URI:  https://github.com/cseven-dev/noodled-wp
 * Description: A full web version of the noodled note-taking app with family sharing, magic-link login, and GitHub sync.
 * Version:     1.1.53
 * Author:      Simon
 * License:     GPL-2.0-or-later
 * Text Domain: noodled
 *
 * @package Noodled
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NOODLED_VERSION', '1.1.53' );
define( 'NOODLED_FILE', __FILE__ );
define( 'NOODLED_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOODLED_URL', plugin_dir_url( __FILE__ ) );
define( 'NOODLED_BASENAME', plugin_basename( __FILE__ ) );

/* ───── Plugin Update Checker ───── */
require_once NOODLED_PATH . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://c7.ca/noodled/metadata.json',
    __FILE__,
    'noodled'
);

/* ───── Includes ───── */
require_once NOODLED_PATH . 'includes/class-noodled-db.php';
require_once NOODLED_PATH . 'includes/class-noodled-settings.php';
require_once NOODLED_PATH . 'includes/class-noodled-app.php';
require_once NOODLED_PATH . 'includes/class-noodled-notebooks.php';
require_once NOODLED_PATH . 'includes/class-noodled-notes.php';
require_once NOODLED_PATH . 'includes/class-noodled-attachments.php';
require_once NOODLED_PATH . 'includes/class-noodled-frontmatter.php';
require_once NOODLED_PATH . 'includes/class-noodled-github.php';
require_once NOODLED_PATH . 'includes/class-noodled-sync.php';
require_once NOODLED_PATH . 'includes/class-noodled-auth.php';
require_once NOODLED_PATH . 'includes/class-noodled-permissions.php';
require_once NOODLED_PATH . 'includes/class-noodled-plaud.php';
require_once NOODLED_PATH . 'includes/class-noodled-evernote.php';
require_once NOODLED_PATH . 'includes/class-noodled-rest.php';

/* ───── Init ───── */
function noodled_init() {
	// Auto-install tables if missing
	if ( get_option( 'noodled_db_version' ) !== NOODLED_VERSION ) {
		Noodled_DB::install();
	}

	// One-time migration to the per-user-private model.
	if ( ! get_option( 'noodled_privacy_migrated' ) ) {
		noodled_migrate_privacy();
		update_option( 'noodled_privacy_migrated', 1 );
	}

	// Revoke stale grants to admin-owned notebooks (the old shared
	// "default notebook" auto-grant leaked admins' notes to every member).
	if ( ! get_option( 'noodled_admin_grants_revoked' ) ) {
		noodled_revoke_admin_grants();
		update_option( 'noodled_admin_grants_revoked', 1 );
	}

	Noodled_Settings::init();
	Noodled_App::init();
	Noodled_REST::init();
	Noodled_Sync::init();
	Noodled_Auth::init();
}
add_action( 'plugins_loaded', 'noodled_init' );

/**
 * One-time migration to per-user-private noodles:
 * - assign any orphaned (owner_id=0) notebooks to the first admin user
 * - seed a private "My Notes" for existing users who own no notebooks
 */
function noodled_migrate_privacy() {
	global $wpdb;
	$nb = $wpdb->prefix . 'noodled_notebooks';
	$us = $wpdb->prefix . 'noodled_users';

	$admin_id = (int) $wpdb->get_var( "SELECT id FROM {$us} WHERE role = 'admin' ORDER BY id ASC LIMIT 1" );
	if ( $admin_id ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$nb} SET owner_id = %d WHERE owner_id = 0", $admin_id ) );
	}

	$users = $wpdb->get_col( "SELECT id FROM {$us} WHERE role IN ('admin','member')" );
	foreach ( $users as $uid ) {
		Noodled_Auth::seed_new_user( (int) $uid ); // idempotent: skips users who already own notebooks
	}
}

/**
 * Revoke every notebook-permission grant pointing at an admin-owned notebook.
 * Pre-privacy versions auto-granted all members access to a shared "default
 * notebook" (often the admin's), which leaked the admin's notes. Owner-initiated
 * shares created after the privacy update are unaffected because no admin-owned
 * notebook would have been shared through the new UI yet; if it was, re-share it.
 */
function noodled_revoke_admin_grants() {
	global $wpdb;
	$nb = $wpdb->prefix . 'noodled_notebooks';
	$us = $wpdb->prefix . 'noodled_users';
	$pm = $wpdb->prefix . 'noodled_permissions';
	$wpdb->query(
		"DELETE FROM {$pm} WHERE notebook_id IN (
			SELECT id FROM {$nb} WHERE owner_id IN ( SELECT id FROM {$us} WHERE role = 'admin' )
		)"
	);
}

/* ───── Activation ───── */
register_activation_hook( __FILE__, 'noodled_activate' );
function noodled_activate() {
	try {
		Noodled_DB::install();
		Noodled_App::register_rewrite();
		flush_rewrite_rules();
	} catch ( \Throwable $e ) {
		wp_die( 'Noodled activation failed: ' . esc_html( $e->getMessage() ) . ' in ' . esc_html( $e->getFile() ) . ':' . $e->getLine() );
	}
}

/* ───── Deactivation ───── */
register_deactivation_hook( __FILE__, 'noodled_deactivate' );
function noodled_deactivate() {
	flush_rewrite_rules();
}
