<?php
/**
 * Plugin Name: Noodled
 * Plugin URI:  https://github.com/cseven-dev/noodled-wp
 * Description: A full web version of the noodled note-taking app with family sharing, magic-link login, and GitHub sync.
 * Version:     1.1.134
 * Author:      Simon
 * License:     GPL-2.0-or-later
 * Text Domain: noodled
 *
 * @package Noodled
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Guard against a duplicate/stray copy of the plugin bootstrapping twice (e.g. a
// malformed earlier install left a second copy behind in wp-content/plugins).
// Without this, both copies require_once the same classes → fatal
// "Cannot redeclare class" and a 500 on every page.
if ( defined( 'NOODLED_VERSION' ) ) return;

define( 'NOODLED_VERSION', '1.1.134' );
define( 'NOODLED_FILE', __FILE__ );
define( 'NOODLED_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOODLED_URL', plugin_dir_url( __FILE__ ) );
define( 'NOODLED_BASENAME', plugin_basename( __FILE__ ) );

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/* ───── Fail-safe ─────
   If anything throws while loading or initialising, log it and stay out of the
   way instead of white-screening every page (including wp-admin). A plugin
   should never be able to lock you out of your own site. */
function noodled_fail_safe( \Throwable $e, string $phase ) {
	error_log( '[Noodled] Fatal during ' . $phase . ': ' . $e->getMessage()
		. ' in ' . $e->getFile() . ':' . $e->getLine() );
	error_log( '[Noodled] Trace: ' . $e->getTraceAsString() );

	if ( function_exists( 'update_option' ) ) {
		update_option( 'noodled_last_fatal', [
			'phase' => $phase,
			'msg'   => $e->getMessage(),
			'file'  => $e->getFile(),
			'line'  => $e->getLine(),
			'time'  => gmdate( 'c' ),
		], false );
	}

	if ( function_exists( 'add_action' ) ) {
		add_action( 'admin_notices', function () use ( $e, $phase ) {
			if ( ! current_user_can( 'manage_options' ) ) return;
			echo '<div class="notice notice-error"><p><strong>Noodled hit an error during '
				. esc_html( $phase ) . ' and disabled itself to keep your site online:</strong><br>'
				. esc_html( $e->getMessage() ) . '<br><code>'
				. esc_html( $e->getFile() ) . ':' . (int) $e->getLine() . '</code></p></div>';
		} );
	}
}

try {
	/* ───── Plugin Update Checker ───── */
	require_once NOODLED_PATH . 'plugin-update-checker/plugin-update-checker.php';
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
} catch ( \Throwable $e ) {
	noodled_fail_safe( $e, 'load' );
	return; // stop here — don't register hooks against half-loaded classes
}

/* ───── Init ───── */
function noodled_init() {
	try {
		// Load translations. Strings use the 'noodled' text domain; .mo files live in /languages.
		load_plugin_textdomain( 'noodled', false, dirname( NOODLED_BASENAME ) . '/languages' );

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

		// Lock the uploads dir against direct web access (one-time, idempotent).
		if ( ! get_option( 'noodled_uploads_protected' ) ) {
			Noodled_Attachments::protect_dir();
			update_option( 'noodled_uploads_protected', 1 );
		}

		Noodled_Settings::init();
		Noodled_App::init();
		Noodled_REST::init();
		Noodled_Sync::init();
		Noodled_Auth::init();

		// Daily trash auto-empty (no-op unless an admin sets a retention period).
		if ( ! wp_next_scheduled( 'noodled_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'noodled_daily_cleanup' );
		}
	} catch ( \Throwable $e ) {
		noodled_fail_safe( $e, 'init' );
	}
}
add_action( 'plugins_loaded', 'noodled_init' );

/**
 * Permanently delete notes that have sat in the trash longer than the admin's
 * configured retention period (and their attachment files/rows). Disabled by
 * default (0 days = keep forever).
 */
add_action( 'noodled_daily_cleanup', 'noodled_run_cleanup' );
function noodled_run_cleanup() {
	$days = (int) get_option( 'noodled_trash_retention', 0 );
	if ( $days <= 0 ) return;
	global $wpdb;
	$t      = $wpdb->prefix . 'noodled_notes';
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$ids    = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$t} WHERE deleted_at IS NOT NULL AND deleted_at < %s", $cutoff
	) );
	foreach ( $ids as $id ) {
		Noodled_Notes::permanent_delete( (int) $id );
	}
}

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
		// Never silently wp_die() the activation screen (that produced a 500 with
		// nothing in the log). Record the real cause and let activation complete —
		// noodled_init retries install() on the next load with the same fail-safe,
		// so a genuine problem is surfaced in php_errorlog instead of hidden.
		noodled_fail_safe( $e, 'activate' );
	}
}

/* ───── Deactivation ───── */
register_deactivation_hook( __FILE__, 'noodled_deactivate' );
function noodled_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'noodled_daily_cleanup' );
}
