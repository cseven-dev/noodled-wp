<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Evernote {

	/**
	 * Import notes from an .enex file.
	 */
	public static function import( string $file_path, int $owner_id = 0 ): array {
		if ( ! file_exists( $file_path ) ) {
			return [ 'error' => __( 'File not found', 'noodled' ) ];
		}

		$xml = @simplexml_load_file( $file_path );
		if ( ! $xml ) {
			return [ 'error' => __( 'Invalid .enex file', 'noodled' ) ];
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $xml->note as $note ) {
			try {
				$result = self::import_note( $note, $owner_id );
				if ( $result ) $imported++;
				else $skipped++;
			} catch ( \Throwable $e ) {
				$errors++;
			}
		}

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		];
	}

	private static function import_note( \SimpleXMLElement $note, int $owner_id ): bool {
		global $wpdb;

		$title   = (string) $note->title ?: 'Untitled';
		$content = (string) $note->content ?: '';
		$created = (string) $note->created ?: '';
		$updated = (string) $note->updated ?: '';

		// Parse Evernote dates (format: 20240101T120000Z)
		$created_at = self::parse_date( $created );
		$updated_at = self::parse_date( $updated ) ?: $created_at;

		// Get notebook name from tag or default
		$notebook = 'Evernote Import';
		if ( isset( $note->tag ) ) {
			// Use first tag as notebook name
			$notebook = (string) $note->tag;
		}

		// Check for duplicate by title in the same notebook
		$nb_id = Noodled_Notebooks::ensure( $notebook, $owner_id );
		if ( ! $nb_id ) return false;

		$slug = self::make_slug( $title );
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}noodled_notes WHERE slug = %s AND notebook_id = %d AND deleted_at IS NULL",
			$slug, $nb_id
		) );
		if ( $existing ) return false; // skip duplicate

		// Convert Evernote HTML to markdown
		$body = self::html_to_markdown( $content );

		// Create the note
		$now = current_time( 'mysql', true );
		$wpdb->insert( $wpdb->prefix . 'noodled_notes', [
			'slug'        => $slug,
			'notebook_id' => $nb_id,
			'title'       => sanitize_text_field( $title ),
			'body'        => $body,
			'source'      => 'evernote',
			'created_at'  => $created_at ?: $now,
			'modified_at' => $updated_at ?: $now,
		] );

		$note_id = (int) $wpdb->insert_id;
		if ( ! $note_id ) return false;

		// Import attachments (resources)
		if ( isset( $note->resource ) ) {
			foreach ( $note->resource as $resource ) {
				self::import_resource( $resource, $note_id );
			}
		}

		return true;
	}

	private static function import_resource( \SimpleXMLElement $resource, int $note_id ): void {
		$data = (string) ( $resource->data ?? '' );
		if ( ! $data ) return;

		$mime     = (string) ( $resource->mime ?? '' );
		$filename = (string) ( $resource->{'resource-attributes'}->{'file-name'} ?? '' );

		if ( ! $filename ) {
			// Generate filename from mime type
			$ext = 'bin';
			$ext_map = [
				'image/png'  => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
				'image/webp' => 'webp', 'application/pdf' => 'pdf', 'audio/wav' => 'wav',
				'audio/mpeg' => 'mp3', 'audio/amr' => 'amr',
			];
			if ( isset( $ext_map[ $mime ] ) ) $ext = $ext_map[ $mime ];
			$filename = 'attachment-' . substr( md5( $data ), 0, 8 ) . '.' . $ext;
		}

		// The data in .enex is base64 encoded (possibly with whitespace)
		$clean_data = preg_replace( '/\s+/', '', $data );

		Noodled_Attachments::save( $note_id, sanitize_file_name( $filename ), $clean_data );
	}

	/**
	 * Convert Evernote HTML (ENML) to markdown.
	 */
	private static function html_to_markdown( string $html ): string {
		// Strip the XML declaration and en-note wrapper
		$html = preg_replace( '/<\?xml[^>]*\?>/', '', $html );
		$html = preg_replace( '/<\/?en-note[^>]*>/', '', $html );

		// Strip CDATA
		$html = str_replace( [ '<![CDATA[', ']]>' ], '', $html );

		// Convert common HTML to markdown
		$md = $html;

		// Headings
		$md = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/si', "# $1\n", $md );
		$md = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/si', "## $1\n", $md );
		$md = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/si', "### $1\n", $md );
		$md = preg_replace( '/<h[4-6][^>]*>(.*?)<\/h[4-6]>/si', "### $1\n", $md );

		// Bold & italic
		$md = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/si', '**$2**', $md );
		$md = preg_replace( '/<(em|i)[^>]*>(.*?)<\/(em|i)>/si', '*$2*', $md );

		// Links
		$md = preg_replace( '/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/si', '[$2]($1)', $md );

		// Images
		$md = preg_replace( '/<img[^>]*src="([^"]*)"[^>]*\/?>/si', '![]($1)', $md );

		// Lists
		$md = preg_replace( '/<li[^>]*>(.*?)<\/li>/si', "- $1\n", $md );
		$md = preg_replace( '/<\/?[ou]l[^>]*>/si', "\n", $md );

		// Evernote checkboxes
		$md = preg_replace( '/<en-todo\s+checked="true"\s*\/?>/si', '- [x] ', $md );
		$md = preg_replace( '/<en-todo[^>]*\/?>/si', '- [ ] ', $md );

		// Code blocks
		$md = preg_replace( '/<pre[^>]*>(.*?)<\/pre>/si', "```\n$1\n```\n", $md );
		$md = preg_replace( '/<code[^>]*>(.*?)<\/code>/si', '`$1`', $md );

		// Blockquotes
		$md = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/si', "> $1\n", $md );

		// Horizontal rules
		$md = preg_replace( '/<hr[^>]*\/?>/si', "\n---\n", $md );

		// Line breaks and paragraphs
		$md = preg_replace( '/<br[^>]*\/?>/si', "\n", $md );
		$md = preg_replace( '/<\/p>/si', "\n\n", $md );
		$md = preg_replace( '/<p[^>]*>/si', '', $md );
		$md = preg_replace( '/<\/div>/si', "\n", $md );
		$md = preg_replace( '/<div[^>]*>/si', '', $md );

		// Tables
		$md = preg_replace_callback( '/<table[^>]*>(.*?)<\/table>/si', function( $m ) {
			$rows = [];
			preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/si', $m[1], $trs );
			foreach ( $trs[1] as $i => $tr ) {
				preg_match_all( '/<t[dh][^>]*>(.*?)<\/t[dh]>/si', $tr, $cells );
				$row = array_map( 'strip_tags', $cells[1] );
				$rows[] = '| ' . implode( ' | ', $row ) . ' |';
				if ( $i === 0 ) {
					$rows[] = '| ' . implode( ' | ', array_fill( 0, count( $row ), '---' ) ) . ' |';
				}
			}
			return implode( "\n", $rows ) . "\n";
		}, $md );

		// Strip remaining HTML tags
		$md = strip_tags( $md );

		// Decode HTML entities
		$md = html_entity_decode( $md, ENT_QUOTES, 'UTF-8' );

		// Clean up whitespace
		$md = preg_replace( '/\n{3,}/', "\n\n", $md );
		$md = trim( $md );

		return $md;
	}

	private static function parse_date( string $evernote_date ): string {
		if ( ! $evernote_date ) return '';
		// Format: 20240101T120000Z
		$d = \DateTime::createFromFormat( 'Ymd\THis\Z', $evernote_date, new \DateTimeZone( 'UTC' ) );
		if ( ! $d ) return '';
		return $d->format( 'Y-m-d H:i:s' );
	}

	private static function make_slug( string $title ): string {
		$slug = sanitize_title( $title );
		return substr( $slug, 0, 200 );
	}
}
