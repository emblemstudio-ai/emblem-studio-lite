<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Field_Matcher {

	public const EMAIL_NAME_VARIANTS  = [ 'email', 'your-email', 'email_address', 'user_email', 'e-mail' ];
	public const FIRST_NAME_VARIANTS  = [ 'first_name', 'fname', 'firstname', 'first-name' ];
	public const LAST_NAME_VARIANTS   = [ 'last_name', 'lname', 'surname', 'last-name', 'lastname' ];
	public const PHONE_NAME_VARIANTS  = [ 'phone', 'tel', 'phone_number', 'mobile', 'contact_number' ];

	public static function find_by_name( array $field_names, array $candidates ): ?string {
		foreach ( $candidates as $candidate ) {
			foreach ( $field_names as $name ) {
				if ( strcasecmp( $name, $candidate ) === 0 ) {
					return $name;
				}
			}
		}
		return null;
	}

	public static function find_by_kind( array $field_names, array $field_kinds, string $kind ): ?string {
		foreach ( $field_names as $name ) {
			if ( ( $field_kinds[ $name ] ?? '' ) === $kind ) {
				return $name;
			}
		}
		return null;
	}

	public static function funnelkit_options( int $type, string $meta_json ): array {
		if ( ! in_array( $type, [ 4, 5, 6 ], true ) || $meta_json === '' ) {
			return [];
		}
		$meta    = json_decode( $meta_json, true );
		$options = is_array( $meta ) && is_array( $meta['options'] ?? null ) ? $meta['options'] : [];
		$out     = [];
		foreach ( $options as $option ) {
			if ( is_scalar( $option ) && (string) $option !== '' ) {
				$out[] = (string) $option;
			}
		}
		return $out;
	}
}
