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

	public static function save( int $note_id, string $filename, string $data_b64 ): array {
		global $wpdb;

		$filename = sanitize_file_name( $filename );
		$dir = self::upload_dir() . '/' . $note_id;
		wp_mkdir_p( $dir );

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
