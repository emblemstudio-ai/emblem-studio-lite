<?php
final class Emblem_Studio_Form_Stamp {

	public const MAX_AGE_MS = 86400000;

	public static function issue( string $form_key ): string {
		$ms    = (int) round( microtime( true ) * 1000 );
		$nonce = bin2hex( random_bytes( 8 ) );
		return $ms . '.' . $nonce . '.' . self::sign( $form_key, $ms, $nonce );
	}

	public static function issued_at( string $stamp, string $form_key ): ?int {
		$parts = explode( '.', $stamp );
		if ( count( $parts ) !== 3 || ! ctype_digit( $parts[0] ) || ! ctype_xdigit( $parts[1] ) ) {
			return null;
		}
		list( $ms, $nonce, $sig ) = $parts;
		if ( ! hash_equals( self::sign( $form_key, (int) $ms, $nonce ), $sig ) ) {
			return null;
		}
		$age = (int) round( microtime( true ) * 1000 ) - (int) $ms;
		return ( $age >= 0 && $age <= self::MAX_AGE_MS ) ? (int) $ms : null;
	}

	public static function claim( string $stamp ): bool {
		return Emblem_Studio_Rate_Limit::claim_once( 'stamp:' . $stamp, (int) ( self::MAX_AGE_MS / 1000 ) );
	}

	private static function sign( string $form_key, int $ms, string $nonce ): string {
		return substr( hash_hmac( 'sha256', 'es-stamp|' . $form_key . '|' . $ms . '|' . $nonce, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
