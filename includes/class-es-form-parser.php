<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a form's stored HTML to a structured list of inputs: name + kind.
 *
 * Kinds are normalised:
 *   text, email, tel, url, number, date, time, datetime, textarea,
 *   select-single, select-multi, radio, checkbox-single, checkbox-multi, hidden, file
 *
 * Multi-name inputs (radio groups, checkbox groups with `name[]`) collapse to
 * a single entry — they represent one logical field.
 *
 * Returns: array<int, array{ name: string, kind: string }>
 */
class ES_Form_Parser {

	public static function extract_fields( string $html ): array {
		if ( trim( $html ) === '' ) {
			return [];
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		// Wrap so unbalanced fragments don't break parsing.
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		/** @var array<string, array{name: string, kind: string}> $by_name */
		$by_name = [];

		// <input>
		foreach ( $dom->getElementsByTagName( 'input' ) as $el ) {
			$name = (string) $el->getAttribute( 'name' );
			if ( $name === '' ) {
				continue;
			}
			$type = strtolower( (string) $el->getAttribute( 'type' ) );
			$kind = self::input_kind( $type, $name );

			// Radio buttons with the same name → one entry.
			// Checkbox: distinguish single (name="x") vs multi-group (name="x[]").
			if ( ! isset( $by_name[ $name ] ) ) {
				$by_name[ $name ] = [ 'name' => $name, 'kind' => $kind ];
			}
		}

		// <select>
		foreach ( $dom->getElementsByTagName( 'select' ) as $el ) {
			$name = (string) $el->getAttribute( 'name' );
			if ( $name === '' ) {
				continue;
			}
			$kind = $el->hasAttribute( 'multiple' ) ? 'select-multi' : 'select-single';
			$by_name[ $name ] = [ 'name' => $name, 'kind' => $kind ];
		}

		// <textarea>
		foreach ( $dom->getElementsByTagName( 'textarea' ) as $el ) {
			$name = (string) $el->getAttribute( 'name' );
			if ( $name === '' ) {
				continue;
			}
			$by_name[ $name ] = [ 'name' => $name, 'kind' => 'textarea' ];
		}

		// Drop reserved keys (handled by other plugin code, not user-mappable).
		$reserved = [ 'esf_hp', 'esf_tag', 'esf_list', 'form_id', 'post_id', 'action', '_wpnonce', '_wp_http_referer' ];
		foreach ( $reserved as $k ) {
			unset( $by_name[ $k ] );
		}

		return array_values( $by_name );
	}

	private static function input_kind( string $type, string $name ): string {
		// Multi-value name pattern (name="x[]")
		$is_multi = (bool) preg_match( '/\[\]$/', $name );

		switch ( $type ) {
			case 'email':           return 'email';
			case 'tel':             return 'tel';
			case 'url':             return 'url';
			case 'number':          return 'number';
			case 'range':           return 'number';
			case 'date':            return 'date';
			case 'time':            return 'time';
			case 'datetime-local':  return 'datetime';
			case 'hidden':          return 'hidden';
			case 'file':            return 'file';
			case 'password':        return 'text';
			case 'search':          return 'text';
			case 'color':           return 'text';
			case 'checkbox':        return $is_multi ? 'checkbox-multi' : 'checkbox-single';
			case 'radio':           return 'radio';
			case 'text':
			default:                return 'text';
		}
	}
}
