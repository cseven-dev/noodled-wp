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

		register_rest_route( 'noodled/v1', '/auth/pin', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_pin' ],
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

		register_rest_route( 'noodled/v1', '/auth/register', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_register' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'noodled/v1', '/auth/request', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_request' ],
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

		$pin    = str_pad( wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$expiry = gmdate( 'Y-m-d H:i:s', time() + self::$token_ttl );

		$wpdb->update( $table, [
			'token'        => $pin,
			'token_expiry' => $expiry,
		], [ 'id' => $user['id'] ] );

		$brand   = Noodled_Settings::get_brand_name();
		$app_url = self::get_app_url();
		$magic   = add_query_arg( [
			'noodled_email' => $email,
			'noodled_login' => $pin,
		], $app_url );
		$subject = "Your {$brand} login link";
		$body    = "Hi {$user['display_name']},\n\n"
			. "Click here to sign in to {$brand} — no PIN to type:\n{$magic}\n\n"
			. "Or enter this PIN manually at {$app_url}\nPIN: {$pin}\n\n"
			. "This link expires in 15 minutes.\n\n— {$brand}";

		$sent = wp_mail( $email, $subject, $body );

		if ( ! $sent ) {
			return [ 'error' => 'Failed to send email. Check your mail settings.' ];
		}

		return [ 'success' => true, 'message' => 'Check your email for a login PIN.' ];
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

		// Set cookie (1 year)
		setcookie( self::$cookie_name, $session_token, [
			'expires'  => time() + 365 * DAY_IN_SECONDS,
			'path'     => '/',
			'httponly' => true,
			'secure'   => is_ssl(),
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

		// Block pending users
		if ( $user['role'] === 'pending' ) return null;

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
			'secure'   => is_ssl(),
			'samesite' => 'Lax',
		] );
	}

	/** Public URL of the full-screen app (honors homepage mode). */
	public static function get_app_url(): string {
		return Noodled_App::get_app_url();
	}

	// ── Access requests ("Get a noodle") ──

	/**
	 * Public access request from the landing page. Always creates a pending
	 * user (or reports an existing one) and notifies the admin. No open-
	 * registration gate — every request waits for admin approve/deny.
	 */
	public static function request_access( string $email, string $name = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';
		$email = sanitize_email( $email );
		$name  = sanitize_text_field( $name );

		if ( ! is_email( $email ) ) return [ 'error' => 'Please enter a valid email address.' ];

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, role FROM {$table} WHERE email = %s", $email
		), ARRAY_A );

		if ( $existing ) {
			if ( $existing['role'] === 'pending' ) {
				return [ 'success' => true, 'message' => "You're already on the list — we'll be in touch soon." ];
			}
			return [ 'success' => true, 'message' => 'You already have access. Just sign in!' ];
		}

		$wpdb->insert( $table, [
			'email'        => $email,
			'display_name' => $name ?: explode( '@', $email )[0],
			'role'         => 'pending',
			'created_at'   => current_time( 'mysql', true ),
		] );

		self::notify_admin_of_request( $name ?: $email, $email );

		return [ 'success' => true, 'message' => "Request received! We'll email you once you're approved." ];
	}

	/** Email a user that a notebook or note has been shared with them. */
	public static function notify_share( int $target_id, string $kind, string $title, string $sharer = '' ): void {
		global $wpdb;
		$u = $wpdb->get_row( $wpdb->prepare(
			"SELECT email, display_name FROM {$wpdb->prefix}noodled_users WHERE id = %d", $target_id
		), ARRAY_A );
		if ( ! $u || ! is_email( $u['email'] ) ) return;

		$brand   = Noodled_Settings::get_brand_name();
		$app     = self::get_app_url();
		$by      = $sharer ? " by {$sharer}" : '';
		$subject = "A {$kind} was shared with you on {$brand}";
		$body    = "Hi {$u['display_name']},\n\n"
			. "The {$kind} \"{$title}\"{$by} is now shared with you on {$brand}.\n\n"
			. "Open {$brand}: {$app}\n\n— {$brand}";
		wp_mail( $u['email'], $subject, $body );
	}

	/** Email the site admin that someone has requested access. */
	public static function notify_admin_of_request( string $name, string $email ): void {
		$brand     = Noodled_Settings::get_brand_name();
		$to        = Noodled_Settings::get_notify_email();
		$users_url = admin_url( 'admin.php?page=noodled-users' );
		$subject   = "[{$brand}] New noodle request from {$name}";
		$body      = "{$name} ({$email}) has requested a noodle.\n\nApprove or deny here:\n{$users_url}";
		wp_mail( $to, $subject, $body );
	}

	// ── User Management (admin) ──

	/**
	 * Provision a brand-new user with their own private starter notebook and a
	 * welcome note. Each noodle is private by default — no shared notebook.
	 * Idempotent: does nothing if the user already owns notebooks.
	 */
	public static function seed_new_user( int $user_id ): void {
		if ( $user_id <= 0 ) return;
		global $wpdb;
		$owns = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}noodled_notebooks WHERE owner_id = %d", $user_id
		) );
		if ( $owns > 0 ) return;

		$brand = Noodled_Settings::get_brand_name();
		$body  = "# Welcome to {$brand}! \u{1F35C}\n\n"
			. "This is **your** private notebook — only you can see it unless you choose to share.\n\n"
			. "## A few things to try\n\n"
			. "- Create notebooks (folders) in the left sidebar\n"
			. "- Link notes together with `[[Note Title]]` wiki-links\n"
			. "- Tag a thought with `#ideas` to find it later\n"
			. "- Right-click a note or notebook to **Share** it with another user\n\n"
			. "## Privacy\n\n"
			. "Your notes are private to your account. Sharing is always opt-in, per notebook or per note, read-only or read/write.\n\n"
			. "Happy noodling.\n";

		// Noodled_Notes::create() ensures the 'My Notes' notebook exists owned by
		// this user, then creates the welcome note inside it.
		Noodled_Notes::create( 'My Notes', "Welcome to {$brand}", $body, $user_id );
	}

	/** Approve a pending user: promote to member, grant default notebook, email a login PIN. */
	public static function approve_user( int $id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';
		$user  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $user ) return [ 'error' => 'User not found' ];

		$wpdb->update( $table, [ 'role' => 'member' ], [ 'id' => $id ] );
		self::seed_new_user( $id );

		$result = self::send_magic_link( $user['email'] );
		return [ 'success' => true, 'emailed' => empty( $result['error'] ) ];
	}

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

		$new_id = (int) $wpdb->insert_id;
		self::seed_new_user( $new_id );
		// Auto-email the invitee a ready-to-use login PIN + link.
		self::send_magic_link( $email );

		return [ 'id' => $new_id, 'email' => $email ];
	}

	public static function get_pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}noodled_users WHERE role = 'pending'"
		);
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

	public static function handle_pin( \WP_REST_Request $req ): \WP_REST_Response {
		$result = self::login_with_pin( $req->get_param( 'email' ), $req->get_param( 'pin' ) );
		$status = isset( $result['error'] ) ? ( $result['status'] ?? 400 ) : 200;
		unset( $result['status'] );
		return new \WP_REST_Response( $result, $status );
	}

	/**
	 * Verify an email + PIN, create a session, and set the login cookie.
	 * Shared by the REST PIN endpoint and the one-click email magic link.
	 * Returns [ 'success' => true, 'name' => ... ] or [ 'error' => ..., 'status' => int ].
	 */
	public static function login_with_pin( string $email, string $pin ): array {
		$email = sanitize_email( $email );
		$pin   = sanitize_text_field( $pin );
		if ( ! $email || ! $pin ) return [ 'error' => 'Email and PIN required', 'status' => 400 ];

		// Rate limiting: max 5 attempts per email per 15 minutes
		$rate_key = 'noodled_pin_' . md5( $email );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) {
			return [ 'error' => 'Too many attempts. Try again in a few minutes.', 'status' => 429 ];
		}
		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );

		global $wpdb;
		$user = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_users WHERE email = %s AND token = %s AND token_expiry > %s",
			$email, $pin, gmdate( 'Y-m-d H:i:s' )
		), ARRAY_A );

		if ( ! $user ) return [ 'error' => 'Invalid or expired PIN', 'status' => 401 ];
		if ( $user['role'] === 'pending' ) return [ 'error' => 'Your account is still awaiting approval.', 'status' => 403 ];

		// Clear rate limit on success
		delete_transient( $rate_key );

		// Create session
		$session_token = wp_generate_password( 48, false );
		$wpdb->update( $wpdb->prefix . 'noodled_users', [
			'token'         => null,
			'token_expiry'  => null,
			'session_token' => $session_token,
			'last_login'    => current_time( 'mysql', true ),
		], [ 'id' => $user['id'] ] );

		setcookie( self::$cookie_name, $session_token, [
			'expires'  => time() + 365 * DAY_IN_SECONDS,
			'path'     => '/',
			'httponly' => true,
			'secure'   => is_ssl(),
			'samesite' => 'Lax',
		] );

		return [ 'success' => true, 'name' => $user['display_name'] ];
	}

	public static function handle_logout( \WP_REST_Request $req ): \WP_REST_Response {
		self::logout();
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public static function handle_register( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! Noodled_Settings::allow_registration() ) {
			return new \WP_REST_Response( [ 'error' => 'Registration is not enabled' ], 403 );
		}

		$email = sanitize_email( $req->get_param( 'email' ) );
		$name  = sanitize_text_field( $req->get_param( 'name' ) ) ?: '';

		if ( ! is_email( $email ) ) return new \WP_REST_Response( [ 'error' => 'Invalid email' ], 400 );

		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", $email
		) );
		if ( $existing ) return new \WP_REST_Response( [ 'error' => 'Account already exists. Try signing in.' ], 400 );

		$role = Noodled_Settings::require_approval() ? 'pending' : 'member';

		$wpdb->insert( $wpdb->prefix . 'noodled_users', [
			'email'        => $email,
			'display_name' => $name ?: explode( '@', $email )[0],
			'role'         => $role,
			'created_at'   => current_time( 'mysql', true ),
		] );

		if ( $role === 'pending' ) {
			self::notify_admin_of_request( $name ?: $email, $email );
			return new \WP_REST_Response( [ 'pending' => true ] );
		}

		// Auto-approved: seed a private starter notebook and send login PIN.
		self::seed_new_user( (int) $wpdb->insert_id );
		$result = self::send_magic_link( $email );
		return new \WP_REST_Response( $result );
	}

	public static function handle_request( \WP_REST_Request $req ): \WP_REST_Response {
		$email = $req->get_param( 'email' );
		$name  = $req->get_param( 'name' ) ?: '';
		if ( ! $email ) return new \WP_REST_Response( [ 'error' => 'Email required' ], 400 );

		$result = self::request_access( $email, $name );
		$status = isset( $result['error'] ) ? 400 : 200;
		return new \WP_REST_Response( $result, $status );
	}

	public static function handle_me( \WP_REST_Request $req ): \WP_REST_Response {
		$user = self::get_current_user();
		if ( ! $user ) return new \WP_REST_Response( [ 'error' => 'Not authenticated' ], 401 );
		return new \WP_REST_Response( $user );
	}
}
