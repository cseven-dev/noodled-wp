<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Plaud {

	private static $base_url = 'https://api.plaud.ai';
	private static $notebook_name = 'Plaud Transcripts';

	/**
	 * Check if Plaud is configured (token exists).
	 */
	public static function is_configured(): bool {
		$token = self::get_token();
		return ! empty( $token );
	}

	/**
	 * Get Plaud token from environment or .env file in the plugin directory.
	 */
	private static function get_token(): string {
		// Check environment variable first
		$token = getenv( 'PLAUD_TOKEN' );
		if ( $token ) return $token;

		// Check .env file in plugin directory
		$env_file = NOODLED_PATH . '.env';
		if ( file_exists( $env_file ) ) {
			foreach ( file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
				if ( strpos( $line, 'PLAUD_TOKEN=' ) === 0 ) {
					return trim( trim( substr( $line, 12 ) ), "\"'" );
				}
			}
		}

		return '';
	}

	/**
	 * Sync recordings from Plaud into the database as notes.
	 */
	public static function sync( int $owner_id = 0 ): array {
		$token = self::get_token();
		if ( empty( $token ) ) {
			return [ 'error' => __( 'No Plaud token configured. Add it in Settings → Noodled.', 'noodled' ) ];
		}

		// List recordings
		$recordings = self::list_recordings( $token );
		if ( isset( $recordings['error'] ) ) {
			return $recordings;
		}

		// Ensure notebook exists
		$notebook_id = Noodled_Notebooks::ensure( self::$notebook_name, $owner_id );
		if ( ! $notebook_id ) {
			return [ 'error' => __( 'Could not create Plaud Transcripts notebook', 'noodled' ) ];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'noodled_notes';

		$downloaded = 0;
		$total      = count( $recordings );

		foreach ( $recordings as $rec ) {
			// Skip recordings without transcription
			if ( empty( $rec['is_trans'] ) ) {
				continue;
			}

			// Build a date prefix + filename for matching
			$created_ts = ! empty( $rec['start_time'] ) ? (int) $rec['start_time'] / 1000 : 0;
			$date_str   = $created_ts ? gmdate( 'Y-m-d', $created_ts ) : 'undated';
			$title      = $date_str . ' - ' . ( $rec['filename'] ?? 'Recording' );
			$slug       = self::safe_slug( $title );

			// Check if already imported (by slug in this notebook)
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug = %s AND notebook_id = %d AND deleted_at IS NULL",
				$slug, $notebook_id
			) );
			if ( $exists ) {
				continue;
			}

			// Fetch full detail (transcript + summary)
			$detail = self::get_recording_detail( $token, $rec['id'] );
			if ( ! $detail ) {
				continue;
			}

			// Build note body from transcript segments
			$parts = [];
			if ( ! empty( $detail['trans_result'] ) && is_array( $detail['trans_result'] ) ) {
				foreach ( $detail['trans_result'] as $seg ) {
					$text = $seg['content'] ?? '';
					if ( ! empty( $text ) ) {
						$parts[] = $text;
					}
				}
			}

			// Append summary if available
			$summary = self::extract_summary( $detail );
			if ( $summary ) {
				$parts[] = '';
				$parts[] = '---';
				$parts[] = '';
				$parts[] = $summary;
			}

			$body = implode( "\n", $parts );
			$now  = current_time( 'mysql', true );
			$created_at = $created_ts ? gmdate( 'Y-m-d H:i:s', $created_ts ) : $now;

			$wpdb->insert( $table, [
				'slug'        => $slug,
				'notebook_id' => $notebook_id,
				'title'       => sanitize_text_field( $rec['filename'] ?? 'Recording' ),
				'body'        => $body,
				'source'      => 'plaud',
				'created_at'  => $created_at,
				'modified_at' => $now,
			] );

			if ( $wpdb->insert_id ) {
				$downloaded++;

				// Push to GitHub if configured
				if ( Noodled_GitHub::is_configured() ) {
					Noodled_Sync::push_note( (int) $wpdb->insert_id );
				}
			}
		}

		return [ 'downloaded' => $downloaded, 'total' => $total ];
	}

	/**
	 * List recordings from Plaud API.
	 */
	private static function list_recordings( string $token ): array {
		$url = self::$base_url . '/file/simple/web?' . http_build_query( [
			'limit'    => 100,
			'skip'     => 0,
			'is_trash' => 0,
			'sort_by'  => 'start_time',
			'is_desc'  => 1,
		] );

		$response = wp_remote_get( $url, [
			'headers' => self::headers( $token ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			/* translators: %s is the connection error detail */
			return [ 'error' => sprintf( __( 'Plaud connection failed: %s', 'noodled' ), $response->get_error_message() ) ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			/* translators: %d is the HTTP status code */
			return [ 'error' => sprintf( __( 'Plaud API returned HTTP %d', 'noodled' ), $code ) ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['data_file_list'] ?? [];
	}

	/**
	 * Get full recording detail (transcript + summary) via POST /file/list.
	 */
	private static function get_recording_detail( string $token, string $file_id ): ?array {
		$response = wp_remote_post( self::$base_url . '/file/list', [
			'headers' => self::headers( $token ),
			'body'    => wp_json_encode( [ $file_id ] ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$list = $body['data_file_list'] ?? [];
		return $list[0] ?? null;
	}

	/**
	 * Extract summary text from ai_content field (handles multiple formats).
	 */
	private static function extract_summary( array $detail ): ?string {
		// Try ai_content first
		$ai = $detail['ai_content'] ?? null;
		if ( $ai ) {
			if ( is_string( $ai ) ) {
				// Could be plain markdown or JSON string
				$decoded = json_decode( $ai, true );
				if ( $decoded ) {
					if ( ! empty( $decoded['markdown'] ) ) return $decoded['markdown'];
					if ( ! empty( $decoded['content']['markdown'] ) ) return $decoded['content']['markdown'];
					if ( ! empty( $decoded['summary'] ) ) return $decoded['summary'];
				}
				// Plain markdown
				return trim( $ai );
			}
			if ( is_array( $ai ) ) {
				if ( ! empty( $ai['markdown'] ) ) return $ai['markdown'];
				if ( ! empty( $ai['content']['markdown'] ) ) return $ai['content']['markdown'];
				if ( ! empty( $ai['summary'] ) ) return $ai['summary'];
			}
		}

		// Fallback: summary_list
		if ( ! empty( $detail['summary_list'] ) && is_array( $detail['summary_list'] ) ) {
			return $detail['summary_list'][0] ?? null;
		}

		return null;
	}

	/**
	 * Build request headers for the Plaud API.
	 */
	private static function headers( string $token ): array {
		return [
			'Authorization' => 'bearer ' . $token,
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'Mozilla/5.0',
			'Origin'        => 'https://web.plaud.ai',
			'Referer'       => 'https://web.plaud.ai/',
			'app-platform'  => 'web',
			'edit-from'     => 'web',
		];
	}

	/**
	 * Create a safe slug from a title (mirrors desktop _safe_filename).
	 */
	private static function safe_slug( string $name ): string {
		$name = preg_replace( '/[<>:"\/\\\\|?*]/', '-', $name );
		$name = preg_replace( '/-+/', '-', $name );
		$name = trim( $name, '. ' );
		return substr( $name, 0, 200 );
	}
}
