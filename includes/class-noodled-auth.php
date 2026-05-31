<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Auth {

	private static $cookie_name = 'noodled_session';
	private static $token_ttl   = 15 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		register_rest_route( 'noodled/v1', '/auth/login', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_login' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'noodled/v1', '/auth/verify', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_verify' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'noodled/v1', '/auth/logout', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_logout' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'noodled/v1', '/auth/me', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_me' ],
			'permission_callback' => '__return_true',
		] );
	}

	// ── Magic Link ──

	public static function send_magic_link( string $email ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';
		$email = sanitize_email( $email );

		$user = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE email = %s", $email
		), ARRAY_A );

		if ( ! $user ) {
			return [ 'error' => 'Email not found. Ask the admin to invite you.' ];
		}

		$token  = wp_generate_password( 48, false );
		$expiry = gmdate( 'Y-m-d H:i:s', time() + self::$token_ttl );

		$wpdb->update( $table, [
			'token'        => $token,
			'token_expiry' => $expiry,
		], [ 'id' => $user['id'] ] );

		$link    = home_url( '/?token=' . $token );
		$subject = 'Your noodled login link';
		$body    = "Hi {$user['display_name']},\n\nClick to log in to noodled:\n\n{$link}\n\nThis link expires in 15 minutes.\n\n— noodled";

		$sent = wp_mail( $email, $subject, $body );

		if ( ! $sent ) {
			return [ 'error' => 'Failed to send email. Check your mail settings.' ];
		}

		return [ 'success' => true, 'message' => 'Check your email for a login link.' ];
	}

	/**
	 * Verify a magic link token and create a session.
	 */
	public static function verify_token( string $token ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';

		$user = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE token = %s AND token_expiry > %s",
			$token, gmdate( 'Y-m-d H:i:s' )
		), ARRAY_A );

		if ( ! $user ) return null;

		// Create session
		$session_token = wp_generate_password( 48, false );

		$wpdb->update( $table, [
			'token'         => null,
			'token_expiry'  => null,
			'session_token' => $session_token,
			'last_login'    => current_time( 'mysql', true ),
		], [ 'id' => $user['id'] ] );

		// Set cookie (30 days)
		setcookie( self::$cookie_name, $session_token, [
			'expires'  => time() + 30 * DAY_IN_SECONDS,
			'path'     => '/',
			'httponly' => true,
			'samesite' => 'Lax',
		] );

		return $user;
	}

	/**
	 * Get the current noodled user from session cookie or WP login.
	 */
	public static function get_current_user(): ?array {
		// Check WP admin first — auto-authenticate
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$wp_user = wp_get_current_user();
			return [
				'id'    => 0,
				'email' => $wp_user->user_email,
				'name'  => $wp_user->display_name,
				'role'  => 'admin',
				'wp'    => true,
			];
		}

		// Check noodled session cookie
		$session = $_COOKIE[ self::$cookie_name ] ?? '';
		if ( ! $session ) return null;

		global $wpdb;
		$user = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_users WHERE session_token = %s",
			$session
		), ARRAY_A );

		if ( ! $user ) return null;

		return [
			'id'    => (int) $user['id'],
			'email' => $user['email'],
			'name'  => $user['display_name'],
			'role'  => $user['role'],
			'wp'    => false,
		];
	}

	/**
	 * Check if any user is authenticated (WP admin or noodled session).
	 */
	public static function is_authenticated(): bool {
		return self::get_current_user() !== null;
	}

	public static function logout(): void {
		$session = $_COOKIE[ self::$cookie_name ] ?? '';
		if ( $session ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'noodled_users', [
				'session_token' => null,
			], [ 'session_token' => $session ] );
		}

		setcookie( self::$cookie_name, '', [
			'expires'  => time() - 3600,
			'path'     => '/',
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}

	// ── User Management (admin) ──

	public static function invite_user( string $email, string $display_name = '', string $role = 'member' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) return [ 'error' => 'Invalid email' ];

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE email = %s", $email
		) );
		if ( $existing ) return [ 'error' => 'User already exists' ];

		$wpdb->insert( $table, [
			'email'        => $email,
			'display_name' => sanitize_text_field( $display_name ) ?: explode( '@', $email )[0],
			'role'         => in_array( $role, [ 'admin', 'member' ], true ) ? $role : 'member',
			'created_at'   => current_time( 'mysql', true ),
		] );

		return [ 'id' => (int) $wpdb->insert_id, 'email' => $email ];
	}

	public static function get_all_users(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, email, display_name, role, last_login, created_at FROM {$wpdb->prefix}noodled_users ORDER BY created_at ASC",
			ARRAY_A
		);
		return $rows ?: [];
	}

	public static function delete_user( int $id ): bool {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'noodled_permissions', [ 'user_id' => $id ] );
		$wpdb->delete( $wpdb->prefix . 'noodled_users', [ 'id' => $id ] );
		return true;
	}

	// ── REST Handlers ──

	public static function handle_login( \WP_REST_Request $req ): \WP_REST_Response {
		$email = $req->get_param( 'email' );
		if ( ! $email ) return new \WP_REST_Response( [ 'error' => 'Email required' ], 400 );
		return new \WP_REST_Response( self::send_magic_link( $email ) );
	}

	public static function handle_verify( \WP_REST_Request $req ): \WP_REST_Response {
		$token = $req->get_param( 'token' );
		if ( ! $token ) return new \WP_REST_Response( [ 'error' => 'Token required' ], 400 );

		$user = self::verify_token( $token );
		if ( ! $user ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid or expired link' ], 401 );
		}

		return new \WP_REST_Response( [ 'success' => true, 'name' => $user['display_name'] ] );
	}

	public static function handle_logout( \WP_REST_Request $req ): \WP_REST_Response {
		self::logout();
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public static function handle_me( \WP_REST_Request $req ): \WP_REST_Response {
		$user = self::get_current_user();
		if ( ! $user ) return new \WP_REST_Response( [ 'error' => 'Not authenticated' ], 401 );
		return new \WP_REST_Response( $user );
	}
}
