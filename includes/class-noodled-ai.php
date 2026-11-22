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
			case 'openai': return 'gpt-4o-mini';
			case 'google': return 'gemini-2.0-flash';
			case 'anthropic':
			default:       return 'claude-sonnet-4-6';
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

		$ctx = '';
		foreach ( $notes as $n ) {
			$body = trim( (string) ( $n['body'] ?? '' ) );
			if ( $body === '' || self::is_locked( $body ) ) continue;
			$chunk = '## ' . ( $n['title'] ?: __( 'Untitled', 'noodled' ) ) . "\n" . $body . "\n\n";
			if ( strlen( $ctx ) + strlen( $chunk ) > self::ASK_CAP ) {
				$ctx .= substr( $chunk, 0, max( 0, self::ASK_CAP - strlen( $ctx ) ) );
				break;
			}
			$ctx .= $chunk;
		}
		if ( trim( $ctx ) === '' ) {
			return [ 'error' => __( 'No readable notes to search. (Locked notes are never sent to the AI.)', 'noodled' ) ];
		}

		$system = "You answer questions using ONLY the user's notes provided below. "
			. 'If the answer is not in the notes, say so plainly rather than guessing. '
			. 'Cite the note titles you drew from. Be concise and use Markdown.';
		$user = "Notes:\n\n{$ctx}\n---\nQuestion: {$query}";
		return self::call( $system, $user );
	}

	// ── Provider dispatch ──

	private static function call( string $system, string $user ): array {
		if ( ! self::is_configured() ) {
			return [ 'error' => __( 'AI is not configured. Add a provider API key in the noodled settings.', 'noodled' ) ];
		}
		switch ( self::provider() ) {
			case 'openai': return self::call_openai( $system, $user );
			case 'google': return self::call_google( $system, $user );
			default:       return self::call_anthropic( $system, $user );
		}
	}

	private static function call_anthropic( string $system, string $user ): array {
		$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 45,
			'headers' => [
				'content-type'      => 'application/json',
				'x-api-key'         => self::api_key(),
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode( [
				'model'      => self::model(),
				'max_tokens' => 1024,
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		$data = self::http_json( $res );
		if ( isset( $data['error'] ) ) return $data;
		$text = $data['content'][0]['text'] ?? '';
		return $text !== '' ? [ 'text' => $text ] : [ 'error' => __( 'No response from the AI provider.', 'noodled' ) ];
	}

	private static function call_openai( string $system, string $user ): array {
		$res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 45,
			'headers' => [
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . self::api_key(),
			],
			'body' => wp_json_encode( [
				'model'      => self::model(),
				'max_tokens' => 1024,
				'messages'   => [
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
			'timeout' => 45,
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
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
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
