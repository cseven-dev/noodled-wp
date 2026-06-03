<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Notebooks {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_notebooks';
	}

	private static function notes_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_notes';
	}

	private static function perms_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_permissions';
	}

	/**
	 * Get notebooks visible to a user: owned + shared with them.
	 */
	public static function get_for_user( int $user_id ): array {
		global $wpdb;
		$t  = self::table();
		$nt = self::notes_table();
		$pt = self::perms_table();
		$ut = $wpdb->prefix . 'noodled_users';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT n.*, COALESCE(c.cnt, 0) AS note_count,
			        ad.display_name AS drop_admin,
			        CASE WHEN n.owner_id = %d THEN 'owner'
			             WHEN p.can_write = 1 THEN 'write'
			             ELSE 'read' END AS access
			 FROM {$t} n
			 LEFT JOIN (
			   SELECT notebook_id, COUNT(*) AS cnt
			   FROM {$nt} WHERE deleted_at IS NULL
			   GROUP BY notebook_id
			 ) c ON c.notebook_id = n.id
			 LEFT JOIN {$pt} p ON p.notebook_id = n.id AND p.user_id = %d
			 LEFT JOIN {$ut} ad ON ad.id = n.drop_to
			 WHERE n.owner_id = %d OR (p.user_id = %d AND p.can_read = 1)
			 ORDER BY n.sort_order ASC, n.name ASC",
			$user_id, $user_id, $user_id, $user_id
		), ARRAY_A );

		return array_map( function ( $r ) use ( $user_id ) {
			$is_drop = (int) $r['drop_to'] > 0;
			// A member's own drop folder is shown to them as "{Admin}'s noodle".
			// Everyone else (the admin receiving it) sees the real name = the member's name.
			$label = ( $is_drop && (int) $r['owner_id'] === $user_id )
				? self::drop_label( $r['drop_admin'] )
				: $r['name'];
			return [
				'id'     => (int) $r['id'],
				'name'   => $r['name'],
				'label'  => $label,
				'count'  => (int) $r['note_count'],
				'access' => $r['access'],
				'owner'  => (int) $r['owner_id'],
				'drop'   => $is_drop,
			];
		}, $rows ?: [] );
	}

	/* ────────────────────────────────────────────────────────────
	   DROP FOLDERS (admin-enabled shared inbox per member)
	   ──────────────────────────────────────────────────────────── */

	/**
	 * The member's drop folder row, or null. `drop_to` is a PERSISTENT designation
	 * (it survives a disable), so this finds the folder whether sharing is currently
	 * on or off — which is what lets re-enable reuse it instead of spawning a duplicate.
	 */
	public static function member_drop_folder( int $member_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE owner_id = %d AND drop_to > 0 LIMIT 1",
			$member_id
		), ARRAY_A );
	}

	/** Whether the member's drop folder is currently SHARED (admin has access). */
	public static function drop_folder_active( int $member_id ): bool {
		global $wpdb;
		$row = self::member_drop_folder( $member_id );
		if ( ! $row ) return false;
		$pt = self::perms_table();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$pt} WHERE user_id = %d AND notebook_id = %d AND can_read = 1 LIMIT 1",
			(int) $row['drop_to'], (int) $row['id']
		) );
	}

	/** A notebook name unique across all owners (suffixes " (2)", " (3)"…). */
	private static function unique_notebook_name( string $base ): string {
		$base = sanitize_text_field( $base ) ?: __( 'Shared', 'noodled' );
		$name = $base;
		$i    = 2;
		while ( self::get_by_name( $name ) ) {
			$name = $base . ' (' . $i . ')';
			$i++;
		}
		return $name;
	}

	/** Friendly possessive label for a drop folder, e.g. "Simon's noodle". */
	private static function drop_label( ?string $admin_name ): string {
		$first = self::first_name( $admin_name );
		/* translators: %s is the admin's first name. */
		return $first ? sprintf( __( "%s's noodle", 'noodled' ), $first ) : __( 'Shared noodle', 'noodled' );
	}

	/** Clean first name from a display name or email ("simon@c7.ca" → "Simon"). */
	private static function first_name( ?string $name ): string {
		$name = trim( (string) $name );
		if ( $name === '' ) return '';
		if ( strpos( $name, '@' ) !== false ) $name = substr( $name, 0, strpos( $name, '@' ) );
		$name  = preg_replace( '/[._-]+/', ' ', $name );
		$first = trim( explode( ' ', trim( $name ) )[0] );
		if ( $first === '' ) return '';
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $first, MB_CASE_TITLE, 'UTF-8' ) : ucfirst( $first );
	}

	/**
	 * Enable/disable a member's shared drop folder. When on, the member owns a
	 * notebook (named after them) whose contents are shared read+write with the
	 * admin; the member sees it labelled "Shared with {Admin}".
	 */
	public static function set_drop_folder( int $member_id, int $admin_id, bool $on ): array {
		global $wpdb;
		if ( $member_id <= 0 || $admin_id <= 0 ) return [ 'error' => __( 'Invalid user', 'noodled' ) ];

		$existing = self::member_drop_folder( $member_id );

		if ( ! $on ) {
			if ( $existing ) {
				// Turn sharing OFF by revoking the admin's access only. We KEEP the
				// folder and its drop_to marker so a later re-enable reuses the exact
				// same notebook — never orphaning the member's notes or spawning a
				// duplicate "Name (2)" folder (the previous name-based lookup could).
				$wpdb->delete( self::perms_table(), [
					'user_id'     => (int) $existing['drop_to'],
					'notebook_id' => (int) $existing['id'],
				] );
			}
			return [ 'success' => true, 'enabled' => false ];
		}

		// Enabling — reuse the member's existing drop folder (active OR dormant).
		if ( $existing ) {
			$nb_id = (int) $existing['id'];
			if ( (int) $existing['drop_to'] !== $admin_id ) {
				$wpdb->update( self::table(), [ 'drop_to' => $admin_id ], [ 'id' => $nb_id ] );
			}
			Noodled_Permissions::set( $admin_id, $nb_id, true, true );
			return [ 'success' => true, 'enabled' => true, 'name' => $existing['name'] ];
		}

		$member = $wpdb->get_row( $wpdb->prepare(
			"SELECT display_name, email FROM {$wpdb->prefix}noodled_users WHERE id = %d", $member_id
		), ARRAY_A );
		if ( ! $member ) return [ 'error' => __( 'Member not found', 'noodled' ) ];

		$base = $member['display_name'] ?: explode( '@', $member['email'] )[0];
		$name = self::unique_notebook_name( $base );

		$wpdb->insert( self::table(), [
			'name'        => $name,
			'owner_id'    => $member_id,
			'drop_to'     => $admin_id,
			'created_at'  => current_time( 'mysql', true ),
			'modified_at' => current_time( 'mysql', true ),
		] );
		$nb_id = (int) $wpdb->insert_id;

		Noodled_Permissions::set( $admin_id, $nb_id, true, true );

		// Seed a short note so the folder is visible immediately and self-explains.
		$admin_name = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT display_name FROM {$wpdb->prefix}noodled_users WHERE id = %d", $admin_id
		) );
		$first = self::first_name( $admin_name );
		$share_with = $first ?: __( 'the admin', 'noodled' );
		$drop_for   = $first ?: __( 'them', 'noodled' );
		/* translators: 1: recipient name (admin or "the admin"), 2: recipient name (admin or "them"). */
		$about_body = sprintf(
			__( "\u{1F4E5} Anything you add to **this folder** is automatically shared with %1\$s.\n\nDrop notes here that you'd like %2\$s to see. Your other notebooks stay private.\n", 'noodled' ),
			$share_with,
			$drop_for
		);
		Noodled_Notes::create(
			$name,
			__( 'About this folder', 'noodled' ),
			$about_body,
			$member_id
		);

		return [ 'success' => true, 'enabled' => true, 'name' => $name ];
	}

	/**
	 * Legacy: get all notebooks (admin settings page).
	 */
	public static function get_all(): array {
		global $wpdb;
		$t  = self::table();
		$nt = self::notes_table();

		$rows = $wpdb->get_results(
			"SELECT n.*, COALESCE(c.cnt, 0) AS note_count
			 FROM {$t} n
			 LEFT JOIN (
			   SELECT notebook_id, COUNT(*) AS cnt
			   FROM {$nt} WHERE deleted_at IS NULL
			   GROUP BY notebook_id
			 ) c ON c.notebook_id = n.id
			 ORDER BY n.owner_id ASC, n.name ASC",
			ARRAY_A
		);

		return array_map( function ( $r ) {
			return [
				'id'    => (int) $r['id'],
				'name'  => $r['name'],
				'count' => (int) $r['note_count'],
				'owner' => (int) $r['owner_id'],
			];
		}, $rows ?: [] );
	}

	public static function get_by_name( string $name, int $owner_id = 0 ): ?array {
		global $wpdb;
		if ( $owner_id ) {
			return $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE name = %s AND owner_id = %d", $name, $owner_id
			), ARRAY_A );
		}
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE name = %s", $name
		), ARRAY_A );
	}

	public static function get_by_id( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d", $id
		), ARRAY_A );
	}

	public static function create( string $name, int $owner_id = 0 ): array {
		global $wpdb;
		$name = sanitize_text_field( $name );

		if ( self::get_by_name( $name, $owner_id ) ) {
			return [ 'error' => __( 'Notebook already exists', 'noodled' ) ];
		}

		$wpdb->insert( self::table(), [
			'name'        => $name,
			'owner_id'    => $owner_id,
			'created_at'  => current_time( 'mysql', true ),
			'modified_at' => current_time( 'mysql', true ),
		] );

		return [ 'id' => (int) $wpdb->insert_id, 'name' => $name, 'count' => 0, 'access' => 'owner' ];
	}

	public static function rename( string $old_name, string $new_name, int $owner_id = 0 ): array {
		global $wpdb;
		$new_name = sanitize_text_field( $new_name );
		$nb = self::get_by_name( $old_name, $owner_id );

		if ( ! $nb ) return [ 'error' => __( 'Notebook not found', 'noodled' ) ];
		if ( self::get_by_name( $new_name, $owner_id ) ) return [ 'error' => __( 'Name already taken', 'noodled' ) ];

		$wpdb->update( self::table(), [
			'name'        => $new_name,
			'modified_at' => current_time( 'mysql', true ),
		], [ 'id' => $nb['id'] ] );

		return [ 'id' => (int) $nb['id'], 'name' => $new_name ];
	}

	public static function delete( string $name, int $owner_id = 0 ): bool {
		global $wpdb;
		$nb = self::get_by_name( $name, $owner_id );
		if ( ! $nb ) return true;

		// Only owner can delete
		if ( (int) $nb['owner_id'] !== $owner_id && $owner_id > 0 ) return false;

		$now = current_time( 'mysql', true );
		$wpdb->update( self::notes_table(), [
			'deleted_at'   => $now,
			'deleted_from' => $nb['id'],
		], [
			'notebook_id' => $nb['id'],
			'deleted_at'  => null,
		] );

		$wpdb->delete( self::perms_table(), [ 'notebook_id' => $nb['id'] ] );
		$wpdb->delete( self::table(), [ 'id' => $nb['id'] ] );
		return true;
	}

	public static function ensure( string $name, int $owner_id = 0 ): int {
		$nb = self::get_by_name( $name, $owner_id );
		if ( $nb ) return (int) $nb['id'];

		$result = self::create( $name, $owner_id );
		return $result['id'] ?? 0;
	}

	/**
	 * Check if a user can access a notebook (owner or has permission).
	 */
	public static function user_can_access( int $user_id, int $notebook_id ): bool {
		$nb = self::get_by_id( $notebook_id );
		if ( ! $nb ) return false;
		if ( (int) $nb['owner_id'] === $user_id ) return true;
		return Noodled_Permissions::can_read( [ 'id' => $user_id, 'role' => 'member', 'wp' => false ], $notebook_id );
	}

	/**
	 * Check if a user can write to a notebook (owner or has write permission).
	 */
	public static function user_can_write( int $user_id, int $notebook_id ): bool {
		$nb = self::get_by_id( $notebook_id );
		if ( ! $nb ) return false;
		if ( (int) $nb['owner_id'] === $user_id ) return true;
		return Noodled_Permissions::can_write( [ 'id' => $user_id, 'role' => 'member', 'wp' => false ], $notebook_id );
	}
}
