<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_HTML_Sanitizer {

	private const URL_ATTRS = [
		'href', 'src', 'srcset', 'action', 'formaction',
		'poster', 'background', 'cite', 'data', 'longdesc',
	];

	private const BAD_SCHEMES = '/^\s*(javascript|vbscript|data:\s*text\/html)/i';

	public static function sanitize( string $html ): string {
		if ( $html === '' ) {
			return $html;
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );

		$wrapped = '<?xml encoding="UTF-8">' . $html;
		$ok = $doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return $html;
		}

		$scripts = iterator_to_array( $doc->getElementsByTagName( 'script' ) );
		foreach ( $scripts as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		$xpath = new DOMXPath( $doc );
		$elements = $xpath->query( '//*' );
		if ( $elements ) {
			foreach ( $elements as $el ) {
				if ( ! $el instanceof DOMElement ) {
					continue;
				}
				$to_remove = [];
				foreach ( $el->attributes as $attr ) {
					$name = strtolower( $attr->name );
					if ( strpos( $name, 'on' ) === 0 ) {
						$to_remove[] = $attr->name;
						continue;
					}
					if ( in_array( $name, self::URL_ATTRS, true )
						&& preg_match( self::BAD_SCHEMES, (string) $attr->value ) ) {
						$to_remove[] = $attr->name;
					}
				}
				foreach ( $to_remove as $n ) {
					$el->removeAttribute( $n );
				}
			}
		}

		$out = $doc->saveHTML();
		if ( $out === false ) {
			return $html;
		}

		$out = preg_replace( '/^(?:<\?xml[^>]*\??>\s*)+/i', '', $out );

		return self::decode_nonascii_entities( $out );
	}

	private static function decode_nonascii_entities( string $html ): string {
		if ( $html === '' || strpos( $html, '&' ) === false ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'/&(#\d+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/',
			static function ( array $m ): string {
				$decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $decoded === $m[0] ) {
					return $m[0];
				}
				$cp = (int) ( function_exists( 'mb_ord' ) ? mb_ord( $decoded, 'UTF-8' ) : ord( $decoded[0] ) );
				if ( $cp > 0 && $cp < 128 ) {
					return $m[0];
				}
				return $decoded;
			},
			$html
		);
	}

	public static function sanitize_css( string $css ): string {
		return $css;
	}
}
