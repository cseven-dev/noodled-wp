<?php
/**
 * Unit test for the scoping of the cross-notebook note queries
 * (Noodled_Notes::titles and the filters of Noodled_Notes::search) and for the
 * server-side #tag rule (tags_of, reached through titles() and search()), no
 * WordPress.
 *
 *   php tests/notes-scope.test.php      (exit 0 only when every scenario passes)
 *
 * Loads the REAL class-noodled-notes.php. Stubs: a handful of WordPress
 * functions, Noodled_Notebooks / Noodled_Attachments, and a fake $wpdb backed by
 * PHP arrays that understands exactly the queries these methods issue (and
 * throws on any other). The fake applies the WHERE clause it parses out of the
 * SQL, so the scenarios check what a user would actually receive.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

// ── WordPress stubs ──

function __( $s, $d = null ) { return $s; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function wp_generate_password( $n = 12, $special = true ) { return substr( md5( (string) mt_rand() ), 0, $n ); }

class Noodled_Notebooks {
	public static function get_by_id( int $id ): ?array { return [ 'id' => $id, 'name' => $GLOBALS['wpdb']->notebooks[ $id ] ?? '' ]; }
}
class Noodled_Attachments {
	public static function delete_for_note( int $id ): void {}
}

// ── Fake $wpdb: notes + notebooks tables ──

class FakeWpdb {
	public $prefix = 'wp_';
	public $notes = [];
	public $notebooks = [ 10 => 'Mine', 20 => 'Shared with me', 30 => 'Someone else' ];
	public $queries = [];

	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
		// Like wpdb: any % that is not a placeholder must be written %%.
		if ( preg_match( '/%(?![sd%])/', $query ) ) throw new Exception( "prepare: stray % in query: $query" );
		if ( preg_match_all( '/%[sd]/', $query ) !== count( $args ) ) throw new Exception( "prepare: placeholder count mismatch: $query" );
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( $args, &$i ) {
			$v = $args[ $i++ ];
			return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $query );
	}

	// Rows matching "deleted_at IS NULL AND (notebook_id IN (a) OR id IN (b))".
	private function scoped( string $nbs, string $ids ): array {
		$nbs = array_map( 'intval', explode( ',', $nbs ) );
		$ids = array_map( 'intval', explode( ',', $ids ) );
		$rows = array_filter( $this->notes, static fn( $n ) => $n['deleted_at'] === null
			&& ( in_array( (int) $n['notebook_id'], $nbs, true ) || in_array( (int) $n['id'], $ids, true ) ) );
		usort( $rows, static fn( $a, $b ) => strcmp( $b['modified_at'], $a['modified_at'] ) );
		return $rows;
	}

	public function get_results( $q, $out = null ) {
		$this->queries[] = $q;
		if ( preg_match( '/^SELECT t\.id, t\.title, t\.body, t\.modified_at, t\.pinned, n\.name AS nb_name FROM wp_noodled_notes t LEFT JOIN wp_noodled_notebooks n ON n\.id = t\.notebook_id WHERE t\.deleted_at IS NULL AND \(t\.notebook_id IN \(([\d,]+)\) OR t\.id IN \(([\d,]+)\)\) ORDER BY t\.modified_at DESC$/', $q, $m ) ) {
			return array_map( fn( $n ) => [
				'id' => $n['id'], 'title' => $n['title'], 'body' => $n['body'], 'modified_at' => $n['modified_at'], 'pinned' => $n['pinned'],
				'nb_name' => $this->notebooks[ $n['notebook_id'] ] ?? null,
			], $this->scoped( $m[1], $m[2] ) );
		}
		// search(): scope, then any of the known filter / text clauses, in order.
		if ( preg_match( '/^SELECT \* FROM wp_noodled_notes WHERE deleted_at IS NULL AND \(notebook_id IN \(([\d,]+)\) OR id IN \(([\d,]+)\)\)(.*) ORDER BY modified_at DESC$/s', $q, $m ) ) {
			$rows = $this->scoped( $m[1], $m[2] );
			$rest = $m[3];
			$str  = "'((?:[^'\\\\]|\\\\.)*)'";
			$clauses = [
				'notebook' => '/^ AND notebook_id IN \(SELECT id FROM wp_noodled_notebooks WHERE name = ' . $str . '\)/s',
				'since'    => "/^ AND modified_at >= '(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)'/",
				'regexp'   => '/^ AND body REGEXP ' . $str . '/s',
				'match'    => '/^ AND MATCH\(title, body\) AGAINST\(' . $str . ' IN BOOLEAN MODE\)/s',
				'like'     => '/^ AND \(title LIKE ' . $str . ' OR body LIKE ' . $str . '\)/s',
			];
			while ( $rest !== '' ) {
				$hit = false;
				foreach ( $clauses as $kind => $re ) {
					if ( ! preg_match( $re, $rest, $c ) ) continue;
					$hit  = true;
					$rest = substr( $rest, strlen( $c[0] ) );
					$v    = stripslashes( $c[1] );
					$rows = array_filter( $rows, function ( $n ) use ( $kind, $v ) {
						switch ( $kind ) {
							case 'notebook': return ( $this->notebooks[ $n['notebook_id'] ] ?? null ) === $v;
							case 'since':    return $n['modified_at'] >= $v;
							case 'regexp':   return (bool) preg_match( '/' . str_replace( '/', '\/', $v ) . '/i', $n['body'] );
							case 'match':    $w = trim( $v, '*' ); return stripos( $n['title'] . ' ' . $n['body'], $w ) !== false;
							case 'like':     $w = stripcslashes( trim( $v, '%' ) ); return stripos( $n['title'], $w ) !== false || stripos( $n['body'], $w ) !== false;
						}
						return false;
					} );
					break;
				}
				if ( ! $hit ) throw new Exception( "get_results: unknown search clause: $rest" );
			}
			return array_values( $rows );
		}
		throw new Exception( "get_results: unknown query: $q" );
	}

	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
}

require __DIR__ . '/../includes/class-noodled-notes.php';

// ── Harness ──

$results = [];
$fails   = [];

function note( int $id, int $nb, string $title, string $body = '', array $f = [] ): void {
	$GLOBALS['wpdb']->notes[ $id ] = array_merge( [
		'id' => $id, 'slug' => 'n' . $id, 'notebook_id' => $nb, 'title' => $title, 'body' => $body, 'source' => '',
		'pinned' => 0, 'created_at' => '2026-01-01 00:00:00', 'modified_at' => sprintf( '2026-01-%02d 10:00:00', $id ),
		'deleted_at' => null, 'deleted_from' => null,
	], $f );
}
// A small account: my notebook (10), a notebook shared with me (20), and a
// notebook I have no access to (30) with one of its notes shared to me directly.
function account(): void {
	note( 1, 10, 'My note', 'hello #mine' );
	note( 2, 10, 'My trashed note', 'gone', [ 'deleted_at' => '2026-02-01 00:00:00' ] );
	note( 3, 20, 'Shared notebook note', 'from the family' );
	note( 4, 30, 'Private to someone else', 'secret #private' );
	note( 5, 30, 'Directly shared note', 'just this one' );
	note( 6, 30, 'Trashed shared note', 'x', [ 'deleted_at' => '2026-02-01 00:00:00' ] );
	note( 7, 10, 'Locked note', 'noodled:enc:v1:CIPHERTEXT', [ 'pinned' => 1 ] );
}
function ok( $cond, string $msg ): void { if ( ! $cond ) $GLOBALS['fails'][] = $msg; }
function same( $want, $got, string $msg ): void { ok( $want === $got, $msg . ' (want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ')' ); }

function scenario( string $name, callable $fn ): void {
	global $results, $fails;
	$GLOBALS['wpdb'] = new FakeWpdb();
	account();
	$fails = [];
	try { $fn(); } catch ( Throwable $e ) { $fails[] = 'exception: ' . $e->getMessage() . ' @' . $e->getLine(); }
	$results[] = [ $name, $fails ];
	echo ( $fails ? 'FAIL' : 'PASS' ) . "  $name" . ( $fails ? "\n      - " . implode( "\n      - ", $fails ) : '' ) . "\n";
}

// ── titles() ──

scenario( 'titles: only the given notebooks plus the directly shared notes, newest first', function () {
	$rows = Noodled_Notes::titles( [ 10, 20 ], [ 5 ] );
	same( [ 7, 5, 3, 1 ], array_column( $rows, 'id' ), 'ids' );
	same( [ 'Locked note', 'Directly shared note', 'Shared notebook note', 'My note' ], array_column( $rows, 'title' ), 'titles' );
	same( 'Someone else', $rows[1]['notebook'], 'notebook name of the directly shared note' );
} );

scenario( 'titles: trashed notes are excluded, in scope or shared', function () {
	$ids = array_column( Noodled_Notes::titles( [ 10, 30 ], [ 6 ] ), 'id' );
	ok( ! in_array( 2, $ids, true ), 'trashed note 2 returned' );
	ok( ! in_array( 6, $ids, true ), 'trashed shared note 6 returned' );
} );

scenario( 'titles: empty scope matches nothing (never every user)', function () {
	same( [], Noodled_Notes::titles( [], [] ), 'empty scope' );
	ok( str_contains( end( $GLOBALS['wpdb']->queries ), 'IN (0) OR t.id IN (0)' ), 'empty scope did not use the 0 rule' );
} );

scenario( 'titles: ids are cast to integers, never interpolated raw', function () {
	$rows = Noodled_Notes::titles( [ '10) OR 1=1 -- ' ], [ '5abc' ] );
	same( [ 7, 5, 1 ], array_column( $rows, 'id' ), 'ids after casting' );
} );

scenario( 'titles: row shape is id, title, notebook, 16-char modified, pinned, tags; no body', function () {
	$rows = Noodled_Notes::titles( [ 10 ], [] );
	same( [ 'id', 'title', 'notebook', 'modified', 'pinned', 'tags' ], array_keys( $rows[0] ), 'keys' );
	same( '2026-01-07 10:00', $rows[0]['modified'], 'modified' );
	same( true, $rows[0]['pinned'], 'pinned' );
	same( 'Locked note', $rows[0]['title'], 'a locked note still contributes its title' );
} );

// ── tags_of(), through titles() ──

function tags_for( string $body ): array {
	$GLOBALS['wpdb']->notes = [];
	note( 1, 10, 'T', $body );
	return Noodled_Notes::titles( [ 10 ], [] )[0]['tags'];
}

scenario( 'tags: inline #tags, unique, in order of first use', function () {
	same( [ 'first', 'mine', 'Work-2', 'snake_case' ], tags_for( "#first line\nhello #mine and #Work-2 #mine\n- #snake_case" ), 'tags' );
	same( [ 'tag' ], tags_for( 'ends with #tag.' ), 'trailing punctuation is not part of the tag' );
} );

scenario( 'tags: a # needs whitespace or line start before it, and a letter after it', function () {
	same( [], tags_for( 'a#glued url.com/#anchor #1st #9 # space' ), 'no tags' );
	same( [ 'ok' ], tags_for( "#123\n#ok" ), 'digit-first skipped, next line counted' );
} );

scenario( 'tags: nothing inside fenced code', function () {
	same( [ 'outside', 'after' ], tags_for( "#outside\n```\n#inside code\n```\ntext #after" ), 'fenced code ignored' );
} );

scenario( 'tags: locked notes have none (ciphertext never parsed)', function () {
	same( [], tags_for( 'noodled:enc:v1:abc #looks-like-a-tag' ), 'locked body' );
	$rows = Noodled_Notes::titles( [ 10 ], [] );
	same( 'T', $rows[0]['title'], 'locked note still has its title' );
} );

scenario( 'tags: list rows from search() carry tags, locked rows none', function () {
	$rows = Noodled_Notes::search( 'note', [ 10, 20 ], [ 5 ] );
	$by = array_column( $rows, 'tags', 'id' );
	same( [ 'mine' ], $by[1] ?? null, 'note 1 tags' );
	same( [], $by[7] ?? null, 'locked note 7 tags' );
	ok( ! isset( $by[4] ), 'note 4 (no access) returned' );
} );

// ── search() filters ──

function last_query(): string { $q = $GLOBALS['wpdb']->queries; return (string) end( $q ); }

scenario( 'search: notebook filter is a bound subquery and stays inside the scope', function () {
	$rows = Noodled_Notes::search( '', [ 10, 20 ], [ 5 ], [ 'notebook' => 'Someone else' ] );
	same( [ 5 ], array_column( $rows, 'id' ), 'only the directly shared note of that notebook' );
	ok( str_contains( last_query(), " AND notebook_id IN (SELECT id FROM wp_noodled_notebooks WHERE name = 'Someone else')" ), 'notebook clause: ' . last_query() );
	ok( str_contains( last_query(), '(notebook_id IN (10,20) OR id IN (5))' ), 'scope clause kept' );
	Noodled_Notes::search( '', [ 10 ], [], [ 'notebook' => "x' OR '1'='1" ] );
	ok( str_contains( last_query(), "name = 'x\\' OR \\'1\\'=\\'1'" ), 'notebook value is escaped by prepare: ' . last_query() );
} );

scenario( 'search: days filter compares modified_at to a UTC cutoff', function () {
	$GLOBALS['wpdb']->notes[1]['modified_at'] = gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 );
	$GLOBALS['wpdb']->notes[3]['modified_at'] = gmdate( 'Y-m-d H:i:s', time() - 20 * 86400 );
	$rows = Noodled_Notes::search( '', [ 10, 20 ], [], [ 'days' => 7 ] );
	same( [ 1 ], array_column( $rows, 'id' ), 'only the note modified within 7 days' );
	ok( preg_match( "/ AND modified_at >= '(\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d)'/", last_query(), $m ) === 1, 'days clause: ' . last_query() );
	ok( abs( strtotime( ( $m[1] ?? '' ) . ' UTC' ) - ( time() - 7 * 86400 ) ) < 5, 'cutoff is 7 days ago (UTC): ' . ( $m[1] ?? '' ) );
} );

scenario( 'search: tag filter is a bound REGEXP that matches whole tags only', function () {
	note( 8, 10, 'Garden', 'plant #garden.' );
	note( 9, 10, 'Gardening', 'weekly #gardening' );
	note( 11, 10, 'Glued', 'x#garden' );
	$rows = Noodled_Notes::search( '', [ 10 ], [], [ 'tag' => 'garden' ] );
	same( [ 8 ], array_column( $rows, 'id' ), 'whole-tag matches' );
	ok( str_contains( last_query(), " AND body REGEXP '(^|[[:space:]])#garden([^A-Za-z0-9_-]|$)'" ), 'tag clause: ' . last_query() );
} );

scenario( 'search: an invalid tag is rejected, never interpolated', function () {
	foreach ( [ "x' OR 1=1 -- ", '1abc', 'two words', 'ok%', "garden\n", '#garden', 'a)|(b' ] as $bad ) {
		$before = count( $GLOBALS['wpdb']->queries );
		same( [], Noodled_Notes::search( 'note', [ 10 ], [], [ 'tag' => $bad ] ), 'invalid tag ' . var_export( $bad, true ) );
		same( $before, count( $GLOBALS['wpdb']->queries ), 'no query issued for ' . var_export( $bad, true ) );
	}
} );

scenario( 'search: empty text skips MATCH and LIKE; no text and no filter returns nothing', function () {
	$before = count( $GLOBALS['wpdb']->queries );
	same( [], Noodled_Notes::search( '', [ 10 ], [] ), 'no text, no filter' );
	same( $before, count( $GLOBALS['wpdb']->queries ), 'no query issued' );
	Noodled_Notes::search( '', [ 10 ], [], [ 'tag' => 'mine' ] );
	ok( ! str_contains( last_query(), 'MATCH' ) && ! str_contains( last_query(), 'LIKE' ), 'text clauses present: ' . last_query() );
} );

scenario( 'search: text plus filters binds every value in order', function () {
	note( 8, 10, 'Pump log', 'serviced the pump #garden' );
	note( 9, 20, 'Pump invoice', 'paid for the pump' );
	$rows = Noodled_Notes::search( 'pump', [ 10, 20 ], [], [ 'notebook' => 'Mine', 'tag' => 'garden' ] );
	same( [ 8 ], array_column( $rows, 'id' ), 'text + notebook + tag' );
	ok( str_contains( last_query(), "name = 'Mine') AND body REGEXP '(^|[[:space:]])#garden([^A-Za-z0-9_-]|$)' AND MATCH(title, body) AGAINST('*pump*' IN BOOLEAN MODE)" ), 'clause order / binding: ' . last_query() );
	$rows = Noodled_Notes::search( 'pu', [ 10, 20 ], [], [ 'tag' => 'garden' ] );
	same( [ 8 ], array_column( $rows, 'id' ), 'short text uses LIKE with the filter' );
	ok( str_contains( last_query(), ' AND body REGEXP ' ) && str_contains( last_query(), "title LIKE '%pu%'" ), 'LIKE query: ' . last_query() );
} );

scenario( 'search: trashed notes never match a filter', function () {
	$GLOBALS['wpdb']->notes[2]['body'] = 'gone #mine';
	$ids = array_column( Noodled_Notes::search( '', [ 10 ], [], [ 'tag' => 'mine' ] ), 'id' );
	same( [ 1 ], $ids, 'trashed note 2 excluded' );
} );

// ── Summary ──

$failed = count( array_filter( $results, static fn( $r ) => $r[1] ) );
echo "\n" . ( count( $results ) - $failed ) . " passed, {$failed} failed (" . count( $results ) . " scenarios)\n";
exit( $failed ? 1 : 0 );
