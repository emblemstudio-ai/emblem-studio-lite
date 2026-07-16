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
 * in depth: a compromised admin password or careless co-admin can't
 * silently turn a form into a stored-XSS pivot.
 *
 * What gets removed:
 *   - <script> elements (entire subtree)
 *   - on* event-handler attributes (onclick, onerror, onload, …) on every element
 *   - URL-scheme attacks: href/src/action/formaction/etc. starting with
 *     javascript:, vbscript:, or data:text/html
 *
 * What stays:
 *   - Every other tag, including form inputs, iframes (for video embeds),
 *     SVG, custom elements, semantic HTML.
 *   - data-* and aria-* attributes pass through unchanged.
 *   - CSS-side content. Modern browsers neutralize CSS XSS in practice and
 *     the legitimate weird-stuff in CSS is hard to write a useful filter
 *     for. Sanitize::css() exists as a stub for future hardening.
 *
 * When (if ever) a dedicated `custom_js` column lands, scripted form
 * behavior gets a separate, opt-in, clearly-labeled field instead of
 * being smuggled inside the markup.
 */
class ES_HTML_Sanitizer {

	/**
	 * URL-bearing attributes. Values starting with a dangerous scheme are
	 * dropped from these.
	 */
	private const URL_ATTRS = [
		'href', 'src', 'srcset', 'action', 'formaction',
		'poster', 'background', 'cite', 'data', 'longdesc',
	];

	/**
	 * URL schemes we refuse on URL-bearing attributes.
	 */
	private const BAD_SCHEMES = '/^\s*(javascript|vbscript|data:\s*text\/html)/i';

	/**
	 * Sanitize admin-authored HTML in-place.
	 *
	 * Empty input is returned unchanged. Parse failures fall back to the
	 * original string — sanitizer is best-effort, never destroys content
	 * on a parser quirk.
	 */
	public static function sanitize( string $html ): string {
		if ( $html === '' ) {
			return $html;
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );

		// Force UTF-8 — DOMDocument's HTML parser is otherwise Latin-1.
		// LIBXML_HTML_NOIMPLIED: don't add <html>/<body>. LIBXML_HTML_NODEFDTD:
		// don't add a DOCTYPE. We just want the fragment back as-is.
		$wrapped = '<?xml encoding="UTF-8">' . $html;
		$ok = $doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return $html;
		}

		// Remove every <script> element subtree.
		$scripts = iterator_to_array( $doc->getElementsByTagName( 'script' ) );
		foreach ( $scripts as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		// Walk every remaining element. Drop on* attributes outright and
		// scrub URL attributes whose values start with a dangerous scheme.
		$xpath = new DOMXPath( $doc );
		$elements = $xpath->query( '//*' );
		if ( $elements ) {
			foreach ( $elements as $el ) {
				// The `//*` XPath only ever yields element nodes, but the
				// node list is typed generically — assert DOMElement so the
				// removeAttribute() call below is type-safe. A DOMElement
				// always exposes an attributes map (empty is fine — the
				// loop below just does nothing then).
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

		// Strip the UTF-8 declaration we injected and any other leading
		// XML processing instructions. DOMDocument's saveHTML() sometimes
		// emits the PI with the standard ending and sometimes without the
		// trailing question mark, so the regex tolerates both forms.
		// Pre-fix forms may have accumulated several leading declarations
		// from earlier saves; the trailing + nukes them all.
		$out = preg_replace( '/^(?:<\?xml[^>]*\??>\s*)+/i', '', $out );

		return self::decode_nonascii_entities( $out );
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
	 * CSS sanitization placeholder. Today's CSS-borne XSS surface in modern
	 * browsers is negligible (no IE expression(), no behavior:). Kept as a
	 * stub so a future tightening — e.g. blocking @import from untrusted
	 * origins — has an obvious home.
	 */
	public static function sanitize_css( string $css ): string {
		return $css;
	}
}
