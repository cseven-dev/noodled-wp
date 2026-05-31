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

		// Config
		register_rest_route( $ns, '/config', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_config' ], ] + $auth,
			[ 'methods' => 'PUT', 'callback' => [ __CLASS__, 'set_config' ], ] + $auth,
		] );

		// Sync placeholders
		register_rest_route( $ns, '/sync/status', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'sync_status' ], ] + $auth,
		] );
		register_rest_route( $ns, '/sync/push', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_push' ], ] + $auth,
		] );
		register_rest_route( $ns, '/sync/pull', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_pull' ], ] + $auth,
		] );
		register_rest_route( $ns, '/sync/import', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_import' ], 'permission_callback' => function() { return current_user_can( 'manage_options' ); }, ],
		] );

		// Sharing
		register_rest_route( $ns, '/share', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'share_notebook' ], ] + $auth,
		] );

		// Admin: user management
		$admin = [ 'permission_callback' => function() { return current_user_can( 'manage_options' ); } ];
		register_rest_route( $ns, '/admin/users', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'admin_invite_user' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)', [
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'admin_delete_user' ], ] + $admin,
		] );
		register_rest_route( $ns, '/admin/permissions', [
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'admin_set_permission' ], ] + $admin,
		] );
	}

	public static function check_auth(): bool {
		return is_user_logged_in() || Noodled_Auth::is_authenticated();
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
		return (int) $wpdb->insert_id;
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

		// All notes from accessible notebooks
		$accessible = Noodled_Notebooks::get_for_user( $uid );
		$all_notes  = [];
		foreach ( $accessible as $nb ) {
			$all_notes = array_merge( $all_notes, Noodled_Notes::get_all( $nb['id'] ) );
		}
		usort( $all_notes, function( $a, $b ) {
			if ( $a['pinned'] !== $b['pinned'] ) return $b['pinned'] ? 1 : -1;
			return strcmp( $b['modified'] ?: $b['created'], $a['modified'] ?: $a['created'] );
		});
		return new \WP_REST_Response( $all_notes );
	}

	public static function get_note( \WP_REST_Request $req ): \WP_REST_Response {
		$note = Noodled_Notes::get_one( (int) $req['id'] );
		if ( ! $note ) return new \WP_REST_Response( [ 'error' => 'Note not found' ], 404 );
		return new \WP_REST_Response( $note );
	}

	public static function create_note( \WP_REST_Request $req ): \WP_REST_Response {
		$uid      = self::current_user_id();
		$notebook = $req->get_param( 'notebook' ) ?: 'General';
		$title    = $req->get_param( 'title' ) ?: 'Untitled Note';
		$body     = $req->get_param( 'body' ) ?: '';
		$result   = Noodled_Notes::create( $notebook, $title, $body, $uid );
		if ( ! isset( $result['error'] ) && Noodled_GitHub::is_configured() ) {
			Noodled_Sync::push_note( $result['id'] );
		}
		return new \WP_REST_Response( $result );
	}

	public static function update_note( \WP_REST_Request $req ): \WP_REST_Response {
		$data = [];
		if ( $req->get_param( 'title' ) !== null ) $data['title'] = $req->get_param( 'title' );
		if ( $req->get_param( 'body' ) !== null )  $data['body']  = $req->get_param( 'body' );
		$result = Noodled_Notes::update( (int) $req['id'], $data );
		if ( ! isset( $result['error'] ) && Noodled_GitHub::is_configured() ) {
			Noodled_Sync::push_note( (int) $req['id'] );
		}
		return new \WP_REST_Response( $result );
	}

	public static function delete_note( \WP_REST_Request $req ): \WP_REST_Response {
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
		$to = $req->get_param( 'notebook' );
		if ( ! $to ) return new \WP_REST_Response( [ 'error' => 'Target notebook required' ], 400 );
		return new \WP_REST_Response( Noodled_Notes::move( (int) $req['id'], $to ) );
	}

	public static function pin_note( \WP_REST_Request $req ): \WP_REST_Response {
		return new \WP_REST_Response( Noodled_Notes::toggle_pin( (int) $req['id'] ) );
	}

	// ── Trash ──

	public static function get_trash(): \WP_REST_Response {
		return new \WP_REST_Response( Noodled_Notes::get_trash() );
	}

	public static function trash_count(): \WP_REST_Response {
		return new \WP_REST_Response( Noodled_Notes::trash_count() );
	}

	public static function restore_note( \WP_REST_Request $req ): \WP_REST_Response {
		return new \WP_REST_Response( Noodled_Notes::restore( (int) $req['id'] ) );
	}

	public static function permanent_delete( \WP_REST_Request $req ): \WP_REST_Response {
		Noodled_Notes::permanent_delete( (int) $req['id'] );
		return new \WP_REST_Response( true );
	}

	public static function empty_trash(): \WP_REST_Response {
		Noodled_Notes::empty_trash();
		return new \WP_REST_Response( true );
	}

	// ── Search ──

	public static function search( \WP_REST_Request $req ): \WP_REST_Response {
		$q = $req->get_param( 'q' ) ?: '';
		if ( strlen( $q ) < 2 ) return new \WP_REST_Response( [] );
		return new \WP_REST_Response( Noodled_Notes::search( $q ) );
	}

	// ── Attachments ──

	public static function save_attachment( \WP_REST_Request $req ): \WP_REST_Response {
		$note_id  = (int) $req->get_param( 'note_id' );
		$filename = $req->get_param( 'filename' );
		$data     = $req->get_param( 'data' );
		if ( ! $note_id || ! $filename || ! $data ) {
			return new \WP_REST_Response( [ 'error' => 'Missing parameters' ], 400 );
		}
		return new \WP_REST_Response( Noodled_Attachments::save( $note_id, $filename, $data ) );
	}

	public static function delete_attachment( \WP_REST_Request $req ): \WP_REST_Response {
		Noodled_Attachments::delete( (int) $req['id'] );
		return new \WP_REST_Response( true );
	}

	// ── Config ──

	public static function get_config(): \WP_REST_Response {
		$config = get_option( 'noodled_user_config', [ 'theme' => 'dark' ] );
		return new \WP_REST_Response( $config );
	}

	public static function set_config( \WP_REST_Request $req ): \WP_REST_Response {
		$key   = sanitize_key( $req->get_param( 'key' ) );
		$value = $req->get_param( 'value' );
		$config = get_option( 'noodled_user_config', [ 'theme' => 'dark' ] );
		$config[ $key ] = $value;
		update_option( 'noodled_user_config', $config );
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

	// ── Admin: User & Permission Management ──

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
		return new \WP_REST_Response( [ 'success' => true, 'shared_with' => $email ] );
	}

	// ── Admin: User & Permission Management ──

	public static function admin_invite_user( \WP_REST_Request $req ): \WP_REST_Response {
		$email = $req->get_param( 'email' );
		$name  = $req->get_param( 'name' ) ?: '';
		$role  = $req->get_param( 'role' ) ?: 'member';
		return new \WP_REST_Response( Noodled_Auth::invite_user( $email, $name, $role ) );
	}

	public static function admin_delete_user( \WP_REST_Request $req ): \WP_REST_Response {
		Noodled_Auth::delete_user( (int) $req['id'] );
		return new \WP_REST_Response( true );
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
}
