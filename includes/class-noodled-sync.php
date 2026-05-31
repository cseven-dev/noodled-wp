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

	/**
	 * Push a single note to GitHub after save.
	 */
	public static function push_note( int $note_id ): array {
		if ( ! Noodled_GitHub::is_configured() ) {
			return [ 'error' => 'GitHub not configured' ];
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}noodled_notes WHERE id = %d", $note_id
		), ARRAY_A );

		if ( ! $row ) return [ 'error' => 'Note not found' ];

		$nb = Noodled_Notebooks::get_by_id( (int) $row['notebook_id'] );
		if ( ! $nb ) return [ 'error' => 'Notebook not found' ];

		$note_data = [
			'title'    => $row['title'],
			'body'     => $row['body'] ?? '',
			'source'   => $row['source'] ?? '',
			'pinned'   => (bool) $row['pinned'],
			'created'  => $row['created_at'],
			'modified' => $row['modified_at'],
		];

		$markdown = Noodled_Frontmatter::to_markdown( $note_data );
		$filename = Noodled_Frontmatter::safe_filename( $row['title'] ) ?: $row['slug'];
		$path     = $nb['name'] . '/' . $filename . '.md';
		$message  = "noodled sync: update {$row['title']}";

		$result = Noodled_GitHub::put_file( $path, $markdown, $message, $row['sha'] ?: '' );

		if ( is_wp_error( $result ) ) {
			return [ 'error' => $result->get_error_message() ];
		}

		// Store new SHA
		$new_sha = $result['content']['sha'] ?? '';
		if ( $new_sha ) {
			$wpdb->update( "{$wpdb->prefix}noodled_notes", [ 'sha' => $new_sha ], [ 'id' => $note_id ] );
		}

		return [ 'success' => true, 'sha' => $new_sha ];
	}

	/**
	 * Delete a note from GitHub.
	 */
	public static function delete_from_github( string $notebook_name, string $title, string $sha ): array {
		if ( ! Noodled_GitHub::is_configured() || ! $sha ) {
			return [ 'error' => 'Not configured or no SHA' ];
		}

		$filename = Noodled_Frontmatter::safe_filename( $title ) ?: sanitize_title( $title );
		$path     = $notebook_name . '/' . $filename . '.md';
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
			return [ 'error' => 'GitHub not configured' ];
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
			$existing = Noodled_Notebooks::get_by_name( $dir );
			if ( ! $existing ) {
				Noodled_Notebooks::create( $dir );
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

			$notebook_name = $parts[0];
			$filename      = pathinfo( $parts[1], PATHINFO_FILENAME );

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

			$parsed = Noodled_Frontmatter::from_markdown( $file['content'] );
			$title  = $parsed['title'] ?: $filename;

			$notebook_id = Noodled_Notebooks::ensure( $notebook_name );

			// Check if note exists by slug+notebook (update it)
			$slug = Noodled_Notes::slug( $title );
			$existing_note = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}noodled_notes WHERE slug = %s AND notebook_id = %d AND deleted_at IS NULL",
				$slug, $notebook_id
			), ARRAY_A );

			$now = current_time( 'mysql', true );
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
			];

			if ( $existing_note ) {
				$wpdb->update( "{$wpdb->prefix}noodled_notes", $data, [ 'id' => $existing_note['id'] ] );
			} else {
				$wpdb->insert( "{$wpdb->prefix}noodled_notes", $data );
			}

			$notes_imported++;
		}

		return [
			'notebooks' => $notebooks_created,
			'notes'     => $notes_imported,
			'skipped'   => $notes_skipped,
		];
	}

	// ── Webhook: GitHub push event → WP ──

	public static function handle_webhook( \WP_REST_Request $req ): \WP_REST_Response {
		// Verify signature
		$cfg    = Noodled_Settings::get();
		$secret = $cfg['webhook_secret'] ?? '';

		if ( $secret ) {
			$sig_header = $req->get_header( 'X-Hub-Signature-256' );
			$payload    = $req->get_body();
			$expected   = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

			if ( ! hash_equals( $expected, $sig_header ?? '' ) ) {
				return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 403 );
			}
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
		global $wpdb;

		$file = Noodled_GitHub::get_file( $path );
		if ( is_wp_error( $file ) ) return false;

		$parts         = explode( '/', $path, 2 );
		$notebook_name = $parts[0];
		$filename      = pathinfo( $parts[1], PATHINFO_FILENAME );

		$parsed      = Noodled_Frontmatter::from_markdown( $file['content'] );
		$title       = $parsed['title'] ?: $filename;
		$notebook_id = Noodled_Notebooks::ensure( $notebook_name );
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
		];

		// Find existing by SHA or slug+notebook
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, sha, modified_at FROM {$wpdb->prefix}noodled_notes
			 WHERE (sha = %s OR (slug = %s AND notebook_id = %d)) AND deleted_at IS NULL
			 LIMIT 1",
			$file['sha'], $slug, $notebook_id
		), ARRAY_A );

		if ( $existing ) {
			// Last-write-wins: only update if GitHub version is newer
			$gh_modified = $data['modified_at'];
			$db_modified = $existing['modified_at'];
			if ( $gh_modified >= $db_modified || $existing['sha'] !== $file['sha'] ) {
				$wpdb->update( "{$wpdb->prefix}noodled_notes", $data, [ 'id' => $existing['id'] ] );
			}
		} else {
			$wpdb->insert( "{$wpdb->prefix}noodled_notes", $data );
		}

		return true;
	}

	/**
	 * Soft-delete a note that was removed from GitHub.
	 */
	private static function remove_file_from_db( string $path ): void {
		global $wpdb;

		$parts         = explode( '/', $path, 2 );
		$notebook_name = $parts[0];
		$filename      = pathinfo( $parts[1], PATHINFO_FILENAME );

		$nb = Noodled_Notebooks::get_by_name( $notebook_name );
		if ( ! $nb ) return;

		$slug = Noodled_Notes::slug( $filename );
		$wpdb->update( "{$wpdb->prefix}noodled_notes", [
			'deleted_at'   => current_time( 'mysql', true ),
			'deleted_from' => $nb['id'],
		], [
			'slug'        => $slug,
			'notebook_id' => $nb['id'],
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
