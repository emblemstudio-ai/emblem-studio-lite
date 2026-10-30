<?php
/**
 * The time a visitor started on a form, written down by us (audit S6).
 *
 * The spam trap pairs a filled hidden field with how fast the form came back.
 * That speed used to come from the visitor's browser (`es_el`), so a bot could
 * send any number it liked and pass as a slow, careful person. Before that it
 * was their timestamp against our clock, which filed every phone with a fast
 * clock as a bot.
 *
 * A stamp fixes both. frontend.js asks for one when someone first touches the
 * form; it carries OUR time and a signature, so it cannot be edited, and the
 * submission is timed as our now minus our then. One clock, ours, every time.
 * It also proves the submission ran our script: a post with no valid stamp is
 * treated like spam posted straight at admin-ajax.
 *
 * Fetched rather than printed into the page, because a page cache would hand
 * every visitor the same hours-old stamp.
 *
 * Same format and rules as the SaaS's App\Support\FormStamp.
 */
final class ES_Form_Stamp {

	/** Older than this and the visitor gets no benefit of the doubt. */
	public const MAX_AGE_MS = 86400000;

	public static function issue( string $form_key ): string {
		$ms    = (int) round( microtime( true ) * 1000 );
		$nonce = bin2hex( random_bytes( 8 ) );
		return $ms . '.' . $nonce . '.' . self::sign( $form_key, $ms, $nonce );
	}

	/**
	 * When the stamp was issued, in milliseconds, or null when it is missing,
	 * forged, for another form, or too old.
	 */
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

	/**
	 * Use the stamp up. True the first time, false after. Only called when the
	 * trap is filled: without it, one stamp fetched by a bot would make every
	 * later post from it look like a person who took their time.
	 */
	public static function claim( string $stamp ): bool {
		return ES_Rate_Limit::claim_once( 'stamp:' . $stamp, (int) ( self::MAX_AGE_MS / 1000 ) );
	}

	private static function sign( string $form_key, int $ms, string $nonce ): string {
		return substr( hash_hmac( 'sha256', 'es-stamp|' . $form_key . '|' . $ms . '|' . $nonce, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
