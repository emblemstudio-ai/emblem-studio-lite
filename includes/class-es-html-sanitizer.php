<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strip script execution vectors from admin-authored form HTML.
 *
 * Runs on every save (and on the preview-endpoint POST so the preview
 * matches what'll actually persist). NOT a full HTML sanitizer / sandbox —
 * the admin is trusted at the manage_options level. The goal is defense
 * in depth: a compromised admin password, a careless co-admin, or a value
 * pulled in from the SaaS / a JSON import can't silently turn a form into
 * a stored-XSS pivot.
 *
 * The SaaS sanitizer (app/Services/HtmlSanitizer.php) follows the SAME
 * rules. Change both together.
 *
 * What gets removed:
 *   - Whole subtrees: script, iframe, frame, frameset, object, embed,
 *     applet, base, meta, noscript, noembed, noframes, xmp, plaintext,
 *     template. <link> too, unless it is an https:// stylesheet
 *     (Google Fonts and the like keep working).
 *   - Inside <svg> / <math>: <style> and <foreignObject>, and in math also
 *     <annotation-xml> and <mglyph>. libxml parses HTML4 and treats <style>
 *     as raw text everywhere; a browser (HTML5) treats it as an ordinary
 *     element inside foreign content. That disagreement lets
 *     `<svg><style><img onerror=…>` pass here as "text" and run as markup
 *     in the browser (mutation XSS). These are the elements that open the
 *     gap, so they go.
 *   - SVG animation elements (set, animate, animateTransform, animateMotion)
 *     that animate href / xlink:href or an on* attribute.
 *   - Attributes: every on* handler, every srcdoc, and ANY attribute whose
 *     decoded, whitespace-stripped value carries a javascript:, vbscript:
 *     or livescript: scheme (at the start, or after ; or ,) or starts with
 *     data:text/html. That covers href, xlink:href, formaction, and SVG
 *     to / from / values / by.
 *
 * What stays:
 *   - Every other tag: forms, inputs, buttons, SVG icons, custom elements.
 *   - data-* and aria-* attributes pass through unchanged.
 *
 * Fails CLOSED: if the markup cannot be parsed or serialised, the result
 * is an empty string, never the raw input.
 *
 * When (if ever) a dedicated `custom_js` column lands, scripted form
 * behavior gets a separate, opt-in, clearly-labeled field instead of
 * being smuggled inside the markup.
 */
class ES_HTML_Sanitizer {

	/** Elements removed with everything inside them, wherever they appear. */
	private const DROP_ELEMENTS = [
		'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta',
		// Parsed differently with scripting on and off, so what we inspect is
		// not what the browser builds.
		'noscript', 'noembed', 'noframes', 'xmp', 'plaintext',
		// Its contents are an inert fragment we can't inspect reliably. Assets
		// never need one; the runtime builds its own.
		'template',
	];

	/** Removed when inside <svg> or <math> (see the class comment). */
	private const DROP_IN_FOREIGN = [ 'style', 'foreignobject' ];

	/** Removed when inside <math>. */
	private const DROP_IN_MATH = [ 'annotation-xml', 'mglyph' ];

	/** SVG animation elements. libxml lowercases element names. */
	private const SVG_ANIMATION = [ 'set', 'animate', 'animatetransform', 'animatemotion' ];

	/** The wrapper element the fragment is parsed inside. */
	private const ROOT = 'es-sanitize-root';

	/** A script-running scheme at the start of a value, or after ; or , */
	private const BAD_SCHEME = '/(^|[;,])(javascript|vbscript|livescript):/i';

	/**
	 * Sanitize admin-authored HTML.
	 *
	 * Empty input is returned unchanged. A parse or save failure returns ''.
	 */
	public static function sanitize( string $html ): string {
		if ( $html === '' ) {
			return $html;
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );

		// Force UTF-8 — DOMDocument's HTML parser is otherwise Latin-1.
		// The fragment is parsed inside a wrapper element in an explicit
		// <body>, and only the wrapper's children are written back. The old
		// LIBXML_HTML_NOIMPLIED fragment parse threw away everything after
		// a <link>, <meta> or <title> (libxml treated it as the end of the
		// <head>), so an asset that loads a Google Font lost its markup. A
		// custom tag is used so a stray </div> in the author's HTML can't
		// close the wrapper early.
		$wrapped = '<?xml encoding="UTF-8"><html><body><' . self::ROOT . '>' . $html . '</' . self::ROOT . '></body></html>';
		$ok = $doc->loadHTML( $wrapped, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return '';
		}

		$xpath = new DOMXPath( $doc );

		// Pass 1: elements that go with their whole subtree. Collected first,
		// removed after, so the walk never runs over a node it just detached.
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

		// Pass 2: attributes on everything that is left.
		$elements = $xpath->query( '//*' );
		if ( $elements === false ) {
			return '';
		}
		foreach ( $elements as $el ) {
			// The `//*` XPath only ever yields element nodes, but the node
			// list is typed generically — assert DOMElement so the attribute
			// calls below are type-safe.
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

		// Write back the wrapper's children only. Anything the author's markup
		// pushed outside it (a literal </body>) is dropped, never passed raw.
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

		// Strip any leading XML processing instructions. Pre-fix forms may
		// have accumulated several `<?xml encoding="UTF-8">` declarations
		// from earlier saves (with or without the trailing question mark);
		// the trailing + nukes them all.
		$out = (string) preg_replace( '/^(?:<\?xml[^>]*\??>\s*)+/i', '', $out );

		return self::decode_nonascii_entities( $out );
	}

	/** Whether an element is removed outright, with everything inside it. */
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

	/**
	 * Whether an attribute value carries a script-running URL. The value is
	 * entity-decoded once more (the parser already decoded one layer) and
	 * every space and control character is removed first, because browsers
	 * ignore those inside a scheme: "java&#x09;script:" still runs.
	 */
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

	/**
	 * DOMDocument::saveHTML() serialises every non-ASCII character as an HTML
	 * entity (e.g. the Romanian "ă" becomes `&#259;`, "î" becomes `&icirc;`),
	 * which leaves the stored markup full of unreadable entities in the editor.
	 * Decode those back to real UTF-8 so authored diacritics, dashes, smart
	 * quotes, etc. stay human-readable.
	 *
	 * Only entities that resolve to a NON-ASCII code point (> 127) are decoded.
	 * The structural ASCII entities — `&lt;` `&gt;` `&amp;` `&quot;` `&#39;` —
	 * are all code points < 128, so they're left encoded and tag boundaries /
	 * attribute quoting are never broken.
	 */
	private static function decode_nonascii_entities( string $html ): string {
		if ( $html === '' || strpos( $html, '&' ) === false ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'/&(#\d+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/',
			static function ( array $m ): string {
				$decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				// Unknown named entity → html_entity_decode returns it verbatim.
				if ( $decoded === $m[0] ) {
					return $m[0];
				}
				// Keep ASCII entities encoded (structural + punctuation like &amp;).
				// mb_ord() returns int|false; normalise a failed decode to 0 (not ASCII).
				$cp = (int) ( function_exists( 'mb_ord' ) ? mb_ord( $decoded, 'UTF-8' ) : ord( $decoded[0] ) );
				if ( $cp > 0 && $cp < 128 ) {
					return $m[0];
				}
				return $decoded;
			},
			$html
		);
	}

	/**
	 * Remove the CSS that can break out of a <style> element or run script.
	 *
	 *   - any "</style" (also "< /style" and the escaped "<\/style"), which
	 *     would end the <style> element early and let the rest read as HTML
	 *   - expression(, -moz-binding and behavior: (old IE / Firefox hooks)
	 *   - a url() whose target, decoded and with spaces and control
	 *     characters removed, is a javascript:, vbscript: or livescript:
	 *     URL or starts with data:text/html. The whole url(...) becomes url().
	 *
	 * @import and ordinary url() are left alone: real assets import Google
	 * Fonts. The patterns re-run until nothing changes, so removing one
	 * match can't splice the text around it into a new one.
	 */
	public static function sanitize_css( string $css ): string {
		if ( $css === '' ) {
			return $css;
		}
		$patterns = [
			'#<\s*\\\\?/\s*style#i'     => '',
			'/expression\s*\(/i'        => '',
			'/-moz-binding/i'           => '',
			// Not scroll-behavior / overscroll-behavior, which are real CSS.
			'/(?<![\w-])behavior\s*:/i' => '',
		];
		for ( $i = 0; $i < 10; $i++ ) {
			$next = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $css );
			$next = (string) preg_replace_callback(
				'/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)/i',
				static function ( array $m ): string {
					// One of the three groups matched (double-quoted, single-quoted
					// or bare); the others are empty or absent.
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
