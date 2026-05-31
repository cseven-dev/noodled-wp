<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_App {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_rewrite' ] );
		add_action( 'init', [ __CLASS__, 'handle_token_early' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'render' ] );
		add_action( 'template_redirect', [ __CLASS__, 'render_homepage' ], 5 );
	}

	/**
	 * Handle magic link token verification early, before template routing.
	 */
	public static function handle_token_early() {
		if ( empty( $_GET['token'] ) ) return;
		if ( is_admin() ) return;

		$user = Noodled_Auth::verify_token( sanitize_text_field( $_GET['token'] ) );
		if ( $user ) {
			wp_redirect( home_url( '/' ) );
			exit;
		}
	}

	public static function register_rewrite() {
		add_rewrite_rule( '^noodled/?$', 'index.php?noodled_app=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'noodled_app';
		return $vars;
	}

	/**
	 * Hijack the homepage to serve noodled.
	 */
	public static function render_homepage() {
		if ( ! is_front_page() && ! is_home() ) return;

		// Handle token on homepage too
		if ( ! empty( $_GET['token'] ) ) {
			$user = Noodled_Auth::verify_token( sanitize_text_field( $_GET['token'] ) );
			if ( $user ) {
				wp_redirect( home_url( '/' ) );
				exit;
			}
			$login_error = 'Invalid or expired link. Please request a new one.';
			include NOODLED_PATH . 'templates/login.php';
			exit;
		}

		$current_user = Noodled_Auth::get_current_user();

		if ( ! $current_user ) {
			include NOODLED_PATH . 'templates/login.php';
			exit;
		}

		$config = [
			'apiBase'     => rest_url( 'noodled/v1' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'logoutNonce' => wp_create_nonce( 'log-out' ),
			'version'     => NOODLED_VERSION,
			'user'        => [
				'name'  => $current_user['name'],
				'email' => $current_user['email'],
				'admin' => $current_user['role'] === 'admin',
			],
		];

		include NOODLED_PATH . 'templates/app.php';
		exit;
	}

	public static function render() {
		if ( ! get_query_var( 'noodled_app' ) ) return;

		// Handle magic link token verification
		if ( ! empty( $_GET['token'] ) ) {
			$user = Noodled_Auth::verify_token( sanitize_text_field( $_GET['token'] ) );
			if ( $user ) {
				wp_redirect( home_url( '/' ) );
				exit;
			}
			$login_error = 'Invalid or expired link. Please request a new one.';
			include NOODLED_PATH . 'templates/login.php';
			exit;
		}

		// Check authentication: WP admin OR noodled session
		$current_user = Noodled_Auth::get_current_user();

		if ( ! $current_user ) {
			include NOODLED_PATH . 'templates/login.php';
			exit;
		}

		$config = [
			'apiBase'     => rest_url( 'noodled/v1' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'logoutNonce' => wp_create_nonce( 'log-out' ),
			'version'     => NOODLED_VERSION,
			'user'        => [
				'name'  => $current_user['name'],
				'email' => $current_user['email'],
				'admin' => $current_user['role'] === 'admin',
			],
		];

		include NOODLED_PATH . 'templates/app.php';
		exit;
	}
}
