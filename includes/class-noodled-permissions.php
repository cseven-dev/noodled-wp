<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Permissions {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_permissions';
	}

	/**
	 * Check if a user has an explicit read grant on a notebook.
	 * Privacy-first: there is NO admin god-view — access is by ownership
	 * (checked by the caller) or an explicit share only.
	 */
	public static function can_read( ?array $user, int $notebook_id ): bool {
		if ( ! $user ) return false;

		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT can_read FROM " . self::table() . " WHERE user_id = %d AND notebook_id = %d",
			$user['id'], $notebook_id
		) );
	}

	/**
	 * Check if a user has an explicit write grant on a notebook.
	 */
	public static function can_write( ?array $user, int $notebook_id ): bool {
		if ( ! $user ) return false;

		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT can_write FROM " . self::table() . " WHERE user_id = %d AND notebook_id = %d",
			$user['id'], $notebook_id
		) );
	}

	/**
	 * Get notebook IDs explicitly shared with a user (for filtering).
	 * No admin shortcut — everyone, including admins, is scoped to their grants.
	 */
	public static function get_accessible_notebook_ids( ?array $user ): array {
		if ( ! $user ) return [];

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
		$wpdb->delete( self::note_table(), [ 'user_id' => $user_id ] );
	}

	// ── Note-level sharing ──

	private static function note_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_note_permissions';
	}

	public static function note_can_read( int $user_id, int $note_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . self::note_table() . " WHERE user_id = %d AND note_id = %d",
			$user_id, $note_id
		) );
	}

	public static function note_can_write( int $user_id, int $note_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT can_write FROM " . self::note_table() . " WHERE user_id = %d AND note_id = %d",
			$user_id, $note_id
		) );
	}

	public static function share_note( int $note_id, int $user_id, bool $can_write ): void {
		global $wpdb;
		$table = self::note_table();
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE note_id = %d AND user_id = %d", $note_id, $user_id
		) );
		if ( $existing ) {
			$wpdb->update( $table, [ 'can_write' => $can_write ? 1 : 0 ], [ 'id' => $existing ] );
		} else {
			$wpdb->insert( $table, [
				'note_id'   => $note_id,
				'user_id'   => $user_id,
				'can_write' => $can_write ? 1 : 0,
			] );
		}
	}

	public static function unshare_note( int $note_id, int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::note_table(), [ 'note_id' => $note_id, 'user_id' => $user_id ] );
	}

	/** Note IDs shared directly with a user. */
	public static function shared_note_ids_for_user( int $user_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT note_id FROM " . self::note_table() . " WHERE user_id = %d", $user_id
		) );
		return array_map( 'intval', $ids );
	}

	/** Users a note is shared with: [ ['user_id','email','display_name','can_write'], ... ]. */
	public static function note_shares( int $note_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT np.user_id, np.can_write, u.email, u.display_name
			 FROM " . self::note_table() . " np
			 JOIN {$wpdb->prefix}noodled_users u ON u.id = np.user_id
			 WHERE np.note_id = %d ORDER BY u.email", $note_id
		), ARRAY_A );
		return $rows ?: [];
	}

	/** Users a notebook is shared with (via notebook permissions). */
	public static function notebook_shares( int $notebook_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.user_id, p.can_read, p.can_write, u.email, u.display_name
			 FROM " . self::table() . " p
			 JOIN {$wpdb->prefix}noodled_users u ON u.id = p.user_id
			 WHERE p.notebook_id = %d AND p.can_read = 1 ORDER BY u.email", $notebook_id
		), ARRAY_A );
		return $rows ?: [];
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
