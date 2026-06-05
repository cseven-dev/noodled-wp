<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Web Push for reminders: VAPID auth (RFC 8292, ES256) + aes128gcm payload
 * encryption (RFC 8291 / RFC 8188), implemented in pure PHP via openssl and
 * hash_hkdf (both available on the required PHP 8.0+). Lets the server deliver a
 * reminder notification when the app is closed.
 *
 * Best-effort by design: every send is wrapped so a failure is swallowed and the
 * existing in-app reminder scheduler stays the fallback. Push only activates for
 * a user who has granted notification permission and subscribed.
 */
class Noodled_Push {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'noodled_push_subs';
	}

	/* ── VAPID keypair (generated once, cached in an option) ── */

	public static function vapid(): array {
		$keys = get_option( 'noodled_vapid', [] );
		if ( ! empty( $keys['public'] ) && ! empty( $keys['private'] ) ) return $keys;

		$res = openssl_pkey_new( [ 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ] );
		if ( ! $res ) return [];
		openssl_pkey_export( $res, $pem );
		$d = openssl_pkey_get_details( $res );
		// Uncompressed point: 0x04 || X(32) || Y(32).
		$public_raw = "\x04" . str_pad( $d['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		$keys = [
			'public'  => self::b64u( $public_raw ), // applicationServerKey for the browser + VAPID k=
			'private' => $pem,
		];
		update_option( 'noodled_vapid', $keys, false );
		return $keys;
	}

	public static function public_key(): string {
		$v = self::vapid();
		return $v['public'] ?? '';
	}

	/* ── Subscription storage ── */

	public static function subscribe( int $user_id, array $sub ): bool {
		global $wpdb;
		$endpoint = esc_url_raw( $sub['endpoint'] ?? '' );
		$p256dh   = sanitize_text_field( $sub['keys']['p256dh'] ?? '' );
		$auth     = sanitize_text_field( $sub['keys']['auth'] ?? '' );
		if ( ! $endpoint || ! $p256dh || ! $auth ) return false;
		$wpdb->delete( self::table(), [ 'endpoint' => $endpoint ] ); // de-dupe on endpoint
		return (bool) $wpdb->insert( self::table(), [
			'user_id'    => $user_id,
			'endpoint'   => $endpoint,
			'p256dh'     => $p256dh,
			'auth'       => $auth,
			'created_at' => current_time( 'mysql', true ),
		] );
	}

	public static function unsubscribe( int $user_id, string $endpoint ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'user_id' => $user_id, 'endpoint' => esc_url_raw( $endpoint ) ] );
	}

	/* ── Send ── */

	public static function send_to_user( int $user_id, array $payload ): int {
		global $wpdb;
		$subs = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id
		), ARRAY_A );
		$sent = 0;
		foreach ( (array) $subs as $sub ) {
			if ( self::send_one( $sub, wp_json_encode( $payload ) ) ) $sent++;
		}
		return $sent;
	}

	private static function send_one( array $sub, string $payload ): bool {
		try {
			$vapid = self::vapid();
			if ( empty( $vapid['private'] ) ) return false;

			$ua_public   = self::b64u_decode( $sub['p256dh'] ); // 65 bytes
			$auth_secret = self::b64u_decode( $sub['auth'] );   // 16 bytes
			if ( strlen( $ua_public ) !== 65 || strlen( $auth_secret ) < 16 ) return false;

			// Per-message ephemeral (application server) keypair.
			$local = openssl_pkey_new( [ 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ] );
			if ( ! $local ) return false;
			$ld = openssl_pkey_get_details( $local );
			$as_public = "\x04" . str_pad( $ld['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $ld['ec']['y'], 32, "\x00", STR_PAD_LEFT );

			// ECDH shared secret (32-byte X coordinate).
			$shared = openssl_pkey_derive( self::ec_pem_from_raw( $ua_public ), $local );
			if ( ! $shared ) return false;
			$shared = str_pad( $shared, 32, "\x00", STR_PAD_LEFT );

			$salt = random_bytes( 16 );

			// RFC 8291: IKM = HKDF(salt=auth_secret, ikm=ecdh, info="WebPush: info\0"||ua||as).
			$ikm   = hash_hkdf( 'sha256', $shared, 32, "WebPush: info\x00" . $ua_public . $as_public, $auth_secret );
			// RFC 8188 content coding, keyed by the random salt.
			$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt );
			$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt );

			// Single record: plaintext + 0x02 (last-record delimiter), AES-128-GCM.
			$tag = '';
			$cipher = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
			if ( $cipher === false ) return false;
			// aes128gcm header: salt(16) || rs(4, BE) || idlen(1) || keyid(as_public) || ciphertext||tag.
			$body = $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_public . $cipher . $tag;

			$endpoint = $sub['endpoint'];
			$origin = wp_parse_url( $endpoint, PHP_URL_SCHEME ) . '://' . wp_parse_url( $endpoint, PHP_URL_HOST );
			$jwt = self::vapid_jwt( $origin, $vapid['private'] );

			$resp = wp_remote_post( $endpoint, [
				'headers' => [
					'Content-Type'     => 'application/octet-stream',
					'Content-Encoding' => 'aes128gcm',
					'TTL'              => '86400',
					'Authorization'    => 'vapid t=' . $jwt . ', k=' . $vapid['public'],
				],
				'body'    => $body,
				'timeout' => 10,
			] );
			if ( is_wp_error( $resp ) ) return false;
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code === 404 || $code === 410 ) { // subscription gone → clean up
				global $wpdb;
				$wpdb->delete( self::table(), [ 'endpoint' => $endpoint ] );
				return false;
			}
			return $code >= 200 && $code < 300;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ── VAPID JWT (ES256) ── */

	private static function vapid_jwt( string $aud, string $private_pem ): string {
		$header  = self::b64u( wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'ES256' ] ) );
		$payload = self::b64u( wp_json_encode( [
			'aud' => $aud,
			'exp' => time() + 12 * HOUR_IN_SECONDS,
			'sub' => 'mailto:' . sanitize_email( get_option( 'admin_email' ) ),
		] ) );
		$data = $header . '.' . $payload;
		$der  = '';
		openssl_sign( $data, $der, $private_pem, OPENSSL_ALGO_SHA256 );
		return $data . '.' . self::b64u( self::der_to_raw_sig( $der ) );
	}

	/* ── Crypto helpers ── */

	// PEM EC public key from a raw uncompressed point, by prefixing the fixed
	// P-256 SubjectPublicKeyInfo DER header.
	private static function ec_pem_from_raw( string $raw ): string {
		$der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $raw;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	// DER ECDSA signature (SEQUENCE{INTEGER r, INTEGER s}) → raw 64-byte R||S.
	private static function der_to_raw_sig( string $der ): string {
		$off = 2;               // skip SEQUENCE tag + length (single-byte for P-256)
		$off += 1;              // INTEGER tag (r)
		$rlen = ord( $der[ $off ] ); $off += 1;
		$r = substr( $der, $off, $rlen ); $off += $rlen;
		$off += 1;              // INTEGER tag (s)
		$slen = ord( $der[ $off ] ); $off += 1;
		$s = substr( $der, $off, $slen );
		$r = str_pad( ltrim( $r, "\x00" ), 32, "\x00", STR_PAD_LEFT );
		$s = str_pad( ltrim( $s, "\x00" ), 32, "\x00", STR_PAD_LEFT );
		return $r . $s;
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
