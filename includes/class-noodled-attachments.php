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

	// Allowlist — anything not here is rejected (safer than a blocklist that can
	// miss executable-capable extensions like .pht, .phtml variants, or .htaccess).
	private static $allowed_extensions = [
		// images
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'avif', 'svg', 'tif', 'tiff', 'ico',
		// documents
		'pdf', 'txt', 'md', 'markdown', 'rtf', 'csv', 'tsv', 'log', 'json', 'xml',
		'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp',
		'pages', 'numbers', 'key', 'epub',
		// web (served sandboxed via the proxy)
		'html', 'htm',
		// archives
		'zip',
		// audio / video
		'mp3', 'm4a', 'wav', 'ogg', 'oga', 'aac', 'flac', 'mp4', 'm4v', 'mov', 'webm', 'mkv',
	];
	private static $image_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'avif', 'tif', 'tiff', 'ico' ];
	private static $max_upload_bytes = 10485760; // 10 MB

	public static function save( int $note_id, string $filename, string $data_b64 ): array {
		global $wpdb;

		$filename = sanitize_file_name( $filename );
		// Never allow dot-files / server-config names (e.g. .htaccess). sanitize_file_name
		// keeps a leading dot, so strip it before validating.
		$filename = ltrim( $filename, '.' );
		if ( $filename === '' ) return [ 'error' => __( 'Invalid filename', 'noodled' ) ];

		// Allowlist the extension — reject scripts, configs and anything unknown.
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( $ext === '' || ! in_array( $ext, self::$allowed_extensions, true ) ) {
			/* translators: %s is the rejected file extension */
			return [ 'error' => sprintf( __( 'File type not allowed: %s', 'noodled' ), '.' . $ext ) ];
		}

		// Size limit (check base64 length before decoding — base64 is ~33% larger)
		if ( strlen( $data_b64 ) > self::$max_upload_bytes * 1.34 ) {
			return [ 'error' => __( 'File too large (max 10 MB)', 'noodled' ) ];
		}

		$data = base64_decode( $data_b64 );
		if ( $data === false ) return [ 'error' => __( 'Invalid base64 data', 'noodled' ) ];
		if ( strlen( $data ) > self::$max_upload_bytes ) return [ 'error' => __( 'File too large (max 10 MB)', 'noodled' ) ];

		// Content must match the claimed type for images — blocks a script disguised
		// as a .png that an Apache handler override could otherwise execute.
		$is_image = in_array( $ext, self::$image_extensions, true );
		if ( $is_image ) {
			$info = @getimagesizefromstring( $data );
			if ( $info === false ) return [ 'error' => __( 'That file is not a valid image.', 'noodled' ) ];
		}

		// Determine a TRUSTWORTHY mime from the bytes (not the attacker-chosen name).
		$mime = '';
		if ( $is_image && ! empty( $info['mime'] ) ) {
			$mime = $info['mime'];
		} elseif ( function_exists( 'finfo_open' ) ) {
			$fi = finfo_open( FILEINFO_MIME_TYPE );
			if ( $fi ) { $mime = (string) finfo_buffer( $fi, $data ); finfo_close( $fi ); }
		}
		if ( ! $mime ) $mime = wp_check_filetype( $filename )['type'] ?: 'application/octet-stream';

		$dir = self::upload_dir() . '/' . $note_id;
		wp_mkdir_p( $dir );

		// Lock the whole noodled uploads tree to direct web access — files are
		// only ever served through the access-checked proxy (REST file/{id}).
		self::protect_dir();

		// Store under a random, unguessable subfolder so the on-disk path can't be
		// derived from the note id or filename even if the server ignores .htaccess.
		$token   = wp_generate_password( 12, false );
		$subdir  = $dir . '/' . $token;
		wp_mkdir_p( $subdir );
		$filepath = $subdir . '/' . $filename;

		file_put_contents( $filepath, $data );

		$relative = $note_id . '/' . $token . '/' . $filename;
		$exif = self::extract_exif( $filepath, $mime );

		$wpdb->insert( self::table(), [
			'note_id'    => $note_id,
			'filename'   => $filename,
			'file_path'  => $relative,
			'mime_type'  => $mime,
			'file_size'  => strlen( $data ),
			'exif'       => $exif,
			'created_at' => current_time( 'mysql', true ),
		] );

		$id = (int) $wpdb->insert_id;
		return [
			'id'       => $id,
			'filename' => $filename,
			'url'      => self::proxy_url( $id ),
			'mime'     => $mime,
			'exif'     => $exif ? json_decode( $exif, true ) : null,
		];
	}

	/** Lock the noodled uploads tree against direct web access (served only via proxy). */
	public static function protect_dir(): void {
		$htaccess = self::upload_dir() . '/.htaccess';
		$rules = "# Noodled: deny ALL direct web access — files are served only through the access-checked proxy.\n"
			. "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
		if ( ! file_exists( $htaccess ) || @file_get_contents( $htaccess ) !== $rules ) {
			@file_put_contents( $htaccess, $rules );
		}
	}

	/** Access-checked proxy URL the browser loads instead of a public file URL. */
	public static function proxy_url( int $id ): string {
		return rest_url( 'noodled/v1/file/' . $id );
	}

	/** Row + absolute disk path for the file proxy, or null. */
	public static function get_raw( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, note_id, filename, file_path, mime_type FROM " . self::table() . " WHERE id = %d", $id
		), ARRAY_A );
		if ( ! $r ) return null;
		$r['path'] = self::upload_dir() . '/' . $r['file_path'];
		return $r;
	}

	/** Pull a clean, JSON-safe subset of EXIF from an image, stored for later use. */
	private static function extract_exif( string $filepath, string $mime ): ?string {
		if ( ! function_exists( 'exif_read_data' ) ) return null;
		if ( stripos( $mime, 'jpeg' ) === false && stripos( $mime, 'tiff' ) === false ) return null;
		$raw = @exif_read_data( $filepath, null, true );
		if ( ! $raw ) return null;

		$ifd0 = $raw['IFD0'] ?? [];
		$exif = $raw['EXIF'] ?? [];
		$gps  = $raw['GPS'] ?? [];
		$map = [
			'Make'             => $ifd0['Make'] ?? null,
			'Model'            => $ifd0['Model'] ?? null,
			'Orientation'      => $ifd0['Orientation'] ?? null,
			'Software'         => $ifd0['Software'] ?? null,
			'DateTimeOriginal' => $exif['DateTimeOriginal'] ?? ( $ifd0['DateTime'] ?? null ),
			'ExposureTime'     => $exif['ExposureTime'] ?? null,
			'FNumber'          => $exif['FNumber'] ?? null,
			'ISO'              => $exif['ISOSpeedRatings'] ?? null,
			'FocalLength'      => $exif['FocalLength'] ?? null,
			'PixelWidth'       => $exif['ExifImageWidth'] ?? null,
			'PixelHeight'      => $exif['ExifImageLength'] ?? null,
		];
		$pick = [];
		foreach ( $map as $k => $v ) {
			if ( $v === null || $v === '' ) continue;
			$pick[ $k ] = is_string( $v ) ? trim( $v ) : $v;
		}
		if ( isset( $gps['GPSLatitude'], $gps['GPSLongitude'] ) ) {
			$lat = self::gps_decimal( $gps['GPSLatitude'], $gps['GPSLatitudeRef'] ?? 'N' );
			$lng = self::gps_decimal( $gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E' );
			if ( $lat !== null && $lng !== null ) { $pick['GPSLatitude'] = $lat; $pick['GPSLongitude'] = $lng; }
		}
		return $pick ? wp_json_encode( $pick ) : null;
	}

	private static function gps_decimal( $coord, string $ref ): ?float {
		if ( ! is_array( $coord ) || count( $coord ) < 3 ) return null;
		$d = self::frac( $coord[0] ); $m = self::frac( $coord[1] ); $s = self::frac( $coord[2] );
		if ( $d === null ) return null;
		$dec = $d + ( $m ?? 0 ) / 60 + ( $s ?? 0 ) / 3600;
		if ( in_array( strtoupper( $ref ), [ 'S', 'W' ], true ) ) $dec = -$dec;
		return round( $dec, 6 );
	}

	private static function frac( $v ): ?float {
		if ( is_numeric( $v ) ) return (float) $v;
		if ( is_string( $v ) && strpos( $v, '/' ) !== false ) {
			[ $n, $d ] = explode( '/', $v, 2 );
			return (float) $d ? (float) $n / (float) $d : 0.0;
		}
		return null;
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

	/** Delete every attachment (files + rows) belonging to a note. Used on hard-delete. */
	public static function delete_for_note( int $note_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM " . self::table() . " WHERE note_id = %d", $note_id
		) );
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
		// Best-effort removal of the now-empty per-note folder.
		$dir = self::upload_dir() . '/' . $note_id;
		if ( is_dir( $dir ) ) {
			foreach ( glob( $dir . '/*' ) ?: [] as $sub ) {
				if ( is_dir( $sub ) && ! ( glob( $sub . '/*' ) ?: [] ) ) @rmdir( $sub );
			}
			if ( ! ( glob( $dir . '/*' ) ?: [] ) ) @rmdir( $dir );
		}
	}

	public static function get_for_note( int $note_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE note_id = %d ORDER BY created_at ASC",
			$note_id
		), ARRAY_A );

		return array_map( function ( $r ) {
			return [
				'id'       => (int) $r['id'],
				'filename' => $r['filename'],
				'url'      => self::proxy_url( (int) $r['id'] ),
				'mime'     => $r['mime_type'],
				'size'     => (int) $r['file_size'],
				'exif'     => ! empty( $r['exif'] ) ? json_decode( $r['exif'], true ) : null,
			];
		}, $rows ?: [] );
	}
}
