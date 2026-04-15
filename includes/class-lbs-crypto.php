<?php
/**
 * Encryption and decryption helpers for Load Balanced Sync.
 *
 * Uses AES-256-GCM keyed from WordPress AUTH_KEY + AUTH_SALT so that
 * credentials stolen from the database are useless without the matching
 * wp-config.php.
 */

defined( 'ABSPATH' ) || exit;

class LBS_Crypto {

	private const CIPHER    = 'aes-256-gcm';
	private const IV_LEN    = 12;
	private const TAG_LEN   = 16;
	private const CONTEXT   = 'lbs_peer_credentials';

	/**
	 * Returns the 32-byte encryption key derived from AUTH_KEY + AUTH_SALT.
	 */
	private static function get_key(): string {
		return hash_hmac( 'sha256', AUTH_KEY . AUTH_SALT, self::CONTEXT, true );
	}

	/**
	 * Returns true if AUTH_KEY still has the WordPress placeholder value.
	 */
	public static function is_auth_key_placeholder(): bool {
		return str_contains( AUTH_KEY, 'put your unique phrase here' );
	}

	/**
	 * Encrypts a plaintext string.
	 *
	 * @param string $plaintext
	 * @return string  Base64-encoded blob: IV(12) + ciphertext + tag(16).
	 * @throws RuntimeException When OpenSSL fails.
	 */
	public static function encrypt( string $plaintext ): string {
		$iv  = random_bytes( self::IV_LEN );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LEN
		);

		if ( false === $ciphertext ) {
			throw new RuntimeException( 'LBS_Crypto: openssl_encrypt failed.' );
		}

		return base64_encode( $iv . $ciphertext . $tag );
	}

	/**
	 * Decrypts a blob produced by encrypt().
	 *
	 * @param string $blob  Base64-encoded ciphertext.
	 * @return string|WP_Error  Plaintext on success, WP_Error on failure.
	 */
	public static function decrypt( string $blob ): string|WP_Error {
		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) < self::IV_LEN + self::TAG_LEN + 1 ) {
			return new WP_Error( 'lbs_decrypt_invalid', 'Invalid ciphertext blob.' );
		}

		$iv         = substr( $raw, 0, self::IV_LEN );
		$tag        = substr( $raw, -self::TAG_LEN );
		$ciphertext = substr( $raw, self::IV_LEN, strlen( $raw ) - self::IV_LEN - self::TAG_LEN );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			return new WP_Error( 'lbs_decrypt_failed', 'Decryption failed — wrong key or corrupted data.' );
		}

		return $plaintext;
	}

	/**
	 * Build an HMAC nonce for an update request.
	 *
	 * The key is the decrypted app password for the peer, binding the HMAC
	 * to the specific peer relationship rather than a shared site secret.
	 *
	 * @param array  $payload  Keys: type, identifier, new_version, initiator_uuid, request_ts.
	 * @param string $key      The app password used as HMAC key.
	 * @return string
	 */
	public static function build_update_nonce( array $payload, string $key ): string {
		$message = implode( '|', array(
			$payload['type'],
			$payload['identifier'] ?? '',
			$payload['new_version'] ?? '',
			$payload['initiator_uuid'],
			(string) $payload['request_ts'],
		) );
		return hash_hmac( 'sha256', $message, $key );
	}

	/**
	 * Verify an update request HMAC nonce.
	 *
	 * @param string $nonce    Received nonce from request.
	 * @param array  $payload  Same keys as build_update_nonce.
	 * @param string $key      The app password used as HMAC key.
	 * @return bool
	 */
	public static function verify_update_nonce( string $nonce, array $payload, string $key ): bool {
		$expected = self::build_update_nonce( $payload, $key );
		return hash_equals( $expected, $nonce );
	}
}
