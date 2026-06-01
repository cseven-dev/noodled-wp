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

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT n.*, COALESCE(c.cnt, 0) AS note_count,
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
			 WHERE n.owner_id = %d OR (p.user_id = %d AND p.can_read = 1)
			 ORDER BY n.sort_order ASC, n.name ASC",
			$user_id, $user_id, $user_id, $user_id
		), ARRAY_A );

		return array_map( function ( $r ) {
			return [
				'id'     => (int) $r['id'],
				'name'   => $r['name'],
				'count'  => (int) $r['note_count'],
				'access' => $r['access'],
				'owner'  => (int) $r['owner_id'],
			];
		}, $rows ?: [] );
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
			return [ 'error' => 'Notebook already exists' ];
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

		if ( ! $nb ) return [ 'error' => 'Notebook not found' ];
		if ( self::get_by_name( $new_name, $owner_id ) ) return [ 'error' => 'Name already taken' ];

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
