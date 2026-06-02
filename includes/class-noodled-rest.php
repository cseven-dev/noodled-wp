<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_REST {

	private static $ns = 'noodled/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		$ns = self::$ns;
		$auth = [ 'permission_callback' => [ __CLASS__, 'check_auth' ] ];

		// Notebooks
		register_rest_route( $ns, '/notebooks', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_notebooks' ], ] + $auth,
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_notebook' ], ] + $auth,
		] );
		register_rest_route( $ns, '/notebooks/rename', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rename_notebook' ], ] + $auth,
		] );
		register_rest_route( $ns, '/notebooks/delete', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'delete_notebook' ], ] + $auth,
		] );
		// Notebook sharing (owner-initiated, user-to-user)
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/shares', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'notebook_shares' ], ] + $auth,
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'notebook_share' ], ] + $auth,
		] );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/unshare', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'notebook_unshare' ], ] + $auth,
		] );

		// Notes
		register_rest_route( $ns, '/notes', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_notes' ], ] + $auth,
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_note' ], ] + $auth,
		] );
		register_rest_route( $ns, '/notes/(?P<id>\d+)', [
			[ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_note' ], ] + $auth,
			[ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_note' ], ] + $auth,
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_note' ], ] + $auth,
		] );
		register_rest_route( $ns, '/notes/(?P<id>\d+)/move', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'move_note' ], ] + $auth,
		] );
		register_rest_route( $ns, '/notes/(?P<id>\d+)/pin', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'pin_note' ], ] + $auth,
		] );
		// Note sharing (owner-initiated, user-to-user)
		register_rest_route( $ns, '/notes/(?P<id>\d+)/shares', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'note_shares' ], ] + $auth,
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'note_share_user' ], ] + $auth,
		] );
		register_rest_route( $ns, '/notes/(?P<id>\d+)/unshare', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'note_unshare_user' ], ] + $auth,
		] );

		// Trash
		register_rest_route( $ns, '/trash', [
			[ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_trash' ], ] + $auth,
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'empty_trash' ], ] + $auth,
		] );
		register_rest_route( $ns, '/trash/count', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'trash_count' ], ] + $auth,
		] );
		register_rest_route( $ns, '/trash/(?P<id>\d+)/restore', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'restore_note' ], ] + $auth,
		] );
		register_rest_route( $ns, '/trash/(?P<id>\d+)', [
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'permanent_delete' ], ] + $auth,
		] );

		// Search
		register_rest_route( $ns, '/search', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'search' ], ] + $auth,
		] );

		// Attachments
		register_rest_route( $ns, '/attachments', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'save_attachment' ], ] + $auth,
		] );
		register_rest_route( $ns, '/attachments/(?P<id>\d+)', [
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_attachment' ], ] + $auth,
		] );

		// Per-user Evernote import (each user imports their own .enex from the app)
		register_rest_route( $ns, '/import/evernote', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'import_evernote' ], ] + $auth,
		] );

		// Full backup: export the user's own notes + attachments as a zip; import a zip back.
		register_rest_route( $ns, '/export', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'export_backup' ], ] + $auth,
		] );
		register_rest_route( $ns, '/import/zip', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'import_zip' ], ] + $auth,
		] );

		// Private file proxy: streams an attachment only to users who can read its
		// note. Cookie-authenticated (so it works in <img src>); files are never
		// served from a public URL. Internal access check, not check_auth.
		register_rest_route( $ns, '/file/(?P<id>\d+)', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'serve_file' ], 'permission_callback' => '__return_true' ],
		] );

		// Config
		register_rest_route( $ns, '/config', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_config' ], ] + $auth,
			[ 'methods' => 'PUT', 'callback' => [ __CLASS__, 'set_config' ], ] + $auth,
		] );

		// Sync — admin only (shared GitHub repo is the admin desktop<->web pipeline)
		$admin_only = [ 'permission_callback' => function() { return current_user_can( 'manage_options' ); } ];
		register_rest_route( $ns, '/sync/status', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'sync_status' ], ] + $auth,
		] );
		register_rest_route( $ns, '/sync/push', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_push' ], ] + $admin_only,
		] );
		register_rest_route( $ns, '/sync/pull', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_pull' ], ] + $admin_only,
		] );
		register_rest_route( $ns, '/sync/import', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_import' ], 'permission_callback' => function() { return current_user_can( 'manage_options' ); }, ],
		] );

		// Note sharing (public link)
		register_rest_route( $ns, '/notes/(?P<id>\d+)/share', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'share_note_link' ], ] + $auth,
		] );
		register_rest_route( $ns, '/shared/(?P<token>[a-zA-Z0-9]+)', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'view_shared_note' ], 'permission_callback' => '__return_true', ],
		] );

		// Plaud sync
		register_rest_route( $ns, '/plaud/sync', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'plaud_sync' ], ] + $auth,
		] );
		register_rest_route( $ns, '/plaud/status', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'plaud_status' ], ] + $auth,
		] );

		// Sharing
		register_rest_route( $ns, '/share', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'share_notebook' ], ] + $auth,
		] );

		// Admin: user management. Owner-gated so it also works from the app (the
		// original admin's PIN session), not just a WP-logged-in administrator.
		$admin = [ 'permission_callback' => function() { return Noodled_Auth::is_owner(); } ];
		register_rest_route( $ns, '/admin/users', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'admin_list_users' ], ] + $admin,
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'admin_invite_user' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)', [
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'admin_delete_user' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/approve', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'admin_approve_user' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/permissions', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'admin_set_permission' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/drop', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'admin_set_drop' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/pin', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'admin_send_pin' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/landing', [
			[ 'methods' => 'DELETE', 'callback' => function() { delete_option( 'noodled_landing_html' ); return new \WP_REST_Response( true ); }, ] + $admin,
		] );
	}

	public static function check_auth(): bool {
		// Per-user note data must never be cached/shared between users. Magic-link
		// users aren't WP-logged-in, so WP won't add these automatically.
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0' );
		header( 'Vary: Cookie' );
		return is_user_logged_in() || Noodled_Auth::is_authenticated();
	}

	/**
	 * Verify the current user can access a note (read). Returns the note or null.
	 */
	private static function verify_note_access( int $note_id ): ?array {
		$note = Noodled_Notes::get_one( $note_id );
		if ( ! $note ) return null;
		$uid = self::current_user_id();
		global $wpdb;
		$nb_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		) );
		// Access if you can read the notebook OR the note is shared with you.
		if ( $nb_id && Noodled_Notebooks::user_can_access( $uid, $nb_id ) ) return $note;
		if ( Noodled_Permissions::note_can_read( $uid, $note_id ) ) return $note;
		return null;
	}

	/**
	 * Verify the current user can write to a note. Returns the note or null.
	 */
	private static function verify_note_write( int $note_id ): ?array {
		$note = Noodled_Notes::get_one( $note_id );
		if ( ! $note ) return null;
		$uid = self::current_user_id();
		global $wpdb;
		$nb_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		) );
		// Write if you can write the notebook OR the note is shared with you for writing.
		if ( $nb_id && Noodled_Notebooks::user_can_write( $uid, $nb_id ) ) return $note;
		if ( Noodled_Permissions::note_can_write( $uid, $note_id ) ) return $note;
		return null;
	}

	/**
	 * Verify the user may MANAGE a note's placement/lifecycle (move, delete,
	 * pin, restore). Requires notebook-level write (owner or notebook share) —
	 * a note-level write share only grants content editing, not the right to
	 * move/delete the owner's note out from under them.
	 */
	private static function verify_note_manage( int $note_id ): ?array {
		$note = Noodled_Notes::get_one( $note_id );
		if ( ! $note ) return null;
		global $wpdb;
		$nb_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		) );
		if ( $nb_id && Noodled_Notebooks::user_can_write( self::current_user_id(), $nb_id ) ) return $note;
		return null;
	}

	/** Resolve an email to an existing noodled user ID, or 0 if none. */
	private static function resolve_user_id( string $email ): int {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) return 0;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", $email
		) );
	}

	/** Does the current user own this notebook? */
	private static function owns_notebook( int $notebook_id ): bool {
		$nb = Noodled_Notebooks::get_by_id( $notebook_id );
		return $nb && (int) $nb['owner_id'] === self::current_user_id();
	}

	/** Does the current user own the notebook this note lives in? */
	private static function owns_note( int $note_id ): bool {
		global $wpdb;
		$nb_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		) );
		return $nb_id && self::owns_notebook( $nb_id );
	}

	// ── User-to-user sharing (owner-initiated) ──

	public static function notebook_shares( \WP_REST_Request $req ): \WP_REST_Response {
		$nb_id = (int) $req['id'];
		if ( ! self::owns_notebook( $nb_id ) ) return new \WP_REST_Response( [], 403 );
		return new \WP_REST_Response( Noodled_Permissions::notebook_shares( $nb_id ) );
	}

	public static function notebook_share( \WP_REST_Request $req ): \WP_REST_Response {
		$nb_id = (int) $req['id'];
		if ( ! self::owns_notebook( $nb_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Only the owner can share this notebook' ], 403 );
		}
		$target = self::resolve_user_id( $req->get_param( 'email' ) );
		if ( ! $target ) {
			return new \WP_REST_Response( [ 'error' => 'No noodled user with that email — they need an account first.' ], 404 );
		}
		if ( $target === self::current_user_id() ) {
			return new \WP_REST_Response( [ 'error' => "That's your own account." ], 400 );
		}
		$can_write = $req->get_param( 'access' ) === 'write';
		Noodled_Permissions::set( $target, $nb_id, true, $can_write );
		$nb = Noodled_Notebooks::get_by_id( $nb_id );
		$me = Noodled_Auth::get_current_user();
		Noodled_Auth::notify_share( $target, 'notebook', $nb['name'] ?? 'notebook', $me['name'] ?? '' );
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public static function notebook_unshare( \WP_REST_Request $req ): \WP_REST_Response {
		$nb_id = (int) $req['id'];
		if ( ! self::owns_notebook( $nb_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Only the owner can manage sharing' ], 403 );
		}
		$target = self::resolve_user_id( $req->get_param( 'email' ) );
		if ( $target ) {
			global $wpdb;
			$wpdb->delete( $wpdb->prefix . 'noodled_permissions', [ 'user_id' => $target, 'notebook_id' => $nb_id ] );
		}
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public static function note_shares( \WP_REST_Request $req ): \WP_REST_Response {
		$note_id = (int) $req['id'];
		if ( ! self::owns_note( $note_id ) ) return new \WP_REST_Response( [], 403 );
		return new \WP_REST_Response( Noodled_Permissions::note_shares( $note_id ) );
	}

	public static function note_share_user( \WP_REST_Request $req ): \WP_REST_Response {
		$note_id = (int) $req['id'];
		if ( ! self::owns_note( $note_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Only the owner can share this note' ], 403 );
		}
		$target = self::resolve_user_id( $req->get_param( 'email' ) );
		if ( ! $target ) {
			return new \WP_REST_Response( [ 'error' => 'No noodled user with that email — they need an account first.' ], 404 );
		}
		if ( $target === self::current_user_id() ) {
			return new \WP_REST_Response( [ 'error' => "That's your own account." ], 400 );
		}
		$can_write = $req->get_param( 'access' ) === 'write';
		Noodled_Permissions::share_note( $note_id, $target, $can_write );
		$note = Noodled_Notes::get_one( $note_id );
		$me   = Noodled_Auth::get_current_user();
		Noodled_Auth::notify_share( $target, 'note', $note['title'] ?? 'note', $me['name'] ?? '' );
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public static function note_unshare_user( \WP_REST_Request $req ): \WP_REST_Response {
		$note_id = (int) $req['id'];
		if ( ! self::owns_note( $note_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Only the owner can manage sharing' ], 403 );
		}
		$target = self::resolve_user_id( $req->get_param( 'email' ) );
		if ( $target ) Noodled_Permissions::unshare_note( $note_id, $target );
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Get the current noodled user ID. Returns the noodled_users.id for magic-link users,
	 * or a synthetic negative ID for WP admins (based on WP user ID).
	 */
	private static function current_user_id(): int {
		$user = Noodled_Auth::get_current_user();
		if ( ! $user ) return 0;
		if ( $user['wp'] ?? false ) {
			// WP admin — use their noodled user row if exists, else auto-create one
			return self::ensure_wp_user( $user );
		}
		return $user['id'];
	}

	private static function ensure_wp_user( array $user ): int {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", $user['email']
		), ARRAY_A );
		if ( $row ) return (int) $row['id'];

		$wpdb->insert( $wpdb->prefix . 'noodled_users', [
			'email'        => $user['email'],
			'display_name' => $user['name'],
			'role'         => 'admin',
			'created_at'   => current_time( 'mysql', true ),
		] );
		$new_id = (int) $wpdb->insert_id;
		Noodled_Auth::seed_new_user( $new_id );
		return $new_id;
	}

	// ── Notebooks ──

	public static function get_notebooks(): \WP_REST_Response {
		$uid = self::current_user_id();
		return new \WP_REST_Response( Noodled_Notebooks::get_for_user( $uid ) );
	}

	public static function create_notebook( \WP_REST_Request $req ): \WP_REST_Response {
		$name = $req->get_param( 'name' );
		if ( empty( $name ) ) return new \WP_REST_Response( [ 'error' => 'Name required' ], 400 );
		return new \WP_REST_Response( Noodled_Notebooks::create( $name, self::current_user_id() ) );
	}

	public static function rename_notebook( \WP_REST_Request $req ): \WP_REST_Response {
		$old = $req->get_param( 'old_name' );
		$new = $req->get_param( 'new_name' );
		if ( ! $old || ! $new ) return new \WP_REST_Response( [ 'error' => 'Names required' ], 400 );
		return new \WP_REST_Response( Noodled_Notebooks::rename( $old, $new, self::current_user_id() ) );
	}

	public static function delete_notebook( \WP_REST_Request $req ): \WP_REST_Response {
		$name = $req->get_param( 'name' );
		if ( ! $name ) return new \WP_REST_Response( [ 'error' => 'Name required' ], 400 );
		Noodled_Notebooks::delete( $name, self::current_user_id() );
		return new \WP_REST_Response( true );
	}

	// ── Notes ──

	public static function get_notes( \WP_REST_Request $req ): \WP_REST_Response {
		$uid      = self::current_user_id();
		$notebook = $req->get_param( 'notebook' );

		if ( $notebook ) {
			$nb = Noodled_Notebooks::get_by_name( $notebook, $uid );
			if ( ! $nb ) $nb = Noodled_Notebooks::get_by_name( $notebook );
			if ( ! $nb || ! Noodled_Notebooks::user_can_access( $uid, (int) $nb['id'] ) ) {
				return new \WP_REST_Response( [] );
			}
			return new \WP_REST_Response( Noodled_Notes::get_all( (int) $nb['id'] ) );
		}

		// All notes from accessible notebooks...
		$accessible = Noodled_Notebooks::get_for_user( $uid );
		$all_notes  = [];
		$seen       = [];
		foreach ( $accessible as $nb ) {
			foreach ( Noodled_Notes::get_all( $nb['id'] ) as $n ) {
				$all_notes[] = $n;
				$seen[ $n['id'] ] = true;
			}
		}
		// ...plus notes shared directly with the user ("Shared with me").
		foreach ( Noodled_Permissions::shared_note_ids_for_user( $uid ) as $nid ) {
			if ( isset( $seen[ $nid ] ) ) continue;
			$n = Noodled_Notes::get_one( $nid );
			if ( $n ) {
				$n['shared'] = true;
				$all_notes[] = $n;
			}
		}
		usort( $all_notes, function( $a, $b ) {
			if ( $a['pinned'] !== $b['pinned'] ) return $b['pinned'] ? 1 : -1;
			return strcmp( $b['modified'] ?: $b['created'], $a['modified'] ?: $a['created'] );
		});
		return new \WP_REST_Response( $all_notes );
	}

	public static function get_note( \WP_REST_Request $req ): \WP_REST_Response {
		$note = self::verify_note_access( (int) $req['id'] );
		if ( ! $note ) return new \WP_REST_Response( [ 'error' => 'Note not found' ], 404 );
		$note['attachments'] = Noodled_Attachments::get_for_note( (int) $req['id'] );
		return new \WP_REST_Response( $note );
	}

	/** Per-user Evernote .enex import from the app. */
	public static function import_evernote( \WP_REST_Request $req ): \WP_REST_Response {
		$files = $req->get_file_params();
		$f = $files['file'] ?? null;
		if ( ! $f || empty( $f['tmp_name'] ) || ( $f['error'] ?? 1 ) !== UPLOAD_ERR_OK ) {
			return new \WP_REST_Response( [ 'error' => 'No file uploaded' ], 400 );
		}
		if ( strtolower( pathinfo( $f['name'] ?? '', PATHINFO_EXTENSION ) ) !== 'enex' ) {
			return new \WP_REST_Response( [ 'error' => 'Please choose an Evernote .enex export file.' ], 400 );
		}
		return new \WP_REST_Response( Noodled_Evernote::import( $f['tmp_name'], self::current_user_id() ) );
	}

	/**
	 * Stream a zip backup of the caller's own notebooks → markdown (with frontmatter)
	 * plus each note's attachments under {slug}_files/, matching the desktop layout.
	 */
	public static function export_backup() {
		if ( ! class_exists( 'ZipArchive' ) ) { status_header( 500 ); echo 'Zip support unavailable on this server.'; exit; }
		$uid = self::current_user_id();
		if ( ! $uid ) { status_header( 403 ); exit; }

		$tmp = wp_tempnam( 'noodled-export' );
		$zip = new \ZipArchive();
		if ( $zip->open( $tmp, \ZipArchive::OVERWRITE ) !== true ) { status_header( 500 ); echo 'Could not build archive.'; exit; }

		$used = [];
		foreach ( Noodled_Notebooks::get_for_user( $uid ) as $nb ) {
			$folder = Noodled_Frontmatter::safe_filename( $nb['name'] ) ?: ( 'Notebook-' . $nb['id'] );
			foreach ( Noodled_Notes::get_all( (int) $nb['id'] ) as $row ) {
				$note = Noodled_Notes::get_one( (int) $row['id'] );
				if ( ! $note ) continue;
				$slug  = Noodled_Frontmatter::safe_filename( $note['slug'] ?: $note['title'] ) ?: ( 'note-' . $note['id'] );
				$key   = $folder . '/' . $slug;
				if ( isset( $used[ $key ] ) ) { $slug .= '-' . $note['id']; $key = $folder . '/' . $slug; }
				$used[ $key ] = true;
				$zip->addFromString( $folder . '/' . $slug . '.md', Noodled_Frontmatter::to_markdown( $note ) );
				foreach ( Noodled_Attachments::get_for_note( (int) $note['id'] ) as $att ) {
					$raw = Noodled_Attachments::get_raw( (int) $att['id'] );
					if ( $raw && ! empty( $raw['path'] ) && is_file( $raw['path'] ) ) {
						$zip->addFile( $raw['path'], $folder . '/' . $slug . '_files/' . Noodled_Frontmatter::safe_filename( $att['filename'] ) );
					}
				}
			}
		}
		$zip->close();
		$data = file_get_contents( $tmp );
		@unlink( $tmp );

		while ( ob_get_level() ) { ob_end_clean(); }
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="noodled-backup-' . gmdate( 'Y-m-d' ) . '.zip"' );
		header( 'Content-Length: ' . strlen( $data ) );
		echo $data;
		exit;
	}

	/** Restore notes from a backup/zip of .md files (folder name → notebook). */
	public static function import_zip( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! class_exists( 'ZipArchive' ) ) return new \WP_REST_Response( [ 'error' => 'Zip support unavailable' ], 500 );
		$files = $req->get_file_params();
		$f = $files['file'] ?? null;
		if ( ! $f || empty( $f['tmp_name'] ) || ( $f['error'] ?? 1 ) !== UPLOAD_ERR_OK ) {
			return new \WP_REST_Response( [ 'error' => 'No file uploaded' ], 400 );
		}
		$uid = self::current_user_id();
		$zip = new \ZipArchive();
		if ( $zip->open( $f['tmp_name'] ) !== true ) return new \WP_REST_Response( [ 'error' => 'Not a valid zip' ], 400 );

		$imported = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( ! $name || strtolower( substr( $name, -3 ) ) !== '.md' ) continue;
			$content = $zip->getFromIndex( $i );
			if ( $content === false ) continue;
			$parts  = explode( '/', str_replace( '\\', '/', $name ) );
			$folder = count( $parts ) > 1 ? $parts[0] : 'Imported';
			$parsed = Noodled_Frontmatter::from_markdown( $content );
			$title  = $parsed['title'] ?: pathinfo( $name, PATHINFO_FILENAME );
			$body   = $parsed['body'] ?: '';
			$res    = Noodled_Notes::create( $folder, $title, $body, $uid );
			if ( empty( $res['error'] ) ) $imported++;
		}
		$zip->close();
		return new \WP_REST_Response( [ 'imported' => $imported ] );
	}

	/**
	 * Stream a private attachment to a user who can read its note. The browser
	 * loads this in <img>/navigation with the session cookie; an attacker with
	 * the URL but no access (or no session) gets 403. Files are stored under a
	 * random subfolder and the uploads dir denies direct web access.
	 */
	public static function serve_file( \WP_REST_Request $req ) {
		$att = Noodled_Attachments::get_raw( (int) $req['id'] );
		if ( ! $att ) { status_header( 404 ); exit; }
		if ( ! self::verify_note_access( (int) $att['note_id'] ) ) { status_header( 403 ); exit; }

		$path = $att['path'];
		if ( ! $path || ! is_file( $path ) ) { status_header( 404 ); exit; }

		while ( ob_get_level() ) { ob_end_clean(); }
		$mime = $att['mime_type'] ?: 'application/octet-stream';
		// Any type that can run script when rendered inline gets sandboxed.
		$risky  = ( stripos( $mime, 'html' ) !== false || stripos( $mime, 'svg' ) !== false
			|| stripos( $mime, 'xml' ) !== false || stripos( $mime, 'text/' ) === 0 );
		// Only render genuinely safe types inline; everything else downloads.
		$inline = $risky
			|| strpos( $mime, 'image/' ) === 0
			|| strpos( $mime, 'audio/' ) === 0
			|| strpos( $mime, 'video/' ) === 0
			|| $mime === 'application/pdf';
		$fname  = str_replace( [ '"', "\r", "\n" ], '', $att['filename'] );
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $fname . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// Neutralise stored HTML/SVG/XML/text so an uploaded file can't run scripts in our origin.
		if ( $risky ) {
			header( "Content-Security-Policy: sandbox allow-popups allow-top-navigation-by-user-activation; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src data:;" );
		}
		readfile( $path );
		exit;
	}

	public static function create_note( \WP_REST_Request $req ): \WP_REST_Response {
		$uid      = self::current_user_id();
		$notebook = $req->get_param( 'notebook' ) ?: 'General';
		$title    = $req->get_param( 'title' ) ?: 'Untitled Note';
		$body     = $req->get_param( 'body' ) ?: '';
		$result   = Noodled_Notes::create( $notebook, $title, $body, $uid );
		// GitHub sync is an admin-only desktop<->web pipeline; regular users'
		// notes are never pushed to the shared repo.
		if ( ! isset( $result['error'] ) && Noodled_GitHub::is_configured() && current_user_can( 'manage_options' ) ) {
			Noodled_Sync::push_note( $result['id'] );
		}
		return new \WP_REST_Response( $result );
	}

	public static function update_note( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! self::verify_note_write( (int) $req['id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		// Optimistic-concurrency guard: if the caller tells us which version it
		// last saw and the stored note has moved on (another device/tab saved),
		// don't silently clobber — hand the current server copy back so the client
		// can ask the user. `force` overrides (the "keep mine" path).
		$base  = (string) $req->get_param( 'base_modified' );
		$force = (bool) $req->get_param( 'force' );
		if ( $base !== '' && ! $force ) {
			$cur = Noodled_Notes::get_one( (int) $req['id'] );
			if ( $cur && substr( (string) ( $cur['modified'] ?? '' ), 0, 16 ) !== substr( $base, 0, 16 ) ) {
				return new \WP_REST_Response( [ 'conflict' => true, 'server' => $cur ] );
			}
		}
		$data = [];
		if ( $req->get_param( 'title' ) !== null ) $data['title'] = $req->get_param( 'title' );
		if ( $req->get_param( 'body' ) !== null )  $data['body']  = $req->get_param( 'body' );
		$result = Noodled_Notes::update( (int) $req['id'], $data );
		if ( ! isset( $result['error'] ) && Noodled_GitHub::is_configured() && current_user_can( 'manage_options' ) ) {
			Noodled_Sync::push_note( (int) $req['id'] );
		}
		return new \WP_REST_Response( $result );
	}

	public static function delete_note( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! self::verify_note_manage( (int) $req['id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		// Get note info before deleting for GitHub sync
		if ( Noodled_GitHub::is_configured() ) {
			$note = Noodled_Notes::get_one( (int) $req['id'] );
			if ( $note && $note['notebook'] ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT sha FROM {$wpdb->prefix}noodled_notes WHERE id = %d", (int) $req['id']
				), ARRAY_A );
				if ( $row && $row['sha'] ) {
					Noodled_Sync::delete_from_github( $note['notebook'], $note['title'], $row['sha'] );
				}
			}
		}
		Noodled_Notes::soft_delete( (int) $req['id'] );
		return new \WP_REST_Response( true );
	}

	public static function move_note( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! self::verify_note_manage( (int) $req['id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		$to = $req->get_param( 'notebook' );
		if ( ! $to ) return new \WP_REST_Response( [ 'error' => 'Target notebook required' ], 400 );
		return new \WP_REST_Response( Noodled_Notes::move( (int) $req['id'], $to, self::current_user_id() ) );
	}

	public static function pin_note( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! self::verify_note_manage( (int) $req['id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		return new \WP_REST_Response( Noodled_Notes::toggle_pin( (int) $req['id'] ) );
	}

	// ── Trash ──

	private static function accessible_notebook_ids(): array {
		$uid = self::current_user_id();
		$nbs = Noodled_Notebooks::get_for_user( $uid );
		return array_map( function( $nb ) { return (int) $nb['id']; }, $nbs );
	}

	public static function get_trash(): \WP_REST_Response {
		$nb_ids = self::accessible_notebook_ids();
		return new \WP_REST_Response( Noodled_Notes::get_trash( $nb_ids ) );
	}

	public static function trash_count(): \WP_REST_Response {
		$nb_ids = self::accessible_notebook_ids();
		return new \WP_REST_Response( Noodled_Notes::trash_count( $nb_ids ) );
	}

	public static function restore_note( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! self::verify_note_manage( (int) $req['id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		return new \WP_REST_Response( Noodled_Notes::restore( (int) $req['id'], self::current_user_id() ) );
	}

	public static function permanent_delete( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! self::verify_note_manage( (int) $req['id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		Noodled_Notes::permanent_delete( (int) $req['id'] );
		return new \WP_REST_Response( true );
	}

	public static function empty_trash(): \WP_REST_Response {
		$nb_ids = self::accessible_notebook_ids();
		Noodled_Notes::empty_trash( $nb_ids );
		return new \WP_REST_Response( true );
	}

	// ── Search ──

	public static function search( \WP_REST_Request $req ): \WP_REST_Response {
		$q = $req->get_param( 'q' ) ?: '';
		if ( strlen( $q ) < 2 ) return new \WP_REST_Response( [] );
		$nb_ids   = self::accessible_notebook_ids();
		$note_ids = Noodled_Permissions::shared_note_ids_for_user( self::current_user_id() );
		return new \WP_REST_Response( Noodled_Notes::search( $q, $nb_ids, $note_ids ) );
	}

	// ── Attachments ──

	public static function save_attachment( \WP_REST_Request $req ): \WP_REST_Response {
		$note_id  = (int) $req->get_param( 'note_id' );
		$filename = $req->get_param( 'filename' );
		$data     = $req->get_param( 'data' );
		if ( ! $note_id || ! $filename || ! $data ) {
			return new \WP_REST_Response( [ 'error' => 'Missing parameters' ], 400 );
		}
		if ( ! self::verify_note_write( $note_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		return new \WP_REST_Response( Noodled_Attachments::save( $note_id, $filename, $data ) );
	}

	public static function delete_attachment( \WP_REST_Request $req ): \WP_REST_Response {
		// Verify ownership via the attachment's note
		global $wpdb;
		$att = $wpdb->get_row( $wpdb->prepare(
			"SELECT note_id FROM {$wpdb->prefix}noodled_attachments WHERE id = %d", (int) $req['id']
		), ARRAY_A );
		if ( ! $att || ! self::verify_note_write( (int) $att['note_id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}
		Noodled_Attachments::delete( (int) $req['id'] );
		return new \WP_REST_Response( true );
	}

	// ── Config (per-user) ──

	private static function config_key(): string {
		return 'noodled_config_' . self::current_user_id();
	}

	public static function get_config(): \WP_REST_Response {
		$config = get_option( self::config_key(), [ 'theme' => 'dark' ] );
		return new \WP_REST_Response( $config );
	}

	public static function set_config( \WP_REST_Request $req ): \WP_REST_Response {
		$key   = sanitize_key( $req->get_param( 'key' ) );
		$value = $req->get_param( 'value' );
		$config = get_option( self::config_key(), [ 'theme' => 'dark' ] );
		$config[ $key ] = $value;
		update_option( self::config_key(), $config );
		return new \WP_REST_Response( $config );
	}

	// ── Sync ──

	public static function sync_status(): \WP_REST_Response {
		$configured = Noodled_GitHub::is_configured();
		$cfg = Noodled_Settings::get();
		return new \WP_REST_Response( [
			'initialized' => $configured,
			'time'        => get_option( 'noodled_last_sync' ),
			'owner'       => $cfg['github_owner'] ?? '(empty)',
			'repo'        => $cfg['github_repo'] ?? '(empty)',
			'branch'      => $cfg['github_branch'] ?? '(empty)',
			'has_token'   => ! empty( $cfg['github_token'] ),
		] );
	}

	public static function sync_push(): \WP_REST_Response {
		if ( ! Noodled_GitHub::is_configured() ) {
			return new \WP_REST_Response( [ 'error' => 'GitHub not configured' ] );
		}

		global $wpdb;
		$notes = $wpdb->get_results(
			"SELECT id FROM {$wpdb->prefix}noodled_notes WHERE deleted_at IS NULL",
			ARRAY_A
		);

		$pushed = 0;
		foreach ( $notes as $n ) {
			$result = Noodled_Sync::push_note( (int) $n['id'] );
			if ( ! isset( $result['error'] ) ) $pushed++;
		}

		update_option( 'noodled_last_sync', current_time( 'mysql', true ) );
		return new \WP_REST_Response( [ 'success' => true, 'pushed' => $pushed ] );
	}

	public static function sync_pull(): \WP_REST_Response {
		if ( ! Noodled_GitHub::is_configured() ) {
			$cfg = Noodled_Settings::get();
			return new \WP_REST_Response( [
				'error' => 'GitHub not configured. Owner: ' . ( $cfg['github_owner'] ?? 'empty' ) . ', Token: ' . ( empty( $cfg['github_token'] ) ? 'missing' : 'set' ),
			] );
		}
		$result = Noodled_Sync::full_import();
		if ( ! isset( $result['error'] ) ) {
			update_option( 'noodled_last_sync', current_time( 'mysql', true ) );
		}
		return new \WP_REST_Response( $result );
	}

	public static function sync_import(): \WP_REST_Response {
		if ( ! Noodled_GitHub::is_configured() ) {
			return new \WP_REST_Response( [ 'error' => 'GitHub not configured' ] );
		}
		$result = Noodled_Sync::full_import();
		return new \WP_REST_Response( $result );
	}

	// ── Note Sharing ──

	public static function share_note_link( \WP_REST_Request $req ): \WP_REST_Response {
		$note_id = (int) $req['id'];
		// Only the owner may mint a public link for a note.
		if ( ! self::owns_note( $note_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Only the owner can create a public link' ], 403 );
		}
		$note = Noodled_Notes::get_one( $note_id );
		if ( ! $note ) return new \WP_REST_Response( [ 'error' => 'Note not found' ], 404 );

		// Generate or retrieve share token
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT meta_json FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		), ARRAY_A );
		$meta = json_decode( $row['meta_json'] ?? '{}', true ) ?: [];

		if ( empty( $meta['share_token'] ) ) {
			$meta['share_token'] = wp_generate_password( 16, false );
			$wpdb->update( $wpdb->prefix . 'noodled_notes', [
				'meta_json' => wp_json_encode( $meta ),
			], [ 'id' => $note_id ] );
		}

		$url = rest_url( 'noodled/v1/shared/' . $meta['share_token'] );
		return new \WP_REST_Response( [ 'url' => $url, 'token' => $meta['share_token'] ] );
	}

	public static function view_shared_note( \WP_REST_Request $req ): \WP_REST_Response {
		$token = sanitize_text_field( $req['token'] );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_notes WHERE meta_json LIKE %s AND deleted_at IS NULL",
			'%"share_token":"' . $wpdb->esc_like( $token ) . '"%'
		), ARRAY_A );

		if ( ! $row ) return new \WP_REST_Response( [ 'error' => 'Note not found or link expired' ], 404 );

		return new \WP_REST_Response( [
			'title' => $row['title'],
			'body'  => $row['body'],
			'modified' => $row['modified_at'],
		] );
	}

	// ── Plaud ──

	public static function plaud_status(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'configured' => Noodled_Plaud::is_configured(),
		] );
	}

	public static function plaud_sync(): \WP_REST_Response {
		$uid    = self::current_user_id();
		$result = Noodled_Plaud::sync( $uid );
		return new \WP_REST_Response( $result );
	}

	// ── Sharing ──

	public static function share_notebook( \WP_REST_Request $req ): \WP_REST_Response {
		$uid         = self::current_user_id();
		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$email       = sanitize_email( $req->get_param( 'email' ) );
		$can_write   = (bool) $req->get_param( 'can_write' );

		// Verify ownership
		$nb = Noodled_Notebooks::get_by_id( $notebook_id );
		if ( ! $nb || (int) $nb['owner_id'] !== $uid ) {
			return new \WP_REST_Response( [ 'error' => 'You can only share notebooks you own' ], 403 );
		}

		// Find target user
		global $wpdb;
		$target = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", $email
		), ARRAY_A );

		if ( ! $target ) {
			// Auto-invite them
			$result = Noodled_Auth::invite_user( $email );
			if ( isset( $result['error'] ) ) return new \WP_REST_Response( $result );
			$target_id = $result['id'];
		} else {
			$target_id = (int) $target['id'];
		}

		Noodled_Permissions::set( $target_id, $notebook_id, true, $can_write );
		// Notify existing users (auto-invited users get a login PIN email instead).
		if ( $target ) {
			$me = Noodled_Auth::get_current_user();
			Noodled_Auth::notify_share( $target_id, 'notebook', $nb['name'], $me['name'] ?? '' );
		}
		return new \WP_REST_Response( [ 'success' => true, 'shared_with' => $email ] );
	}

	// ── Admin: User & Permission Management ──

	/** List all users (for the in-app owner user manager), with drop-folder state. */
	public static function admin_list_users(): \WP_REST_Response {
		$users = Noodled_Auth::get_all_users();
		foreach ( $users as &$u ) {
			$u['id']   = (int) $u['id'];
			$u['drop'] = $u['role'] === 'member' && Noodled_Notebooks::drop_folder_active( (int) $u['id'] );
		}
		return new \WP_REST_Response( $users );
	}

	public static function admin_invite_user( \WP_REST_Request $req ): \WP_REST_Response {
		$email  = $req->get_param( 'email' );
		$name   = $req->get_param( 'name' ) ?: '';
		$role   = $req->get_param( 'role' ) ?: 'member';
		$result = Noodled_Auth::invite_user( $email, $name, $role );
		// Optionally drop them straight into a shared drop folder at invite time.
		if ( ! isset( $result['error'] ) && $req->get_param( 'drop' ) && ! empty( $result['id'] ) ) {
			Noodled_Notebooks::set_drop_folder( (int) $result['id'], self::current_user_id(), true );
		}
		return new \WP_REST_Response( $result );
	}

	public static function admin_delete_user( \WP_REST_Request $req ): \WP_REST_Response {
		Noodled_Auth::delete_user( (int) $req['id'] );
		return new \WP_REST_Response( true );
	}

	public static function admin_approve_user( \WP_REST_Request $req ): \WP_REST_Response {
		$result = Noodled_Auth::approve_user( (int) $req['id'] );
		$status = isset( $result['error'] ) ? 404 : 200;
		return new \WP_REST_Response( $result, $status );
	}

	public static function admin_set_permission( \WP_REST_Request $req ): \WP_REST_Response {
		$user_id     = (int) $req->get_param( 'user_id' );
		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$type        = $req->get_param( 'type' );
		$value       = (bool) $req->get_param( 'value' );

		// Get current permissions
		global $wpdb;
		$perm = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_permissions WHERE user_id = %d AND notebook_id = %d",
			$user_id, $notebook_id
		), ARRAY_A );

		$can_read  = $perm ? (bool) $perm['can_read'] : false;
		$can_write = $perm ? (bool) $perm['can_write'] : false;

		if ( $type === 'read' )  $can_read  = $value;
		if ( $type === 'write' ) $can_write = $value;
		if ( $can_write ) $can_read = true; // Writing implies reading

		Noodled_Permissions::set( $user_id, $notebook_id, $can_read, $can_write );
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	/** Toggle a member's shared drop folder (shared read+write with the acting admin). */
	public static function admin_set_drop( \WP_REST_Request $req ): \WP_REST_Response {
		$member_id = (int) $req['id'];
		$enabled   = (bool) $req->get_param( 'enabled' );
		$result    = Noodled_Notebooks::set_drop_folder( $member_id, self::current_user_id(), $enabled );
		$status    = isset( $result['error'] ) ? 400 : 200;
		return new \WP_REST_Response( $result, $status );
	}

	/** Issue + email a login PIN to a member, returning the PIN for the admin to relay. */
	public static function admin_send_pin( \WP_REST_Request $req ): \WP_REST_Response {
		$result = Noodled_Auth::admin_send_pin( (int) $req['id'] );
		$status = isset( $result['error'] ) ? 400 : 200;
		return new \WP_REST_Response( $result, $status );
	}
}
