<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Notes {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_notes';
	}

	private static function format_row( array $r ): array {
		$nb = Noodled_Notebooks::get_by_id( (int) $r['notebook_id'] );
		return [
			'id'       => (int) $r['id'],
			'slug'     => $r['slug'],
			'notebook' => $nb ? $nb['name'] : '',
			'title'    => $r['title'],
			'body'     => $r['body'] ?? '',
			'source'   => $r['source'] ?? '',
			'pinned'   => (bool) $r['pinned'],
			'created'  => $r['created_at'] ? substr( $r['created_at'], 0, 16 ) : '',
			'modified' => $r['modified_at'] ? substr( $r['modified_at'], 0, 16 ) : '',
			'att'      => isset( $r['att_count'] ) ? (int) $r['att_count'] : 0,
		];
	}

	public static function slug( string $title ): string {
		$slug = sanitize_title( $title );
		$slug = preg_replace( '/[^a-z0-9\-]/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', $slug );
		return trim( $slug, '-' ) ?: 'note-' . wp_generate_password( 8, false );
	}

	public static function get_all( ?int $notebook_id = null ): array {
		global $wpdb;
		$t  = self::table();
		$at = $wpdb->prefix . 'noodled_attachments';
		$cnt = "(SELECT COUNT(*) FROM {$at} a WHERE a.note_id = t.id) AS att_count";

		if ( $notebook_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.*, {$cnt} FROM {$t} t WHERE t.notebook_id = %d AND t.deleted_at IS NULL ORDER BY t.pinned DESC, t.modified_at DESC, t.created_at DESC",
				$notebook_id
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results(
				"SELECT t.*, {$cnt} FROM {$t} t WHERE t.deleted_at IS NULL ORDER BY t.pinned DESC, t.modified_at DESC, t.created_at DESC",
				ARRAY_A
			);
		}

		return array_map( [ __CLASS__, 'format_row' ], $rows ?: [] );
	}

	public static function get_one( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d", $id
		), ARRAY_A );

		return $row ? self::format_row( $row ) : null;
	}

	public static function create( string $notebook_name, string $title, string $body = '', int $owner_id = 0 ): array {
		global $wpdb;
		$notebook_id = Noodled_Notebooks::ensure( $notebook_name, $owner_id );
		if ( ! $notebook_id ) return [ 'error' => 'Could not create notebook' ];

		$slug = self::slug( $title );
		$now  = current_time( 'mysql', true );

		// Avoid slug collision within notebook
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . self::table() . " WHERE slug = %s AND notebook_id = %d AND deleted_at IS NULL",
			$slug, $notebook_id
		) );
		if ( $existing ) {
			$slug .= '-' . wp_generate_password( 4, false );
		}

		$wpdb->insert( self::table(), [
			'slug'        => $slug,
			'notebook_id' => $notebook_id,
			'title'       => sanitize_text_field( $title ),
			'body'        => $body,
			'created_at'  => $now,
			'modified_at' => $now,
		] );

		return self::get_one( (int) $wpdb->insert_id ) ?? [ 'error' => 'Failed to create note' ];
	}

	public static function update( int $id, array $data ): array {
		global $wpdb;

		$update = [ 'modified_at' => current_time( 'mysql', true ) ];
		if ( isset( $data['title'] ) ) $update['title'] = sanitize_text_field( $data['title'] );
		if ( isset( $data['body'] ) )  $update['body']  = $data['body'];

		// Update slug if title changed
		if ( isset( $data['title'] ) ) {
			$new_slug = self::slug( $data['title'] );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ), ARRAY_A );
			if ( $row && $new_slug !== $row['slug'] ) {
				$conflict = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM " . self::table() . " WHERE slug = %s AND notebook_id = %d AND id != %d AND deleted_at IS NULL",
					$new_slug, $row['notebook_id'], $id
				) );
				if ( ! $conflict ) {
					$update['slug'] = $new_slug;
				}
			}
		}

		$wpdb->update( self::table(), $update, [ 'id' => $id ] );
		return self::get_one( $id ) ?? [ 'error' => 'Note not found' ];
	}

	public static function soft_delete( int $id ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) return true;

		$wpdb->update( self::table(), [
			'deleted_at'   => current_time( 'mysql', true ),
			'deleted_from' => $row['notebook_id'],
		], [ 'id' => $id ] );

		return true;
	}

	public static function get_trash( array $notebook_ids = [] ): array {
		global $wpdb;
		// Empty scope must match NOTHING, never all notes (cross-user leak guard).
		$ids = $notebook_ids ? implode( ',', array_map( 'intval', $notebook_ids ) ) : '0';
		$where = "deleted_at IS NOT NULL AND (notebook_id IN ($ids) OR deleted_from IN ($ids))";
		$rows = $wpdb->get_results(
			"SELECT * FROM " . self::table() . " WHERE $where ORDER BY deleted_at DESC",
			ARRAY_A
		);
		return array_map( [ __CLASS__, 'format_row' ], $rows ?: [] );
	}

	public static function trash_count( array $notebook_ids = [] ): int {
		global $wpdb;
		$ids = $notebook_ids ? implode( ',', array_map( 'intval', $notebook_ids ) ) : '0';
		$where = "deleted_at IS NOT NULL AND (notebook_id IN ($ids) OR deleted_from IN ($ids))";
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE $where" );
	}

	public static function restore( int $id, int $owner_id = 0 ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) return [ 'error' => 'Note not found in trash' ];

		$target_id = $row['deleted_from'] ?: null;
		if ( $target_id ) {
			$nb = Noodled_Notebooks::get_by_id( (int) $target_id );
			if ( ! $nb ) $target_id = Noodled_Notebooks::ensure( 'My Notes', $owner_id );
		} else {
			$target_id = Noodled_Notebooks::ensure( 'My Notes', $owner_id );
		}

		$wpdb->update( self::table(), [
			'deleted_at'   => null,
			'deleted_from' => null,
			'notebook_id'  => $target_id,
			'modified_at'  => current_time( 'mysql', true ),
		], [ 'id' => $id ] );

		return self::get_one( $id ) ?? [ 'error' => 'Failed to restore' ];
	}

	public static function permanent_delete( int $id ): bool {
		global $wpdb;
		Noodled_Attachments::delete_for_note( $id );
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		return true;
	}

	public static function empty_trash( array $notebook_ids = [] ): bool {
		global $wpdb;
		// Empty scope deletes NOTHING (never wipe all users' trash).
		$ids = $notebook_ids ? implode( ',', array_map( 'intval', $notebook_ids ) ) : '0';
		$where = "deleted_at IS NOT NULL AND (notebook_id IN ($ids) OR deleted_from IN ($ids))";
		// Clean up each note's attachment files + rows before dropping the notes.
		$note_ids = $wpdb->get_col( "SELECT id FROM " . self::table() . " WHERE $where" );
		foreach ( $note_ids as $nid ) {
			Noodled_Attachments::delete_for_note( (int) $nid );
		}
		$wpdb->query( "DELETE FROM " . self::table() . " WHERE $where" );
		return true;
	}

	public static function move( int $id, string $to_notebook, int $owner_id = 0 ): array {
		global $wpdb;
		// Scope the target notebook to the acting user so notes can't be moved
		// into another tenant's notebook (or an orphaned owner_id=0 one).
		$notebook_id = Noodled_Notebooks::ensure( $to_notebook, $owner_id );

		$wpdb->update( self::table(), [
			'notebook_id' => $notebook_id,
			'modified_at' => current_time( 'mysql', true ),
		], [ 'id' => $id ] );

		return self::get_one( $id ) ?? [ 'error' => 'Note not found' ];
	}

	public static function toggle_pin( int $id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT pinned FROM " . self::table() . " WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) return [ 'error' => 'Note not found' ];

		$wpdb->update( self::table(), [
			'pinned' => $row['pinned'] ? 0 : 1,
		], [ 'id' => $id ] );

		return self::get_one( $id ) ?? [ 'error' => 'Note not found' ];
	}

	public static function search( string $query, array $notebook_ids = [], array $extra_note_ids = [] ): array {
		global $wpdb;
		// Scope to the user's accessible notebooks plus any directly-shared notes.
		// Empty scope matches nothing (never search all users' notes).
		$ids       = $notebook_ids ? implode( ',', array_map( 'intval', $notebook_ids ) ) : '0';
		$note_ids  = $extra_note_ids ? implode( ',', array_map( 'intval', $extra_note_ids ) ) : '0';
		$where     = "deleted_at IS NULL AND (notebook_id IN ($ids) OR id IN ($note_ids))";
		// Try FULLTEXT first, fall back to LIKE for short queries
		if ( strlen( $query ) >= 3 ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE $where AND MATCH(title, body) AGAINST(%s IN BOOLEAN MODE) ORDER BY modified_at DESC",
				'*' . $query . '*'
			), ARRAY_A );
			if ( $rows ) return array_map( [ __CLASS__, 'format_row' ], $rows );
		}
		$like = '%' . $wpdb->esc_like( $query ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE $where AND (title LIKE %s OR body LIKE %s) ORDER BY modified_at DESC",
			$like, $like
		), ARRAY_A );
		return array_map( [ __CLASS__, 'format_row' ], $rows ?: [] );
	}
}
