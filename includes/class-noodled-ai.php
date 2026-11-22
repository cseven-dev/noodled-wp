<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI assistant: bring-your-own-key, server-proxied. The provider API key lives in
 * wp_options and never reaches the browser; locked (encrypted) note bodies are
 * never sent to a provider. Supports Anthropic (Claude), OpenAI, and Google Gemini.
 */
class Noodled_AI {

	const PROVIDERS = [ 'anthropic', 'openai', 'google' ];

	/** Per-note summary context cap (chars) and ask corpus cap (chars). */
	const SUMMARIZE_CAP = 24000;
	const ASK_CAP       = 60000;

	public static function default_model( string $provider ): string {
		switch ( $provider ) {
			case 'openai': return 'gpt-6-luna';
			case 'google': return 'gemini-3.6-flash';
			case 'anthropic':
			default:       return 'claude-sonnet-5-5';
		}
	}

	public static function provider(): string {
		$p = (string) Noodled_Settings::get( 'ai_provider' );
		return in_array( $p, self::PROVIDERS, true ) ? $p : 'anthropic';
	}

	public static function api_key(): string {
		return trim( (string) Noodled_Settings::get( 'ai_key' ) );
	}

	public static function model(): string {
		$m = trim( (string) Noodled_Settings::get( 'ai_model' ) );
		return $m !== '' ? $m : self::default_model( self::provider() );
	}

	public static function is_configured(): bool {
		return self::api_key() !== '';
	}

	private static function is_locked( string $body ): bool {
		return str_starts_with( ltrim( $body ), 'noodled:enc:v1:' );
	}

	private static function clip( string $s, int $max ): string {
		return strlen( $s ) > $max ? substr( $s, 0, $max ) : $s;
	}

	// ── Operations ──

	public static function summarize( array $note ): array {
		$body = (string) ( $note['body'] ?? '' );
		if ( self::is_locked( $body ) ) {
			return [ 'error' => __( 'Locked notes are encrypted and cannot be summarized.', 'noodled' ) ];
		}
		$body = trim( $body );
		if ( $body === '' ) return [ 'error' => __( 'This note is empty.', 'noodled' ) ];

		$title  = (string) ( $note['title'] ?? '' );
		$system = 'You are a concise assistant summarizing a single personal note. '
			. 'Reply with a 2-4 sentence summary, then up to 5 key bullet points. '
			. 'Use plain Markdown. Only use facts present in the note; never invent details.';
		$user = "Title: {$title}\n\nNote:\n" . self::clip( $body, self::SUMMARIZE_CAP );
		return self::call( $system, $user );
	}

	/**
	 * Answer a question across the user's notes. $notes is a scoped corpus of
	 * [ id, title, body ] (already access-checked by the caller). Locked bodies
	 * are skipped here as a second line of defence.
	 */
	public static function ask( string $query, array $notes ): array {
		$query = trim( $query );
		if ( $query === '' ) return [ 'error' => __( 'Ask a question first.', 'noodled' ) ];

		$ctx     = '';
		$sources = [];
		foreach ( $notes as $n ) {
			$body = trim( (string) ( $n['body'] ?? '' ) );
			if ( $body === '' || self::is_locked( $body ) ) continue;
			$chunk = '## ' . ( $n['title'] ?: __( 'Untitled', 'noodled' ) ) . "\n" . $body . "\n\n";
			if ( strlen( $ctx ) + strlen( $chunk ) > self::ASK_CAP ) {
				$room = max( 0, self::ASK_CAP - strlen( $ctx ) );
				$ctx .= substr( $chunk, 0, $room );
				if ( $room > 0 ) $sources[] = [ 'id' => (int) ( $n['id'] ?? 0 ), 'title' => (string) ( $n['title'] ?? '' ) ];
				break;
			}
			$ctx .= $chunk;
			$sources[] = [ 'id' => (int) ( $n['id'] ?? 0 ), 'title' => (string) ( $n['title'] ?? '' ) ];
		}
		if ( trim( $ctx ) === '' ) {
			return [ 'error' => __( 'No readable notes to search. (Locked notes are never sent to the AI.)', 'noodled' ) ];
		}

		$system = "You answer questions using ONLY the user's notes provided below. "
			. 'If the answer is not in the notes, say so plainly rather than guessing. '
			. 'Cite the note titles you drew from. Be concise and use Markdown.';
		$user = "Notes:\n\n{$ctx}\n---\nQuestion: {$query}";
		$r = self::call( $system, $user );
		if ( ! isset( $r['error'] ) ) $r['sources'] = $sources;
		return $r;
	}

	/**
	 * Words worth searching for in a question: lower case, 3 or more characters,
	 * common English words removed, at most 8.
	 */
	public static function keywords( string $q ): array {
		static $stop = [ 'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'had', 'her', 'was', 'one', 'our', 'out', 'has', 'him', 'his', 'how', 'its', 'who', 'did', 'get', 'let', 'say', 'she', 'too', 'use', 'what', 'when', 'where', 'which', 'why', 'with', 'this', 'that', 'there', 'their', 'them', 'then', 'they', 'have', 'from', 'about', 'into', 'than', 'your', 'were', 'been', 'will', 'would', 'could', 'should', 'does', 'some', 'just', 'like', 'over', 'also', 'more', 'most', 'only', 'very', 'tell', 'know', 'notes', 'note' ];
		$out = [];
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $q ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $w ) {
			if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) || in_array( $w, $out, true ) ) continue;
			$out[] = $w;
			if ( count( $out ) >= 8 ) break;
		}
		return $out;
	}

	/**
	 * A summary plus action items for one note, as Markdown to put at the top of
	 * the note. Locked and empty notes are refused before anything is sent.
	 */
	public static function digest( array $note ): array {
		$body = (string) ( $note['body'] ?? '' );
		if ( self::is_locked( $body ) ) {
			return [ 'error' => __( 'Locked notes are encrypted and cannot be summarized.', 'noodled' ) ];
		}
		$body = trim( $body );
		if ( $body === '' ) return [ 'error' => __( 'This note is empty.', 'noodled' ) ];

		$title  = (string) ( $note['title'] ?? '' );
		$system = 'You summarize a single personal note and list its action items. '
			. 'Reply with JSON only, no other text, in exactly this shape: '
			. '{"summary": "2-4 sentences", "actions": ["one short task per item"]}. '
			. 'Use an empty actions list when the note has no tasks. '
			. 'Only use facts present in the note; never invent details.';
		$user = "Title: {$title}\n\nNote:\n" . self::clip( $body, self::SUMMARIZE_CAP );
		$r = self::call( $system, $user );
		if ( isset( $r['error'] ) ) return $r;
		$d = self::parse_digest( (string) ( $r['text'] ?? '' ) );
		if ( ! $d ) return [ 'error' => __( 'The AI reply could not be read. Please try again.', 'noodled' ) ];
		$md = self::digest_markdown( $d );
		return [ 'markdown' => $md, 'text' => $md ];
	}

	/**
	 * Read the provider's JSON digest. Returns plain single-line strings only:
	 * summary (required) and at most 10 actions of at most 200 characters each.
	 */
	public static function parse_digest( string $t ): ?array {
		$t = trim( $t );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/si', $t, $m ) ) $t = $m[1];
		$d = json_decode( $t, true );
		if ( ! is_array( $d ) || ! isset( $d['summary'] ) || ! is_string( $d['summary'] ) ) return null;
		$line = static function ( string $s, int $max ): string {
			$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) );
			return mb_strlen( $s ) > $max ? rtrim( mb_substr( $s, 0, $max ) ) : $s;
		};
		$summary = $line( $d['summary'], 2000 );
		if ( $summary === '' ) return null;
		$actions = [];
		foreach ( is_array( $d['actions'] ?? null ) ? $d['actions'] : [] as $a ) {
			if ( ! is_string( $a ) ) continue;
			$a = $line( $a, 200 );
			if ( $a === '' ) continue;
			$actions[] = $a;
			if ( count( $actions ) >= 10 ) break;
		}
		return [ 'summary' => $summary, 'actions' => $actions ];
	}

	public static function digest_markdown( array $d ): string {
		$md = '## ' . __( 'Summary', 'noodled' ) . "\n" . $d['summary'] . "\n";
		if ( ! empty( $d['actions'] ) ) {
			$md .= "\n## " . __( 'Action items', 'noodled' ) . "\n";
			foreach ( $d['actions'] as $a ) $md .= '- [ ] ' . $a . "\n";
		}
		return $md;
	}

	/**
	 * Automatic Plaud summaries (setting ai_plaud_auto), run from the 5-minute
	 * noodled_push_check tick. At most 2 notes per tick. Each note is claimed
	 * (pending to running) with a conditional update so it is never processed
	 * twice, the digest is put on top of the note's CURRENT body after a version
	 * snapshot (reason 'ai'), and a failure is retried up to 3 times in all.
	 */
	public static function plaud_tick(): void {
		if ( ! self::is_configured() || ! Noodled_Settings::get( 'ai_plaud_auto' ) ) return;
		global $wpdb;
		$t    = $wpdb->prefix . 'noodled_notes';
		$rows = $wpdb->get_results(
			"SELECT id, title, body, meta_json FROM {$t} WHERE source = 'plaud' AND deleted_at IS NULL AND meta_json LIKE '%\"ai_plaud\":\"pending\"%' ORDER BY id ASC LIMIT 2",
			ARRAY_A
		);
		foreach ( $rows ?: [] as $row ) {
			$id   = (int) $row['id'];
			$meta = json_decode( (string) $row['meta_json'], true );
			if ( ! is_array( $meta ) ) $meta = [];
			$claimed = $wpdb->update( $t,
				[ 'meta_json' => wp_json_encode( array_merge( $meta, [ 'ai_plaud' => 'running' ] ) ) ],
				[ 'id' => $id, 'meta_json' => (string) $row['meta_json'] ]
			);
			if ( ! $claimed ) continue; // another tick has it
			$meta['ai_plaud'] = 'running';

			$d = self::digest( [ 'title' => (string) $row['title'], 'body' => (string) $row['body'] ] );
			// Prepend to the body as it is NOW, so an edit made during the call is kept.
			$cur = (string) $wpdb->get_var( $wpdb->prepare( "SELECT body FROM {$t} WHERE id = %d AND deleted_at IS NULL", $id ) );
			if ( ! isset( $d['error'] ) && ( trim( $cur ) === '' || self::is_locked( $cur ) ) ) {
				$d = [ 'error' => 'gone' ];
			}
			if ( isset( $d['error'] ) ) {
				$tries = (int) ( $meta['ai_tries'] ?? 0 ) + 1;
				$meta['ai_tries'] = $tries;
				$meta['ai_plaud'] = $tries < 3 ? 'pending' : 'failed';
			} else {
				Noodled_Notes::update( $id, [ 'body' => $d['markdown'] . "\n---\n\n" . $cur ], 'ai' );
				Noodled_Sync::queue_push( $id );
				$meta['ai_plaud'] = 'done';
			}
			$wpdb->update( $t, [ 'meta_json' => wp_json_encode( $meta ) ], [ 'id' => $id ] );
		}
	}

	// ── Provider dispatch ──

	private static function call( string $system, string $user ): array {
		if ( ! self::is_configured() ) {
			return [ 'error' => __( 'AI is not configured. Add a provider API key in the noodled settings.', 'noodled' ) ];
		}
		// Test seam: a PHP filter (tests, or a Local mu-plugin) can answer instead
		// of the provider. PHP only, so it adds no remote surface.
		$fake = apply_filters( 'noodled_ai_transport', null, $system, $user );
		if ( is_array( $fake ) ) return $fake;
		switch ( self::provider() ) {
			case 'openai': return self::call_openai( $system, $user );
			case 'google': return self::call_google( $system, $user );
			default:       return self::call_anthropic( $system, $user );
		}
	}

	private static function call_anthropic( string $system, string $user ): array {
		$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 60,
			'headers' => [
				'content-type'      => 'application/json',
				'x-api-key'         => self::api_key(),
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode( [
				'model'      => self::model(),
				'max_tokens' => 4096,
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		$data = self::http_json( $res );
		if ( isset( $data['error'] ) ) return $data;
		if ( ( $data['stop_reason'] ?? '' ) === 'refusal' ) {
			return [ 'error' => __( 'The AI provider declined this request.', 'noodled' ) ];
		}
		// Newer models may return a thinking block first: join every text block.
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' ) $text .= (string) ( $block['text'] ?? '' );
		}
		return $text !== '' ? [ 'text' => $text ] : [ 'error' => __( 'No response from the AI provider.', 'noodled' ) ];
	}

	private static function call_openai( string $system, string $user ): array {
		$res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 60,
			'headers' => [
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . self::api_key(),
			],
			'body' => wp_json_encode( [
				'model'                 => self::model(),
				'max_completion_tokens' => 4096,
				'messages'              => [
					[ 'role' => 'system', 'content' => $system ],
					[ 'role' => 'user',   'content' => $user ],
				],
			] ),
		] );
		$data = self::http_json( $res );
		if ( isset( $data['error'] ) ) return $data;
		$text = $data['choices'][0]['message']['content'] ?? '';
		return $text !== '' ? [ 'text' => $text ] : [ 'error' => __( 'No response from the AI provider.', 'noodled' ) ];
	}

	private static function call_google( string $system, string $user ): array {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. rawurlencode( self::model() ) . ':generateContent';
		$res = wp_remote_post( $url, [
			'timeout' => 60,
			'headers' => [
				'content-type'     => 'application/json',
				'x-goog-api-key'   => self::api_key(),
			],
			'body' => wp_json_encode( [
				'system_instruction' => [ 'parts' => [ [ 'text' => $system ] ] ],
				'contents'           => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $user ] ] ] ],
			] ),
		] );
		$data = self::http_json( $res );
		if ( isset( $data['error'] ) ) return $data;
		// Skip thought-summary parts and join the rest.
		$text = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? [] ) as $part ) {
			if ( is_array( $part ) && empty( $part['thought'] ) ) $text .= (string) ( $part['text'] ?? '' );
		}
		return $text !== '' ? [ 'text' => $text ] : [ 'error' => __( 'No response from the AI provider.', 'noodled' ) ];
	}

	/**
	 * Decode an HTTP response to an array. On transport error or HTTP >= 400,
	 * returns [ 'error' => message ] using the provider's error text when present.
	 */
	private static function http_json( $res ): array {
		if ( is_wp_error( $res ) ) return [ 'error' => $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) $data = [];
		if ( $code >= 400 ) {
			$msg = $data['error']['message'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : '' );
			/* translators: %d is an HTTP status code. */
			return [ 'error' => $msg ?: sprintf( __( 'AI request failed (HTTP %d).', 'noodled' ), $code ) ];
		}
		return $data;
	}
}
