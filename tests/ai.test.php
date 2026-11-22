<?php
/**
 * Unit test for the AI finishing work (P13), no WordPress and no AI provider:
 * Noodled_AI::parse_digest / digest_markdown / digest / ask / keywords /
 * plaud_tick and Noodled_Notes::ask_candidates.
 *
 *   php tests/ai.test.php      (exit 0 only when every scenario passes)
 *
 * Loads the REAL class-noodled-ai.php and class-noodled-notes.php. The provider
 * is never called: the 'noodled_ai_transport' filter (the test seam in
 * Noodled_AI::call) answers instead, and records every prompt it was sent so
 * the scenarios can check what would have left the server. Nothing here is an
 * end-to-end test against a real provider.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

// ── WordPress stubs ──

function __( $s, $d = null ) { return $s; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_remote_post( ...$a ) { throw new Exception( 'a real provider request was attempted' ); }

// The seam: $GLOBALS['transport'] is a callable( $system, $user ) returning the
// fake provider reply; every call is logged in $GLOBALS['sent'].
function apply_filters( $hook, $value, ...$args ) {
	if ( $hook !== 'noodled_ai_transport' ) return $value;
	$GLOBALS['sent'][] = [ 'system' => $args[0], 'user' => $args[1] ];
	return ( $GLOBALS['transport'] )( $args[0], $args[1] );
}

class Noodled_Settings {
	public static $opts = [];
	public static function get( $key = null ) { return self::$opts[ $key ] ?? null; }
}
class Noodled_Notebooks {
	public static function get_by_id( int $id ): ?array { return [ 'id' => $id, 'name' => 'NB' . $id ]; }
}
class Noodled_Attachments {
	public static function delete_for_note( int $id ): void {}
}
class Noodled_Sync {
	public static $pushed = [];
	public static function queue_push( int $id ): void { self::$pushed[] = $id; }
}

// ── Fake $wpdb: notes + revisions, exactly the queries these methods issue ──

class FakeWpdb {
	public $prefix = 'wp_';
	public $notes = [];
	public $revisions = [];
	public $queries = [];

	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
		if ( preg_match( '/%(?![sd%])/', $query ) ) throw new Exception( "prepare: stray % in query: $query" );
		if ( preg_match_all( '/%[sd]/', $query ) !== count( $args ) ) throw new Exception( "prepare: placeholder count mismatch: $query" );
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( $args, &$i ) {
			$v = $args[ $i++ ];
			return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $query );
	}

	private function scoped( string $nbs, string $ids ): array {
		$nbs = array_map( 'intval', explode( ',', $nbs ) );
		$ids = array_map( 'intval', explode( ',', $ids ) );
		$rows = array_filter( $this->notes, static fn( $n ) => $n['deleted_at'] === null
			&& ( in_array( (int) $n['notebook_id'], $nbs, true ) || in_array( (int) $n['id'], $ids, true ) ) );
		usort( $rows, static fn( $a, $b ) => strcmp( $b['modified_at'], $a['modified_at'] ) ?: $b['id'] <=> $a['id'] );
		return $rows;
	}
	private static function pick( array $rows, int $limit ): array {
		return array_slice( array_map( static fn( $n ) => [ 'id' => $n['id'], 'title' => $n['title'], 'body' => $n['body'] ], $rows ), 0, $limit );
	}

	public function get_results( $q, $out = null ) {
		$this->queries[] = $q;
		$scope = '^SELECT id, title, body FROM wp_noodled_notes WHERE deleted_at IS NULL AND \(notebook_id IN \(([\d,]+)\) OR id IN \(([\d,]+)\)\)';
		$str   = "'((?:[^'\\\\]|\\\\.)*)'";
		// ask_candidates(): FULLTEXT natural language, by relevance.
		if ( preg_match( '/' . $scope . ' AND MATCH\(title, body\) AGAINST\(' . $str . '\) ORDER BY MATCH\(title, body\) AGAINST\(' . $str . '\) DESC LIMIT (\d+)$/s', $q, $m ) ) {
			if ( $m[3] !== $m[4] ) throw new Exception( 'MATCH terms differ' );
			$words = explode( ' ', stripslashes( $m[3] ) );
			$score = static function ( $n ) use ( $words ) { $s = 0; foreach ( $words as $w ) $s += substr_count( strtolower( $n['title'] . ' ' . $n['body'] ), $w ); return $s; };
			$rows  = array_filter( $this->scoped( $m[1], $m[2] ), static fn( $n ) => $score( $n ) > 0 );
			usort( $rows, static fn( $a, $b ) => $score( $b ) <=> $score( $a ) );
			return self::pick( $rows, (int) $m[5] );
		}
		// ask_candidates(): LIKE per keyword.
		if ( preg_match( '/' . $scope . ' AND \(title LIKE ' . $str . ' OR body LIKE ' . $str . '\) ORDER BY modified_at DESC LIMIT (\d+)$/s', $q, $m ) ) {
			$w = stripcslashes( trim( stripslashes( $m[3] ), '%' ) );
			return self::pick( array_filter( $this->scoped( $m[1], $m[2] ), static fn( $n ) => stripos( $n['title'], $w ) !== false || stripos( $n['body'], $w ) !== false ), (int) $m[5] );
		}
		// corpus()
		if ( preg_match( '/' . $scope . ' ORDER BY modified_at DESC LIMIT (\d+)$/', $q, $m ) ) {
			return self::pick( $this->scoped( $m[1], $m[2] ), (int) $m[3] );
		}
		// plaud_tick()
		if ( $q === "SELECT id, title, body, meta_json FROM wp_noodled_notes WHERE source = 'plaud' AND deleted_at IS NULL AND meta_json LIKE '%\"ai_plaud\":\"pending\"%' ORDER BY id ASC LIMIT 2" ) {
			$rows = array_filter( $this->notes, static fn( $n ) => $n['source'] === 'plaud' && $n['deleted_at'] === null && str_contains( (string) $n['meta_json'], '"ai_plaud":"pending"' ) );
			ksort( $rows );
			return array_slice( array_map( static fn( $n ) => [ 'id' => $n['id'], 'title' => $n['title'], 'body' => $n['body'], 'meta_json' => $n['meta_json'] ], array_values( $rows ) ), 0, 2 );
		}
		throw new Exception( "get_results: unknown query: $q" );
	}

	public function get_row( $q, $out = null ) {
		$this->queries[] = $q;
		if ( preg_match( '/^SELECT title, body FROM wp_noodled_notes WHERE id = (\d+)$/', $q, $m ) ) {
			$n = $this->notes[ (int) $m[1] ] ?? null;
			return $n ? [ 'title' => $n['title'], 'body' => $n['body'] ] : null;
		}
		if ( preg_match( '/^SELECT title, body, created_at FROM wp_noodled_revisions WHERE note_id = (\d+) ORDER BY id DESC LIMIT 1$/', $q, $m ) ) {
			$r = array_values( array_filter( $this->revisions, static fn( $r ) => $r['note_id'] === (int) $m[1] ) );
			return $r ? end( $r ) : null;
		}
		if ( preg_match( '/^SELECT \* FROM wp_noodled_notes WHERE id = (\d+)$/', $q, $m ) ) {
			return $this->notes[ (int) $m[1] ] ?? null;
		}
		throw new Exception( "get_row: unknown query: $q" );
	}

	public function get_var( $q ) {
		$this->queries[] = $q;
		if ( preg_match( '/^SELECT body FROM wp_noodled_notes WHERE id = (\d+) AND deleted_at IS NULL$/', $q, $m ) ) {
			$n = $this->notes[ (int) $m[1] ] ?? null;
			return ( $n && $n['deleted_at'] === null ) ? $n['body'] : null;
		}
		if ( preg_match( '/^SELECT body FROM wp_noodled_notes WHERE id = (\d+)$/', $q, $m ) ) {
			return $this->notes[ (int) $m[1] ]['body'] ?? null;
		}
		throw new Exception( "get_var: unknown query: $q" );
	}

	public function get_col( $q ) {
		if ( preg_match( '/^SELECT id FROM wp_noodled_revisions WHERE note_id = \d+ ORDER BY id DESC LIMIT 100 OFFSET \d+$/', $q ) ) return [];
		throw new Exception( "get_col: unknown query: $q" );
	}

	public function insert( $table, $data ) {
		if ( $table !== 'wp_noodled_revisions' ) throw new Exception( "insert: unexpected table $table" );
		$this->revisions[] = $data;
		return 1;
	}

	// Like wpdb::update: returns the number of rows changed (0 when the WHERE misses).
	public function update( $table, $data, $where ) {
		if ( $table !== 'wp_noodled_notes' ) throw new Exception( "update: unexpected table $table" );
		$id = (int) $where['id'];
		if ( ! isset( $this->notes[ $id ] ) ) return 0;
		foreach ( $where as $k => $v ) if ( (string) $this->notes[ $id ][ $k ] !== (string) $v ) return 0;
		$this->notes[ $id ] = array_merge( $this->notes[ $id ], $data );
		return 1;
	}

	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
}

require __DIR__ . '/../includes/class-noodled-ai.php';
require __DIR__ . '/../includes/class-noodled-notes.php';

// ── Harness ──

$results = [];
$fails   = [];

function note( int $id, int $nb, string $title, string $body = '', array $f = [] ): void {
	$GLOBALS['wpdb']->notes[ $id ] = array_merge( [
		'id' => $id, 'slug' => 'n' . $id, 'notebook_id' => $nb, 'title' => $title, 'body' => $body, 'source' => '',
		'pinned' => 0, 'meta_json' => null, 'created_at' => '2026-01-01 00:00:00', 'modified_at' => sprintf( '2026-01-%02d 00:00:00', $id ),
		'deleted_at' => null,
	], $f );
}
function ok( $cond, string $msg ): void { if ( ! $cond ) $GLOBALS['fails'][] = $msg; }
function same( $want, $got, string $msg ): void { ok( $want === $got, $msg . ' (want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ')' ); }
function sent_text(): string { return implode( "\n", array_map( static fn( $s ) => $s['system'] . "\n" . $s['user'], $GLOBALS['sent'] ) ); }
function reply( string $text ): void { $GLOBALS['transport'] = static fn( $s, $u ) => [ 'text' => $text ]; }
function digest_json( array $actions = [ 'Call the vet' ] ): string { return json_encode( [ 'summary' => 'Talked about the dog.', 'actions' => $actions ] ); }

function scenario( string $name, callable $fn ): void {
	global $results, $fails;
	$GLOBALS['wpdb']      = new FakeWpdb();
	$GLOBALS['sent']      = [];
	Noodled_Settings::$opts = [ 'ai_key' => 'test-key', 'ai_provider' => 'anthropic' ];
	Noodled_Sync::$pushed = [];
	reply( 'an answer' );
	$fails = [];
	try { $fn(); } catch ( Throwable $e ) { $fails[] = 'exception: ' . $e->getMessage() . ' @' . $e->getLine(); }
	$results[] = [ $name, $fails ];
	echo ( $fails ? 'FAIL' : 'PASS' ) . "  $name" . ( $fails ? "\n      - " . implode( "\n      - ", $fails ) : '' ) . "\n";
}

const LOCKED = 'noodled:enc:v1:SECRETCIPHERTEXT';

// ── parse_digest ──

scenario( 'parse_digest: a ```json fenced reply', function () {
	$d = Noodled_AI::parse_digest( "```json\n{\"summary\": \"Two lines.\", \"actions\": [\"Buy milk\", \"Email Sam\"]}\n```" );
	same( [ 'summary' => 'Two lines.', 'actions' => [ 'Buy milk', 'Email Sam' ] ], $d, 'fenced' );
	$d = Noodled_AI::parse_digest( "```\n{\"summary\": \"Bare fence.\"}\n```" );
	same( [ 'summary' => 'Bare fence.', 'actions' => [] ], $d, 'bare fence, no actions key' );
} );

scenario( 'parse_digest: a plain JSON reply, flattened to single-line plain text', function () {
	$d = Noodled_AI::parse_digest( '{"summary": "Line one.\nLine <b>two</b>.", "actions": ["  Do\n<script>x</script>it  ", "", 5, ["nested"]]}' );
	same( [ 'summary' => 'Line one. Line two.', 'actions' => [ 'Do it' ] ], $d, 'plain' );
} );

scenario( 'parse_digest: garbage and a missing or non-string summary give null', function () {
	same( null, Noodled_AI::parse_digest( 'Sure! Here is your summary: the dog.' ), 'prose' );
	same( null, Noodled_AI::parse_digest( '' ), 'empty' );
	same( null, Noodled_AI::parse_digest( '{"actions": ["x"]}' ), 'no summary' );
	same( null, Noodled_AI::parse_digest( '{"summary": ["x"]}' ), 'array summary' );
	same( null, Noodled_AI::parse_digest( '{"summary": "   "}' ), 'blank summary' );
	same( null, Noodled_AI::parse_digest( '["summary"]' ), 'a list' );
} );

scenario( 'parse_digest: 15 actions are capped at 10, each at 200 characters', function () {
	$acts = [];
	for ( $i = 1; $i <= 15; $i++ ) $acts[] = "Task $i " . str_repeat( 'é', 300 );
	$d = Noodled_AI::parse_digest( json_encode( [ 'summary' => 'Many.', 'actions' => $acts ] ) );
	same( 10, count( $d['actions'] ), 'count' );
	same( 200, max( array_map( 'mb_strlen', $d['actions'] ) ), 'longest action (characters)' );
	ok( str_starts_with( $d['actions'][9], 'Task 10 ' ), 'order kept' );
	ok( mb_check_encoding( implode( '', $d['actions'] ), 'UTF-8' ), 'multibyte cut cleanly' );
} );

// ── digest_markdown ──

scenario( 'digest_markdown: summary plus action items as a checklist', function () {
	same( "## Summary\nShort.\n\n## Action items\n- [ ] A\n- [ ] B\n", Noodled_AI::digest_markdown( [ 'summary' => 'Short.', 'actions' => [ 'A', 'B' ] ] ), 'with actions' );
} );

scenario( 'digest_markdown: no action items section when there are none', function () {
	same( "## Summary\nShort.\n", Noodled_AI::digest_markdown( [ 'summary' => 'Short.', 'actions' => [] ] ), 'without actions' );
} );

// ── digest ──

scenario( 'digest: locked and empty notes are refused and nothing is sent', function () {
	ok( isset( Noodled_AI::digest( [ 'title' => 'T', 'body' => LOCKED ] )['error'] ), 'locked not refused' );
	ok( isset( Noodled_AI::digest( [ 'title' => 'T', 'body' => "  \n" . LOCKED ] )['error'] ), 'locked after whitespace not refused' );
	ok( isset( Noodled_AI::digest( [ 'title' => 'T', 'body' => "  \n " ] )['error'] ), 'empty not refused' );
	same( 0, count( $GLOBALS['sent'] ), 'prompts sent' );
} );

scenario( 'digest: returns the markdown; an unreadable reply is an error', function () {
	reply( "```json\n" . digest_json() . "\n```" );
	$r = Noodled_AI::digest( [ 'title' => 'Vet', 'body' => 'We talked about the dog.' ] );
	same( "## Summary\nTalked about the dog.\n\n## Action items\n- [ ] Call the vet\n", $r['markdown'] ?? null, 'markdown' );
	ok( str_contains( $GLOBALS['sent'][0]['system'], 'JSON only' ), 'system prompt asks for JSON' );
	ok( str_contains( $GLOBALS['sent'][0]['user'], 'We talked about the dog.' ), 'note body sent' );
	reply( 'I cannot do JSON today.' );
	ok( isset( Noodled_AI::digest( [ 'title' => 'Vet', 'body' => 'x' ] )['error'] ), 'garbage reply not an error' );
	$GLOBALS['transport'] = static fn( $s, $u ) => [ 'error' => 'provider down' ];
	same( [ 'error' => 'provider down' ], Noodled_AI::digest( [ 'title' => 'Vet', 'body' => 'x' ] ), 'provider error passed through' );
} );

scenario( 'call: AI not configured sends nothing', function () {
	Noodled_Settings::$opts = [];
	ok( isset( Noodled_AI::digest( [ 'title' => 'T', 'body' => 'x' ] )['error'] ), 'digest' );
	ok( isset( Noodled_AI::ask( 'what?', [ [ 'id' => 1, 'title' => 'T', 'body' => 'x' ] ] )['error'] ), 'ask' );
	same( 0, count( $GLOBALS['sent'] ), 'prompts sent' );
} );

// ── ask ──

scenario( 'ask: locked bodies are skipped and never sent; sources list what was used', function () {
	$r = Noodled_AI::ask( 'dog?', [
		[ 'id' => 1, 'title' => 'Dog', 'body' => 'The dog is called Rex.' ],
		[ 'id' => 2, 'title' => 'Diary', 'body' => LOCKED ],
		[ 'id' => 3, 'title' => 'Empty', 'body' => '   ' ],
		[ 'id' => 4, 'title' => 'Cat', 'body' => 'The cat is called Tom.' ],
	] );
	same( 'an answer', $r['text'] ?? null, 'text' );
	same( [ [ 'id' => 1, 'title' => 'Dog' ], [ 'id' => 4, 'title' => 'Cat' ] ], $r['sources'] ?? null, 'sources' );
	ok( ! str_contains( sent_text(), 'SECRETCIPHERTEXT' ), 'ciphertext was sent' );
	ok( ! str_contains( sent_text(), 'noodled:enc:v1:' ), 'lock prefix was sent' );
	ok( ! str_contains( sent_text(), 'Diary' ), 'locked note title was sent' );
} );

scenario( 'ask: only locked notes gives an error and sends nothing', function () {
	$r = Noodled_AI::ask( 'dog?', [ [ 'id' => 2, 'title' => 'Diary', 'body' => LOCKED ] ] );
	ok( isset( $r['error'] ), 'no error' );
	same( 0, count( $GLOBALS['sent'] ), 'prompts sent' );
} );

scenario( 'ask: the context is capped; sources stop where the cap cut', function () {
	$notes = [];
	for ( $i = 1; $i <= 10; $i++ ) $notes[] = [ 'id' => $i, 'title' => "Note $i", 'body' => str_repeat( "w$i ", 5000 ) ];
	$r = Noodled_AI::ask( 'anything?', $notes );
	$user = $GLOBALS['sent'][0]['user'];
	ok( strlen( $user ) <= Noodled_AI::ASK_CAP + 200, 'prompt too long: ' . strlen( $user ) );
	ok( strlen( $user ) > Noodled_AI::ASK_CAP - 100, 'context not filled to the cap: ' . strlen( $user ) );
	$ids = array_column( $r['sources'], 'id' );
	same( range( 1, count( $ids ) ), $ids, 'sources are the leading notes' );
	ok( count( $ids ) < 10, 'every note listed as a source' );
	ok( ! str_contains( $user, '## Note ' . ( count( $ids ) + 1 ) . "\n" ), 'a note past the last source was sent' );
	ok( ! isset( Noodled_AI::ask( 'x', [] )['sources'] ), 'error carries sources' );
} );

// ── keywords ──

scenario( 'keywords: lower case, 3+ characters, no stopwords, unique, at most 8', function () {
	same( [ 'decide', 'trip', 'japan' ], Noodled_AI::keywords( 'What did I DECIDE about the trip to Japan, the trip?' ), 'basic' );
	same( [], Noodled_AI::keywords( 'why is it so?' ), 'only stopwords and short words' );
	same( [ 'café', 'zürich' ], Noodled_AI::keywords( 'Café in Zürich' ), 'unicode' );
	same( 8, count( Noodled_AI::keywords( 'alpha bravo charlie delta echo foxtrot golf hotel india juliet' ) ), 'cap' );
	same( [ 'brien', 'boat' ], Noodled_AI::keywords( "O'Brien's boat" ), 'punctuation splits words' );
} );

// ── ask_candidates (scope) ──

function account(): void {
	note( 1, 10, 'Trip plan', 'We decided to go to Japan in May.' );
	note( 2, 10, 'Groceries', 'milk eggs' );
	note( 3, 20, 'Shared trip notes', 'Japan rail pass' );
	note( 4, 30, 'Other user trip', 'Japan secret of someone else' );
	note( 5, 30, 'Directly shared', 'japan japan japan' );
	note( 6, 10, 'Trashed trip', 'Japan old', [ 'deleted_at' => '2026-02-01 00:00:00' ] );
	note( 7, 10, 'Locked trip', LOCKED );
}

scenario( 'ask_candidates: relevance first, only inside the scope pair, no trash', function () {
	account();
	$rows = Noodled_Notes::ask_candidates( 'What about the Japan trip?', [ 10, 20 ], [ 5 ] );
	$ids  = array_column( $rows, 'id' );
	same( 5, $ids[0], 'most relevant first' );
	ok( ! in_array( 4, $ids, true ), 'note 4 (another user) returned' );
	ok( ! in_array( 6, $ids, true ), 'trashed note 6 returned' );
	same( count( $ids ), count( array_unique( $ids ) ), 'duplicates' );
	ok( ! array_diff( $ids, [ 1, 2, 3, 5, 7 ] ), 'out of scope id in ' . implode( ',', $ids ) );
	ok( str_contains( $GLOBALS['wpdb']->queries[0], "AGAINST('japan trip')" ), 'keywords not joined into MATCH: ' . $GLOBALS['wpdb']->queries[0] );
} );

scenario( 'ask_candidates: few matches are topped up with LIKE then the newest notes', function () {
	account();
	$rows = Noodled_Notes::ask_candidates( 'eggs', [ 10 ], [] );
	same( 2, $rows[0]['id'], 'match first' );
	same( [ 2, 7, 1 ], array_column( $rows, 'id' ), 'then the rest of the scope, newest first' );
	ok( count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => str_contains( $q, 'LIKE' ) ) ) === 1, 'one LIKE query per keyword' );
} );

scenario( 'ask_candidates: empty scope matches nothing, ids are cast', function () {
	account();
	same( [], Noodled_Notes::ask_candidates( 'japan', [], [] ), 'empty scope' );
	ok( str_contains( $GLOBALS['wpdb']->queries[0], 'IN (0) OR id IN (0)' ), 'empty scope did not use the 0 rule' );
	$ids = array_column( Noodled_Notes::ask_candidates( 'japan', [ '20) OR 1=1 -- ' ], [ '5abc' ] ), 'id' );
	ok( ! array_diff( $ids, [ 3, 5 ] ), 'cast scope leaked: ' . implode( ',', $ids ) );
} );

scenario( 'ask over ask_candidates: nothing outside the scope or locked is ever sent', function () {
	account();
	$r = Noodled_AI::ask( 'What about the Japan trip?', Noodled_Notes::ask_candidates( 'What about the Japan trip?', [ 10, 20 ], [] ) );
	$sent = sent_text();
	ok( ! str_contains( $sent, 'someone else' ), 'other user body sent' );
	ok( ! str_contains( $sent, 'japan japan' ), 'unshared note sent' );
	ok( ! str_contains( $sent, 'Japan old' ), 'trashed note sent' );
	ok( ! str_contains( $sent, 'SECRETCIPHERTEXT' ), 'locked body sent' );
	ok( ! array_diff( array_column( $r['sources'], 'id' ), [ 1, 2, 3 ] ), 'sources out of scope' );
} );

// ── plaud_tick (automatic Plaud summaries) ──

function plaud( int $id, string $body, ?array $meta ): void {
	note( $id, 10, "Recording $id", $body, [ 'source' => 'plaud', 'meta_json' => $meta === null ? null : json_encode( $meta ) ] );
}
function meta( int $id ): array { return json_decode( (string) $GLOBALS['wpdb']->notes[ $id ]['meta_json'], true ) ?: []; }

scenario( 'plaud_tick: off by default, and off when AI is not configured', function () {
	plaud( 1, 'transcript', [ 'ai_plaud' => 'pending' ] );
	reply( digest_json() );
	Noodled_AI::plaud_tick();
	Noodled_Settings::$opts = [ 'ai_plaud_auto' => '1' ];
	Noodled_AI::plaud_tick();
	same( 0, count( $GLOBALS['sent'] ), 'prompts sent' );
	same( 'transcript', $GLOBALS['wpdb']->notes[1]['body'], 'body' );
} );

scenario( 'plaud_tick: prepends the digest after an ai snapshot, pushes, marks done, keeps other meta', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, 'transcript text', [ 'ai_plaud' => 'pending', 'other' => 'kept' ] );
	reply( digest_json() );
	Noodled_AI::plaud_tick();
	same( "## Summary\nTalked about the dog.\n\n## Action items\n- [ ] Call the vet\n\n---\n\ntranscript text", $GLOBALS['wpdb']->notes[1]['body'], 'body' );
	same( [ 'ai_plaud' => 'done', 'other' => 'kept' ], meta( 1 ), 'meta' );
	same( 1, count( $GLOBALS['wpdb']->revisions ), 'revisions' );
	same( 'ai', $GLOBALS['wpdb']->revisions[0]['reason'] ?? null, 'revision reason' );
	same( 'transcript text', $GLOBALS['wpdb']->revisions[0]['body'] ?? null, 'revision holds the old body' );
	same( [ 1 ], Noodled_Sync::$pushed, 'pushed' );
	Noodled_AI::plaud_tick();
	same( 1, count( $GLOBALS['sent'] ), 'a done note was sent again' );
} );

scenario( 'plaud_tick: at most 2 notes per tick, and never non-Plaud, trashed or unmarked notes', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, 'a', [ 'ai_plaud' => 'pending' ] );
	plaud( 2, 'b', [ 'ai_plaud' => 'pending' ] );
	plaud( 3, 'c', [ 'ai_plaud' => 'pending' ] );
	plaud( 4, 'd', null );
	note( 5, 10, 'typed', 'e', [ 'meta_json' => json_encode( [ 'ai_plaud' => 'pending' ] ) ] );
	plaud( 6, 'f', [ 'ai_plaud' => 'pending' ] );
	$GLOBALS['wpdb']->notes[6]['deleted_at'] = '2026-02-01 00:00:00';
	reply( digest_json() );
	Noodled_AI::plaud_tick();
	same( 2, count( $GLOBALS['sent'] ), 'first tick' );
	Noodled_AI::plaud_tick();
	Noodled_AI::plaud_tick();
	same( 3, count( $GLOBALS['sent'] ), 'all ticks' );
	same( [ 'done', 'done', 'done' ], [ meta( 1 )['ai_plaud'], meta( 2 )['ai_plaud'], meta( 3 )['ai_plaud'] ], 'states' );
	same( 'd', $GLOBALS['wpdb']->notes[4]['body'], 'unmarked note' );
	same( 'e', $GLOBALS['wpdb']->notes[5]['body'], 'non-Plaud note' );
	same( 'f', $GLOBALS['wpdb']->notes[6]['body'], 'trashed note' );
} );

scenario( 'plaud_tick: a note claimed by another run is skipped (never twice)', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, 'a', [ 'ai_plaud' => 'pending' ] );
	// Another tick flips the marker between this tick's SELECT and its claim.
	$real = $GLOBALS['wpdb'];
	$GLOBALS['wpdb'] = new class extends FakeWpdb {
		public function get_results( $q, $out = null ) {
			$rows = parent::get_results( $q, $out );
			foreach ( $rows as $r ) $this->notes[ $r['id'] ]['meta_json'] = json_encode( [ 'ai_plaud' => 'running' ] );
			return $rows;
		}
	};
	$GLOBALS['wpdb']->notes = $real->notes;
	reply( digest_json() );
	Noodled_AI::plaud_tick();
	same( 0, count( $GLOBALS['sent'] ), 'prompts sent' );
	same( [ 'ai_plaud' => 'running' ], meta( 1 ), 'meta left to the other run' );
} );

scenario( 'plaud_tick: the marker is running while the provider is called', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, 'a', [ 'ai_plaud' => 'pending' ] );
	$seen = null;
	$GLOBALS['transport'] = static function ( $s, $u ) use ( &$seen ) { $seen = meta( 1 )['ai_plaud'] ?? null; return [ 'text' => digest_json() ]; };
	Noodled_AI::plaud_tick();
	same( 'running', $seen, 'marker during the call' );
} );

scenario( 'plaud_tick: an edit made during the call is kept (prepended to the current body)', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, 'original', [ 'ai_plaud' => 'pending' ] );
	$GLOBALS['transport'] = static function ( $s, $u ) {
		$GLOBALS['wpdb']->notes[1]['body'] = 'original plus my edit';
		return [ 'text' => digest_json( [] ) ];
	};
	Noodled_AI::plaud_tick();
	same( "## Summary\nTalked about the dog.\n\n---\n\noriginal plus my edit", $GLOBALS['wpdb']->notes[1]['body'], 'body' );
	same( 'original plus my edit', $GLOBALS['wpdb']->revisions[0]['body'] ?? null, 'snapshot of the edited body' );
} );

scenario( 'plaud_tick: a note locked during the call is not written', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, 'original', [ 'ai_plaud' => 'pending' ] );
	$GLOBALS['transport'] = static function ( $s, $u ) { $GLOBALS['wpdb']->notes[1]['body'] = LOCKED; return [ 'text' => digest_json() ]; };
	Noodled_AI::plaud_tick();
	same( LOCKED, $GLOBALS['wpdb']->notes[1]['body'], 'body' );
	same( 'pending', meta( 1 )['ai_plaud'], 'state' );
	Noodled_AI::plaud_tick();
	same( 1, count( $GLOBALS['sent'] ), 'locked body sent on the retry' );
} );

scenario( 'plaud_tick: a locked transcript is never sent', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, LOCKED, [ 'ai_plaud' => 'pending' ] );
	reply( digest_json() );
	Noodled_AI::plaud_tick();
	same( 0, count( $GLOBALS['sent'] ), 'prompts sent' );
	same( LOCKED, $GLOBALS['wpdb']->notes[1]['body'], 'body' );
} );

scenario( 'plaud_tick: gives up after 3 failures', function () {
	Noodled_Settings::$opts['ai_plaud_auto'] = '1';
	plaud( 1, 'a', [ 'ai_plaud' => 'pending' ] );
	reply( 'not json' );
	Noodled_AI::plaud_tick();
	same( [ 'ai_plaud' => 'pending', 'ai_tries' => 1 ], meta( 1 ), 'after 1' );
	Noodled_AI::plaud_tick();
	same( [ 'ai_plaud' => 'pending', 'ai_tries' => 2 ], meta( 1 ), 'after 2' );
	Noodled_AI::plaud_tick();
	same( [ 'ai_plaud' => 'failed', 'ai_tries' => 3 ], meta( 1 ), 'after 3' );
	Noodled_AI::plaud_tick();
	same( 3, count( $GLOBALS['sent'] ), 'calls' );
	same( 'a', $GLOBALS['wpdb']->notes[1]['body'], 'body untouched' );
	same( [], Noodled_Sync::$pushed, 'pushed' );
} );

// ── Summary ──

$failed = count( array_filter( $results, static fn( $r ) => $r[1] ) );
echo "\n" . ( count( $results ) - $failed ) . " passed, {$failed} failed (" . count( $results ) . " scenarios)\n";
exit( $failed ? 1 : 0 );
