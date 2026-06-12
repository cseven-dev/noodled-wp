<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Notes {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_notes';
	}

	// List responses ($with_body = false) ship a short server-built preview and
	// task counts instead of the whole body, so a heavy notebook isn't a giant
	// payload parsed on every load. The editor (single-row callers) still gets
	// the full body.
	private static function format_row( array $r, bool $with_body = true ): array {
		// The list query joins the notebook name in (nb_name) to avoid an N+1
		// lookup; single-row callers (get_one/restore/etc.) fall back to a lookup.
		if ( array_key_exists( 'nb_name', $r ) ) {
			$nb_name = (string) $r['nb_name'];
		} else {
			$nb = Noodled_Notebooks::get_by_id( (int) $r['notebook_id'] );
			$nb_name = $nb ? $nb['name'] : '';
		}
		$body = $r['body'] ?? '';
		$out  = [
			'id'       => (int) $r['id'],
			'slug'     => $r['slug'],
			'notebook' => $nb_name,
			'title'    => $r['title'],
			'source'   => $r['source'] ?? '',
			'pinned'   => (bool) $r['pinned'],
			'created'  => $r['created_at'] ? substr( $r['created_at'], 0, 16 ) : '',
			'modified' => $r['modified_at'] ? substr( $r['modified_at'], 0, 16 ) : '',
			'att'      => isset( $r['att_count'] ) ? (int) $r['att_count'] : 0,
			'preview'  => self::preview_of( $body ),
			'tasks'    => self::task_counts( $body ),
		];
		if ( $with_body ) $out['body'] = $body;
		return $out;
	}

	// A plain-text snippet of the note body for the list, with markdown stripped.
	private static function preview_of( string $body ): string {
		// Locked (client-side encrypted) notes never leak their ciphertext into
		// the list preview.
		if ( str_starts_with( $body, 'noodled:enc:v1:' ) ) {
			return "\u{1F512} " . __( 'Locked note', 'noodled' );
		}
		$s = $body;
		$s = preg_replace( '/```.*?```/s', ' ', $s );              // fenced code
		$s = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', ' ', $s );    // images
		$s = preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $s );  // links → text
		$s = preg_replace( '/\[\[([^\]]+)\]\]/', '$1', $s );       // wiki links
		$s = preg_replace( '/^\s{0,3}#{1,6}\s*/m', '', $s );       // headings
		$s = preg_replace( '/^\s*[-*+]\s+\[[ xX]\]\s*/m', '', $s );// task markers
		$s = preg_replace( '/^\s*[-*+]\s+/m', '', $s );            // bullets
		$s = preg_replace( '/^\s*\d+\.\s+/m', '', $s );            // numbered
		$s = preg_replace( '/^\s*>\s?/m', '', $s );                // blockquote
		$s = preg_replace( '/[*_`~>#|]/', '', $s );                // stray md chars
		$s = preg_replace( '/\s+/', ' ', (string) $s );            // collapse ws
		$s = trim( (string) $s );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > 160 ) {
			return mb_substr( $s, 0, 160 ) . '…';
		}
		return strlen( $s ) > 160 ? substr( $s, 0, 160 ) . '…' : $s;
	}

	// Checklist progress for the list badge, without shipping the body.
	private static function task_counts( string $body ): array {
		if ( str_starts_with( $body, 'noodled:enc:v1:' ) ) {
			return [ 'done' => 0, 'total' => 0 ];
		}
		if ( ! preg_match_all( '/^\s*[-*+]\s+\[([ xX])\]/m', $body, $m ) ) {
			return [ 'done' => 0, 'total' => 0 ];
		}
		$done = 0;
		foreach ( $m[1] as $c ) { if ( strtolower( $c ) === 'x' ) $done++; }
		return [ 'done' => $done, 'total' => count( $m[1] ) ];
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
		$nb = $wpdb->prefix . 'noodled_notebooks';
		// One LEFT JOIN for the notebook name and one aggregated JOIN for the
		// attachment count, instead of an N+1 lookup per note plus a correlated
		// COUNT subquery per row.
		$join = "LEFT JOIN {$nb} n ON n.id = t.notebook_id "
		      . "LEFT JOIN ( SELECT note_id, COUNT(*) AS cnt FROM {$at} GROUP BY note_id ) ac ON ac.note_id = t.id";
		$cols = "t.*, n.name AS nb_name, COALESCE( ac.cnt, 0 ) AS att_count";
		$order = "ORDER BY t.pinned DESC, t.modified_at DESC, t.created_at DESC";

		if ( $notebook_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT {$cols} FROM {$t} t {$join} WHERE t.notebook_id = %d AND t.deleted_at IS NULL {$order}",
				$notebook_id
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results(
				"SELECT {$cols} FROM {$t} t {$join} WHERE t.deleted_at IS NULL {$order}",
				ARRAY_A
			);
		}

		return array_map( static fn( $r ) => self::format_row( $r, false ), $rows ?: [] );
	}

	/**
	 * Aggregate checklist items across the given notebooks + directly-shared notes
	 * into a flat task list. `idx` is the checkbox's document-order position within
	 * its note (matching the client's toggleCheck index), so a task can be toggled
	 * back at its source. A trailing date token (📅 / @due / due:) becomes `due`.
	 */
	public static function tasks_for( array $nb_ids, array $note_ids ): array {
		global $wpdb;
		$t  = self::table();
		$nb = $wpdb->prefix . 'noodled_notebooks';
		$clauses = [];
		if ( $nb_ids )   $clauses[] = 't.notebook_id IN (' . implode( ',', array_map( 'intval', $nb_ids ) ) . ')';
		if ( $note_ids ) $clauses[] = 't.id IN (' . implode( ',', array_map( 'intval', $note_ids ) ) . ')';
		if ( ! $clauses ) return [];
		$where = '(' . implode( ' OR ', $clauses ) . ') AND t.deleted_at IS NULL';
		$rows  = $wpdb->get_results(
			"SELECT t.id, t.title, t.body, n.name AS nb_name
			   FROM {$t} t LEFT JOIN {$nb} n ON n.id = t.notebook_id
			  WHERE {$where} ORDER BY t.modified_at DESC",
			ARRAY_A
		);
		$out = [];
		foreach ( $rows ?: [] as $r ) {
			$idx = 0;
			foreach ( explode( "\n", (string) $r['body'] ) as $line ) {
				if ( ! preg_match( '/^\s*[-*+]\s+\[([ xX])\]\s+(.*\S)/u', $line, $m ) ) continue;
				$text = trim( $m[2] );
				$due  = null;
				if ( preg_match( '/(?:\x{1F4C5}|@due|due:)\s*(\d{4}-\d{2}-\d{2})/u', $text, $dm ) ) {
					$due = $dm[1];
				}
				$out[] = [
					'note_id'  => (int) $r['id'],
					'title'    => $r['title'],
					'notebook' => $r['nb_name'],
					'text'     => $text,
					'done'     => ( $m[1] !== ' ' ),
					'idx'      => $idx,
					'due'      => $due,
				];
				$idx++;
			}
		}
		return $out;
	}

	// Flip the idx-th checklist item in a note (document order) and save.
	public static function toggle_task( int $id, int $idx ): array {
		global $wpdb;
		$body  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT body FROM " . self::table() . " WHERE id = %d", $id ) );
		$lines = explode( "\n", $body );
		$count = 0; $changed = false;
		foreach ( $lines as $k => $line ) {
			if ( preg_match( '/^(\s*[-*+]\s+\[)([ xX])(\].*)$/', $line, $m ) ) {
				if ( $count === $idx ) {
					$lines[ $k ] = $m[1] . ( $m[2] === ' ' ? 'x' : ' ' ) . $m[3];
					$changed = true;
					break;
				}
				$count++;
			}
		}
		if ( ! $changed ) return [ 'error' => __( 'Task not found', 'noodled' ) ];
		return self::update( $id, [ 'body' => implode( "\n", $lines ) ] );
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
		if ( ! $notebook_id ) return [ 'error' => __( 'Could not create notebook', 'noodled' ) ];

		// Guarantee a unique title+slug within the notebook so the GitHub file
		// (Folder/Title.md) can never collide with an existing note and overwrite
		// it on push/import. The filename is derived from the TITLE, so deduping
		// the slug alone is not enough (two same-titled notes share a filename) —
		// this is the same note-eating bug move() was hardened against. Appends
		// " 2"/" 3"/... on a clash. 0 = no existing note to exclude (not saved yet).
		list( $title, $slug ) = self::unique_in_notebook( $title, $notebook_id, 0 );
		$now = current_time( 'mysql', true );

		$wpdb->insert( self::table(), [
			'slug'        => $slug,
			'notebook_id' => $notebook_id,
			'title'       => sanitize_text_field( $title ),
			'body'        => $body,
			'created_at'  => $now,
			'modified_at' => $now,
		] );

		return self::get_one( (int) $wpdb->insert_id ) ?? [ 'error' => __( 'Failed to create note', 'noodled' ) ];
	}

	public static function update( int $id, array $data ): array {
		global $wpdb;

		// Snapshot the pre-edit state into version history before overwriting it.
		if ( isset( $data['title'] ) || isset( $data['body'] ) ) {
			self::save_revision( $id );
		}

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
		return self::get_one( $id ) ?? [ 'error' => __( 'Note not found', 'noodled' ) ];
	}

	/* ── Version history (durable, server-side) ── */

	// Snapshot the note's CURRENT stored title/body as a revision. Deduped against
	// the latest revision and pruned to the most recent 15 so history stays bounded.
	public static function save_revision( int $id ): void {
		global $wpdb;
		$cur = $wpdb->get_row( $wpdb->prepare(
			"SELECT title, body FROM " . self::table() . " WHERE id = %d", $id
		), ARRAY_A );
		if ( ! $cur ) return;
		$rev = $wpdb->prefix . 'noodled_revisions';
		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT title, body FROM $rev WHERE note_id = %d ORDER BY id DESC LIMIT 1", $id
		), ARRAY_A );
		if ( $last && $last['title'] === (string) $cur['title'] && $last['body'] === (string) $cur['body'] ) {
			return; // no change since the last snapshot
		}
		$wpdb->insert( $rev, [
			'note_id'    => $id,
			'title'      => $cur['title'],
			'body'       => $cur['body'],
			'created_at' => current_time( 'mysql', true ),
		] );
		$stale = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $rev WHERE note_id = %d ORDER BY id DESC LIMIT 50 OFFSET 15", $id
		) );
		if ( $stale ) {
			$wpdb->query( "DELETE FROM $rev WHERE id IN (" . implode( ',', array_map( 'intval', $stale ) ) . ")" );
		}
	}

	public static function get_revisions( int $id ): array {
		global $wpdb;
		$rev = $wpdb->prefix . 'noodled_revisions';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, body, created_at FROM $rev WHERE note_id = %d ORDER BY id DESC LIMIT 15", $id
		), ARRAY_A );
		return array_map( function ( $r ) {
			return [
				'id'    => (int) $r['id'],
				'title' => $r['title'],
				'body'  => (string) $r['body'],
				// ISO-8601 UTC so the client can render a local relative time.
				'time'  => $r['created_at'] ? ( str_replace( ' ', 'T', substr( $r['created_at'], 0, 19 ) ) . 'Z' ) : '',
			];
		}, $rows ?: [] );
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
		return array_map( static fn( $r ) => self::format_row( $r, false ), $rows ?: [] );
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
		if ( ! $row ) return [ 'error' => __( 'Note not found in trash', 'noodled' ) ];

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

		return self::get_one( $id ) ?? [ 'error' => __( 'Failed to restore', 'noodled' ) ];
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
		$note = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ), ARRAY_A );
		if ( ! $note ) return [ 'error' => __( 'Note not found', 'noodled' ) ];

		$old_nb = Noodled_Notebooks::get_by_id( (int) $note['notebook_id'] );
		// Scope the target notebook to the acting user so notes can't be moved
		// into another tenant's notebook (or an orphaned owner_id=0 one).
		$notebook_id = Noodled_Notebooks::ensure( $to_notebook, $owner_id );

		// Guarantee a unique filename in the destination so the GitHub file
		// (Folder/Title.md) can't collide with an existing note and overwrite it
		// (this was the note-eating bug). Appends " 2"/" 3"/... on a real clash.
		list( $title, $slug ) = self::unique_in_notebook( (string) $note['title'], $notebook_id, $id );

		// Remove the old-folder GitHub file so it doesn't orphan and re-import as a
		// duplicate, and clear the sha so the note re-pushes cleanly to its new path.
		if ( (int) $note['notebook_id'] !== $notebook_id && ! empty( $note['sha'] ) && $old_nb && class_exists( 'Noodled_Sync' ) ) {
			Noodled_Sync::delete_from_github( $old_nb['name'], (string) $note['title'], (string) $note['sha'] );
		}

		// Snapshot before a move (which can force-rename the title on a clash) so the
		// pre-move title/body stays recoverable from History.
		self::save_revision( $id );
		$wpdb->update( self::table(), [
			'notebook_id' => $notebook_id,
			'title'       => $title,
			'slug'        => $slug,
			'sha'         => null,
			'modified_at' => current_time( 'mysql', true ),
		], [ 'id' => $id ] );

		return self::get_one( $id ) ?? [ 'error' => __( 'Note not found', 'noodled' ) ];
	}

	/**
	 * Make a note's title/slug unique within a notebook so two notes can never
	 * share the GitHub filename (Folder/Title.md), which is what let a move
	 * overwrite another note. Appends " 2", " 3", ... to the title (and matching
	 * slug) on collision. Returns [ title, slug ].
	 */
	private static function unique_in_notebook( string $title, int $notebook_id, int $exclude_id ): array {
		global $wpdb;
		$base = $title !== '' ? $title : __( 'Untitled Note', 'noodled' );
		for ( $n = 1; $n <= 50; $n++ ) {
			$try_title = $n === 1 ? $base : $base . ' ' . $n;
			$try_slug  = self::slug( $try_title );
			$clash = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM " . self::table() . " WHERE slug = %s AND notebook_id = %d AND id != %d AND deleted_at IS NULL",
				$try_slug, $notebook_id, $exclude_id
			) );
			if ( ! $clash ) return [ $try_title, $try_slug ];
		}
		$suffix = wp_generate_password( 4, false );
		return [ $base . ' ' . $suffix, self::slug( $base . '-' . $suffix ) ];
	}

	public static function toggle_pin( int $id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT pinned FROM " . self::table() . " WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) return [ 'error' => __( 'Note not found', 'noodled' ) ];

		$wpdb->update( self::table(), [
			'pinned' => $row['pinned'] ? 0 : 1,
		], [ 'id' => $id ] );

		return self::get_one( $id ) ?? [ 'error' => __( 'Note not found', 'noodled' ) ];
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
			if ( $rows ) return array_map( static fn( $r ) => self::format_row( $r, false ), $rows );
		}
		$like = '%' . $wpdb->esc_like( $query ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE $where AND (title LIKE %s OR body LIKE %s) ORDER BY modified_at DESC",
			$like, $like
		), ARRAY_A );
		return array_map( static fn( $r ) => self::format_row( $r, false ), $rows ?: [] );
	}

	// Notes that link to a given title via a [[wiki-link]] (for the backlinks
	// panel), resolved server-side so the list never has to ship every body.
	public static function backlinks( string $title, array $notebook_ids = [], array $extra_note_ids = [] ): array {
		global $wpdb;
		$title = trim( $title );
		if ( $title === '' ) return [];
		$ids      = $notebook_ids ? implode( ',', array_map( 'intval', $notebook_ids ) ) : '0';
		$note_ids = $extra_note_ids ? implode( ',', array_map( 'intval', $extra_note_ids ) ) : '0';
		$where    = "deleted_at IS NULL AND (notebook_id IN ($ids) OR id IN ($note_ids))";
		$like     = '%[[' . $wpdb->esc_like( $title ) . ']]%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE $where AND body LIKE %s ORDER BY modified_at DESC",
			$like
		), ARRAY_A );
		return array_map( static fn( $r ) => self::format_row( $r, false ), $rows ?: [] );
	}

	// Lightweight id→body list for client features that scan all note content
	// (search, tag cloud, link graph, stats). Fetched lazily so the note list
	// itself stays body-free for fast first paint.
	public static function bodies( array $notebook_ids = [], array $extra_note_ids = [] ): array {
		global $wpdb;
		$ids      = $notebook_ids ? implode( ',', array_map( 'intval', $notebook_ids ) ) : '0';
		$note_ids = $extra_note_ids ? implode( ',', array_map( 'intval', $extra_note_ids ) ) : '0';
		$where    = "deleted_at IS NULL AND (notebook_id IN ($ids) OR id IN ($note_ids))";
		$rows = $wpdb->get_results( "SELECT id, body FROM " . self::table() . " WHERE $where", ARRAY_A );
		$out = [];
		foreach ( $rows ?: [] as $r ) $out[] = [ 'id' => (int) $r['id'], 'body' => $r['body'] ?? '' ];
		return $out;
	}
}
