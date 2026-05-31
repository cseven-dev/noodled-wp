<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GitHub API client for reading and writing to the noodled-notes repo.
 */
class Noodled_GitHub {

	private static function config(): array {
		return Noodled_Settings::get();
	}

	/**
	 * Make a GitHub API request.
	 */
	private static function api( string $method, string $endpoint, ?array $body = null ) {
		$cfg   = self::config();
		$owner = $cfg['github_owner'] ?? '';
		$repo  = $cfg['github_repo'] ?? 'noodled-notes';
		$token = $cfg['github_token'] ?? '';

		if ( empty( $owner ) ) {
			return new \WP_Error( 'noodled', 'GitHub owner not configured.' );
		}

		$url  = "https://api.github.com/repos/{$owner}/{$repo}/{$endpoint}";
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'noodled-wp/1.0',
			],
		];

		if ( $token ) {
			// Support both classic (ghp_) and fine-grained tokens
			$prefix = str_starts_with( $token, 'ghp_' ) || str_starts_with( $token, 'github_pat_' ) ? 'token' : 'Bearer';
			$args['headers']['Authorization'] = "{$prefix} {$token}";
		}

		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			error_log( '[noodled] GitHub API WP_Error: ' . $response->get_error_message() . ' URL: ' . $url );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data = json_decode( $body_raw, true );

		if ( $code >= 400 ) {
			$msg = $data['message'] ?? "HTTP {$code}";
			error_log( "[noodled] GitHub API error {$code}: {$msg} URL: {$url}" );
			return new \WP_Error( 'github_api', $msg . " (URL: {$url})", [ 'status' => $code ] );
		}

		return $data;
	}

	// ── Read operations ──

	/**
	 * Get the full repo tree (recursive).
	 */
	public static function get_tree() {
		$cfg    = self::config();
		$branch = $cfg['github_branch'] ?? 'main';
		$result = self::api( 'GET', "git/trees/{$branch}?recursive=1" );

		if ( is_wp_error( $result ) ) return $result;
		return $result['tree'] ?? [];
	}

	/**
	 * Get file content from repo.
	 */
	public static function get_file( string $path ) {
		$result = self::api( 'GET', 'contents/' . rawurlencode_path( $path ) );
		if ( is_wp_error( $result ) ) return $result;

		return [
			'content' => base64_decode( $result['content'] ?? '' ),
			'sha'     => $result['sha'] ?? '',
			'path'    => $result['path'] ?? $path,
		];
	}

	/**
	 * List files in a directory.
	 */
	public static function get_contents( string $path = '' ) {
		$endpoint = $path ? 'contents/' . rawurlencode_path( $path ) : 'contents';
		return self::api( 'GET', $endpoint );
	}

	// ── Write operations ──

	/**
	 * Create or update a file in the repo.
	 */
	public static function put_file( string $path, string $content, string $message, string $sha = '' ) {
		$body = [
			'message' => $message,
			'content' => base64_encode( $content ),
		];

		if ( $sha ) {
			$body['sha'] = $sha;
		}

		$cfg = self::config();
		$branch = $cfg['github_branch'] ?? 'main';
		$body['branch'] = $branch;

		return self::api( 'PUT', 'contents/' . rawurlencode_path( $path ), $body );
	}

	/**
	 * Delete a file from the repo.
	 */
	public static function delete_file( string $path, string $sha, string $message ) {
		$cfg = self::config();
		$branch = $cfg['github_branch'] ?? 'main';

		return self::api( 'DELETE', 'contents/' . rawurlencode_path( $path ), [
			'message' => $message,
			'sha'     => $sha,
			'branch'  => $branch,
		] );
	}

	/**
	 * Check if the repo is configured and accessible.
	 */
	public static function is_configured(): bool {
		$cfg = self::config();
		return ! empty( $cfg['github_owner'] ) && ! empty( $cfg['github_token'] );
	}
}

/**
 * URL-encode a file path preserving slashes.
 */
function rawurlencode_path( string $path ): string {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
}
