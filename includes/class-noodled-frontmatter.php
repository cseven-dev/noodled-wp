<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Converts between DB rows and noodled markdown format (matching app.py's _write_note/_parse_note).
 */
class Noodled_Frontmatter {

	/**
	 * Convert a DB note row to markdown with frontmatter.
	 */
	public static function to_markdown( array $note ): string {
		$lines = [ '---' ];

		$meta = [
			'title'    => $note['title'] ?? '',
			'created'  => $note['created'] ?? $note['created_at'] ?? '',
			'modified' => $note['modified'] ?? $note['modified_at'] ?? '',
		];

		if ( ! empty( $note['source'] ) )  $meta['source'] = $note['source'];
		if ( ! empty( $note['pinned'] ) )   $meta['pinned'] = 'true';

		foreach ( $meta as $k => $v ) {
			if ( $v !== '' && $v !== null ) {
				// Truncate datetime to YYYY-MM-DD HH:MM
				if ( in_array( $k, [ 'created', 'modified' ], true ) && strlen( $v ) > 16 ) {
					$v = substr( $v, 0, 16 );
				}
				$lines[] = "{$k}: {$v}";
			}
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = $note['body'] ?? '';

		return implode( "\n", $lines );
	}

	/**
	 * Parse noodled markdown frontmatter into components.
	 * Mirrors app.py's _parse_note().
	 */
	public static function from_markdown( string $raw ): array {
		$meta = [];
		$body = $raw;

		if ( strpos( $raw, "---\n" ) === 0 ) {
			$end = strpos( $raw, "\n---\n", 4 );
			if ( $end !== false ) {
				$header = substr( $raw, 4, $end - 4 );
				$body   = substr( $raw, $end + 5 );

				foreach ( explode( "\n", $header ) as $line ) {
					if ( strpos( $line, ': ' ) !== false ) {
						list( $k, $v ) = explode( ': ', $line, 2 );
						$meta[ trim( $k ) ] = trim( $v );
					}
				}
			}
		}

		return [
			'title'    => $meta['title'] ?? '',
			'body'     => trim( $body ),
			'created'  => $meta['created'] ?? '',
			'modified' => $meta['modified'] ?? '',
			'source'   => $meta['source'] ?? '',
			'pinned'   => ( $meta['pinned'] ?? '' ) === 'true',
			'meta'     => $meta,
		];
	}

	/**
	 * Derive a filesystem-safe slug from a title (matching app.py's _safe_filename).
	 */
	public static function safe_filename( string $name ): string {
		$name = preg_replace( '/[<>:"\/\\\\|?*]/', '-', $name );
		$name = preg_replace( '/-+/', '-', $name );
		$name = trim( $name, '. ' );
		return substr( $name, 0, 200 );
	}
}
