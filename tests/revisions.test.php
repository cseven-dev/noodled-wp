<?php
/**
 * Unit test for version history (Noodled_Notes revisions), no WordPress.
 *
 *   php tests/revisions.test.php      (exit 0 only when every scenario passes)
 *
 * Loads the REAL class-noodled-notes.php. Stubs: a handful of WordPress
 * functions, Noodled_Notebooks / Noodled_Attachments, and a fake $wpdb backed by
 * PHP arrays that understands exactly the queries the revision code issues (and
 * throws on any other).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

// ── WordPress stubs ──

$GLOBALS['NOW'] = gmdate( 'Y-m-d H:i:s' );

function __( $s, $d = null ) { return $s; }
function current_time( $type, $gmt = 0 ) { return $GLOBALS['NOW']; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function wp_generate_password( $n = 12, $special = true ) { return substr( md5( (string) mt_rand() ), 0, $n ); }

class Noodled_Notebooks {
	public static function get_by_id( int $id ): ?array { return [ 'id' => $id, 'name' => 'General' ]; }
}
class Noodled_Attachments {
	public static function delete_for_note( int $id ): void {}
}

// ── Fake $wpdb: notes + revisions tables ──

class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $notes = [];
	public $revisions = [];
	private $seq = 0;

	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
		if ( preg_match_all( '/%[sd]/', $query ) !== count( $args ) ) throw new Exception( "prepare: placeholder count mismatch: $query" );
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( $args, &$i ) {
			$v = $args[ $i++ ];
			return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $query );
	}

	// Revisions of a note, newest first.
	private function revs_of( int $note_id ): array {
		$r = array_values( array_filter( $this->revisions, static fn( $x ) => $x['note_id'] === $note_id ) );
		usort( $r, static fn( $a, $b ) => $b['id'] <=> $a['id'] );
		return $r;
	}

	public function get_row( $q, $out = null ) {
		if ( preg_match( '/^SELECT (\*|title, body) FROM wp_noodled_notes WHERE id = (\d+)$/', $q, $m ) ) {
			$n = $this->notes[ (int) $m[2] ] ?? null;
			if ( ! $n ) return null;
			return $m[1] === '*' ? $n : [ 'title' => $n['title'], 'body' => $n['body'] ];
		}
		if ( preg_match( '/^SELECT title, body, created_at FROM wp_noodled_revisions WHERE note_id = (\d+) ORDER BY id DESC LIMIT 1$/', $q, $m ) ) {
			$r = $this->revs_of( (int) $m[1] );
			return $r ? [ 'title' => $r[0]['title'], 'body' => $r[0]['body'], 'created_at' => $r[0]['created_at'] ] : null;
		}
		throw new Exception( "get_row: unknown query: $q" );
	}

	public function get_var( $q ) {
		if ( preg_match( '/^SELECT body FROM wp_noodled_notes WHERE id = (\d+)$/', $q, $m ) ) {
			return $this->notes[ (int) $m[1] ]['body'] ?? null;
		}
		throw new Exception( "get_var: unknown query: $q" );
	}

	public function get_col( $q ) {
		if ( preg_match( '/^SELECT id FROM wp_noodled_revisions WHERE note_id = (\d+) ORDER BY id DESC LIMIT (\d+) OFFSET (\d+)$/', $q, $m ) ) {
			return array_column( array_slice( $this->revs_of( (int) $m[1] ), (int) $m[3], (int) $m[2] ), 'id' );
		}
		if ( preg_match( '/^SELECT id FROM wp_noodled_notes WHERE deleted_at IS NOT NULL AND \(notebook_id IN \(([\d,]+)\) OR deleted_from IN \(([\d,]+)\)\)$/', $q, $m ) ) {
			$nbs = array_map( 'intval', explode( ',', $m[1] ) );
			return array_values( array_map( static fn( $n ) => $n['id'], array_filter( $this->notes,
				static fn( $n ) => $n['deleted_at'] !== null && in_array( (int) $n['notebook_id'], $nbs, true ) ) ) );
		}
		throw new Exception( "get_col: unknown query: $q" );
	}

	public function get_results( $q, $out = null ) {
		if ( preg_match( '/^SELECT id, title, body, reason, created_at FROM wp_noodled_revisions WHERE note_id = (\d+) ORDER BY id DESC LIMIT (\d+)$/', $q, $m ) ) {
			return array_slice( $this->revs_of( (int) $m[1] ), 0, (int) $m[2] );
		}
		throw new Exception( "get_results: unknown query: $q" );
	}

	public function query( $q ) {
		if ( preg_match( '/^DELETE FROM wp_noodled_revisions WHERE (id|note_id) IN \(([\d,]+)\)$/', $q, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[2] ) );
			$col = $m[1];
			$before = count( $this->revisions );
			$this->revisions = array_values( array_filter( $this->revisions, static fn( $r ) => ! in_array( $r[ $col ], $ids, true ) ) );
			return $before - count( $this->revisions );
		}
		if ( preg_match( '/^DELETE FROM wp_noodled_notes WHERE deleted_at IS NOT NULL AND \(notebook_id IN \(([\d,]+)\) OR deleted_from IN \(([\d,]+)\)\)$/', $q, $m ) ) {
			$nbs = array_map( 'intval', explode( ',', $m[1] ) );
			$this->notes = array_filter( $this->notes, static fn( $n ) => ! ( $n['deleted_at'] !== null && in_array( (int) $n['notebook_id'], $nbs, true ) ) );
			return 1;
		}
		throw new Exception( "query: unknown query: $q" );
	}

	public function insert( $table, $data ) {
		if ( $table !== 'wp_noodled_revisions' ) throw new Exception( "insert: unknown table $table" );
		foreach ( array_keys( $data ) as $c ) {
			if ( ! in_array( $c, [ 'note_id', 'title', 'body', 'reason', 'created_at' ], true ) ) throw new Exception( "insert: unknown column $c" );
		}
		$row = array_merge( [ 'reason' => '' ], $data, [ 'id' => ++$this->seq ] );
		$row['note_id'] = (int) $row['note_id'];
		$this->revisions[] = $row;
		$this->insert_id = $row['id'];
		return 1;
	}

	public function update( $table, $data, $where ) {
		if ( $table !== 'wp_noodled_notes' || array_keys( $where ) !== [ 'id' ] ) throw new Exception( "update: unknown target $table" );
		$id = (int) $where['id'];
		if ( ! isset( $this->notes[ $id ] ) ) return 0;
		$this->notes[ $id ] = array_merge( $this->notes[ $id ], $data );
		return 1;
	}

	public function delete( $table, $where ) {
		if ( $table === 'wp_noodled_revisions' && array_keys( $where ) === [ 'note_id' ] ) {
			return $this->query( 'DELETE FROM wp_noodled_revisions WHERE note_id IN (' . (int) $where['note_id'] . ')' );
		}
		if ( $table === 'wp_noodled_notes' && array_keys( $where ) === [ 'id' ] ) {
			unset( $this->notes[ (int) $where['id'] ] );
			return 1;
		}
		throw new Exception( "delete: unknown target $table" );
	}
}

require __DIR__ . '/../includes/class-noodled-notes.php';

// ── Harness ──

$results = [];
$fails   = [];

// Set the server clock to $ago seconds before now (UTC).
function at( int $ago ): void { $GLOBALS['NOW'] = gmdate( 'Y-m-d H:i:s', time() - $ago ); }
function note( int $id, string $body, array $f = [] ): void {
	$GLOBALS['wpdb']->notes[ $id ] = array_merge( [
		'id' => $id, 'slug' => 'n' . $id, 'notebook_id' => 10, 'title' => 'Note ' . $id, 'body' => $body, 'source' => '',
		'pinned' => 0, 'sha' => '', 'created_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
		'deleted_at' => null, 'deleted_from' => null,
	], $f );
}
function revs( int $id ): array { return Noodled_Notes::get_revisions( $id ); }
function ok( $cond, string $msg ): void { if ( ! $cond ) $GLOBALS['fails'][] = $msg; }
function same( $want, $got, string $msg ): void { ok( $want === $got, $msg . ' (want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ')' ); }

function scenario( string $name, callable $fn ): void {
	global $results, $fails;
	$GLOBALS['wpdb'] = new FakeWpdb();
	at( 0 );
	$fails = [];
	try { $fn(); } catch ( Throwable $e ) { $fails[] = 'exception: ' . $e->getMessage() . ' @' . $e->getLine(); }
	$results[] = [ $name, $fails ];
	echo ( $fails ? 'FAIL' : 'PASS' ) . "  $name" . ( $fails ? "\n      - " . implode( "\n      - ", $fails ) : '' ) . "\n";
}

// ── Scenarios ──

scenario( 'first edit snapshots with reason edit', function () {
	note( 1, 'v1' );
	Noodled_Notes::update( 1, [ 'body' => 'v2' ] );
	$r = revs( 1 );
	same( 1, count( $r ), 'one revision' );
	same( 'v1', $r[0]['body'] ?? null, 'snapshot holds the pre-edit body' );
	same( 'edit', $r[0]['reason'] ?? null, 'reason' );
	same( 'v2', $GLOBALS['wpdb']->notes[1]['body'], 'note updated' );
} );

scenario( 'throttle skips a plain edit within 10 minutes of the last snapshot', function () {
	note( 1, 'v1' );
	at( 300 );
	Noodled_Notes::update( 1, [ 'body' => 'v2' ] );   // snapshot of v1, 5 minutes ago
	at( 0 );
	Noodled_Notes::update( 1, [ 'body' => 'v3' ] );   // v2 not snapshotted: last one is under 10 minutes old
	same( [ 'v1' ], array_column( revs( 1 ), 'body' ), 'only the first snapshot kept' );
	same( 'v3', $GLOBALS['wpdb']->notes[1]['body'], 'note still updated' );
} );

scenario( 'throttle lets a plain edit through once 10 minutes have passed', function () {
	note( 1, 'v1' );
	at( 700 );
	Noodled_Notes::update( 1, [ 'body' => 'v2' ] );
	at( 0 );
	Noodled_Notes::update( 1, [ 'body' => 'v3' ] );
	same( [ 'v2', 'v1' ], array_column( revs( 1 ), 'body' ), 'both snapshots kept, newest first' );
} );

scenario( 'a forced reason ignores the throttle', function () {
	note( 1, 'v1' );
	Noodled_Notes::update( 1, [ 'body' => 'v2' ] );
	Noodled_Notes::update( 1, [ 'body' => 'v3' ], 'restore' );
	Noodled_Notes::update( 1, [ 'body' => 'v4' ], 'conflict' );
	same( [ 'v3', 'v2', 'v1' ], array_column( revs( 1 ), 'body' ), 'every forced save snapshotted' );
	same( [ 'conflict', 'restore', 'edit' ], array_column( revs( 1 ), 'reason' ), 'reasons recorded' );
} );

scenario( 'duplicates are skipped', function () {
	note( 1, 'same' );
	Noodled_Notes::save_revision( 1, 'sync' );
	Noodled_Notes::save_revision( 1, 'move' );
	Noodled_Notes::update( 1, [ 'body' => 'same' ], 'restore' );
	same( 1, count( revs( 1 ) ), 'identical content snapshotted once' );
	same( 'sync', revs( 1 )[0]['reason'], 'the first reason kept' );
} );

scenario( 'prune keeps the newest 30', function () {
	note( 1, 'v0' );
	for ( $i = 1; $i <= 35; $i++ ) Noodled_Notes::update( 1, [ 'body' => 'v' . $i ], 'restore' );
	same( 30, count( $GLOBALS['wpdb']->revisions ), 'rows kept in the table' );
	$r = revs( 1 );
	same( 30, count( $r ), 'get_revisions returns 30' );
	same( 'v34', $r[0]['body'], 'newest kept' );
	same( 'v5', $r[29]['body'], 'oldest kept' );
} );

scenario( 'prune and get_revisions stay per note', function () {
	note( 1, 'a0' );
	note( 2, 'b0' );
	Noodled_Notes::update( 2, [ 'body' => 'b1' ] );
	for ( $i = 1; $i <= 32; $i++ ) Noodled_Notes::update( 1, [ 'body' => 'a' . $i ], 'restore' );
	same( [ 'b0' ], array_column( revs( 2 ), 'body' ), 'other note untouched' );
} );

scenario( 'locking purges the plaintext versions and skips the snapshot', function () {
	note( 1, 'secret v1' );
	note( 2, 'other' );
	Noodled_Notes::update( 1, [ 'body' => 'secret v2' ], 'restore' );
	Noodled_Notes::update( 1, [ 'body' => 'secret v3' ], 'restore' );
	Noodled_Notes::update( 2, [ 'body' => 'other v2' ] );
	Noodled_Notes::update( 1, [ 'body' => 'noodled:enc:v1:CIPHER' ] );
	same( [], revs( 1 ), 'no plaintext versions left' );
	same( 'noodled:enc:v1:CIPHER', $GLOBALS['wpdb']->notes[1]['body'], 'locked body saved' );
	same( [ 'other' ], array_column( revs( 2 ), 'body' ), 'other notes keep their history' );
	// Saving a new ciphertext over a locked note is a normal (snapshotted) edit.
	Noodled_Notes::update( 1, [ 'body' => 'noodled:enc:v1:CIPHER2' ], 'restore' );
	same( [ 'noodled:enc:v1:CIPHER' ], array_column( revs( 1 ), 'body' ), 'locked-to-locked save keeps a snapshot' );
} );

scenario( 'move and sync snapshots record their reason', function () {
	note( 1, 'v1' );
	Noodled_Notes::save_revision( 1, 'move' );
	$GLOBALS['wpdb']->notes[1]['body'] = 'v2';
	Noodled_Notes::save_revision( 1, 'sync' );
	same( [ 'sync', 'move' ], array_column( revs( 1 ), 'reason' ), 'reasons' );
} );

scenario( 'permanent delete and empty trash drop the revisions', function () {
	note( 1, 'v1', [ 'deleted_at' => '2026-01-02 00:00:00' ] );
	note( 2, 'w1', [ 'deleted_at' => '2026-01-02 00:00:00' ] );
	note( 3, 'x1' );
	foreach ( [ 1, 2, 3 ] as $id ) Noodled_Notes::save_revision( $id, 'edit' );
	Noodled_Notes::permanent_delete( 1 );
	same( [], revs( 1 ), 'permanent delete dropped its revisions' );
	Noodled_Notes::empty_trash( [ 10 ] );
	same( [], revs( 2 ), 'empty trash dropped the trashed note revisions' );
	same( 1, count( revs( 3 ) ), 'live note keeps its revisions' );
} );

// ── Summary ──

$failed = count( array_filter( $results, static fn( $r ) => $r[1] ) );
echo "\n" . ( count( $results ) - $failed ) . " passed, {$failed} failed (" . count( $results ) . " scenarios)\n";
exit( $failed ? 1 : 0 );
