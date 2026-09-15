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
 * Checkboxes that share a plain name (no `[]`) also count as a multi group.
 * Choice fields (checkbox, radio, select) carry `options`: the values they
 * can send, from the value attributes. The FunnelKit field mapper compares
 * those with the FunnelKit field's own option labels.
 *
 * Returns: array<int, array{ name: string, kind: string, options?: array<int, string> }>
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

		/** @var array<string, array{name: string, kind: string, options?: array<int, string>}> $by_name */
		$by_name = [];
		/** @var array<string, int> $checkbox_count */
		$checkbox_count = [];

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

			// The answers a choice input can send: its value attribute, or "on"
			// when it has none (what a browser posts for a bare checkbox).
			if ( $type === 'checkbox' || $type === 'radio' ) {
				$value = $el->hasAttribute( 'value' ) ? (string) $el->getAttribute( 'value' ) : 'on';
				self::add_option( $by_name[ $name ], $value );
			}

			// Several checkboxes sharing a plain name are a group too: frontend.js
			// sends two or more ticked ones as a list (name[]), so it is multi.
			if ( $type === 'checkbox' ) {
				$checkbox_count[ $name ] = ( $checkbox_count[ $name ] ?? 0 ) + 1;
				if ( $checkbox_count[ $name ] >= 2 && $by_name[ $name ]['kind'] === 'checkbox-single' ) {
					$by_name[ $name ]['kind'] = 'checkbox-multi';
				}
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
			foreach ( $el->getElementsByTagName( 'option' ) as $option ) {
				$value = $option->hasAttribute( 'value' )
					? (string) $option->getAttribute( 'value' )
					: trim( (string) $option->textContent );
				self::add_option( $by_name[ $name ], $value );
			}
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

	/**
	 * Record one answer a choice field can send, once. An empty value is a
	 * placeholder ("Choose one"), not an answer, so it is skipped.
	 *
	 * @param array{name: string, kind: string, options?: array<int, string>} $field
	 */
	private static function add_option( array &$field, string $value ): void {
		if ( $value === '' ) {
			return;
		}
		$options = $field['options'] ?? [];
		if ( ! in_array( $value, $options, true ) ) {
			$options[] = $value;
		}
		$field['options'] = $options;
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
