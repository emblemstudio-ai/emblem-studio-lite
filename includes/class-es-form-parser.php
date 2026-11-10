<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Form_Parser {

	public static function extract_fields( string $html ): array {
		if ( trim( $html ) === '' ) {
			return [];
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		/** @var array<string, array{name: string, kind: string, options?: array<int, string>}> $by_name */
		$by_name = [];
		/** @var array<string, int> $checkbox_count */
		$checkbox_count = [];

		foreach ( $dom->getElementsByTagName( 'input' ) as $el ) {
			$name = (string) $el->getAttribute( 'name' );
			if ( $name === '' ) {
				continue;
			}
			$type = strtolower( (string) $el->getAttribute( 'type' ) );
			$kind = self::input_kind( $type, $name );

			if ( ! isset( $by_name[ $name ] ) ) {
				$by_name[ $name ] = [ 'name' => $name, 'kind' => $kind ];
			}

			if ( $type === 'checkbox' || $type === 'radio' ) {
				$value = $el->hasAttribute( 'value' ) ? (string) $el->getAttribute( 'value' ) : 'on';
				self::add_option( $by_name[ $name ], $value );
			}

			if ( $type === 'checkbox' ) {
				$checkbox_count[ $name ] = ( $checkbox_count[ $name ] ?? 0 ) + 1;
				if ( $checkbox_count[ $name ] >= 2 && $by_name[ $name ]['kind'] === 'checkbox-single' ) {
					$by_name[ $name ]['kind'] = 'checkbox-multi';
				}
			}
		}

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

		foreach ( $dom->getElementsByTagName( 'textarea' ) as $el ) {
			$name = (string) $el->getAttribute( 'name' );
			if ( $name === '' ) {
				continue;
			}
			$by_name[ $name ] = [ 'name' => $name, 'kind' => 'textarea' ];
		}

		$reserved = [ 'esf_hp', 'esf_tag', 'esf_list', 'form_id', 'post_id', 'action', '_wpnonce', '_wp_http_referer' ];
		foreach ( $reserved as $k ) {
			unset( $by_name[ $k ] );
		}

		return array_values( $by_name );
	}

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
