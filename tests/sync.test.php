<?php
/**
 * Unit test for the GitHub backup engine (Noodled_Sync), no WordPress, no network.
 *
 *   php tests/sync.test.php      (exit 0 only when every scenario passes)
 *
 * Loads the REAL class-noodled-sync.php, class-noodled-frontmatter.php and
 * class-noodled-notebooks.php. Stubs: a handful of WordPress functions, WP_Error,
 * Noodled_Notes (slug + save_revision only), a fake $wpdb backed by PHP arrays
 * that understands exactly the queries this code issues (and throws on any
 * other), and a fake Noodled_GitHub with an in-memory repo.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

// ── WordPress stubs ──

$GLOBALS['NOW']     = '2026-06-01 12:00:00';
$GLOBALS['actions'] = [];

function __( $s, $d = null ) { return $s; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function current_time( $type, $gmt = 0 ) { return $GLOBALS['NOW']; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function wp_generate_password( $n = 12, $special = true ) { return substr( md5( (string) mt_rand() ), 0, $n ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class WP_Error {
	private $code; private $msg; private $data;
	public function __construct( $code = '', $msg = '', $data = '' ) { $this->code = $code; $this->msg = $msg; $this->data = $data; }
	public function get_error_message() { return $this->msg; }
	public function get_error_data() { return $this->data; }
}

// Minimal Noodled_Notes: the real class pulls in many more queries than sync needs.
class Noodled_Notes {
	public static function slug( string $title ): string {
		$slug = sanitize_title( $title );
		$slug = preg_replace( '/[^a-z0-9\-]/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', $slug );
		return trim( $slug, '-' ) ?: 'note-' . wp_generate_password( 8, false );
	}
	public static function save_revision( int $id ): void {
		global $wpdb;
		$n = $wpdb->t['notes'][ $id ];
		$wpdb->revisions[] = [ 'note_id' => $id, 'title' => $n['title'], 'body' => $n['body'] ];
	}
}

// ── Fake GitHub (in-memory repo with the Contents API's sha rules) ──

function blob_sha( string $content ): string { return sha1( 'blob ' . strlen( $content ) . "\0" . $content ); }

class Noodled_GitHub {
	public static $files = [];      // path => content
	public static $calls = [];      // [ method, path, sha ]
	public static $fail_next = 0;   // next N calls fail with 500
	public static $configured = true;

	public static function reset(): void { self::$files = []; self::$calls = []; self::$fail_next = 0; self::$configured = true; }
	public static function is_configured(): bool { return self::$configured; }

	private static function failing() {
		if ( self::$fail_next > 0 ) { self::$fail_next--; return new WP_Error( 'github_api', 'Server Error', [ 'status' => 500 ] ); }
		return null;
	}
	public static function get_tree() {
		$tree = []; $dirs = [];
		foreach ( self::$files as $p => $c ) {
			$dirs[ explode( '/', $p )[0] ] = true;
			$tree[] = [ 'path' => $p, 'type' => 'blob', 'sha' => blob_sha( $c ) ];
		}
		foreach ( array_keys( $dirs ) as $d ) $tree[] = [ 'path' => $d, 'type' => 'tree', 'sha' => 'x' ];
		return $tree;
	}
	public static function get_file( string $path ) {
		self::$calls[] = [ 'GET', $path, '' ];
		if ( $e = self::failing() ) return $e;
		if ( ! isset( self::$files[ $path ] ) ) return new WP_Error( 'github_api', 'Not Found', [ 'status' => 404 ] );
		return [ 'content' => self::$files[ $path ], 'sha' => blob_sha( self::$files[ $path ] ), 'path' => $path ];
	}
	public static function put_file( string $path, string $content, string $message, string $sha = '' ) {
		self::$calls[] = [ 'PUT', $path, $sha ];
		if ( $e = self::failing() ) return $e;
		if ( isset( self::$files[ $path ] ) ) {
			if ( $sha === '' ) return new WP_Error( 'github_api', 'Invalid request. "sha" wasn\'t supplied.', [ 'status' => 422 ] );
			if ( $sha !== blob_sha( self::$files[ $path ] ) ) return new WP_Error( 'github_api', 'sha does not match', [ 'status' => 409 ] );
		} elseif ( $sha !== '' ) {
			// ASSUMPTION: a sha for a path that does not exist is rejected like a stale one.
			return new WP_Error( 'github_api', 'sha does not match', [ 'status' => 409 ] );
		}
		self::$files[ $path ] = $content;
		return [ 'content' => [ 'sha' => blob_sha( $content ), 'path' => $path ] ];
	}
	public static function delete_file( string $path, string $sha, string $message ) {
		self::$calls[] = [ 'DELETE', $path, $sha ];
		if ( $e = self::failing() ) return $e;
		if ( ! isset( self::$files[ $path ] ) ) return new WP_Error( 'github_api', 'Not Found', [ 'status' => 404 ] );
		if ( $sha !== blob_sha( self::$files[ $path ] ) ) return new WP_Error( 'github_api', 'sha does not match', [ 'status' => 409 ] );
		unset( self::$files[ $path ] );
		return [ 'commit' => [] ];
	}
	public static function api_calls(): int { return count( self::$calls ); }
}

// ── Fake $wpdb ──

class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $t = [ 'notes' => [], 'notebooks' => [], 'users' => [] ];
	public $revisions = [];
	private $seq = 1000;

	const DEFAULTS = [
		'notes' => [
			'id' => 0, 'slug' => '', 'notebook_id' => 0, 'title' => '', 'body' => null, 'source' => '',
			'pinned' => 0, 'sha' => '', 'gh_path' => '', 'push_pending' => 0, 'push_attempts' => 0,
			'push_error' => '', 'meta_json' => null, 'created_at' => '2026-01-01 00:00:00',
			'modified_at' => '2026-01-01 00:00:00', 'deleted_at' => null, 'deleted_from' => null,
		],
		'notebooks' => [
			'id' => 0, 'name' => '', 'owner_id' => 0, 'drop_to' => 0, 'parent_id' => 0, 'sort_order' => 0,
			'color' => '', 'created_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
		],
		'users' => [ 'id' => 0, 'email' => '', 'display_name' => '', 'role' => 'member' ],
	];

	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
		if ( preg_match_all( '/%[sd]/', $query ) !== count( $args ) ) throw new Exception( "prepare: placeholder count mismatch: $query" );
		return "\x01" . json_encode( [ $query, array_values( $args ) ] );
	}

	private function table( string $name ): string {
		$short = preg_replace( '/^wp_noodled_/', '', $name );
		if ( ! isset( $this->t[ $short ] ) ) throw new Exception( "unknown table $name" );
		return $short;
	}

	private function check_cols( string $tbl, array $cols ): void {
		foreach ( $cols as $c ) {
			if ( ! array_key_exists( $c, self::DEFAULTS[ $tbl ] ) ) throw new Exception( "unknown column $tbl.$c" );
		}
	}

	public function insert( $table, $data ) {
		$tbl = $this->table( $table );
		$this->check_cols( $tbl, array_keys( $data ) );
		$row = array_merge( self::DEFAULTS[ $tbl ], $data );
		$row['id'] = ++$this->seq;
		$this->t[ $tbl ][ $row['id'] ] = $row;
		$this->insert_id = $row['id'];
		return 1;
	}

	public function update( $table, $data, $where ) {
		$tbl = $this->table( $table );
		$this->check_cols( $tbl, array_merge( array_keys( $data ), array_keys( $where ) ) );
		$n = 0;
		foreach ( $this->t[ $tbl ] as $id => $row ) {
			foreach ( $where as $k => $v ) {
				if ( $v === null ? $row[ $k ] !== null : (string) $row[ $k ] !== (string) $v ) continue 2;
			}
			$this->t[ $tbl ][ $id ] = array_merge( $row, $data );
			$n++;
		}
		return $n;
	}

	// WordPress returns every column as a string (NULL stays null).
	private static function str( array $row ): array {
		return array_map( static fn( $v ) => $v === null ? null : (string) $v, $row );
	}

	// Notes joined to their notebook and its owner: [ note, notebook, user ].
	private function joined(): array {
		$out = [];
		foreach ( $this->t['notes'] as $n ) {
			$b = $this->t['notebooks'][ $n['notebook_id'] ] ?? null;
			$u = $b ? ( $this->t['users'][ $b['owner_id'] ] ?? null ) : null;
			if ( $b && $u ) $out[] = [ $n, $b, $u ];
		}
		return $out;
	}

	private static function blank_sha( $n ): bool { return $n['sha'] === '' || $n['sha'] === null; }

	private function run( string $q ) {
		$args = [];
		if ( $q !== '' && $q[0] === "\x01" ) list( $q, $args ) = json_decode( substr( $q, 1 ), true );
		$q = preg_replace( '/\s+/', ' ', trim( $q ) );
		$J = 'wp_noodled_notes n JOIN wp_noodled_notebooks b ON b.id = n.notebook_id JOIN wp_noodled_users u ON u.id = b.owner_id';
		$notes = &$this->t['notes'];

		switch ( $q ) {
			case 'SELECT * FROM wp_noodled_notes WHERE id = %d':
				return isset( $notes[ $args[0] ] ) ? [ $notes[ $args[0] ] ] : [];
			case 'SELECT notebook_id FROM wp_noodled_notes WHERE id = %d':
				return isset( $notes[ $args[0] ] ) ? [ [ 'notebook_id' => $notes[ $args[0] ]['notebook_id'] ] ] : [];
			case 'SELECT role FROM wp_noodled_users WHERE id = %d':
				return isset( $this->t['users'][ $args[0] ] ) ? [ [ 'role' => $this->t['users'][ $args[0] ]['role'] ] ] : [];
			case "SELECT id FROM wp_noodled_users WHERE role = 'admin' ORDER BY id ASC LIMIT 1":
				$ids = array_keys( array_filter( $this->t['users'], static fn( $u ) => $u['role'] === 'admin' ) );
				sort( $ids );
				return $ids ? [ [ 'id' => $ids[0] ] ] : [];
			case 'SELECT * FROM wp_noodled_notebooks WHERE id = %d':
				return isset( $this->t['notebooks'][ $args[0] ] ) ? [ $this->t['notebooks'][ $args[0] ] ] : [];
			case 'SELECT * FROM wp_noodled_notebooks WHERE name = %s AND owner_id = %d':
				return array_values( array_filter( $this->t['notebooks'], static fn( $b ) => $b['name'] === $args[0] && (int) $b['owner_id'] === (int) $args[1] ) );
			case "SELECT n.id, n.title, n.slug, n.sha, n.gh_path FROM $J WHERE b.name = %s AND u.role = 'admin' AND n.id != %d":
				$out = [];
				foreach ( $this->joined() as [ $n, $b, $u ] ) {
					if ( $b['name'] === $args[0] && $u['role'] === 'admin' && (int) $n['id'] !== (int) $args[1] ) {
						$out[] = array_intersect_key( $n, array_flip( [ 'id', 'title', 'slug', 'sha', 'gh_path' ] ) );
					}
				}
				return $out;
			case 'UPDATE wp_noodled_notes SET push_pending = 1, push_attempts = push_attempts + 1, push_error = %s WHERE id = %d':
				if ( ! isset( $notes[ $args[1] ] ) ) return 0;
				$notes[ $args[1] ]['push_pending'] = 1;
				$notes[ $args[1] ]['push_attempts']++;
				$notes[ $args[1] ]['push_error'] = $args[0];
				return 1;
			case 'SELECT id FROM wp_noodled_notes WHERE push_pending = 1 AND push_attempts < 8 ORDER BY modified_at ASC, id ASC LIMIT %d':
				$rows = array_filter( $notes, static fn( $n ) => (int) $n['push_pending'] === 1 && (int) $n['push_attempts'] < 8 );
				usort( $rows, static fn( $a, $b ) => [ $a['modified_at'], $a['id'] ] <=> [ $b['modified_at'], $b['id'] ] );
				return array_map( static fn( $n ) => [ 'id' => $n['id'] ], array_slice( $rows, 0, (int) $args[0] ) );
			case 'SELECT COUNT(*) FROM wp_noodled_notes WHERE push_pending = 1 AND push_attempts < 8':
				return [ [ 'c' => count( array_filter( $notes, static fn( $n ) => (int) $n['push_pending'] === 1 && (int) $n['push_attempts'] < 8 ) ) ] ];
			case "UPDATE $J SET n.push_pending = 1, n.push_attempts = 0 WHERE n.deleted_at IS NULL AND u.role = 'admin'":
				$c = 0;
				foreach ( $this->joined() as [ $n, $b, $u ] ) {
					if ( $n['deleted_at'] === null && $u['role'] === 'admin' ) { $notes[ $n['id'] ]['push_pending'] = 1; $notes[ $n['id'] ]['push_attempts'] = 0; $c++; }
				}
				return $c;
			case "UPDATE $J SET n.push_pending = 1 WHERE n.deleted_at IS NULL AND u.role = 'admin' AND ( n.sha = '' OR n.sha IS NULL )":
				$c = 0;
				foreach ( $this->joined() as [ $n, $b, $u ] ) {
					if ( $n['deleted_at'] === null && $u['role'] === 'admin' && self::blank_sha( $n ) ) { $notes[ $n['id'] ]['push_pending'] = 1; $c++; }
				}
				return $c;
			case "SELECT COUNT(*) FROM $J WHERE n.deleted_at IS NULL AND u.role = 'admin' AND ( n.sha = '' OR n.sha IS NULL OR n.push_pending = 1 )":
				$c = 0;
				foreach ( $this->joined() as [ $n, $b, $u ] ) {
					if ( $n['deleted_at'] === null && $u['role'] === 'admin' && ( self::blank_sha( $n ) || (int) $n['push_pending'] === 1 ) ) $c++;
				}
				return [ [ 'c' => $c ] ];
			case "SELECT n.id, n.title, b.name AS notebook, n.push_error AS error, n.push_attempts AS attempts FROM $J WHERE n.deleted_at IS NULL AND u.role = 'admin' AND n.push_attempts > 0 ORDER BY n.modified_at DESC LIMIT 20":
				$out = [];
				foreach ( $this->joined() as [ $n, $b, $u ] ) {
					if ( $n['deleted_at'] === null && $u['role'] === 'admin' && (int) $n['push_attempts'] > 0 ) {
						$out[] = [ 'id' => $n['id'], 'title' => $n['title'], 'notebook' => $b['name'], 'error' => $n['push_error'], 'attempts' => $n['push_attempts'], '_m' => $n['modified_at'] ];
					}
				}
				usort( $out, static fn( $a, $b ) => strcmp( $b['_m'], $a['_m'] ) );
				return array_map( static function ( $r ) { unset( $r['_m'] ); return $r; }, array_slice( $out, 0, 20 ) );
			case 'SELECT id FROM wp_noodled_notes WHERE sha = %s':
				return array_values( array_map( static fn( $n ) => [ 'id' => $n['id'] ], array_filter( $notes, static fn( $n ) => (string) $n['sha'] === $args[0] ) ) );
			case 'SELECT id, slug, title, body, pinned, sha, gh_path, modified_at FROM wp_noodled_notes WHERE notebook_id = %d AND deleted_at IS NULL AND ( gh_path = %s OR sha = %s OR slug = %s )':
				$out = [];
				foreach ( $notes as $n ) {
					if ( (int) $n['notebook_id'] === (int) $args[0] && $n['deleted_at'] === null
						&& ( $n['gh_path'] === $args[1] || (string) $n['sha'] === $args[2] || $n['slug'] === $args[3] ) ) {
						$out[] = array_intersect_key( $n, array_flip( [ 'id', 'slug', 'title', 'body', 'pinned', 'sha', 'gh_path', 'modified_at' ] ) );
					}
				}
				return $out;
			case 'SELECT id FROM wp_noodled_notes WHERE gh_path = %s AND notebook_id = %d AND deleted_at IS NULL LIMIT 1':
				foreach ( $notes as $n ) {
					if ( $n['gh_path'] === $args[0] && (int) $n['notebook_id'] === (int) $args[1] && $n['deleted_at'] === null ) return [ [ 'id' => $n['id'] ] ];
				}
				return [];
		}
		throw new Exception( "FakeWpdb: unrecognised query: $q" );
	}

	public function get_results( $q, $out = null ) { return array_map( [ self::class, 'str' ], $this->run( $q ) ); }
	public function get_row( $q, $out = null ) { $r = $this->get_results( $q ); return $r ? $r[0] : null; }
	public function get_col( $q ) { return array_map( static fn( $r ) => reset( $r ), $this->get_results( $q ) ); }
	public function get_var( $q ) { $r = $this->get_row( $q ); return $r ? reset( $r ) : null; }
	public function query( $q ) { return $this->run( $q ); }
}

require __DIR__ . '/../includes/class-noodled-frontmatter.php';
require __DIR__ . '/../includes/class-noodled-notebooks.php';
require __DIR__ . '/../includes/class-noodled-sync.php';

// ── Harness ──

$results = [];
$fails   = [];

function reset_world(): void {
	$GLOBALS['wpdb']    = new FakeWpdb();
	$GLOBALS['actions'] = [];
	$GLOBALS['NOW']     = '2026-06-01 12:00:00';
	Noodled_GitHub::reset();
	$r = new ReflectionClass( 'Noodled_Sync' );
	foreach ( [ 'owner_is_admin' => [], 'sync_owner' => null, 'queued' => [], 'shutdown_hooked' => false ] as $p => $v ) {
		$prop = $r->getProperty( $p );
		$prop->setAccessible( true );
		$prop->setValue( null, $v );
	}
}
function user( int $id, string $role ): void { $GLOBALS['wpdb']->t['users'][ $id ] = array_merge( FakeWpdb::DEFAULTS['users'], [ 'id' => $id, 'role' => $role ] ); }
function nb( int $id, string $name, int $owner ): void { $GLOBALS['wpdb']->t['notebooks'][ $id ] = array_merge( FakeWpdb::DEFAULTS['notebooks'], [ 'id' => $id, 'name' => $name, 'owner_id' => $owner ] ); }
function note( int $id, array $f ): void {
	$f += [ 'slug' => Noodled_Notes::slug( $f['title'] ?? 'n' ) ];
	$GLOBALS['wpdb']->t['notes'][ $id ] = array_merge( FakeWpdb::DEFAULTS['notes'], [ 'id' => $id ], $f );
}
function row( int $id ): array { return $GLOBALS['wpdb']->t['notes'][ $id ]; }
function set_row( int $id, array $f ): void { $GLOBALS['wpdb']->t['notes'][ $id ] = array_merge( row( $id ), $f ); }
function md_of( int $id ): string {
	$r = row( $id );
	return Noodled_Frontmatter::to_markdown( [ 'title' => $r['title'], 'body' => $r['body'] ?? '', 'source' => $r['source'],
		'pinned' => (bool) $r['pinned'], 'created' => $r['created_at'], 'modified' => $r['modified_at'] ] );
}
function fire_shutdown(): void { foreach ( $GLOBALS['actions']['shutdown'] ?? [] as $cb ) call_user_func( $cb ); }
function pull( string $path ): bool {
	$m = new ReflectionMethod( 'Noodled_Sync', 'pull_single_file' );
	$m->setAccessible( true );
	return $m->invoke( null, $path );
}
function remove_file( string $path ): void {
	$m = new ReflectionMethod( 'Noodled_Sync', 'remove_file_from_db' );
	$m->setAccessible( true );
	$m->invoke( null, $path );
}
function ok( $cond, string $msg ): void { if ( ! $cond ) $GLOBALS['fails'][] = $msg; }
function same( $want, $got, string $msg ): void { ok( $want === $got, $msg . ' (want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ')' ); }
function files(): array { $f = array_keys( Noodled_GitHub::$files ); sort( $f ); return $f; }

function scenario( string $name, callable $fn ): void {
	global $results, $fails;
	reset_world();
	$fails = [];
	try { $fn(); } catch ( Throwable $e ) { $fails[] = 'exception: ' . $e->getMessage() . ' @' . $e->getLine(); }
	$results[] = [ $name, $fails ];
	echo ( $fails ? 'FAIL' : 'PASS' ) . "  $name" . ( $fails ? "\n      - " . implode( "\n      - ", $fails ) : '' ) . "\n";
}

// Common world: admin 1 with "General" (10) and "Work" (11); member 2 with "General" (20).
function base(): void { user( 1, 'admin' ); user( 2, 'member' ); nb( 10, 'General', 1 ); nb( 11, 'Work', 1 ); nb( 20, 'General', 2 ); }

// ── Scenarios ──

scenario( 'first push stores sha and gh_path', function () {
	base();
	note( 100, [ 'notebook_id' => 10, 'title' => 'Hello', 'body' => 'one', 'push_pending' => 1 ] );
	$r = Noodled_Sync::push_note( 100 );
	ok( ! empty( $r['success'] ), 'push succeeded' );
	same( 'General/Hello.md', row( 100 )['gh_path'], 'gh_path' );
	same( blob_sha( md_of( 100 ) ), row( 100 )['sha'], 'sha is the git blob sha' );
	same( 0, row( 100 )['push_pending'], 'pending cleared' );
	same( md_of( 100 ), Noodled_GitHub::$files['General/Hello.md'] ?? null, 'repo content' );
} );

scenario( 'second push of an unchanged note makes no API call', function () {
	base();
	note( 100, [ 'notebook_id' => 10, 'title' => 'Hello', 'body' => 'one' ] );
	Noodled_Sync::push_note( 100 );
	$calls = Noodled_GitHub::api_calls();
	$r = Noodled_Sync::push_note( 100 );
	ok( ! empty( $r['unchanged'] ), 'reported unchanged' );
	same( $calls, Noodled_GitHub::api_calls(), 'no API call' );
} );

scenario( 'edit then push updates the file in place', function () {
	base();
	note( 100, [ 'notebook_id' => 10, 'title' => 'Hello', 'body' => 'one' ] );
	Noodled_Sync::push_note( 100 );
	set_row( 100, [ 'body' => 'two', 'modified_at' => '2026-06-01 12:05:00' ] );
	Noodled_GitHub::$calls = [];
	Noodled_Sync::push_note( 100 );
	same( [ 'General/Hello.md' ], files(), 'still one file' );
	same( md_of( 100 ), Noodled_GitHub::$files['General/Hello.md'], 'content updated' );
	same( 1, count( Noodled_GitHub::$calls ), 'one PUT with the right sha' );
} );

scenario( 'title rename moves the file (one file left)', function () {
	base();
	note( 100, [ 'notebook_id' => 10, 'title' => 'Hello', 'body' => 'one' ] );
	Noodled_Sync::push_note( 100 );
	set_row( 100, [ 'title' => 'Hello again', 'slug' => 'hello-again' ] );
	Noodled_Sync::push_note( 100 );
	same( [ 'General/Hello again.md' ], files(), 'only the new file' );
	same( 'General/Hello again.md', row( 100 )['gh_path'], 'gh_path follows' );
} );

scenario( 'notebook move moves the file (one file left)', function () {
	base();
	note( 100, [ 'notebook_id' => 10, 'title' => 'Hello', 'body' => 'one' ] );
	Noodled_Sync::push_note( 100 );
	set_row( 100, [ 'notebook_id' => 11 ] );
	Noodled_Sync::push_note( 100 );
	same( [ 'Work/Hello.md' ], files(), 'only the new file' );
} );

scenario( 'Notes::move flow (delete via gh_path, sha cleared, re-push) leaves one file', function () {
	base();
	note( 100, [ 'notebook_id' => 10, 'title' => 'Dup', 'body' => 'a' ] );
	note( 101, [ 'notebook_id' => 10, 'title' => 'Dup', 'body' => 'b' ] );
	Noodled_Sync::push_note( 100 );
	Noodled_Sync::push_note( 101 ); // lands on "General/Dup (101).md"
	$r = row( 101 );
	$del = Noodled_Sync::delete_from_github( 'General', $r['title'], $r['sha'], $r['gh_path'] );
	ok( ! empty( $del['success'] ), 'delete by gh_path succeeded' );
	set_row( 101, [ 'notebook_id' => 11, 'sha' => null ] ); // what Notes::move() writes
	Noodled_Sync::push_note( 101 );
	same( [ 'General/Dup.md', 'Work/Dup.md' ], files(), 'note 100 untouched, note 101 only in Work' );
	same( md_of( 100 ), Noodled_GitHub::$files['General/Dup.md'], 'note 100 content intact' );
} );

scenario( 'two notes with the same title get two files, neither overwrites', function () {
	base();
	note( 200, [ 'notebook_id' => 10, 'title' => 'Dup', 'body' => 'first' ] );
	note( 201, [ 'notebook_id' => 10, 'title' => 'Dup', 'body' => 'second' ] );
	Noodled_Sync::push_note( 200 );
	Noodled_Sync::push_note( 201 );
	same( [ 'General/Dup (201).md', 'General/Dup.md' ], files(), 'two files' );
	same( md_of( 200 ), Noodled_GitHub::$files['General/Dup.md'], 'first intact' );
	same( md_of( 201 ), Noodled_GitHub::$files['General/Dup (201).md'], 'second intact' );
	set_row( 200, [ 'body' => 'first edited' ] );
	Noodled_Sync::push_note( 200 );
	same( md_of( 201 ), Noodled_GitHub::$files['General/Dup (201).md'], 'second still intact after first re-push' );
} );

scenario( 'legacy duplicates: lower id keeps plain, the other gets its own file', function () {
	base();
	note( 300, [ 'notebook_id' => 10, 'title' => 'Leg', 'body' => 'older note' ] );
	note( 301, [ 'notebook_id' => 10, 'title' => 'Leg', 'body' => 'newer note' ] );
	Noodled_GitHub::$files['General/Leg.md'] = md_of( 301 );   // 301 overwrote the shared file last
	set_row( 300, [ 'sha' => str_repeat( 'a', 40 ) ] );         // stale
	set_row( 301, [ 'sha' => blob_sha( md_of( 301 ) ) ] );
	Noodled_Sync::push_note( 301 );
	Noodled_Sync::push_note( 300 );
	same( 'General/Leg.md', row( 300 )['gh_path'], 'lower id keeps plain' );
	same( 'General/Leg (301).md', row( 301 )['gh_path'], 'higher id disambiguated' );
	same( md_of( 300 ), Noodled_GitHub::$files['General/Leg.md'], 'plain holds 300' );
	same( md_of( 301 ), Noodled_GitHub::$files['General/Leg (301).md'], 'own file holds 301' );
} );

scenario( 'remote file exists but row has no sha (422) self-heals', function () {
	base();
	Noodled_GitHub::$files['General/Exists.md'] = "old remote\n";
	note( 400, [ 'notebook_id' => 10, 'title' => 'Exists', 'body' => 'web' ] );
	$r = Noodled_Sync::push_note( 400 );
	ok( ! empty( $r['success'] ), 'push succeeded' );
	same( [ 'PUT', 'GET', 'PUT' ], array_column( Noodled_GitHub::$calls, 0 ), 'PUT 422, GET, PUT' );
	same( md_of( 400 ), Noodled_GitHub::$files['General/Exists.md'], 'content replaced' );
	same( blob_sha( md_of( 400 ) ), row( 400 )['sha'], 'sha stored' );
} );

scenario( 'stale sha (409) self-heals', function () {
	base();
	Noodled_GitHub::$files['General/Stale.md'] = "edited on desktop\n";
	note( 500, [ 'notebook_id' => 10, 'title' => 'Stale', 'body' => 'web', 'sha' => str_repeat( 'b', 40 ), 'gh_path' => 'General/Stale.md' ] );
	$r = Noodled_Sync::push_note( 500 );
	ok( ! empty( $r['success'] ), 'push succeeded' );
	same( md_of( 500 ), Noodled_GitHub::$files['General/Stale.md'], 'content replaced' );
	same( 0, row( 500 )['push_attempts'], 'no attempt recorded' );
} );

scenario( 'a file claimed by another note is never adopted', function () {
	base();
	note( 600, [ 'notebook_id' => 10, 'title' => 'Mine', 'body' => 'owner' ] );
	Noodled_Sync::push_note( 600 );
	$before = Noodled_GitHub::$files['General/Mine.md'];
	note( 601, [ 'notebook_id' => 10, 'title' => 'Mine', 'body' => 'other, no sha' ] );
	note( 602, [ 'notebook_id' => 10, 'title' => 'Mine', 'body' => 'other, legacy sha', 'sha' => blob_sha( $before ) ] );
	Noodled_Sync::push_note( 601 );
	Noodled_Sync::push_note( 602 );
	same( $before, Noodled_GitHub::$files['General/Mine.md'], 'owner file untouched' );
	same( 'General/Mine (601).md', row( 601 )['gh_path'], '601 own file' );
	same( 'General/Mine (602).md', row( 602 )['gh_path'], '602 own file' );
	same( 1, count( array_filter( Noodled_GitHub::$calls, static fn( $c ) => $c[1] === 'General/Mine.md' ) ), 'claimed path touched only by its owner' );
} );

scenario( 'transient 500 leaves push_pending=1, counts the attempt, later drain succeeds', function () {
	base();
	note( 700, [ 'notebook_id' => 10, 'title' => 'Flaky', 'body' => 'x' ] );
	Noodled_GitHub::$fail_next = 1;
	$r = Noodled_Sync::push_note( 700 );
	ok( isset( $r['error'] ), 'error returned' );
	same( 1, row( 700 )['push_pending'], 'still pending' );
	same( 1, row( 700 )['push_attempts'], 'attempt counted' );
	same( 'Server Error', row( 700 )['push_error'], 'error stored' );
	$d = Noodled_Sync::drain();
	same( 1, $d['pushed'], 'drain pushed it' );
	same( 0, $d['remaining'], 'nothing remaining' );
	same( 0, row( 700 )['push_pending'], 'pending cleared' );
	same( 0, row( 700 )['push_attempts'], 'attempts reset' );
	same( '', row( 700 )['push_error'], 'error cleared' );
} );

scenario( 'retries stop at 8 attempts; push-all (queue_all) resets them', function () {
	base();
	note( 800, [ 'notebook_id' => 10, 'title' => 'Broken', 'body' => 'x', 'push_pending' => 1 ] );
	Noodled_GitHub::$fail_next = 1000;
	for ( $i = 0; $i < 10; $i++ ) Noodled_Sync::drain();
	same( 8, row( 800 )['push_attempts'], 'stopped at 8' );
	same( 8, count( Noodled_GitHub::$calls ), 'exactly 8 GitHub calls' );
	Noodled_GitHub::$fail_next = 0;
	$queued = Noodled_Sync::queue_all();
	same( 1, $queued, 'queue_all counted it' );
	same( 0, row( 800 )['push_attempts'], 'attempts reset' );
	$d = Noodled_Sync::drain();
	same( 1, $d['pushed'], 'pushed after reset' );
	ok( isset( Noodled_GitHub::$files['General/Broken.md'] ), 'file exists' );
} );

scenario( 'member notebook is never pushed (push_note, queue_push, drain, push-all)', function () {
	base();
	note( 900, [ 'notebook_id' => 20, 'title' => 'Private', 'body' => 'secret', 'push_pending' => 1 ] );
	$r = Noodled_Sync::push_note( 900 );
	ok( ! empty( $r['skipped'] ), 'push_note skipped' );
	same( 0, row( 900 )['push_pending'], 'pending cleared' );
	Noodled_Sync::queue_push( 900 );
	same( 0, row( 900 )['push_pending'], 'queue_push did not queue' );
	ok( empty( $GLOBALS['actions']['shutdown'] ), 'no shutdown push registered' );
	set_row( 900, [ 'push_pending' => 1 ] );
	$d = Noodled_Sync::drain();
	same( 1, $d['skipped'], 'drain skipped it' );
	Noodled_Sync::queue_all();
	same( 0, row( 900 )['push_pending'], 'push-all did not queue it' );
	Noodled_Sync::drain();
	same( 0, Noodled_GitHub::api_calls(), 'no API call at all' );
	same( [], files(), 'repo empty' );
} );

scenario( 'trashed note is skipped', function () {
	base();
	note( 950, [ 'notebook_id' => 10, 'title' => 'Gone', 'body' => 'x', 'push_pending' => 1, 'deleted_at' => '2026-05-01 00:00:00' ] );
	$r = Noodled_Sync::push_note( 950 );
	ok( ! empty( $r['skipped'] ), 'skipped' );
	same( 0, row( 950 )['push_pending'], 'pending cleared' );
	same( 0, Noodled_GitHub::api_calls(), 'no API call' );
} );

scenario( 'resolve_path table cases', function () {
	$p = static fn( $row, $claimed = [], $nb = 'NB' ) => Noodled_Sync::resolve_path( $row + [ 'id' => 7, 'slug' => 'slug-7', 'sha' => '', 'gh_path' => '' ], $nb, $claimed );
	same( [ 'target' => 'NB/T.md', 'current' => '' ], $p( [ 'title' => 'T' ] ), 'new note' );
	same( [ 'target' => 'NB/T (7).md', 'current' => '' ], $p( [ 'title' => 'T' ], [ 'NB/T.md' ] ), 'new note, plain claimed' );
	same( [ 'target' => 'NB/T.md', 'current' => 'NB/T.md' ], $p( [ 'title' => 'T', 'sha' => 's' ] ), 'legacy sha, plain free' );
	same( [ 'target' => 'NB/T (7).md', 'current' => '' ], $p( [ 'title' => 'T', 'sha' => 's' ], [ 'NB/T.md' ] ), 'legacy sha, plain claimed' );
	same( [ 'target' => 'NB/T.md', 'current' => 'NB/T.md' ], $p( [ 'title' => 'T', 'sha' => 's', 'gh_path' => 'NB/T.md' ], [ 'NB/T.md' ] ), 'owns plain even if a legacy row maps there' );
	same( [ 'target' => 'NB/T (7).md', 'current' => 'NB/T (7).md' ], $p( [ 'title' => 'T', 'gh_path' => 'NB/T (7).md' ] ), 'keeps its disamb file' );
	same( [ 'target' => 'NB/T.md', 'current' => 'NB/Old.md' ], $p( [ 'title' => 'T', 'gh_path' => 'NB/Old.md' ] ), 'renamed' );
	same( [ 'target' => 'NB/T (7).md', 'current' => 'NB/Old.md' ], $p( [ 'title' => 'T', 'gh_path' => 'NB/Old.md' ], [ 'NB/T.md' ] ), 'renamed onto a claimed title' );
	same( [ 'target' => 'NB/T.md', 'current' => 'Other/T.md' ], $p( [ 'title' => 'T', 'gh_path' => 'Other/T.md' ] ), 'moved notebook' );
	same( [ 'target' => 'NB/slug-7.md', 'current' => '' ], $p( [ 'title' => '...' ] ), 'unsafe title falls back to slug' );
	same( [ 'target' => 'NB/a-b.md', 'current' => '' ], $p( [ 'title' => 'a/b' ] ), 'slash in title' );
} );

scenario( 'pull with identical content only stores the sha, modified_at byte-identical', function () {
	base();
	note( 1100, [ 'notebook_id' => 10, 'title' => 'Same', 'body' => "body text\n", 'modified_at' => '2026-02-03 04:05:37', 'sha' => 'oldsha' ] );
	$before = row( 1100 );
	Noodled_GitHub::$files['General/Same.md'] = "---\ntitle: Same\ncreated: 2026-01-01 00:00\nmodified: 2026-05-05 05:05\n---\n\nbody text";
	ok( pull( 'General/Same.md' ), 'pulled' );
	$after = row( 1100 );
	same( blob_sha( Noodled_GitHub::$files['General/Same.md'] ), $after['sha'], 'sha stored' );
	same( 'General/Same.md', $after['gh_path'], 'gh_path stored' );
	same( $before['modified_at'], $after['modified_at'], 'modified_at untouched' );
	unset( $before['sha'], $before['gh_path'], $after['sha'], $after['gh_path'] );
	same( $before, $after, 'nothing else changed' );
	same( [], $GLOBALS['wpdb']->revisions, 'no revision' );
} );

scenario( 'pull with older remote content keeps the web copy and queues a push', function () {
	base();
	note( 1200, [ 'notebook_id' => 10, 'title' => 'Edited', 'body' => 'web edit', 'modified_at' => '2026-05-01 12:00:30', 'gh_path' => 'General/Edited.md', 'sha' => 'x' ] );
	Noodled_GitHub::$files['General/Edited.md'] = "---\ntitle: Edited\nmodified: 2026-05-01 12:00\n---\n\nold desktop text";
	pull( 'General/Edited.md' );
	same( 'web edit', row( 1200 )['body'], 'web copy kept (same minute is not newer)' );
	same( '2026-05-01 12:00:30', row( 1200 )['modified_at'], 'modified_at untouched' );
	same( 1, row( 1200 )['push_pending'], 'queued' );
	same( [], $GLOBALS['wpdb']->revisions, 'no revision' );
	fire_shutdown();
	same( md_of( 1200 ), Noodled_GitHub::$files['General/Edited.md'], 'stale file replaced by the web copy' );
	same( 0, row( 1200 )['push_pending'], 'pushed' );
} );

scenario( 'pull with newer remote content overwrites and snapshots a revision', function () {
	base();
	note( 1300, [ 'notebook_id' => 10, 'title' => 'Desk', 'body' => 'web text', 'modified_at' => '2026-05-01 12:00:30', 'sha' => 'x' ] );
	Noodled_GitHub::$files['General/Desk.md'] = "---\ntitle: Desk\nmodified: 2026-05-01 12:01\n---\n\ndesktop text";
	pull( 'General/Desk.md' );
	same( 'desktop text', row( 1300 )['body'], 'overwritten' );
	same( 'General/Desk.md', row( 1300 )['gh_path'], 'gh_path stored' );
	same( '2026-05-01 12:01:00', row( 1300 )['modified_at'], 'modified from frontmatter' );
	same( [ [ 'note_id' => 1300, 'title' => 'Desk', 'body' => 'web text' ] ], $GLOBALS['wpdb']->revisions, 'revision of the web copy' );
	same( 0, row( 1300 )['push_pending'], 'not queued' );
} );

scenario( 'pull never writes into a same-named notebook owned by a member', function () {
	user( 1, 'admin' ); user( 2, 'member' );
	nb( 5, 'General', 2 ); // member's, and the lower id
	note( 1400, [ 'notebook_id' => 5, 'title' => 'X', 'body' => 'member private' ] );
	Noodled_GitHub::$files['General/X.md'] = "---\ntitle: X\nmodified: 2030-01-01 00:00\n---\n\nfrom repo";
	pull( 'General/X.md' );
	same( 'member private', row( 1400 )['body'], 'member note untouched (webhook pull)' );
	$admin_nb = Noodled_Notebooks::get_by_name( 'General', 1 );
	ok( $admin_nb && (int) $admin_nb['id'] !== 5, 'admin General created' );
	$in_admin = array_filter( $GLOBALS['wpdb']->t['notes'], static fn( $n ) => (int) $n['notebook_id'] === (int) $admin_nb['id'] );
	same( 1, count( $in_admin ), 'pulled into the admin notebook' );
	Noodled_Sync::full_import();
	same( 'member private', row( 1400 )['body'], 'member note untouched (full import)' );
	same( 1, count( array_filter( $GLOBALS['wpdb']->t['notebooks'], static fn( $b ) => $b['name'] === 'General' && (int) $b['owner_id'] === 1 ) ), 'one admin General' );
	remove_file( 'General/X.md' );
	same( null, row( 1400 )['deleted_at'], 'remove never trashes the member note' );
	ok( reset( $in_admin ) && row( (int) reset( $in_admin )['id'] )['deleted_at'] !== null, 'remove trashes the admin note' );
} );

scenario( 'full import: new files inserted with gh_path, identical files skipped', function () {
	base();
	Noodled_GitHub::$files['Work/A.md'] = "---\ntitle: A\nmodified: 2026-01-01 10:00\n---\n\naaa";
	Noodled_GitHub::$files['Work/B.md'] = "---\ntitle: B\nmodified: 2026-01-01 10:00\n---\n\nbbb";
	$r = Noodled_Sync::full_import();
	same( 2, $r['notes'], 'two imported' );
	$paths = array_column( $GLOBALS['wpdb']->t['notes'], 'gh_path' );
	sort( $paths );
	same( [ 'Work/A.md', 'Work/B.md' ], $paths, 'gh_path recorded' );
	$r = Noodled_Sync::full_import();
	same( 0, $r['notes'], 'second import imports nothing' );
	same( 2, $r['skipped'], 'both skipped by sha' );
} );

scenario( 'queue_push: one shutdown hook, pushes at most 5, cron drains the rest', function () {
	base();
	for ( $i = 1; $i <= 7; $i++ ) {
		note( 1500 + $i, [ 'notebook_id' => 10, 'title' => "Q$i", 'body' => 'q' ] );
		Noodled_Sync::queue_push( 1500 + $i );
	}
	same( 1, count( $GLOBALS['actions']['shutdown'] ?? [] ), 'one shutdown callback' );
	fire_shutdown();
	same( 5, count( files() ), 'five pushed on shutdown' );
	same( 2, count( array_filter( $GLOBALS['wpdb']->t['notes'], static fn( $n ) => (int) $n['push_pending'] === 1 ) ), 'two left pending' );
	$d = Noodled_Sync::drain();
	same( 2, $d['pushed'], 'drain pushed the rest' );
	same( 7, count( files() ), 'all seven backed up' );
} );

scenario( 'drain respects its push budget', function () {
	base();
	for ( $i = 1; $i <= 4; $i++ ) note( 1600 + $i, [ 'notebook_id' => 10, 'title' => "D$i", 'body' => 'd', 'push_pending' => 1 ] );
	note( 1610, [ 'notebook_id' => 20, 'title' => 'member', 'body' => 'm', 'push_pending' => 1 ] );
	$d = Noodled_Sync::drain( 2, 20 );
	same( 2, $d['pushed'], 'two pushed' );
	// The member note is still flagged until a drain reaches it (then it is skipped).
	same( 3, $d['remaining'], 'two admin notes + the not-yet-visited member note remaining' );
	$d = Noodled_Sync::drain( 20, 20 );
	same( [ 'pushed' => 2, 'skipped' => 1, 'failed' => 0, 'remaining' => 0 ], $d, 'second drain finishes' );
} );

scenario( 'unsynced count, failed list and one-time reconcile', function () {
	base();
	note( 1701, [ 'notebook_id' => 10, 'title' => 'synced', 'sha' => 's1' ] );
	note( 1702, [ 'notebook_id' => 10, 'title' => 'never', 'sha' => '' ] );
	note( 1703, [ 'notebook_id' => 11, 'title' => 'pending', 'sha' => 's3', 'push_pending' => 1, 'push_attempts' => 2, 'push_error' => 'boom', 'modified_at' => '2026-03-01 00:00:00' ] );
	note( 1704, [ 'notebook_id' => 10, 'title' => 'trashed', 'sha' => '', 'deleted_at' => '2026-01-02 00:00:00', 'push_attempts' => 3 ] );
	note( 1705, [ 'notebook_id' => 20, 'title' => 'member', 'sha' => '', 'push_attempts' => 1 ] );
	note( 1706, [ 'notebook_id' => 10, 'title' => 'null sha', 'sha' => null ] );
	same( 3, Noodled_Sync::unsynced_count(), 'unsynced = never + pending + null sha' );
	same( [ [ 'id' => 1703, 'title' => 'pending', 'notebook' => 'Work', 'error' => 'boom', 'attempts' => 2 ] ], Noodled_Sync::failed_pushes(), 'failed list' );
	ok( Noodled_Sync::queue_unsynced(), 'reconcile ran' );
	same( 0, row( 1701 )['push_pending'], 'synced note not queued' );
	same( 1, row( 1702 )['push_pending'], 'never-backed-up note queued' );
	same( 1, row( 1706 )['push_pending'], 'null-sha note queued' );
	same( 0, row( 1704 )['push_pending'], 'trashed note not queued' );
	same( 0, row( 1705 )['push_pending'], 'member note not queued' );
} );

scenario( 'legacy row renamed: still gets backed up (old-title file is left behind)', function () {
	base();
	Noodled_GitHub::$files['General/Old title.md'] = "old\n";
	note( 1800, [ 'notebook_id' => 10, 'title' => 'New title', 'body' => 'b', 'sha' => blob_sha( "old\n" ) ] );
	$r = Noodled_Sync::push_note( 1800 );
	ok( ! empty( $r['success'] ), 'backed up' );
	same( 'General/New title.md', row( 1800 )['gh_path'], 'gh_path recorded' );
	same( [ 'General/New title.md', 'General/Old title.md' ], files(), 'orphan remains (path was never recorded)' );
} );

// ── Summary ──

$failed = count( array_filter( $results, static fn( $r ) => $r[1] ) );
echo "\n" . ( count( $results ) - $failed ) . " passed, {$failed} failed (" . count( $results ) . " scenarios)\n";
exit( $failed ? 1 : 0 );
