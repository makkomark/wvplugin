<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * libsodium secretbox encryption for API keys, tokens and raw payment payloads.
 */
final class Encryption {

	private const PREFIX = 'enc:';

	private static function key(): string {
		if ( defined( 'WVM_ENCRYPTION_KEY' ) && '' !== (string) constant( 'WVM_ENCRYPTION_KEY' ) ) {
			$material = (string) constant( 'WVM_ENCRYPTION_KEY' );
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . 'wvm';
		}
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( ?string $value ): ?string {
		if ( null === $value || '' === $value ) {
			return $value;
		}
		if ( ! self::isEncrypted( $value ) ) {
			return $value;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? null : $plain;
	}

	public static function isEncrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/** HMAC-SHA256 signature using the encryption key (for signed media / tokens). */
	public static function sign( string $data ): string {
		return hash_hmac( 'sha256', $data, self::key() );
	}

	public static function token( int $bytes = 24 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/** Mask a secret for display: "sk_live_••••abcd". */
	public static function mask( ?string $secret ): string {
		if ( null === $secret || '' === $secret ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
