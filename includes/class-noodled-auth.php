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

	// ── Branded email ──

	/** A styled paragraph for branded HTML emails. */
	private static function email_p( string $html ): string {
		return '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3a3a40">' . $html . '</p>';
	}

	/**
	 * Send an HTML email wrapped in noodled branding (brand name, tagline, accent
	 * colour, CTA button) so it matches the marketing look. $cta = [ 'label', 'url' ]
	 * is optional. $body_html is trusted HTML built by the caller.
	 */
	private static function send_branded( string $to, string $subject, string $heading, string $body_html, ?array $cta = null ): bool {
		$brand   = Noodled_Settings::get_brand_name();
		$tagline = Noodled_Settings::get_brand_tagline();
		$accent  = Noodled_Settings::get_accent_color();
		$app     = self::get_app_url();

		$cta_html = '';
		if ( $cta && ! empty( $cta['url'] ) ) {
			$cta_html = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 4px"><tr>'
				. '<td style="border-radius:10px;background:' . esc_attr( $accent ) . '">'
				. '<a href="' . esc_url( $cta['url'] ) . '" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none">' . esc_html( $cta['label'] ) . '</a>'
				. '</td></tr></table>';
		}

		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
			. '<body style="margin:0;padding:0;background:#faf6f0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf6f0;padding:32px 12px"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border:1px solid #ecebe6;border-radius:16px;overflow:hidden">'
			. '<tr><td style="padding:26px 32px 0">'
			. '<div style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:' . esc_attr( $accent ) . '">' . esc_html( $brand ) . '</div>'
			. '<div style="font-size:12px;color:#9a978f;margin-top:3px">' . esc_html( $tagline ) . '</div>'
			. '</td></tr>'
			. '<tr><td style="padding:20px 32px 28px">'
			. ( $heading ? '<h1 style="margin:0 0 14px;font-size:19px;font-weight:700;color:#1a1a1f">' . esc_html( $heading ) . '</h1>' : '' )
			. $body_html
			. $cta_html
			. '</td></tr>'
			. '<tr><td style="padding:16px 32px;background:#faf6f0;border-top:1px solid #ecebe6">'
			. '<div style="font-size:12px;color:#9a978f">Sent by ' . esc_html( $brand ) . ' &middot; <a href="' . esc_url( $app ) . '" style="color:' . esc_attr( $accent ) . ';text-decoration:none">Open ' . esc_html( $brand ) . '</a></div>'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		return (bool) wp_mail( $to, $subject, $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
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

		$r = self::generate_and_email_pin( $user );
		if ( ! $r['sent'] ) {
			return [ 'error' => 'Failed to send email. Check your mail settings.' ];
		}

		return [ 'success' => true, 'message' => 'Check your email for a login PIN.' ];
	}

	/**
	 * Generate a fresh 6-digit PIN, store it (+15-min expiry), and email the user
	 * a one-click magic link plus the PIN. Returns [ 'pin' => ..., 'sent' => bool ].
	 * Shared by the public login flow and the admin "Send PIN" tool.
	 */
	private static function generate_and_email_pin( array $user ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';

		$pin    = str_pad( wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$expiry = gmdate( 'Y-m-d H:i:s', time() + self::$token_ttl );

		$wpdb->update( $table, [
			'token'        => $pin,
			'token_expiry' => $expiry,
		], [ 'id' => $user['id'] ] );

		$brand   = Noodled_Settings::get_brand_name();
		$accent  = Noodled_Settings::get_accent_color();
		$app_url = self::get_app_url();
		$magic   = add_query_arg( [
			'noodled_email' => $user['email'],
			'noodled_login' => $pin,
		], $app_url );

		$pin_box = '<div style="text-align:center;margin:4px 0 16px">'
			. '<div style="display:inline-block;padding:14px 24px;background:#faf6f0;border:1px dashed ' . esc_attr( $accent )
			. ';border-radius:12px;font-size:30px;font-weight:700;letter-spacing:8px;color:#1a1a1f;font-family:\'Courier New\',monospace">'
			. esc_html( $pin ) . '</div></div>';
		$body = self::email_p( 'Tap the button to sign in instantly, or enter this PIN on the login screen:' )
			. $pin_box
			. self::email_p( '<span style="color:#9a978f;font-size:13px">This PIN expires in 15 minutes. If you didn\'t request it, you can ignore this email.</span>' );

		$sent = self::send_branded(
			$user['email'],
			"Your {$brand} login PIN",
			'Hi ' . $user['display_name'] . ',',
			$body,
			[ 'label' => "Sign in to {$brand}", 'url' => $magic ]
		);

		return [ 'pin' => $pin, 'sent' => (bool) $sent ];
	}

	/**
	 * Admin tool: issue a login PIN for a member, email it, and return the PIN so
	 * the admin can relay it directly (e.g. over the phone).
	 */
	public static function admin_send_pin( int $id ): array {
		global $wpdb;
		$user = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_users WHERE id = %d", $id
		), ARRAY_A );
		if ( ! $user ) return [ 'error' => 'User not found' ];
		if ( $user['role'] === 'pending' ) return [ 'error' => 'Approve this user before sending a PIN.' ];

		$r = self::generate_and_email_pin( $user );
		return [
			'success' => true,
			'pin'     => $r['pin'],
			'emailed' => $r['sent'],
			'email'   => $user['email'],
		];
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
		$by      = $sharer ? ' by <strong>' . esc_html( $sharer ) . '</strong>' : '';
		$subject = "A {$kind} was shared with you on {$brand}";
		$body    = self::email_p( 'The ' . esc_html( $kind ) . ' <strong>' . esc_html( $title ) . '</strong>' . $by . ' is now shared with you.' )
			. self::email_p( "Open {$brand} to take a look." );
		self::send_branded( $u['email'], $subject, 'Hi ' . $u['display_name'] . ',', $body, [ 'label' => "Open {$brand}", 'url' => $app ] );
	}

	/** Email the site admin that someone has requested access. */
	public static function notify_admin_of_request( string $name, string $email ): void {
		$brand     = Noodled_Settings::get_brand_name();
		$to        = Noodled_Settings::get_notify_email();
		$users_url = admin_url( 'admin.php?page=noodled&tab=users' );
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
			. "This is **your** private notebook — only you can see what's here unless you choose to share it. Here's the quick tour.\n\n"
			. "## What you can do\n\n"
			. "- **Write notes** in plain language — they save automatically as you type.\n"
			. "- **Organize with notebooks** — make folders for projects, topics, anyone you like, from the left sidebar.\n"
			. "- **Format with Markdown** — type `# ` for a heading, `- ` for a bullet, `- [ ] ` for a checkbox, `**bold**`, and they render live.\n"
			. "- **Search everything** instantly from the search box at the top.\n"
			. "- **Dictate** a note with the \u{1F3A4} button, attach files with the \u{1F4CE} button.\n"
			. "- **Share** a note or notebook with another person, read-only or read/write.\n\n"
			. "## Two quick tips\n\n"
			. "1. **Link your notes together** — type `[[` and the title of another note to connect them, like a personal wiki.\n"
			. "2. **Tag a thought** with `#idea` or `#todo` anywhere in a note, then search the tag later to pull them all up.\n\n"
			. "## Your privacy\n\n"
			. "Everything you write is private to your account. Sharing is always opt-in — nothing leaves your noodle unless you decide it should.\n\n"
			. "Happy noodling \u{2014} delete this note whenever you're ready.\n";

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
