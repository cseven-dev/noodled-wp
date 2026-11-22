<?php
/**
 * Unit test for PDF search (P15), no WordPress: the three REST routes
 * (POST /attachments/{id}/text, GET /attachments/unindexed, GET /search/files),
 * the PDF hits merged into GET /search, and the attachment list payload.
 *
 *   php tests/attachments-text.test.php      (exit 0 only when every scenario passes)
 *
 * Loads the REAL class-noodled-attachments.php, class-noodled-notes.php and
 * class-noodled-rest.php. Stubs: a few WordPress functions, the request and
 * response classes, Noodled_Auth / Noodled_Notebooks / Noodled_Permissions
 * (a fixed permission fixture), and a fake $wpdb backed by PHP arrays that
 * understands exactly the queries these paths issue (and throws on any other).
 * The fake applies the scope, trash and lock rules it parses out of the SQL, so
 * the scenarios check what a user would actually receive. The text extraction
 * itself runs in the browser and is not covered here.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

// ── WordPress stubs ──

function __( $s, $d = null ) { return $s; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
// Like WordPress with $strip = false: invalid UTF-8 becomes ''.
function wp_check_invalid_utf8( $s, $strip = false ) { return mb_check_encoding( (string) $s, 'UTF-8' ) ? (string) $s : ''; }
function wp_create_nonce( $a ) { return 'nonce'; }
function rest_url( $p ) { return 'https://example.test/wp-json/' . $p; }
function add_query_arg( $k, $v, $url ) { return $url . '?' . $k . '=' . $v; }

class WP_REST_Request implements ArrayAccess {
	private $p;
	public function __construct( array $p = [] ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; }
	public function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
	public function offsetGet( $k ): mixed { return $this->p[ $k ] ?? null; }
	public function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; }
	public function offsetUnset( $k ): void { unset( $this->p[ $k ] ); }
}
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}

// ── Permission fixture: user 1 ──
// Notebook 10 is theirs (write), 20 is shared with them read-only, 30 belongs to
// someone else. Note 50 (in 30) is shared with them read-only, note 51 for writing.

class Noodled_Auth {
	public static function get_current_user() { return [ 'id' => 1 ]; }
}
class Noodled_Notebooks {
	public static function get_for_user( int $uid ): array { return [ [ 'id' => 10 ], [ 'id' => 20 ] ]; }
	public static function user_can_access( int $uid, int $nb ): bool { return in_array( $nb, [ 10, 20 ], true ); }
	public static function user_can_write( int $uid, int $nb ): bool { return $nb === 10; }
	public static function get_by_id( int $id ): ?array { return [ 'id' => $id, 'name' => 'NB' . $id ]; }
}
class Noodled_Permissions {
	public static function shared_note_ids_for_user( int $uid ): array { return [ 50, 51 ]; }
	public static function note_can_read( int $uid, int $id ): bool { return in_array( $id, [ 50, 51 ], true ); }
	public static function note_can_write( int $uid, int $id ): bool { return $id === 51; }
}

// ── Fake $wpdb: notes + attachments ──

class FakeWpdb {
	public $prefix = 'wp_';
	public $notes = [];
	public $atts = [];
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
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }

	private static function ids( string $s ): array { return array_map( 'intval', explode( ',', $s ) ); }
	private function in_scope( array $n, array $nbs, array $ids ): bool {
		return in_array( (int) $n['notebook_id'], $nbs, true ) || in_array( (int) $n['id'], $ids, true );
	}
	private function by_modified( array $rows ): array {
		usort( $rows, static fn( $a, $b ) => strcmp( $b['modified_at'], $a['modified_at'] ) );
		return $rows;
	}
	// Attachments joined to their note under the scoped_join() rules.
	private function joined( string $nbs, string $ids ): array {
		$nbs = self::ids( $nbs ); $ids = self::ids( $ids );
		$out = [];
		foreach ( $this->atts as $a ) {
			$n = $this->notes[ $a['note_id'] ] ?? null;
			if ( ! $n || $n['deleted_at'] !== null || ! $this->in_scope( $n, $nbs, $ids ) ) continue;
			if ( $n['body'] !== null && substr( $n['body'], 0, 15 ) === 'noodled:enc:v1:' ) continue;
			$out[] = [ $a, $n ];
		}
		return $out;
	}

	public function get_results( $q, $out = null ) {
		$this->queries[] = $q;
		$str  = "'((?:[^'\\\\]|\\\\.)*)'";
		$join = 'FROM wp_noodled_attachments a JOIN wp_noodled_notes n ON n\.id = a\.note_id WHERE n\.deleted_at IS NULL AND \(n\.notebook_id IN \(([\d,]+)\) OR n\.id IN \(([\d,]+)\)\) AND \(n\.body IS NULL OR LEFT\(n\.body, 15\) <> \'noodled:enc:v1:\'\)';
		if ( preg_match( '/^SELECT a\.id, a\.note_id, a\.filename ' . $join . ' AND a\.mime_type = \'application\/pdf\' AND a\.text_content IS NULL ORDER BY a\.id ASC$/', $q, $m ) ) {
			$rows = array_filter( $this->joined( $m[1], $m[2] ), static fn( $p ) => $p[0]['mime_type'] === 'application/pdf' && $p[0]['text_content'] === null );
			usort( $rows, static fn( $x, $y ) => $x[0]['id'] <=> $y[0]['id'] );
			return array_map( static fn( $p ) => [ 'id' => $p[0]['id'], 'note_id' => $p[0]['note_id'], 'filename' => $p[0]['filename'] ], $rows );
		}
		if ( preg_match( '/^SELECT a\.id, a\.note_id, a\.filename, SUBSTRING\(a\.text_content, GREATEST\(1, LOCATE\(' . $str . ', a\.text_content\) - 50\), 120\) AS snippet ' . $join . ' AND a\.text_content LIKE ' . $str . ' ORDER BY n\.modified_at DESC, a\.id ASC LIMIT 50$/s', $q, $m ) ) {
			$needle = stripslashes( $m[1] );
			$like   = stripcslashes( substr( stripslashes( $m[4] ), 1, -1 ) ); // drop the outer %...%, then unescape
			if ( $needle !== $like ) throw new Exception( 'LOCATE and LIKE terms differ' );
			$rows = array_filter( $this->joined( $m[2], $m[3] ), static fn( $p ) => $p[0]['text_content'] !== null && mb_stripos( $p[0]['text_content'], $needle ) !== false );
			usort( $rows, static fn( $x, $y ) => strcmp( $y[1]['modified_at'], $x[1]['modified_at'] ) ?: $x[0]['id'] <=> $y[0]['id'] );
			return array_slice( array_map( static function ( $p ) use ( $needle ) {
				$pos = mb_stripos( $p[0]['text_content'], $needle ) + 1; // LOCATE is 1-based
				return [ 'id' => $p[0]['id'], 'note_id' => $p[0]['note_id'], 'filename' => $p[0]['filename'],
					'snippet' => mb_substr( $p[0]['text_content'], max( 1, $pos - 50 ) - 1, 120 ) ];
			}, $rows ), 0, 50 );
		}
		if ( preg_match( '/^SELECT \* FROM wp_noodled_attachments WHERE note_id = (\d+) ORDER BY sort_order ASC, created_at ASC, id ASC$/', $q, $m ) ) {
			return array_values( array_filter( $this->atts, static fn( $a ) => $a['note_id'] === (int) $m[1] ) );
		}
		// Noodled_Notes::search_rows (MATCH, then LIKE) and the PDF merge (id IN).
		if ( preg_match( '/^SELECT \* FROM wp_noodled_notes WHERE deleted_at IS NULL AND \(notebook_id IN \(([\d,]+)\) OR id IN \(([\d,]+)\)\) AND (.*) ORDER BY modified_at DESC$/s', $q, $m ) ) {
			$nbs = self::ids( $m[1] ); $ids = self::ids( $m[2] ); $c = $m[3];
			$rows = array_filter( $this->notes, fn( $n ) => $n['deleted_at'] === null && $this->in_scope( $n, $nbs, $ids ) );
			if ( preg_match( '/^MATCH\(title, body\) AGAINST\(' . $str . ' IN BOOLEAN MODE\)$/', $c, $k ) ) {
				$w = trim( stripslashes( $k[1] ), '*' );
				$rows = array_filter( $rows, static fn( $n ) => stripos( $n['title'] . ' ' . $n['body'], $w ) !== false );
			} elseif ( preg_match( '/^\(title LIKE ' . $str . ' OR body LIKE ' . $str . '\)$/', $c, $k ) ) {
				$w = stripcslashes( trim( stripslashes( $k[1] ), '%' ) );
				$rows = array_filter( $rows, static fn( $n ) => stripos( $n['title'] . ' ' . $n['body'], $w ) !== false );
			} elseif ( preg_match( '/^id IN \(([\d,]+)\)$/', $c, $k ) ) {
				$want = self::ids( $k[1] );
				$rows = array_filter( $rows, static fn( $n ) => in_array( (int) $n['id'], $want, true ) );
			} else {
				throw new Exception( "get_results: unknown notes clause: $c" );
			}
			return $this->by_modified( array_values( $rows ) );
		}
		throw new Exception( "get_results: unknown query: $q" );
	}

	public function get_row( $q, $out = null ) {
		$this->queries[] = $q;
		if ( preg_match( '/^SELECT \* FROM wp_noodled_notes WHERE id = (\d+)$/', $q, $m ) ) return $this->notes[ (int) $m[1] ] ?? null;
		throw new Exception( "get_row: unknown query: $q" );
	}

	public function get_var( $q ) {
		$this->queries[] = $q;
		if ( preg_match( '/^SELECT notebook_id FROM wp_noodled_notes WHERE id = (\d+)$/', $q, $m ) ) return $this->notes[ (int) $m[1] ]['notebook_id'] ?? null;
		if ( preg_match( '/^SELECT note_id FROM wp_noodled_attachments WHERE id = (\d+)$/', $q, $m ) ) return $this->atts[ (int) $m[1] ]['note_id'] ?? null;
		if ( preg_match( '/^SELECT mime_type FROM wp_noodled_attachments WHERE id = (\d+)$/', $q, $m ) ) return $this->atts[ (int) $m[1] ]['mime_type'] ?? null;
		throw new Exception( "get_var: unknown query: $q" );
	}

	public function update( $table, $data, $where ) {
		if ( $table !== 'wp_noodled_attachments' || array_keys( $where ) !== [ 'id' ] ) throw new Exception( "update: unexpected $table" );
		if ( ! isset( $this->atts[ (int) $where['id'] ] ) ) return 0;
		$this->atts[ (int) $where['id'] ] = array_merge( $this->atts[ (int) $where['id'] ], $data );
		return 1;
	}
}

require __DIR__ . '/../includes/class-noodled-attachments.php';
require __DIR__ . '/../includes/class-noodled-notes.php';
require __DIR__ . '/../includes/class-noodled-rest.php';

// ── Harness ──

$results = [];
$fails   = [];

const LOCKED = 'noodled:enc:v1:CIPHERTEXT';

function note( int $id, int $nb, string $title, ?string $body, int $day, ?string $deleted = null ): void {
	$GLOBALS['wpdb']->notes[ $id ] = [
		'id' => $id, 'slug' => 'n' . $id, 'notebook_id' => $nb, 'title' => $title, 'body' => $body, 'source' => '',
		'pinned' => 0, 'created_at' => '2026-01-01 00:00:00', 'modified_at' => sprintf( '2026-01-%02d 00:00:00', $day ),
		'deleted_at' => $deleted,
	];
}
function att( int $id, int $note, string $mime, ?string $text, string $name = '' ): void {
	$GLOBALS['wpdb']->atts[ $id ] = [
		'id' => $id, 'note_id' => $note, 'filename' => $name ?: "file$id.pdf", 'file_path' => "$note/x/file$id", 'mime_type' => $mime,
		'file_size' => 100, 'sha' => '', 'exif' => null, 'alt' => '', 'sort_order' => 0, 'text_content' => $text,
		'created_at' => '2026-01-01 00:00:00',
	];
}
function account(): void {
	note( 1, 10, 'Deeds', 'see the pdf', 9 );
	note( 2, 10, 'Old deeds', 'trashed', 8, '2026-02-01 00:00:00' );
	note( 3, 10, 'Locked deeds', LOCKED, 7 );
	note( 4, 20, 'Family folder', 'read-only notebook', 6 );
	note( 5, 30, 'Someone else', 'not yours', 5 );
	note( 50, 30, 'Shared to read', 'read share', 4 );
	note( 51, 30, 'Shared to edit', 'write share', 3 );
	att( 1, 1, 'application/pdf', 'Title page. The pumphouse key is under the blue mat by the door.', 'deed.pdf' );
	att( 2, 2, 'application/pdf', 'pumphouse in the trash' );
	att( 3, 3, 'application/pdf', 'pumphouse in a locked note' );
	att( 4, 4, 'application/pdf', 'pumphouse in a read-only notebook' );
	att( 5, 5, 'application/pdf', 'pumphouse of someone else' );
	att( 6, 50, 'application/pdf', 'pumphouse shared to read' );
	att( 7, 51, 'application/pdf', null );
	att( 8, 1, 'image/png', null, 'photo.png' );
	att( 9, 1, 'application/pdf', null );
	att( 10, 4, 'application/pdf', null );
	att( 11, 50, 'application/pdf', null );
	att( 12, 5, 'application/pdf', null );
	att( 13, 3, 'application/pdf', null );
	att( 14, 2, 'application/pdf', null );
}
function ok( $cond, string $msg ): void { if ( ! $cond ) $GLOBALS['fails'][] = $msg; }
function same( $want, $got, string $msg ): void { ok( $want === $got, $msg . ' (want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ')' ); }
function text_of( int $id ) { return $GLOBALS['wpdb']->atts[ $id ]['text_content']; }
function post_text( int $id, $text ): WP_REST_Response { return Noodled_REST::set_attachment_text( new WP_REST_Request( [ 'id' => (string) $id, 'text' => $text ] ) ); }

function scenario( string $name, callable $fn ): void {
	global $results, $fails;
	$GLOBALS['wpdb'] = new FakeWpdb();
	account();
	$fails = [];
	try { $fn(); } catch ( Throwable $e ) { $fails[] = 'exception: ' . $e->getMessage() . ' @' . $e->getLine(); }
	$results[] = [ $name, $fails ];
	echo ( $fails ? 'FAIL' : 'PASS' ) . "  $name" . ( $fails ? "\n      - " . implode( "\n      - ", $fails ) : '' ) . "\n";
}

// ── GET /search/files ──

scenario( 'search/files: only the scope pair; trashed, locked and other users\' notes never match', function () {
	$r = Noodled_REST::search_files( new WP_REST_Request( [ 'q' => 'pumphouse' ] ) );
	same( 200, $r->status, 'status' );
	same( [ 1, 4, 50 ], array_column( $r->data, 'note_id' ), 'note ids, newest note first' );
	same( [ 1, 4, 6 ], array_column( $r->data, 'attachment_id' ), 'attachment ids' );
	same( [ 'note_id', 'attachment_id', 'filename', 'snippet' ], array_keys( $r->data[0] ), 'row shape' );
	same( 'deed.pdf', $r->data[0]['filename'], 'filename' );
	ok( str_contains( $GLOBALS['wpdb']->queries[0], 'IN (10,20) OR n.id IN (50,51)' ), 'scope pair not used: ' . $GLOBALS['wpdb']->queries[0] );
} );

scenario( 'search/files: snippet is about 120 characters around the first match, on one line', function () {
	$GLOBALS['wpdb']->atts[1]['text_content'] = str_repeat( 'filler ', 40 ) . "The\n\npumphouse   key" . str_repeat( ' more', 60 );
	$r = Noodled_REST::search_files( new WP_REST_Request( [ 'q' => 'PumpHouse' ] ) );
	$s = $r->data[0]['snippet'];
	ok( stripos( $s, 'pumphouse key' ) !== false, 'match and whitespace collapsed: ' . $s );
	ok( mb_strlen( $s ) <= 120, 'snippet too long: ' . mb_strlen( $s ) );
	ok( stripos( $s, 'pumphouse' ) >= 30, 'match not centred: ' . $s );
	ok( ! str_contains( $s, "\n" ), 'newline in snippet' );
} );

scenario( 'search/files: fewer than 3 characters returns nothing and runs no query', function () {
	same( [], Noodled_REST::search_files( new WP_REST_Request( [ 'q' => ' pu ' ] ) )->data, 'short query' );
	same( [], $GLOBALS['wpdb']->queries, 'queries' );
} );

scenario( 'search/files: LIKE wildcards in the query are escaped', function () {
	same( [], Noodled_REST::search_files( new WP_REST_Request( [ 'q' => '%%%' ] ) )->data, 'percent signs match nothing' );
	ok( str_contains( end( $GLOBALS['wpdb']->queries ), "LIKE '%\\\\%\\\\%\\\\%%'" ), 'not escaped: ' . end( $GLOBALS['wpdb']->queries ) );
} );

// ── GET /search merges PDF hits ──

scenario( 'search: notes that match only inside a PDF are merged, same scope, no trash or locked', function () {
	$r = Noodled_REST::search( new WP_REST_Request( [ 'q' => 'pumphouse' ] ) );
	same( [ 1, 4, 50 ], array_column( $r->data, 'id' ), 'merged note ids' );
	ok( ! isset( $r->data[0]['body'] ), 'list rows carry a body' );
	ok( ! str_contains( json_encode( $r->data ), 'blue mat' ), 'PDF text leaked into the search payload' );
} );

// ── POST /attachments/{id}/text ──

scenario( 'text: stored for a PDF on a note the caller can write (own notebook and write share)', function () {
	$r = post_text( 9, 'Hello PDF' );
	same( [ 200, [ 'ok' => true ] ], [ $r->status, $r->data ], 'own notebook' );
	same( 'Hello PDF', text_of( 9 ), 'stored' );
	$r = post_text( 7, 'Shared edit' );
	same( 200, $r->status, 'write share status' );
	same( 'Shared edit', text_of( 7 ), 'write share stored' );
	$r = post_text( 9, '' );
	same( 200, $r->status, 'empty text (a scanned PDF) is stored as done' );
	same( '', text_of( 9 ), 'empty stored' );
} );

scenario( 'text: refused without write access (read-only notebook, read share, other user, unknown id)', function () {
	foreach ( [ 10 => 'read-only notebook', 11 => 'read share', 12 => 'other user', 999 => 'unknown id' ] as $id => $why ) {
		same( 403, post_text( $id, 'x' )->status, $why );
		if ( $id !== 999 ) same( null, text_of( $id ), "$why stored" );
	}
} );

scenario( 'text: refused for a non-PDF and for a locked note', function () {
	$r = post_text( 8, 'x' );
	same( 400, $r->status, 'png status' );
	same( null, text_of( 8 ), 'png stored' );
	$r = post_text( 13, 'x' );
	same( 400, $r->status, 'locked status' );
	same( null, text_of( 13 ), 'locked stored' );
} );

scenario( 'text: capped at 500000 characters, invalid UTF-8 never stored', function () {
	post_text( 9, str_repeat( 'é', 600000 ) );
	same( 500000, mb_strlen( text_of( 9 ) ), 'characters stored' );
	ok( mb_check_encoding( text_of( 9 ), 'UTF-8' ), 'cut mid-character' );
	post_text( 9, "bad \xC3\x28 bytes" );
	same( '', text_of( 9 ), 'invalid UTF-8' );
} );

// ── GET /attachments/unindexed ──

scenario( 'unindexed: PDFs without text, in notes the caller can write, never trashed or locked', function () {
	$r = Noodled_REST::unindexed_attachments();
	same( [ 7, 9 ], array_column( $r->data, 'id' ), 'ids' );
	same( [ 'id' => 9, 'note_id' => 1, 'filename' => 'file9.pdf' ], $r->data[1], 'row shape' );
	post_text( 9, 'done' );
	same( [ 7 ], array_column( Noodled_REST::unindexed_attachments()->data, 'id' ), 'after indexing 9' );
} );

// ── Payloads never carry the text ──

scenario( 'attachment list payload has no text_content', function () {
	post_text( 9, 'secret words' );
	$list = Noodled_Attachments::get_for_note( 1 );
	same( [ 1, 8, 9 ], array_column( $list, 'id' ), 'ids' );
	foreach ( $list as $a ) ok( ! array_key_exists( 'text_content', $a ), 'text_content in attachment ' . $a['id'] );
	ok( ! str_contains( json_encode( $list ), 'secret words' ), 'text leaked into the list' );
	ok( ! str_contains( json_encode( $list ), 'pumphouse' ), 'text leaked into the list' );
} );

// ── Summary ──

$failed = count( array_filter( $results, static fn( $r ) => $r[1] ) );
echo "\n" . ( count( $results ) - $failed ) . " passed, {$failed} failed (" . count( $results ) . " scenarios)\n";
exit( $failed ? 1 : 0 );
