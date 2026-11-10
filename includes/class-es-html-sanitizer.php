<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_HTML_Sanitizer {

	private const DROP_ELEMENTS = [
		'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta',
		'noscript', 'noembed', 'noframes', 'xmp', 'plaintext',
		'template',
	];

	private const DROP_IN_FOREIGN = [ 'style', 'foreignobject' ];

	private const DROP_IN_MATH = [ 'annotation-xml', 'mglyph' ];

	private const SVG_ANIMATION = [ 'set', 'animate', 'animatetransform', 'animatemotion' ];

	private const ROOT = 'es-sanitize-root';

	private const BAD_SCHEME = '/(^|[;,])(javascript|vbscript|livescript):/i';

	public static function sanitize( string $html ): string {
		if ( $html === '' ) {
			return $html;
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );

		$wrapped = '<?xml encoding="UTF-8"><html><body><' . self::ROOT . '>' . $html . '</' . self::ROOT . '></body></html>';
		$ok = $doc->loadHTML( $wrapped, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return '';
		}

		$xpath = new DOMXPath( $doc );

		$nodes = $xpath->query( '//comment() | //processing-instruction()' );
		if ( $nodes === false ) {
			return '';
		}
		foreach ( iterator_to_array( $nodes ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
		$nodes = $xpath->query( '//textarea | //title' );
		if ( $nodes === false ) {
			return '';
		}
		foreach ( iterator_to_array( $nodes ) as $el ) {
			$text = $el->textContent;
			while ( $el->firstChild ) {
				$el->removeChild( $el->firstChild );
			}
			if ( $text !== '' ) {
				$el->appendChild( $doc->createTextNode( $text ) );
			}
		}

		$elements = $xpath->query( '//*' );
		if ( $elements === false ) {
			return '';
		}
		$drop = [];
		foreach ( $elements as $el ) {
			if ( $el instanceof DOMElement && self::must_drop( $el ) ) {
				$drop[] = $el;
			}
		}
		foreach ( $drop as $el ) {
			if ( $el->parentNode ) {
				$el->parentNode->removeChild( $el );
			}
		}

		$elements = $xpath->query( '//*' );
		if ( $elements === false ) {
			return '';
		}
		foreach ( $elements as $el ) {
			if ( ! $el instanceof DOMElement ) {
				continue;
			}
			$to_remove = [];
			foreach ( $el->attributes as $attr ) {
				$name = strtolower( $attr->name );
				if ( strpos( $name, 'on' ) === 0 || $name === 'srcdoc' ) {
					$to_remove[] = $attr->name;
					continue;
				}
				if ( self::is_dangerous_value( (string) $attr->value ) ) {
					$to_remove[] = $attr->name;
				}
			}
			foreach ( $to_remove as $n ) {
				$el->removeAttribute( $n );
			}
		}

		$root = $doc->getElementsByTagName( self::ROOT )->item( 0 );
		if ( ! $root ) {
			return '';
		}
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$part = $doc->saveHTML( $child );
			if ( $part === false ) {
				return '';
			}
			$out .= $part;
		}

		$out = (string) preg_replace( '/^(?:<\?xml[^>]*\??>\s*)+/i', '', $out );

		return self::decode_nonascii_entities( $out );
	}

	private static function must_drop( DOMElement $el ): bool {
		$name = strtolower( $el->nodeName );
		if ( in_array( $name, self::DROP_ELEMENTS, true ) ) {
			return true;
		}
		if ( $name === 'link' ) {
			$rel  = strtolower( $el->getAttribute( 'rel' ) );
			$href = strtolower( trim( $el->getAttribute( 'href' ) ) );
			return ! ( strpos( $rel, 'stylesheet' ) !== false && strpos( $href, 'https://' ) === 0 );
		}
		if ( in_array( $name, self::SVG_ANIMATION, true ) ) {
			$target = strtolower( trim( $el->getAttribute( 'attributename' ) ) );
			if ( $target === 'href' || $target === 'xlink:href' || strpos( $target, 'on' ) === 0 ) {
				return true;
			}
		}
		$in_svg  = false;
		$in_math = false;
		for ( $p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode ) {
			$pn = strtolower( $p->nodeName );
			if ( $pn === 'svg' ) {
				$in_svg = true;
			} elseif ( $pn === 'math' ) {
				$in_math = true;
			}
		}
		if ( ( $in_svg || $in_math ) && in_array( $name, self::DROP_IN_FOREIGN, true ) ) {
			return true;
		}
		return $in_math && in_array( $name, self::DROP_IN_MATH, true );
	}

	private static function is_dangerous_value( string $value ): bool {
		if ( $value === '' ) {
			return false;
		}
		$v = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$v = (string) preg_replace( '/[\x00-\x20\x7F]+/', '', $v );
		if ( preg_match( self::BAD_SCHEME, $v ) ) {
			return true;
		}
		return stripos( $v, 'data:text/html' ) === 0;
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
		if ( $css === '' ) {
			return $css;
		}
		$patterns = [
			'#<\s*\\\\?/\s*style#i'     => '',
			'/expression\s*\(/i'        => '',
			'/-moz-binding/i'           => '',
			'/(?<![\w-])behavior\s*:/i' => '',
		];
		for ( $i = 0; $i < 10; $i++ ) {
			$next = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $css );
			$next = (string) preg_replace_callback(
				'/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)/i',
				static function ( array $m ): string {
					$target = $m[1] . ( $m[2] ?? '' ) . ( $m[3] ?? '' );
					return self::is_dangerous_value( $target ) ? 'url()' : $m[0];
				},
				$next
			);
			if ( $next === $css ) {
				break;
			}
			$css = $next;
		}
		return $css;
	}
}
