<?php
/**
 * Plugin Name: Noodled
 * Plugin URI:  https://github.com/cseven-dev/noodled-wp
 * Description: A full web version of the noodled note-taking app with family sharing, magic-link login, and GitHub sync.
 * Version:     1.1.1
 * Author:      Simon
 * License:     GPL-2.0-or-later
 * Text Domain: noodled
 *
 * @package Noodled
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NOODLED_VERSION', '1.1.1' );
define( 'NOODLED_FILE', __FILE__ );
define( 'NOODLED_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOODLED_URL', plugin_dir_url( __FILE__ ) );
define( 'NOODLED_BASENAME', plugin_basename( __FILE__ ) );

/* ───── Plugin Update Checker ───── */
require_once NOODLED_PATH . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://github.com/cseven-dev/noodled-wp/',
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
require_once NOODLED_PATH . 'includes/class-noodled-rest.php';

/* ───── Init ───── */
function noodled_init() {
	// Auto-install tables if missing
	if ( get_option( 'noodled_db_version' ) !== NOODLED_VERSION ) {
		Noodled_DB::install();
	}

	Noodled_Settings::init();
	Noodled_App::init();
	Noodled_REST::init();
	Noodled_Sync::init();
	Noodled_Auth::init();
}
add_action( 'plugins_loaded', 'noodled_init' );

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
