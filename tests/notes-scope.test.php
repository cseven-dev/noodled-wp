<?php
/**
 * Unit test for the scoping of the cross-notebook note queries
 * (Noodled_Notes::titles), no WordPress.
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
		if ( preg_match( '/^SELECT t\.id, t\.title, t\.modified_at, t\.pinned, n\.name AS nb_name FROM wp_noodled_notes t LEFT JOIN wp_noodled_notebooks n ON n\.id = t\.notebook_id WHERE t\.deleted_at IS NULL AND \(t\.notebook_id IN \(([\d,]+)\) OR t\.id IN \(([\d,]+)\)\) ORDER BY t\.modified_at DESC$/', $q, $m ) ) {
			return array_map( fn( $n ) => [
				'id' => $n['id'], 'title' => $n['title'], 'modified_at' => $n['modified_at'], 'pinned' => $n['pinned'],
				'nb_name' => $this->notebooks[ $n['notebook_id'] ] ?? null,
			], $this->scoped( $m[1], $m[2] ) );
		}
		throw new Exception( "get_results: unknown query: $q" );
	}
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

scenario( 'titles: row shape is id, title, notebook, 16-char modified, pinned; no body', function () {
	$rows = Noodled_Notes::titles( [ 10 ], [] );
	same( [ 'id', 'title', 'notebook', 'modified', 'pinned' ], array_keys( $rows[0] ), 'keys' );
	same( '2026-01-07 10:00', $rows[0]['modified'], 'modified' );
	same( true, $rows[0]['pinned'], 'pinned' );
	same( 'Locked note', $rows[0]['title'], 'a locked note still contributes its title' );
} );

// ── Summary ──

$failed = count( array_filter( $results, static fn( $r ) => $r[1] ) );
echo "\n" . ( count( $results ) - $failed ) . " passed, {$failed} failed (" . count( $results ) . " scenarios)\n";
exit( $failed ? 1 : 0 );
