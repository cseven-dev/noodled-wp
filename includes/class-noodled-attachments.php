<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Attachments {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_attachments';
	}

	private static function upload_dir(): string {
		$upload = wp_upload_dir();
		$dir = $upload['basedir'] . '/noodled';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	private static function upload_url(): string {
		$upload = wp_upload_dir();
		return $upload['baseurl'] . '/noodled';
	}

	private static $blocked_extensions = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'jsp', 'asp', 'aspx' ];
	private static $max_upload_bytes = 10485760; // 10 MB

	public static function save( int $note_id, string $filename, string $data_b64 ): array {
		global $wpdb;

		$filename = sanitize_file_name( $filename );

		// Block dangerous file types
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::$blocked_extensions, true ) ) {
			return [ 'error' => 'File type not allowed: .' . $ext ];
		}

		// Size limit (check base64 length before decoding — base64 is ~33% larger)
		if ( strlen( $data_b64 ) > self::$max_upload_bytes * 1.34 ) {
			return [ 'error' => 'File too large (max 10 MB)' ];
		}

		$dir = self::upload_dir() . '/' . $note_id;
		wp_mkdir_p( $dir );

		// Write .htaccess to prevent PHP execution in uploads dir
		$htaccess = self::upload_dir() . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "# Prevent script execution\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl|py|sh|jsp|asp|aspx)$\">\n  Deny from all\n</FilesMatch>\nAddHandler default-handler .php .phtml .php3 .php4 .php5\n" );
		}

		$filepath = $dir . '/' . $filename;
		$data = base64_decode( $data_b64 );
		if ( $data === false ) return [ 'error' => 'Invalid base64 data' ];

		file_put_contents( $filepath, $data );

		$relative = $note_id . '/' . $filename;
		$mime = wp_check_filetype( $filename )['type'] ?: '';

		$wpdb->insert( self::table(), [
			'note_id'    => $note_id,
			'filename'   => $filename,
			'file_path'  => $relative,
			'mime_type'  => $mime,
			'file_size'  => strlen( $data ),
			'created_at' => current_time( 'mysql', true ),
		] );

		return [
			'id'       => (int) $wpdb->insert_id,
			'filename' => $filename,
			'url'      => self::upload_url() . '/' . $relative,
		];
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d", $id
		), ARRAY_A );

		if ( $row ) {
			$filepath = self::upload_dir() . '/' . $row['file_path'];
			if ( file_exists( $filepath ) ) {
				unlink( $filepath );
			}
			$wpdb->delete( self::table(), [ 'id' => $id ] );
		}

		return true;
	}

	public static function get_for_note( int $note_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE note_id = %d ORDER BY created_at ASC",
			$note_id
		), ARRAY_A );

		$base_url = self::upload_url();
		return array_map( function ( $r ) use ( $base_url ) {
			return [
				'id'       => (int) $r['id'],
				'filename' => $r['filename'],
				'url'      => $base_url . '/' . $r['file_path'],
				'mime'     => $r['mime_type'],
				'size'     => (int) $r['file_size'],
			];
		}, $rows ?: [] );
	}
}
