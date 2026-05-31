<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Permissions {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_permissions';
	}

	/**
	 * Check if a user can read a notebook.
	 * Admins (WP or noodled) can always read all.
	 */
	public static function can_read( ?array $user, int $notebook_id ): bool {
		if ( ! $user ) return false;
		if ( ( $user['role'] ?? '' ) === 'admin' ) return true;
		if ( $user['wp'] ?? false ) return true;

		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT can_read FROM " . self::table() . " WHERE user_id = %d AND notebook_id = %d",
			$user['id'], $notebook_id
		) );
	}

	/**
	 * Check if a user can write to a notebook.
	 */
	public static function can_write( ?array $user, int $notebook_id ): bool {
		if ( ! $user ) return false;
		if ( ( $user['role'] ?? '' ) === 'admin' ) return true;
		if ( $user['wp'] ?? false ) return true;

		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT can_write FROM " . self::table() . " WHERE user_id = %d AND notebook_id = %d",
			$user['id'], $notebook_id
		) );
	}

	/**
	 * Get notebook IDs accessible by a user (for filtering).
	 * Returns null for admins (meaning all notebooks).
	 */
	public static function get_accessible_notebook_ids( ?array $user ): ?array {
		if ( ! $user ) return [];
		if ( ( $user['role'] ?? '' ) === 'admin' || ( $user['wp'] ?? false ) ) return null;

		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT notebook_id FROM " . self::table() . " WHERE user_id = %d AND can_read = 1",
			$user['id']
		) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Set permissions for a user on a notebook.
	 */
	public static function set( int $user_id, int $notebook_id, bool $can_read, bool $can_write ): void {
		global $wpdb;
		$table = self::table();

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND notebook_id = %d",
			$user_id, $notebook_id
		) );

		if ( $existing ) {
			$wpdb->update( $table, [
				'can_read'  => $can_read ? 1 : 0,
				'can_write' => $can_write ? 1 : 0,
			], [ 'id' => $existing ] );
		} else {
			$wpdb->insert( $table, [
				'user_id'     => $user_id,
				'notebook_id' => $notebook_id,
				'can_read'    => $can_read ? 1 : 0,
				'can_write'   => $can_write ? 1 : 0,
			] );
		}
	}

	/**
	 * Remove all permissions for a user.
	 */
	public static function remove_all_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'user_id' => $user_id ] );
	}

	/**
	 * Get the permission matrix for all users and notebooks.
	 */
	public static function get_matrix(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.*, u.email, u.display_name, n.name as notebook_name
			 FROM " . self::table() . " p
			 JOIN {$wpdb->prefix}noodled_users u ON u.id = p.user_id
			 JOIN {$wpdb->prefix}noodled_notebooks n ON n.id = p.notebook_id
			 ORDER BY u.email, n.name",
			ARRAY_A
		);
		return $rows ?: [];
	}
}
