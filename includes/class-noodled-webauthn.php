<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passkeys (WebAuthn) for one-tap biometric sign-in on returning visits.
 * Pure PHP: a minimal CBOR decoder + openssl signature verification. Supports
 * ES256 (P-256), which every platform authenticator uses (Apple Face ID/Touch
 * ID, Android, Windows Hello). Attestation is accepted as "none" (we trust the
 * user's own device); the security comes from verifying the assertion signature,
 * the challenge, and the origin/RP id on every login.
 *
 * Sits on top of the existing auth: a verified passkey login mints a normal
 * per-device session via Noodled_Auth::create_session(). The PIN/magic-link path
 * remains the fallback and recovery method.
 */
class Noodled_WebAuthn {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_webauthn_creds';
	}

	/* ── Relying-party identity (derived from the site) ── */

	private static function rp_id(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
	private static function origin(): string {
		$u = wp_parse_url( home_url() );
		$o = $u['scheme'] . '://' . $u['host'];
		if ( ! empty( $u['port'] ) ) $o .= ':' . $u['port'];
		return $o;
	}

	/* ── Registration ── */

	public static function register_options( int $user_id, string $email, string $name ): array {
		$challenge = random_bytes( 32 );
		set_transient( 'noodled_wa_reg_' . $user_id, base64_encode( $challenge ), 5 * MINUTE_IN_SECONDS );
		return [
			'challenge' => self::b64u( $challenge ),
			'rp'        => [ 'id' => self::rp_id(), 'name' => get_bloginfo( 'name' ) ?: 'noodled' ],
			'user'      => [
				'id'          => self::b64u( (string) $user_id ),
				'name'        => $email,
				'displayName' => $name ?: $email,
			],
			'pubKeyCredParams' => [ [ 'type' => 'public-key', 'alg' => -7 ] ], // ES256
			'authenticatorSelection' => [ 'residentKey' => 'preferred', 'userVerification' => 'preferred' ],
			'timeout'     => 60000,
			'attestation' => 'none',
			'excludeCredentials' => self::user_credential_descriptors( $user_id ),
		];
	}

	public static function register_verify( int $user_id, array $body, string $label = '' ): array {
		$challenge = get_transient( 'noodled_wa_reg_' . $user_id );
		delete_transient( 'noodled_wa_reg_' . $user_id );
		if ( ! $challenge ) return [ 'error' => __( 'Registration timed out, please try again.', 'noodled' ) ];

		$client = json_decode( self::b64u_decode( $body['clientDataJSON'] ?? '' ), true );
		$check  = self::check_client_data( $client, 'webauthn.create', base64_decode( $challenge ) );
		if ( $check !== true ) return [ 'error' => $check ];

		$att = self::cbor_decode( self::b64u_decode( $body['attestationObject'] ?? '' ) );
		if ( ! is_array( $att ) || empty( $att['authData'] ) ) return [ 'error' => __( 'Could not read the passkey.', 'noodled' ) ];

		$parsed = self::parse_auth_data( $att['authData'] );
		if ( ! $parsed || empty( $parsed['credId'] ) || empty( $parsed['pem'] ) ) {
			return [ 'error' => __( 'This passkey type is not supported.', 'noodled' ) ];
		}
		if ( ! ( $parsed['flags'] & 0x01 ) ) return [ 'error' => __( 'Passkey was not confirmed on the device.', 'noodled' ) ];
		if ( substr( $parsed['rpIdHash'], 0, 32 ) !== hash( 'sha256', self::rp_id(), true ) ) {
			return [ 'error' => __( 'Passkey is for a different site.', 'noodled' ) ];
		}

		global $wpdb;
		$cred_b64 = self::b64u( $parsed['credId'] );
		$wpdb->delete( self::table(), [ 'credential_id' => $cred_b64 ] ); // replace if re-registered
		$wpdb->insert( self::table(), [
			'user_id'       => $user_id,
			'credential_id' => $cred_b64,
			'public_key'    => $parsed['pem'],
			'sign_count'    => (int) $parsed['signCount'],
			'label'         => substr( sanitize_text_field( $label ), 0, 100 ),
			'created_at'    => current_time( 'mysql', true ),
		] );
		return [ 'success' => true ];
	}

	/* ── Authentication ── */

	public static function auth_options( string $email = '' ): array {
		$challenge = random_bytes( 32 );
		$handle    = wp_generate_password( 24, false );
		set_transient( 'noodled_wa_auth_' . $handle, base64_encode( $challenge ), 5 * MINUTE_IN_SECONDS );

		$allow = [];
		if ( $email ) {
			global $wpdb;
			$uid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", sanitize_email( $email )
			) );
			if ( $uid ) $allow = self::user_credential_descriptors( $uid );
		}
		return [
			'challenge'        => self::b64u( $challenge ),
			'handle'           => $handle,
			'rpId'             => self::rp_id(),
			'timeout'          => 60000,
			'userVerification' => 'preferred',
			'allowCredentials' => $allow, // empty = let the device offer any discoverable passkey
		];
	}

	/** Verify an assertion. Returns the authenticated user id, or 0 on failure. */
	public static function auth_verify( array $body ): int {
		$handle    = sanitize_text_field( $body['handle'] ?? '' );
		$challenge = $handle ? get_transient( 'noodled_wa_auth_' . $handle ) : '';
		if ( $handle ) delete_transient( 'noodled_wa_auth_' . $handle );
		if ( ! $challenge ) return 0;

		global $wpdb;
		$cred = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE credential_id = %s",
			sanitize_text_field( $body['id'] ?? '' )
		), ARRAY_A );
		if ( ! $cred ) return 0;

		$client = json_decode( self::b64u_decode( $body['clientDataJSON'] ?? '' ), true );
		if ( self::check_client_data( $client, 'webauthn.get', base64_decode( $challenge ) ) !== true ) return 0;

		$auth_data  = self::b64u_decode( $body['authenticatorData'] ?? '' );
		$signature  = self::b64u_decode( $body['signature'] ?? '' );
		if ( strlen( $auth_data ) < 37 ) return 0;
		// RP id + user-present flag.
		if ( substr( $auth_data, 0, 32 ) !== hash( 'sha256', self::rp_id(), true ) ) return 0;
		if ( ! ( ord( $auth_data[32] ) & 0x01 ) ) return 0;

		// Signature is over: authenticatorData || SHA256(clientDataJSON).
		$signed = $auth_data . hash( 'sha256', self::b64u_decode( $body['clientDataJSON'] ?? '' ), true );
		$ok = openssl_verify( $signed, $signature, $cred['public_key'], OPENSSL_ALGO_SHA256 );
		if ( $ok !== 1 ) return 0;

		// Counter regression check (clone detection); 0/0 is allowed for authenticators that don't count.
		$new_count = self::auth_data_sign_count( $auth_data );
		if ( $new_count !== 0 && $new_count <= (int) $cred['sign_count'] && (int) $cred['sign_count'] !== 0 ) return 0;

		$wpdb->update( self::table(), [
			'sign_count' => $new_count,
			'last_used'  => current_time( 'mysql', true ),
		], [ 'id' => (int) $cred['id'] ] );

		return (int) $cred['user_id'];
	}

	/* ── Shared helpers ── */

	public static function has_credentials( int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d", $user_id
		) );
	}

	private static function user_credential_descriptors( int $user_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT credential_id FROM " . self::table() . " WHERE user_id = %d", $user_id
		) );
		return array_map( function ( $id ) {
			return [ 'type' => 'public-key', 'id' => $id ];
		}, (array) $ids );
	}

	private static function check_client_data( $client, string $expected_type, string $challenge ) {
		if ( ! is_array( $client ) ) return __( 'Malformed passkey response.', 'noodled' );
		if ( ( $client['type'] ?? '' ) !== $expected_type ) return __( 'Unexpected passkey response.', 'noodled' );
		if ( ! hash_equals( $challenge, self::b64u_decode( $client['challenge'] ?? '' ) ) ) return __( 'Passkey challenge did not match.', 'noodled' );
		if ( ( $client['origin'] ?? '' ) !== self::origin() ) return __( 'Passkey came from the wrong site.', 'noodled' );
		return true;
	}

	private static function auth_data_sign_count( string $auth_data ): int {
		$b = substr( $auth_data, 33, 4 );
		return strlen( $b ) === 4 ? unpack( 'N', $b )[1] : 0;
	}

	/** Parse attested authenticator data → credId, COSE public key (as PEM), flags. */
	private static function parse_auth_data( string $d ): ?array {
		if ( strlen( $d ) < 37 ) return null;
		$rpIdHash  = substr( $d, 0, 32 );
		$flags     = ord( $d[32] );
		$signCount = unpack( 'N', substr( $d, 33, 4 ) )[1];
		$out = [ 'rpIdHash' => $rpIdHash, 'flags' => $flags, 'signCount' => $signCount ];
		if ( ! ( $flags & 0x40 ) ) return $out; // no attested credential data
		$off = 37;
		$off += 16; // aaguid
		$len = unpack( 'n', substr( $d, $off, 2 ) )[1]; $off += 2;
		$out['credId'] = substr( $d, $off, $len ); $off += $len;
		$cose = self::cbor_decode( substr( $d, $off ) );
		$out['pem'] = self::cose_to_pem( is_array( $cose ) ? $cose : [] );
		return $out;
	}

	/** COSE EC2 (ES256/P-256) key map → PEM public key. */
	private static function cose_to_pem( array $cose ): ?string {
		// kty(1)=2 EC2, alg(3)=-7 ES256, crv(-1)=1 P-256, x(-2), y(-3).
		if ( ( $cose[1] ?? null ) !== 2 || ( $cose[-1] ?? null ) !== 1 ) return null;
		$x = $cose[-2] ?? ''; $y = $cose[-3] ?? '';
		if ( strlen( $x ) !== 32 || strlen( $y ) !== 32 ) return null;
		$raw = "\x04" . $x . $y;
		$der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $raw;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/* ── Minimal CBOR decoder (RFC 8949 subset: ints, byte/text strings, arrays,
	   maps — enough for the attestationObject and a COSE key) ── */

	private static function cbor_decode( string $data, int &$offset = 0 ) {
		$ib = ord( $data[ $offset++ ] );
		$major = $ib >> 5;
		$info  = $ib & 0x1f;
		$val   = self::cbor_length( $data, $offset, $info );
		switch ( $major ) {
			case 0: return $val;                         // unsigned int
			case 1: return -1 - $val;                     // negative int
			case 2:                                       // byte string
			case 3:                                       // text string
				$s = substr( $data, $offset, $val ); $offset += $val; return $s;
			case 4:                                       // array
				$a = [];
				for ( $i = 0; $i < $val; $i++ ) $a[] = self::cbor_decode( $data, $offset );
				return $a;
			case 5:                                       // map
				$m = [];
				for ( $i = 0; $i < $val; $i++ ) {
					$k = self::cbor_decode( $data, $offset );
					$m[ $k ] = self::cbor_decode( $data, $offset );
				}
				return $m;
			case 7: return $val; // simple values (true/false/null encoded in info); unused here
		}
		return null;
	}

	private static function cbor_length( string $data, int &$offset, int $info ): int {
		if ( $info < 24 ) return $info;
		if ( $info === 24 ) return ord( $data[ $offset++ ] );
		if ( $info === 25 ) { $v = unpack( 'n', substr( $data, $offset, 2 ) )[1]; $offset += 2; return $v; }
		if ( $info === 26 ) { $v = unpack( 'N', substr( $data, $offset, 4 ) )[1]; $offset += 4; return $v; }
		if ( $info === 27 ) { $v = unpack( 'J', substr( $data, $offset, 8 ) )[1]; $offset += 8; return $v; }
		return 0;
	}

	private static function b64u( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}
	private static function b64u_decode( string $s ): string {
		$s = strtr( $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) $s .= str_repeat( '=', 4 - $pad );
		return (string) base64_decode( $s );
	}
}
