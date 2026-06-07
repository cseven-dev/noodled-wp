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

		$html = '<!DOCTYPE html><html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
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
			/* translators: %s is the brand name. */
			. '<div style="font-size:12px;color:#9a978f">' . sprintf( esc_html__( 'Sent by %s', 'noodled' ), esc_html( $brand ) ) . ' &middot; <a href="' . esc_url( $app ) . '" style="color:' . esc_attr( $accent ) . ';text-decoration:none">' . sprintf( esc_html__( 'Open %s', 'noodled' ), esc_html( $brand ) ) . '</a></div>'
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
			return [ 'error' => __( 'Email not found. Ask the admin to invite you.', 'noodled' ) ];
		}

		$r = self::generate_and_email_pin( $user );
		if ( ! $r['sent'] ) {
			return [ 'error' => __( 'Failed to send email. Check your mail settings.', 'noodled' ) ];
		}

		return [ 'success' => true, 'message' => __( 'Check your email for a login PIN.', 'noodled' ) ];
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
		$body = self::email_p( esc_html__( 'Tap the button to sign in instantly, or enter this PIN on the login screen:', 'noodled' ) )
			. $pin_box
			. self::email_p( '<span style="color:#9a978f;font-size:13px">' . esc_html__( "This PIN expires in 15 minutes. If you didn't request it, you can ignore this email.", 'noodled' ) . '</span>' );

		/* translators: %s is the brand name. */
		$subject = sprintf( __( 'Your %s login PIN', 'noodled' ), $brand );
		/* translators: %s is the user's display name. */
		$greeting = sprintf( __( 'Hi %s,', 'noodled' ), $user['display_name'] );
		/* translators: %s is the brand name. */
		$cta_label = sprintf( __( 'Sign in to %s', 'noodled' ), $brand );

		$sent = self::send_branded(
			$user['email'],
			$subject,
			$greeting,
			$body,
			[ 'label' => $cta_label, 'url' => $magic ]
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
		if ( ! $user ) return [ 'error' => __( 'User not found', 'noodled' ) ];
		if ( $user['role'] === 'pending' ) return [ 'error' => __( 'Approve this user before sending a PIN.', 'noodled' ) ];

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

		// Clear the used magic-link token and stamp the login.
		$wpdb->update( $table, [
			'token'        => null,
			'token_expiry' => null,
			'last_login'   => current_time( 'mysql', true ),
		], [ 'id' => $user['id'] ] );

		// New per-device session (does not disturb this user's other devices).
		self::create_session( (int) $user['id'] );

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

		// Check the noodled session cookie against the per-device sessions table.
		$session = $_COOKIE[ self::$cookie_name ] ?? '';
		if ( ! $session ) return null;

		$user = self::user_by_session( $session );
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

	/* ── Session helpers (multi-device: one row per device in noodled_sessions, so
	   signing in on one device never logs you out of another) ── */

	private static function set_session_cookie( string $token, int $expires ): void {
		setcookie( self::$cookie_name, $token, [
			'expires'  => $expires,
			'path'     => '/',
			'httponly' => true,
			'secure'   => is_ssl(),
			'samesite' => 'Lax',
		] );
		$_COOKIE[ self::$cookie_name ] = $token; // visible within this same request
	}

	/** Create a new per-device session (1 year) for a user and set the cookie. */
	public static function create_session( int $user_id ): string {
		global $wpdb;
		$token   = wp_generate_password( 48, false );
		$expires = time() + 365 * DAY_IN_SECONDS;
		$wpdb->insert( $wpdb->prefix . 'noodled_sessions', [
			'user_id'    => $user_id,
			'token'      => $token,
			'user_agent' => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
			'created_at' => current_time( 'mysql', true ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
		] );
		self::set_session_cookie( $token, $expires );
		return $token;
	}

	/** Resolve a session token to its (non-expired) user row, or null. */
	private static function user_by_session( string $token ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT u.* FROM {$wpdb->prefix}noodled_users u
			   JOIN {$wpdb->prefix}noodled_sessions s ON s.user_id = u.id
			  WHERE s.token = %s AND s.expires_at > UTC_TIMESTAMP()",
			$token
		), ARRAY_A );
		return $row ?: null;
	}

	/** Remove a single device's session (used by logout — this device only). */
	private static function destroy_session( string $token ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'noodled_sessions', [ 'token' => $token ] );
	}

	/**
	 * Give a signed-in WP admin a long-lived app session so they are not logged
	 * out when the (short) WordPress login cookie lapses. Called on app load.
	 * Day-to-day app access then persists for a year like a member; admin-only
	 * management screens still re-check the live WordPress capability.
	 */
	public static function ensure_admin_session(): void {
		if ( headers_sent() ) return; // can't set a cookie after output started
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
		$existing = $_COOKIE[ self::$cookie_name ] ?? '';
		if ( $existing && self::user_by_session( $existing ) ) return; // already has a valid app session
		$uid = self::ensure_admin_user_row();
		if ( $uid ) self::create_session( $uid );
	}

	/** Find or create the noodled_users row for the current WP admin; return its id. */
	private static function ensure_admin_user_row(): int {
		if ( ! is_user_logged_in() ) return 0;
		$wp = wp_get_current_user();
		if ( ! $wp || ! $wp->user_email ) return 0;
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", $wp->user_email
		) );
		if ( $id ) return $id;
		$wpdb->insert( $wpdb->prefix . 'noodled_users', [
			'email'        => $wp->user_email,
			'display_name' => $wp->display_name,
			'role'         => 'admin',
			'created_at'   => current_time( 'mysql', true ),
		] );
		$id = (int) $wpdb->insert_id;
		if ( $id ) self::seed_new_user( $id );
		return $id;
	}

	/**
	 * Is the current request "the owner" — i.e. you? In wp-admin that's any WP
	 * admin. In the app (PIN session) it's only the original admin account: the
	 * one whose email matches the site admin email, or failing that the first
	 * admin user created. Other admins or members can't manage users from the app.
	 */
	public static function is_owner(): bool {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return true;
		$u = self::get_current_user();
		if ( ! $u || ( $u['wp'] ?? false ) || ( $u['role'] ?? '' ) !== 'admin' ) return false;
		if ( strcasecmp( (string) $u['email'], (string) get_option( 'admin_email' ) ) === 0 ) return true;
		global $wpdb;
		$first = (int) $wpdb->get_var(
			"SELECT id FROM {$wpdb->prefix}noodled_users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
		);
		return $first > 0 && (int) $u['id'] === $first;
	}

	public static function logout(): void {
		$session = $_COOKIE[ self::$cookie_name ] ?? '';
		if ( $session ) {
			self::destroy_session( $session );
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

		if ( ! is_email( $email ) ) return [ 'error' => __( 'Please enter a valid email address.', 'noodled' ) ];

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, role FROM {$table} WHERE email = %s", $email
		), ARRAY_A );

		if ( $existing ) {
			if ( $existing['role'] === 'pending' ) {
				return [ 'success' => true, 'message' => __( "You're already on the list — we'll be in touch soon.", 'noodled' ) ];
			}
			return [ 'success' => true, 'message' => __( 'You already have access. Just sign in!', 'noodled' ) ];
		}

		$wpdb->insert( $table, [
			'email'        => $email,
			'display_name' => $name ?: explode( '@', $email )[0],
			'role'         => 'pending',
			'created_at'   => current_time( 'mysql', true ),
		] );

		self::notify_admin_of_request( $name ?: $email, $email );

		return [ 'success' => true, 'message' => __( "Request received! We'll email you once you're approved.", 'noodled' ) ];
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
		$kind_label = ( $kind === 'notebook' ) ? __( 'notebook', 'noodled' ) : __( 'note', 'noodled' );
		/* translators: 1: item type (note or notebook), 2: brand name. */
		$subject = sprintf( __( 'A %1$s was shared with you on %2$s', 'noodled' ), $kind_label, $brand );
		if ( $sharer ) {
			/* translators: 1: item type (note or notebook), 2: item title, 3: name of the person who shared it. */
			$intro = sprintf( __( 'The %1$s %2$s by %3$s is now shared with you.', 'noodled' ), esc_html( $kind_label ), '<strong>' . esc_html( $title ) . '</strong>', '<strong>' . esc_html( $sharer ) . '</strong>' );
		} else {
			/* translators: 1: item type (note or notebook), 2: item title. */
			$intro = sprintf( __( 'The %1$s %2$s is now shared with you.', 'noodled' ), esc_html( $kind_label ), '<strong>' . esc_html( $title ) . '</strong>' );
		}
		/* translators: %s is the brand name. */
		$body    = self::email_p( $intro )
			. self::email_p( sprintf( esc_html__( 'Open %s to take a look.', 'noodled' ), esc_html( $brand ) ) );
		/* translators: %s is the user's display name. */
		$greeting = sprintf( __( 'Hi %s,', 'noodled' ), $u['display_name'] );
		/* translators: %s is the brand name. */
		$cta_label = sprintf( __( 'Open %s', 'noodled' ), $brand );
		self::send_branded( $u['email'], $subject, $greeting, $body, [ 'label' => $cta_label, 'url' => $app ] );
	}

	/** Email the site admin that someone has requested access. */
	public static function notify_admin_of_request( string $name, string $email ): void {
		$brand     = Noodled_Settings::get_brand_name();
		$to        = Noodled_Settings::get_notify_email();
		$users_url = admin_url( 'admin.php?page=noodled&tab=users' );
		/* translators: 1: brand name, 2: requester name. */
		$subject   = sprintf( __( '[%1$s] New noodle request from %2$s', 'noodled' ), $brand, $name );
		/* translators: 1: requester name, 2: requester email, 3: URL to approve or deny. */
		$body      = sprintf( __( "%1\$s (%2\$s) has requested a noodle.\n\nApprove or deny here:\n%3\$s", 'noodled' ), $name, $email, $users_url );
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
		/* translators: %s is the brand name. This is the body of the markdown welcome note shown to new users. */
		$body  = sprintf( __( "# Welcome to %s! \u{1F35C}\n\n"
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
			. "Happy noodling \u{2014} delete this note whenever you're ready.\n", 'noodled' ), $brand );

		// Noodled_Notes::create() ensures the 'My Notes' notebook exists owned by
		// this user, then creates the welcome note inside it.
		/* translators: %s is the brand name; title of the welcome note. */
		Noodled_Notes::create( 'My Notes', sprintf( __( 'Welcome to %s', 'noodled' ), $brand ), $body, $user_id );
	}

	/** Approve a pending user: promote to member, grant default notebook, email a login PIN. */
	public static function approve_user( int $id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';
		$user  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $user ) return [ 'error' => __( 'User not found', 'noodled' ) ];

		$wpdb->update( $table, [ 'role' => 'member' ], [ 'id' => $id ] );
		self::seed_new_user( $id );

		$result = self::send_magic_link( $user['email'] );
		return [ 'success' => true, 'emailed' => empty( $result['error'] ) ];
	}

	public static function invite_user( string $email, string $display_name = '', string $role = 'member' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'noodled_users';
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) return [ 'error' => __( 'Invalid email', 'noodled' ) ];

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE email = %s", $email
		) );
		if ( $existing ) return [ 'error' => __( 'User already exists', 'noodled' ) ];

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
		if ( ! $email ) return new \WP_REST_Response( [ 'error' => __( 'Email required', 'noodled' ) ], 400 );
		return new \WP_REST_Response( self::send_magic_link( $email ) );
	}

	public static function handle_verify( \WP_REST_Request $req ): \WP_REST_Response {
		$token = trim( (string) $req->get_param( 'token' ) );
		if ( ! $token ) return new \WP_REST_Response( [ 'error' => __( 'PIN required', 'noodled' ) ], 400 );

		// Token-only sign-in has no email to scope it, so it's brute-forceable —
		// rate-limit by IP (the magic link and manual "Already have a PIN" both use this).
		$rate_key = 'noodled_verify_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 10 ) {
			return new \WP_REST_Response( [ 'error' => __( 'Too many attempts. Try again in a few minutes.', 'noodled' ) ], 429 );
		}
		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );

		$user = self::verify_token( $token );
		if ( ! $user ) {
			return new \WP_REST_Response( [ 'error' => __( 'Invalid or expired PIN', 'noodled' ) ], 401 );
		}
		if ( ( $user['role'] ?? '' ) === 'pending' ) {
			return new \WP_REST_Response( [ 'error' => __( 'Your account is still awaiting approval.', 'noodled' ) ], 403 );
		}

		delete_transient( $rate_key );
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
		if ( ! $email || ! $pin ) return [ 'error' => __( 'Email and PIN required', 'noodled' ), 'status' => 400 ];

		// Rate limiting: max 5 attempts per email per 15 minutes
		$rate_key = 'noodled_pin_' . md5( $email );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) {
			return [ 'error' => __( 'Too many attempts. Try again in a few minutes.', 'noodled' ), 'status' => 429 ];
		}
		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );

		global $wpdb;
		$user = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_users WHERE email = %s AND token = %s AND token_expiry > %s",
			$email, $pin, gmdate( 'Y-m-d H:i:s' )
		), ARRAY_A );

		if ( ! $user ) return [ 'error' => __( 'Invalid or expired PIN', 'noodled' ), 'status' => 401 ];
		if ( $user['role'] === 'pending' ) return [ 'error' => __( 'Your account is still awaiting approval.', 'noodled' ), 'status' => 403 ];

		// Clear rate limit on success
		delete_transient( $rate_key );

		// Clear the used PIN and stamp the login.
		$wpdb->update( $wpdb->prefix . 'noodled_users', [
			'token'        => null,
			'token_expiry' => null,
			'last_login'   => current_time( 'mysql', true ),
		], [ 'id' => $user['id'] ] );

		// New per-device session (does not disturb this user's other devices).
		self::create_session( (int) $user['id'] );

		return [ 'success' => true, 'name' => $user['display_name'] ];
	}

	public static function handle_logout( \WP_REST_Request $req ): \WP_REST_Response {
		self::logout();
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public static function handle_register( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! Noodled_Settings::allow_registration() ) {
			return new \WP_REST_Response( [ 'error' => __( 'Registration is not enabled', 'noodled' ) ], 403 );
		}

		$email = sanitize_email( $req->get_param( 'email' ) );
		$name  = sanitize_text_field( $req->get_param( 'name' ) ) ?: '';

		if ( ! is_email( $email ) ) return new \WP_REST_Response( [ 'error' => __( 'Invalid email', 'noodled' ) ], 400 );

		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", $email
		) );
		if ( $existing ) return new \WP_REST_Response( [ 'error' => __( 'Account already exists. Try signing in.', 'noodled' ) ], 400 );

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
		if ( ! $email ) return new \WP_REST_Response( [ 'error' => __( 'Email required', 'noodled' ) ], 400 );

		$result = self::request_access( $email, $name );
		$status = isset( $result['error'] ) ? 400 : 200;
		return new \WP_REST_Response( $result, $status );
	}

	public static function handle_me( \WP_REST_Request $req ): \WP_REST_Response {
		$user = self::get_current_user();
		if ( ! $user ) return new \WP_REST_Response( [ 'error' => __( 'Not authenticated', 'noodled' ) ], 401 );
		return new \WP_REST_Response( $user );
	}
}
