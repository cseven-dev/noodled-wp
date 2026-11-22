<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Sync {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_webhook' ] );
	}

	public static function register_webhook() {
		register_rest_route( 'noodled/v1', '/webhook/github', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_webhook' ],
			'permission_callback' => '__return_true', // Auth via signature
		] );
	}

	// ── Push: WP → GitHub ──

	// Per-request caches, and the queue the shutdown push works through.
	private static $owner_is_admin  = [];
	private static $sync_owner      = null;
	private static $queued          = [];
	private static $shutdown_hooked = false;

	/**
	 * Only notebooks owned by an admin are backed up to the (admin's) repo.
	 * Members' notes never leave the site, whoever saves them.
	 */
	public static function is_pushable_notebook( array $notebook ): bool {
		$owner = (int) ( $notebook['owner_id'] ?? 0 );
		if ( $owner <= 0 ) return false;
		if ( ! isset( self::$owner_is_admin[ $owner ] ) ) {
			global $wpdb;
			self::$owner_is_admin[ $owner ] = $wpdb->get_var( $wpdb->prepare(
				"SELECT role FROM {$wpdb->prefix}noodled_users WHERE id = %d", $owner
			) ) === 'admin';
		}
		return self::$owner_is_admin[ $owner ];
	}

	/**
	 * The noodled_users id whose notebooks the repo pulls into: the first admin
	 * (same rule as noodled_migrate_privacy). 0 when there is no admin.
	 */
	public static function sync_owner_id(): int {
		if ( self::$sync_owner === null ) {
			global $wpdb;
			self::$sync_owner = (int) $wpdb->get_var(
				"SELECT id FROM {$wpdb->prefix}noodled_users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
			);
		}
		return self::$sync_owner;
	}

	// Notes (n) joined to their notebook (b) and its owner (u), for "pushable" filters.
	private static function owner_join(): string {
		global $wpdb;
		return "{$wpdb->prefix}noodled_notes n
			JOIN {$wpdb->prefix}noodled_notebooks b ON b.id = n.notebook_id
			JOIN {$wpdb->prefix}noodled_users u ON u.id = b.owner_id";
	}

	private static function plain_name( array $row ): string {
		return Noodled_Frontmatter::safe_filename( (string) $row['title'] ) ?: (string) $row['slug'];
	}

	/**
	 * Decide which repo file a note lives in. $claimed = the paths held by OTHER
	 * notes in admin notebooks with this name. Returns 'current' (the file the
	 * note already owns, '' if none) and 'target' (the file it should be in).
	 */
	public static function resolve_path( array $row, string $nb_name, array $claimed ): array {
		$base   = self::plain_name( $row );
		$plain  = "{$nb_name}/{$base}.md";
		$disamb = "{$nb_name}/{$base} (" . (int) $row['id'] . ').md';

		$current = (string) ( $row['gh_path'] ?? '' );
		if ( $current === '' && ! empty( $row['sha'] ) && ! in_array( $plain, $claimed, true ) ) {
			$current = $plain;
		}

		if ( $current === $plain || $current === $disamb ) {
			$target = $current;
		} else {
			$target = in_array( $plain, $claimed, true ) ? $disamb : $plain;
		}
		return [ 'target' => $target, 'current' => $current ];
	}

	// Paths held by the other notes (trashed included) of admin notebooks named $nb_name.
	private static function claimed_paths( array $row, string $nb_name ): array {
		global $wpdb;
		$others = $wpdb->get_results( $wpdb->prepare(
			"SELECT n.id, n.title, n.slug, n.sha, n.gh_path FROM " . self::owner_join() . "
			 WHERE b.name = %s AND u.role = 'admin' AND n.id != %d",
			$nb_name, (int) $row['id']
		), ARRAY_A );
		$claimed = [];
		foreach ( $others ?: [] as $o ) {
			if ( (string) $o['gh_path'] !== '' ) {
				$claimed[] = (string) $o['gh_path'];
			} elseif ( ! empty( $o['sha'] ) && (int) $o['id'] < (int) $row['id'] ) {
				$claimed[] = $nb_name . '/' . self::plain_name( $o ) . '.md'; // legacy row (no gh_path yet)
			}
		}
		return $claimed;
	}

	/**
	 * Push a single note to GitHub. Records the outcome on the row (sha + gh_path
	 * on success, push_pending/attempts/error on failure) and never touches
	 * modified_at. Returns [ 'success' ], [ 'skipped' ] or [ 'error' ].
	 */
	public static function push_note( int $note_id ): array {
		if ( ! Noodled_GitHub::is_configured() ) {
			return [ 'error' => __( 'GitHub not configured', 'noodled' ) ];
		}

		global $wpdb;
		$t   = "{$wpdb->prefix}noodled_notes";
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		), ARRAY_A );

		if ( ! $row ) return [ 'error' => __( 'Note not found', 'noodled' ) ];

		$nb = Noodled_Notebooks::get_by_id( (int) $row['notebook_id'] );
		if ( ! $nb || ! self::is_pushable_notebook( $nb ) || ! empty( $row['deleted_at'] ) ) {
			$wpdb->update( $t, [ 'push_pending' => 0 ], [ 'id' => $note_id ] );
			return [ 'skipped' => true ];
		}

		$note_data = [
			'title'    => $row['title'],
			'body'     => $row['body'] ?? '',
			'source'   => $row['source'] ?? '',
			'pinned'   => (bool) $row['pinned'],
			'created'  => $row['created_at'],
			'modified' => $row['modified_at'],
		];

		$markdown = Noodled_Frontmatter::to_markdown( $note_data );
		$blob     = sha1( 'blob ' . strlen( $markdown ) . "\0" . $markdown ); // git blob sha of the file
		$paths    = self::resolve_path( $row, $nb['name'], self::claimed_paths( $row, $nb['name'] ) );
		$target   = $paths['target'];
		$current  = $paths['current'];
		$done     = [ 'gh_path' => $target, 'push_pending' => 0, 'push_attempts' => 0, 'push_error' => '' ];

		if ( ! empty( $row['sha'] ) && $row['sha'] === $blob && $current === $target ) {
			$wpdb->update( $t, $done, [ 'id' => $note_id ] ); // already in sync: no API call
			return [ 'success' => true, 'sha' => $blob, 'unchanged' => true ];
		}

		$message = "noodled sync: update {$row['title']}";
		$result  = Noodled_GitHub::put_file( $target, $markdown, $message, $current === $target ? (string) $row['sha'] : '' );

		// 422: the file exists but we sent no sha. 409: our sha is stale. 404: we
		// sent a sha for a file that is gone. Take over the remote file, once,
		// unless another note claimed $target since resolve_path ran (two pushes
		// racing): then leave this attempt failed, and the retry gets its own file.
		if ( is_wp_error( $result ) && in_array( self::error_status( $result ), [ 404, 409, 422 ], true )
			&& ! in_array( $target, self::claimed_paths( $row, $nb['name'] ), true ) ) {
			$remote = Noodled_GitHub::get_file( $target );
			if ( ! is_wp_error( $remote ) ) {
				$result = Noodled_GitHub::put_file( $target, $markdown, $message, (string) $remote['sha'] );
			} elseif ( self::error_status( $remote ) === 404 ) {
				$result = Noodled_GitHub::put_file( $target, $markdown, $message, '' );
			}
		}

		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$t} SET push_pending = 1, push_attempts = push_attempts + 1, push_error = %s WHERE id = %d",
				mb_substr( $msg, 0, 250 ), $note_id
			) );
			return [ 'error' => $msg ];
		}

		$new_sha = (string) ( $result['content']['sha'] ?? $blob );
		$wpdb->update( $t, [ 'sha' => $new_sha ] + $done, [ 'id' => $note_id ] );

		// Title or notebook changed: remove the file the note used to live in. Best
		// effort: a stale sha or an already-deleted file just fails quietly.
		if ( $current !== '' && $current !== $target && ! empty( $row['sha'] ) ) {
			Noodled_GitHub::delete_file( $current, (string) $row['sha'], "noodled sync: move {$row['title']}" );
		}

		return [ 'success' => true, 'sha' => $new_sha ];
	}

	// HTTP status carried by a Noodled_GitHub WP_Error (0 when unknown).
	private static function error_status( \WP_Error $e ): int {
		$data = $e->get_error_data();
		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}

	/**
	 * Mark a note for backup and push it after the response is sent. Only notes
	 * in pushable notebooks are queued; cron (drain) picks up anything left over.
	 */
	public static function queue_push( int $note_id ): void {
		if ( $note_id <= 0 || ! Noodled_GitHub::is_configured() ) return;
		global $wpdb;
		$nb_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		) );
		$nb = $nb_id ? Noodled_Notebooks::get_by_id( $nb_id ) : null;
		if ( ! $nb || ! self::is_pushable_notebook( $nb ) ) return;

		$wpdb->update( "{$wpdb->prefix}noodled_notes", [ 'push_pending' => 1 ], [ 'id' => $note_id ] );
		if ( ! in_array( $note_id, self::$queued, true ) ) self::$queued[] = $note_id;
		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', [ __CLASS__, 'push_queued' ] );
		}
	}

	/** Shutdown callback for queue_push(): finish the response, then push up to 5 notes. */
	public static function push_queued(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();
		$ids = array_slice( self::$queued, 0, 5 ); // the rest stay pending for cron
		self::$queued = [];
		foreach ( $ids as $id ) {
			self::push_note( $id );
		}
	}

	/**
	 * Push pending notes, oldest edit first. Stops after $max_pushes calls that
	 * reached GitHub, or after $max_seconds. A note that failed 8 times waits for
	 * "Push all", which resets its attempts.
	 */
	public static function drain( int $max_pushes = 20, int $max_seconds = 20 ): array {
		global $wpdb;
		$t     = "{$wpdb->prefix}noodled_notes";
		$out   = [ 'pushed' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0 ];
		$start = microtime( true );
		$calls = 0;
		$seen  = [];

		while ( Noodled_GitHub::is_configured() ) {
			// Failed notes stay pending, so widen the window past the ones seen.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$t} WHERE push_pending = 1 AND push_attempts < 8 ORDER BY modified_at ASC, id ASC LIMIT %d",
				count( $seen ) + 25
			) );
			$ids = array_diff( array_map( 'intval', $ids ?: [] ), $seen );
			if ( ! $ids ) break;
			foreach ( $ids as $id ) {
				if ( $calls >= $max_pushes || microtime( true ) - $start >= $max_seconds ) break 2;
				$seen[] = $id;
				$r = self::push_note( $id );
				if ( ! empty( $r['skipped'] ) || ! empty( $r['unchanged'] ) ) {
					$out['skipped']++;
					continue;
				}
				$calls++;
				if ( isset( $r['error'] ) ) $out['failed']++;
				else $out['pushed']++;
			}
		}

		$out['remaining'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$t} WHERE push_pending = 1 AND push_attempts < 8"
		);
		return $out;
	}

	/** "Push all": queue every live note in a pushable notebook. Returns how many are queued. */
	public static function queue_all(): int {
		global $wpdb;
		$wpdb->query(
			"UPDATE " . self::owner_join() . " SET n.push_pending = 1, n.push_attempts = 0
			 WHERE n.deleted_at IS NULL AND u.role = 'admin'"
		);
		return self::unsynced_count();
	}

	/** One-time reconcile: queue every live pushable note that was never backed up. */
	public static function queue_unsynced(): bool {
		global $wpdb;
		return false !== $wpdb->query(
			"UPDATE " . self::owner_join() . " SET n.push_pending = 1
			 WHERE n.deleted_at IS NULL AND u.role = 'admin' AND ( n.sha = '' OR n.sha IS NULL )"
		);
	}

	/** Live notes in pushable notebooks that are not backed up yet. */
	public static function unsynced_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::owner_join() . "
			 WHERE n.deleted_at IS NULL AND u.role = 'admin' AND ( n.sha = '' OR n.sha IS NULL OR n.push_pending = 1 )"
		);
	}

	/** Up to 20 live pushable notes whose pushes have been failing, most recent first. */
	public static function failed_pushes(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT n.id, n.title, b.name AS notebook, n.push_error AS error, n.push_attempts AS attempts
			 FROM " . self::owner_join() . "
			 WHERE n.deleted_at IS NULL AND u.role = 'admin' AND n.push_attempts > 0
			 ORDER BY n.modified_at DESC LIMIT 20",
			ARRAY_A
		);
		return array_map( static function ( $r ) {
			return [
				'id'       => (int) $r['id'],
				'title'    => (string) $r['title'],
				'notebook' => (string) $r['notebook'],
				'error'    => (string) $r['error'],
				'attempts' => (int) $r['attempts'],
			];
		}, $rows ?: [] );
	}

	/**
	 * Delete a note from GitHub. Pass the note's gh_path as $path when it has
	 * one; otherwise the path is computed from the notebook name and title.
	 */
	public static function delete_from_github( string $notebook_name, string $title, string $sha, string $path = '' ): array {
		if ( ! Noodled_GitHub::is_configured() || ! $sha ) {
			return [ 'error' => 'Not configured or no SHA' ];
		}

		if ( $path === '' ) {
			$filename = Noodled_Frontmatter::safe_filename( $title ) ?: sanitize_title( $title );
			$path     = $notebook_name . '/' . $filename . '.md';
		}
		$message  = "noodled sync: delete {$title}";

		$result = Noodled_GitHub::delete_file( $path, $sha, $message );
		if ( is_wp_error( $result ) ) {
			return [ 'error' => $result->get_error_message() ];
		}

		return [ 'success' => true ];
	}

	// ── Pull: GitHub → WP (full import) ──

	/**
	 * Import all notes from the GitHub repo into WordPress.
	 */
	public static function full_import(): array {
		if ( ! Noodled_GitHub::is_configured() ) {
			return [ 'error' => __( 'GitHub not configured', 'noodled' ) ];
		}

		// The repo is the admin's: pull only into the sync owner's own notebooks,
		// never into a member's notebook that happens to share a name.
		$owner_id = self::sync_owner_id();
		if ( ! $owner_id ) {
			return [ 'error' => __( 'No admin user to sync into', 'noodled' ) ];
		}

		$tree = Noodled_GitHub::get_tree();
		if ( is_wp_error( $tree ) ) {
			return [ 'error' => $tree->get_error_message() ];
		}

		$notebooks_created = 0;
		$notes_imported    = 0;
		$notes_skipped     = 0;

		// First pass: identify directories (notebooks) and .md files
		$dirs  = [];
		$files = [];

		foreach ( $tree as $item ) {
			$path = $item['path'];

			// Skip hidden dirs/files
			if ( str_starts_with( $path, '.' ) || str_contains( $path, '/.' ) ) continue;
			// Skip attachment folders
			if ( str_contains( $path, '_files/' ) || str_ends_with( $path, '_files' ) ) continue;

			if ( $item['type'] === 'tree' && ! str_contains( $path, '/' ) ) {
				$dirs[] = $path;
			}

			if ( $item['type'] === 'blob' && str_ends_with( $path, '.md' ) && str_contains( $path, '/' ) ) {
				$files[] = $item;
			}
		}

		// Create notebooks
		foreach ( $dirs as $dir ) {
			$existing = Noodled_Notebooks::get_by_name( $dir, $owner_id );
			if ( ! $existing ) {
				Noodled_Notebooks::create( $dir, $owner_id );
				$notebooks_created++;
			}
		}

		// Import notes
		global $wpdb;

		foreach ( $files as $item ) {
			$path  = $item['path'];
			$sha   = $item['sha'];
			$parts = explode( '/', $path, 2 );

			if ( count( $parts ) !== 2 ) continue;

			// Skip if we already have this SHA
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}noodled_notes WHERE sha = %s", $sha
			) );
			if ( $existing ) {
				$notes_skipped++;
				continue;
			}

			// Fetch file content
			$file = Noodled_GitHub::get_file( $path );
			if ( is_wp_error( $file ) ) continue;

			$outcome = self::apply_pulled_file( $path, $file, $owner_id );
			if ( $outcome === 'inserted' || $outcome === 'updated' ) {
				$notes_imported++;
			} else {
				$notes_skipped++;
			}
		}

		return [
			'notebooks' => $notebooks_created,
			'notes'     => $notes_imported,
			'skipped'   => $notes_skipped,
		];
	}

	// ── Webhook: GitHub push event → WP ──

	public static function handle_webhook( \WP_REST_Request $req ): \WP_REST_Response {
		// Verify signature. The route is public (auth is the HMAC), so a missing
		// secret must fail closed — otherwise a forged push payload could write to
		// any notebook unauthenticated.
		$cfg    = Noodled_Settings::get();
		$secret = $cfg['webhook_secret'] ?? '';

		if ( ! $secret ) {
			return new \WP_REST_Response( [ 'error' => 'Webhook secret not configured' ], 403 );
		}

		$sig_header = $req->get_header( 'X-Hub-Signature-256' );
		$payload    = $req->get_body();
		$expected   = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $expected, $sig_header ?? '' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 403 );
		}

		$event = $req->get_header( 'X-GitHub-Event' );
		if ( $event === 'ping' ) {
			return new \WP_REST_Response( [ 'ok' => true, 'msg' => 'pong' ] );
		}

		if ( $event !== 'push' ) {
			return new \WP_REST_Response( [ 'ok' => true, 'msg' => 'ignored' ] );
		}

		$body    = $req->get_json_params();
		$commits = $body['commits'] ?? [];

		$added    = [];
		$modified = [];
		$removed  = [];

		foreach ( $commits as $commit ) {
			// Skip commits from noodled-wp to avoid loops
			if ( str_contains( $commit['message'] ?? '', 'noodled sync:' ) ) continue;

			foreach ( $commit['added'] ?? [] as $f )    $added[]    = $f;
			foreach ( $commit['modified'] ?? [] as $f ) $modified[] = $f;
			foreach ( $commit['removed'] ?? [] as $f )  $removed[]  = $f;
		}

		$processed = 0;

		// Process added + modified files
		foreach ( array_unique( array_merge( $added, $modified ) ) as $path ) {
			if ( ! str_ends_with( $path, '.md' ) ) continue;
			if ( str_starts_with( $path, '.' ) || str_contains( $path, '/.' ) ) continue;
			if ( str_contains( $path, '_files/' ) ) continue;

			$parts = explode( '/', $path, 2 );
			if ( count( $parts ) !== 2 ) continue;

			$result = self::pull_single_file( $path );
			if ( $result ) $processed++;
		}

		// Process removed files
		foreach ( array_unique( $removed ) as $path ) {
			if ( ! str_ends_with( $path, '.md' ) ) continue;

			$parts = explode( '/', $path, 2 );
			if ( count( $parts ) !== 2 ) continue;

			self::remove_file_from_db( $path );
			$processed++;
		}

		return new \WP_REST_Response( [ 'ok' => true, 'processed' => $processed ] );
	}

	/**
	 * Pull a single file from GitHub and upsert into DB.
	 */
	private static function pull_single_file( string $path ): bool {
		$owner_id = self::sync_owner_id();
		if ( ! $owner_id ) return false;

		$file = Noodled_GitHub::get_file( $path );
		if ( is_wp_error( $file ) ) return false;

		self::apply_pulled_file( $path, $file, $owner_id );
		return true;
	}

	/**
	 * Upsert one repo file into the sync owner's notebook of the same name.
	 * Matches an existing note by gh_path, then (among notes that own no other
	 * file) by sha, then by slug. Same content: only the sha is stored. Changed
	 * content: the repo copy wins only when its `modified` is strictly later;
	 * otherwise the web copy is kept and re-pushed over the stale file.
	 * Returns 'inserted', 'updated', 'same' or 'kept' ('' if no notebook).
	 */
	private static function apply_pulled_file( string $path, array $file, int $owner_id ): string {
		global $wpdb;
		$t = "{$wpdb->prefix}noodled_notes";

		$parts         = explode( '/', $path, 2 );
		$notebook_name = $parts[0];
		$filename      = pathinfo( $parts[1], PATHINFO_FILENAME );

		$parsed      = Noodled_Frontmatter::from_markdown( $file['content'] );
		$title       = $parsed['title'] ?: $filename;
		$notebook_id = Noodled_Notebooks::ensure( $notebook_name, $owner_id );
		if ( ! $notebook_id ) return '';
		$slug        = Noodled_Notes::slug( $title );
		$now         = current_time( 'mysql', true );

		$data = [
			'slug'        => $slug,
			'notebook_id' => $notebook_id,
			'title'       => $title,
			'body'        => $parsed['body'],
			'source'      => $parsed['source'],
			'pinned'      => $parsed['pinned'] ? 1 : 0,
			'sha'         => $file['sha'],
			'created_at'  => $parsed['created'] ? self::normalize_datetime( $parsed['created'] ) : $now,
			'modified_at' => $parsed['modified'] ? self::normalize_datetime( $parsed['modified'] ) : $now,
			'gh_path'     => $path,
		];

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, slug, title, body, pinned, sha, gh_path, modified_at FROM {$t}
			 WHERE notebook_id = %d AND deleted_at IS NULL AND ( gh_path = %s OR sha = %s OR slug = %s )",
			$notebook_id, $path, $file['sha'], $slug
		), ARRAY_A );
		$want     = [ 'gh_path' => $path, 'sha' => $file['sha'], 'slug' => $slug ];
		$existing = null;
		foreach ( $want as $col => $val ) {
			foreach ( $rows ?: [] as $r ) {
				// A note that already owns a different file is never this file's note.
				if ( (string) $r[ $col ] === $val && ( $col === 'gh_path' || (string) $r['gh_path'] === '' ) ) {
					$existing = $r;
					break 2;
				}
			}
		}

		if ( ! $existing ) {
			$wpdb->insert( $t, $data );
			return 'inserted';
		}

		$same = (string) $existing['title'] === $title
			&& trim( (string) $existing['body'] ) === $parsed['body']
			&& (int) $existing['pinned'] === $data['pinned'];
		if ( $same ) {
			$set = [ 'sha' => $file['sha'] ];
			if ( (string) $existing['gh_path'] === '' ) $set['gh_path'] = $path;
			$wpdb->update( $t, $set, [ 'id' => (int) $existing['id'] ] );
			return 'same';
		}

		// Minute precision on both sides: the frontmatter only carries minutes.
		if ( strcmp( substr( $data['modified_at'], 0, 16 ), substr( (string) $existing['modified_at'], 0, 16 ) ) > 0 ) {
			// Snapshot the current content before the sync overwrites it, so the
			// pre-sync version stays recoverable from History.
			Noodled_Notes::save_revision( (int) $existing['id'] );
			$wpdb->update( $t, $data, [ 'id' => (int) $existing['id'] ] );
			return 'updated';
		}

		self::queue_push( (int) $existing['id'] );
		return 'kept';
	}

	/**
	 * Soft-delete a note that was removed from GitHub.
	 */
	private static function remove_file_from_db( string $path ): void {
		global $wpdb;

		$parts         = explode( '/', $path, 2 );
		$notebook_name = $parts[0];
		$filename      = pathinfo( $parts[1], PATHINFO_FILENAME );

		$owner_id = self::sync_owner_id();
		$nb = $owner_id ? Noodled_Notebooks::get_by_name( $notebook_name, $owner_id ) : null;
		if ( ! $nb ) return;

		$trash = [
			'deleted_at'   => current_time( 'mysql', true ),
			'deleted_from' => $nb['id'],
		];

		// The note that owns this exact file, if one is recorded.
		$owner_note = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_notes WHERE gh_path = %s AND notebook_id = %d AND deleted_at IS NULL LIMIT 1",
			$path, $nb['id']
		) );
		if ( $owner_note ) {
			$wpdb->update( "{$wpdb->prefix}noodled_notes", $trash, [ 'id' => $owner_note ] );
			return;
		}

		// Legacy rule: match the slug, but never a note that owns a different file.
		$slug = Noodled_Notes::slug( $filename );
		$wpdb->update( "{$wpdb->prefix}noodled_notes", $trash, [
			'slug'        => $slug,
			'notebook_id' => $nb['id'],
			'gh_path'     => '',
		] );
	}

	/**
	 * Normalize a datetime string to MySQL format.
	 */
	private static function normalize_datetime( string $dt ): string {
		// Handle "YYYY-MM-DD HH:MM" format (no seconds)
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dt ) ) {
			return $dt . ':00';
		}
		// Already full datetime
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dt ) ) {
			return $dt;
		}
		return current_time( 'mysql', true );
	}
}
