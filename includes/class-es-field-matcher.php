<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-detect matching for FunnelKit's core columns (email / first name / last
 * name / phone) against a submitted form's field names — pure logic, no
 * WordPress dependency, so ES_Ajax::submit() (the authoritative, server-side
 * caller) and assets/admin.js's autoDetected() (the mapper UI's "auto" hint)
 * agree on what counts as a match. Keep the two in sync when either changes.
 */
class ES_Field_Matcher {

	public const EMAIL_NAME_VARIANTS  = [ 'email', 'your-email', 'email_address', 'user_email', 'e-mail' ];
	public const FIRST_NAME_VARIANTS  = [ 'first_name', 'fname', 'firstname', 'first-name' ];
	public const LAST_NAME_VARIANTS   = [ 'last_name', 'lname', 'surname', 'last-name', 'lastname' ];
	public const PHONE_NAME_VARIANTS  = [ 'phone', 'tel', 'phone_number', 'mobile', 'contact_number' ];

	/**
	 * First posted field name matching any candidate, case-insensitively.
	 *
	 * @param string[] $field_names Names actually posted (or on the form).
	 * @param string[] $candidates  Name-convention variants to try, in order.
	 */
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

	/**
	 * First posted field name whose parsed HTML input kind (from
	 * ES_Form_Parser::extract_fields()) matches — a last-resort signal when no
	 * name variant fits, e.g. a field named "contact" but type="tel".
	 *
	 * @param string[]              $field_names Names actually posted.
	 * @param array<string, string> $field_kinds Field name => kind (e.g. 'email', 'tel').
	 */
	public static function find_by_kind( array $field_names, array $field_kinds, string $kind ): ?string {
		foreach ( $field_names as $name ) {
			if ( ( $field_kinds[ $name ] ?? '' ) === $kind ) {
				return $name;
			}
		}
		return null;
	}
}
