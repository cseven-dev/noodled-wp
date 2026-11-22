<?php
/*
 * Renders the REAL templates/app.php (or templates/login.php) outside WordPress
 * for tests/visual.js.
 *
 * Run:   php tests/visual-render.php [origin] [app|login]   (prints the HTML to stdout)
 *
 * Defines only the WordPress functions, plugin constants and plugin classes the
 * templates call, sets $config the way Noodled_App::build_config() does, then
 * includes the template unchanged.
 */

$origin   = rtrim( $argv[1] ?? 'http://noodled.test', '/' );
$template = ( $argv[2] ?? 'app' ) === 'login' ? 'login' : 'app';

define( 'ABSPATH', __DIR__ . '/' );
define( 'NOODLED_PATH', dirname( __DIR__ ) . '/' );
define( 'NOODLED_URL', $origin . '/wp-content/plugins/noodled/' );
define( 'NOODLED_VERSION', '0.0.0' );

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_js( $s ) { return addcslashes( htmlspecialchars( (string) $s, ENT_COMPAT, 'UTF-8' ), "'\\n\r" ); }
function __( $s, $domain = 'default' ) { return $s; }
function esc_html__( $s, $domain = 'default' ) { return esc_html( $s ); }
function esc_attr__( $s, $domain = 'default' ) { return esc_attr( $s ); }
function esc_html_e( $s, $domain = 'default' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $domain = 'default' ) { echo esc_attr( $s ); }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
function includes_url( $path = '' ) { global $origin; return $origin . '/wp-includes/' . ltrim( $path, '/' ); }
function rest_url( $path = '' ) { global $origin; return $origin . '/wp-json/' . ltrim( $path, '/' ); }
function get_locale() { return 'en_US'; }

class Noodled_Settings {
	public static function get_brand_name() { return 'noodled'; }
	public static function get_brand_tagline() { return 'Your notes, everywhere.'; }
	public static function get_accent_color() { return '#0078d4'; }
	public static function allow_registration() { return false; }
}
class Noodled_Plaud {
	public static function is_configured() { return false; }
}
class Noodled_App {
	public static function get_app_url() { global $origin; return $origin . '/'; }
}

if ( $template === 'login' ) {
	$login_error = '';
	include NOODLED_PATH . 'templates/login.php';
	return;
}

// Same shape as Noodled_App::build_config().
$config = [
	'apiBase'   => $origin . '/wp-json/noodled/v1',
	'nonce'     => 'visual-nonce',
	'version'   => NOODLED_VERSION,
	'whatsNew'  => '',
	'appUrl'    => $origin . '/',
	'brandName' => 'noodled',
	'map'       => [ 'provider' => 'osm', 'mapboxToken' => '', 'gmapsKey' => '' ],
	'aiEnabled' => false,
	'user'      => [ 'name' => 'Sam Rivera', 'email' => 'sam@example.com', 'admin' => true, 'owner' => true ],
];

include NOODLED_PATH . 'templates/app.php';
