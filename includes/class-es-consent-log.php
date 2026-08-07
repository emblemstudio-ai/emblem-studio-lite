<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable per-submission consent logging (SER-853) — ported from the SaaS
 * (emblem-studio-saas app/Services/ConsentLog.php; keep both in sync). Finds
 * the consent checkbox in the exact HTML rendered to the visitor, hashes its
 * label text + policy-link target, and records the affirmative act — same
 * detection rule as ES_Ajax::find_missing_consent_fields (kept in sync
 * deliberately: a required checkbox, or one marked data-esf-consent).
 *
 * The wording is stored once, content-addressed by hash
 * (emblemstudio_consent_texts); the tiny per-submission row
 * (emblemstudio_lead_consents) just references that hash. Neither table
 * depends on emblemstudio_form_revisions or emblemstudio_forms, so a record
 * survives ordinary revision pruning (ES_Form_Revisions::prune) and form
 * deletion.
 *
 * A form with no consent control simply has nothing to log — record() is
 * then a no-op, not an error. No IP is stored, matching the plugin's
 * existing no-IP-for-genuine-subscribers stance. Loaded in every edition
 * (like revisions) — this is compliance infrastructure, not a paid feature.
 */
class ES_Consent_Log {

	/**
	 * @param array<string, mixed> $fields the collected POST fields (an unchecked
	 *                                     checkbox is simply absent, per HTML form semantics)
	 */
	public static function record( int $form_id, string $form_html, int $lead_id, array $fields, ?int $post_id, ?string $variant_slot = null ): void {
		$html    = self::rendered_html( $form_id, $form_html, $variant_slot );
		$consent = self::extract_consent( $html );
		if ( ! $consent ) {
			return;
		}

		global $wpdb;
		$hash = hash( 'sha256', $consent['text'] );

		$texts_table = ES_Install::table( 'consent_texts' );
		$exists      = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$texts_table} WHERE hash = %s", $hash ) );
		if ( ! $exists ) {
			$wpdb->insert( $texts_table, [
				'hash'       => $hash,
				'text'       => $consent['text'],
				'created_at' => current_time( 'mysql' ),
			] );
		}

		$value   = $fields[ $consent['field'] ] ?? null;
		$checked = $value !== null && $value !== '' && ! in_array( strtolower( (string) $value ), [ 'off', 'false' ], true );

		$wpdb->insert( ES_Install::table( 'lead_consents' ), [
			'lead_id'           => $lead_id,
			'consent_text_hash' => $hash,
			'checkbox_state'    => $checked ? 1 : 0,
			'page_url'          => $post_id ? (string) get_permalink( $post_id ) : null,
			// Best-effort convenience pointer only — form_revisions is pruned to
			// the last 50 per form (ES_Form_Revisions::KEEP_PER_FORM), so this
			// can go dangling. Never load-bearing; the hash above is the durable proof.
			'form_revision_id' => self::latest_revision_id( $form_id ),
			'created_at'       => current_time( 'mysql' ),
		] );
	}

	/** The HTML actually served to this visitor: their A/B slot if one was live, else the form's own. */
	private static function rendered_html( int $form_id, string $form_html, ?string $variant_slot ): string {
		if ( $variant_slot ) {
			global $wpdb;
			$variants = ES_Install::table( 'variants' );
			$variant_html = $wpdb->get_var( $wpdb->prepare(
				"SELECT html FROM {$variants} WHERE form_id = %d AND slot = %s ORDER BY id DESC LIMIT 1",
				$form_id,
				$variant_slot
			) );
			if ( $variant_html ) {
				return (string) $variant_html;
			}
		}

		return $form_html;
	}

	private static function latest_revision_id( int $form_id ): ?int {
		global $wpdb;
		$revisions = ES_Install::table( 'form_revisions' );
		$id        = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$revisions} WHERE form_id = %d ORDER BY id DESC LIMIT 1", $form_id ) );

		return $id ? (int) $id : null;
	}

	/**
	 * Find the consent checkbox and pull its field name plus the exact text
	 * the visitor saw: the enclosing <label>'s text, with any link's href
	 * appended so a changed policy-link target changes the hash too.
	 *
	 * @return array{field: string, text: string}|null
	 */
	private static function extract_consent( string $html ): ?array {
		if ( trim( $html ) === '' ) {
			return null;
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( $dom->getElementsByTagName( 'input' ) as $input ) {
			$type = strtolower( $input->getAttribute( 'type' ) );
			$name = $input->getAttribute( 'name' );
			if ( ! $name ) {
				continue;
			}

			$is_required_checkbox = ( $type === 'checkbox' && $input->hasAttribute( 'required' ) );
			$has_consent_marker   = $input->hasAttribute( 'data-esf-consent' );
			if ( ! $is_required_checkbox && ! $has_consent_marker ) {
				continue;
			}

			$label = self::closest_label( $input );
			$text  = $label ? self::normalized_text( $label ) : '';
			$href  = $label ? self::first_link_href( $label ) : null;
			$text  = trim( $text . ( $href ? " ({$href})" : '' ) );

			if ( $text === '' ) {
				continue; // no wording to prove — skip rather than log an empty hash
			}

			return [
				'field' => $name,
				'text'  => $text,
			];
		}

		return null;
	}

	private static function closest_label( DOMElement $node ): ?DOMElement {
		$current = $node->parentNode;
		while ( $current instanceof DOMElement ) {
			if ( strtolower( $current->tagName ) === 'label' ) {
				return $current;
			}
			$current = $current->parentNode;
		}

		return null;
	}

	private static function normalized_text( DOMElement $label ): string {
		return trim( preg_replace( '/\s+/', ' ', $label->textContent ) );
	}

	private static function first_link_href( DOMElement $label ): ?string {
		$links = $label->getElementsByTagName( 'a' );

		return $links->length > 0 ? $links->item( 0 )->getAttribute( 'href' ) : null;
	}
}
