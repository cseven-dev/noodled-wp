<?php
/**
 * Unit test for the service worker and share target entry points of
 * Noodled_App (serve_request's early routes), no WordPress.
 *
 *   php tests/app-share.test.php      (exit 0 only when every scenario passes)
 *
 * Loads the REAL includes/class-noodled-app.php and calls its pure helpers
 * (early_route, sw_headers, share_redirect_url, get_app_url) plus the private
 * serve_sw through reflection. Stubs: the handful of WordPress functions those
 * helpers call, and Noodled_Settings. serve_request itself only wires these
 * helpers to header(), wp_safe_redirect() and exit.
 */

// Buffer everything so header() in serve_sw is not refused for output already sent.
ob_start();

define( 'ABSPATH', __DIR__ . '/' );
define( 'NOODLED_PATH', dirname( __DIR__ ) . '/' );

// ── WordPress stubs ──

$GLOBALS['HOME'] = 'https://notes.example';

function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
// Close enough to core for these checks: strips tags, keeps newlines, trims.
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function home_url( $path = '' ) { return rtrim( $GLOBALS['HOME'], '/' ) . '/' . ltrim( (string) $path, '/' ); }
// Core's array form: append each key=value (values are passed in already encoded).
function add_query_arg( $args, $url ) {
	$pairs = [];
	foreach ( $args as $k => $v ) $pairs[] = $k . '=' . $v;
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . implode( '&', $pairs );
}

class Noodled_Settings {
	public static $homepage = true;
	public static function is_homepage_mode() { return self::$homepage; }
	public static function get_app_path() { return 'noodled'; }
}

require dirname( __DIR__ ) . '/includes/class-noodled-app.php';

// ── Harness ──

$results = [];
$fails   = [];
function ok( $cond, string $msg ): void { if ( ! $cond ) $GLOBALS['fails'][] = $msg; }
function same( $want, $got, string $msg ): void { ok( $want === $got, $msg . ' (want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ')' ); }
function scenario( string $name, callable $fn ): void {
	global $results, $fails;
	$GLOBALS['HOME'] = 'https://notes.example';
	Noodled_Settings::$homepage = true;
	$fails = [];
	try { $fn(); } catch ( Throwable $e ) { $fails[] = 'exception: ' . $e->getMessage() . ' @' . $e->getLine(); }
	$results[] = [ $name, $fails ];
	echo ( $fails ? 'FAIL' : 'PASS' ) . "  $name" . ( $fails ? "\n      - " . implode( "\n      - ", $fails ) : '' ) . "\n";
}
// Decoded query of a redirect target.
function query_of( string $url ): array {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
}
function share( array $post ): string { return Noodled_App::share_redirect_url( $post, Noodled_App::get_app_url() ); }

// ── Scenarios ──

scenario( 'only noodled_sw serves the worker, on any method', function () {
	same( 'sw', Noodled_App::early_route( [ 'noodled_sw' => '1.2.3' ], 'GET' ), 'GET ?noodled_sw' );
	same( 'sw', Noodled_App::early_route( [ 'noodled_sw' => '' ], 'GET' ), 'empty noodled_sw value' );
	same( 'sw', Noodled_App::early_route( [ 'noodled_sw' => '1' ], 'POST' ), 'POST ?noodled_sw' );
	same( '', Noodled_App::early_route( [], 'GET' ), 'plain app page' );
	same( '', Noodled_App::early_route( [ 'noodled_do' => 'new' ], 'GET' ), 'shortcut launch' );
	same( '', Noodled_App::early_route( [ 'noodled_legal' => 'privacy' ], 'GET' ), 'legal page' );
} );

scenario( 'only a POST with noodled_share is the no-worker share route', function () {
	same( 'share', Noodled_App::early_route( [ 'noodled_share' => '1' ], 'POST' ), 'POST ?noodled_share' );
	same( 'share', Noodled_App::early_route( [ 'noodled_share' => '1' ], 'post' ), 'method compared case-insensitively' );
	same( '', Noodled_App::early_route( [ 'noodled_share' => '1', 'title' => 'x' ], 'GET' ), 'a GET share is left to the app page (handleLaunchParams)' );
	same( '', Noodled_App::early_route( [], 'POST' ), 'POST without noodled_share' );
} );

scenario( 'the worker is sent with script, no-cache and nosniff headers', function () {
	same( [
		'Content-Type: application/javascript; charset=utf-8',
		'Cache-Control: no-cache, no-store, must-revalidate',
		'X-Content-Type-Options: nosniff',
	], Noodled_App::sw_headers(), 'header list' );
} );

scenario( 'serve_sw outputs exactly assets/sw.js whatever the request holds', function () {
	$m = new ReflectionMethod( 'Noodled_App', 'serve_sw' );
	$m->setAccessible( true );
	$_GET = [ 'noodled_sw' => '../../../wp-config.php' ];
	ob_start();
	$m->invoke( null );
	$out = ob_get_clean();
	$_GET = [];
	same( md5_file( NOODLED_PATH . 'assets/sw.js' ), md5( $out ), 'served bytes are assets/sw.js' );
} );

scenario( 'title, text and url are carried to the app URL', function () {
	$to = share( [ 'title' => 'Lunch spot', 'text' => "Line one\nLine two & more", 'url' => 'https://example.com/a?b=1&c=2' ] );
	same( 0, strpos( $to, 'https://notes.example/?' ), 'starts at the app URL' );
	same( [ 'noodled_share' => '1', 'title' => 'Lunch spot', 'text' => "Line one\nLine two & more", 'url' => 'https://example.com/a?b=1&c=2' ], query_of( $to ), 'decoded query' );
} );

scenario( 'each field is cut to 2000 characters (multibyte safe)', function () {
	$q = query_of( share( [ 'title' => str_repeat( 'é', 2500 ), 'text' => str_repeat( 'x', 9000 ), 'url' => 'https://e.example/' . str_repeat( 'p', 3000 ) ] ) );
	same( 2000, mb_strlen( $q['title'] ), 'title length' );
	same( str_repeat( 'é', 2000 ), $q['title'], 'title is whole characters' );
	same( 2000, mb_strlen( $q['text'] ), 'text length' );
	same( 2000, mb_strlen( $q['url'] ), 'url length' );
} );

scenario( 'fields are unslashed and sanitized, empty or non-string ones dropped', function () {
	$q = query_of( share( [ 'title' => "O\\'Brien <b>notes</b>", 'text' => '   ', 'url' => [ 'https://x.example' ], 'files' => 'ignored', 'redirect_to' => 'https://evil.example' ] ) );
	same( [ 'noodled_share' => '1', 'title' => "O'Brien notes" ], $q, 'query' );
} );

scenario( 'the redirect never leaves the site', function () {
	foreach ( [ true, false ] as $home ) {
		Noodled_Settings::$homepage = $home;
		$app = Noodled_App::get_app_url();
		$to  = share( [
			'title' => "x\r\nLocation: https://evil.example/",
			'text'  => '//evil.example/',
			'url'   => 'https://evil.example/@notes.example',
		] );
		same( 'notes.example', parse_url( $to, PHP_URL_HOST ), 'host (homepage mode ' . var_export( $home, true ) . ')' );
		same( parse_url( $app, PHP_URL_PATH ), parse_url( $to, PHP_URL_PATH ), 'path is the app path' );
		ok( strpos( $to, "\r" ) === false && strpos( $to, "\n" ) === false, 'no raw line breaks in the target' );
		same( "x\r\nLocation: https://evil.example/", query_of( $to )['title'], 'header text stays inside the encoded value' );
	}
} );

scenario( 'app URL is the site root in homepage mode, else the app path', function () {
	same( 'https://notes.example/', Noodled_App::get_app_url(), 'homepage mode' );
	Noodled_Settings::$homepage = false;
	same( 'https://notes.example/noodled/', Noodled_App::get_app_url(), 'path mode' );
	same( 0, strpos( share( [ 'title' => 't' ] ), 'https://notes.example/noodled/?noodled_share=1' ), 'share target follows the app path' );
} );

// ── Summary ──

$failed = count( array_filter( $results, static fn( $r ) => $r[1] ) );
echo "\n" . ( count( $results ) - $failed ) . " passed, {$failed} failed (" . count( $results ) . " scenarios)\n";
exit( $failed ? 1 : 0 );
