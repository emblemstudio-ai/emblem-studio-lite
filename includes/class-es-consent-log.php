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
		$html     = self::rendered_html( $form_id, $form_html, $variant_slot );
		$consents = self::extract_consents( $html );
		if ( ! $consents ) {
			return;
		}

		global $wpdb;
		$texts_table   = ES_Install::table( 'consent_texts' );
		$consent_table = ES_Install::table( 'lead_consents' );
		// Best-effort convenience pointer only — form_revisions is pruned to the
		// last 50 per form (ES_Form_Revisions::KEEP_PER_FORM), so this can go
		// dangling. Never load-bearing; the hash is the durable proof. Resolved
		// once for all of them.
		$revision_id = self::latest_revision_id( $form_id );
		$page_url    = $post_id ? (string) get_permalink( $post_id ) : null;

		foreach ( $consents as $consent ) {
			$hash = hash( 'sha256', $consent['text'] );

			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$texts_table} WHERE hash = %s", $hash ) );
			if ( ! $exists ) {
				$wpdb->insert( $texts_table, [
					'hash'       => $hash,
					'text'       => $consent['text'],
					'created_at' => current_time( 'mysql' ),
				] );
			}

			// A replayed submission must never append a second row for the same
			// agreement, nor overwrite the first — this table is evidence and is
			// append-only. The unique index is (lead_id, field_name).
			$already = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$consent_table} WHERE lead_id = %d AND field_name = %s",
				$lead_id,
				$consent['field']
			) );
			if ( $already ) {
				continue;
			}

			// Same reading as the submit guard: `agree[]` arrives as `agree`, and a
			// ticked group arrives as a list rather than one string.
			$checked = ES_Ajax::checkbox_value_is_ticked( ES_Ajax::posted_value( $fields, $consent['field'] ) );

			$wpdb->insert( $consent_table, [
				'lead_id'           => $lead_id,
				'field_name'        => $consent['field'],
				'consent_text_hash' => $hash,
				'checkbox_state'    => $checked ? 1 : 0,
				'page_url'          => $page_url,
				'form_revision_id'  => $revision_id,
				'created_at'        => current_time( 'mysql' ),
			] );
		}
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
	 * Every consent checkbox in the form — each one's field name plus the exact
	 * text the visitor saw: the enclosing <label>'s text, with any link's href
	 * appended so a changed policy target changes the hash too.
	 *
	 * ALL of them, not the first: a visitor can agree to several separate things
	 * in one submission (store my data / email me about the beta), and recording
	 * only one misrepresents what they actually agreed to.
	 *
	 * The data-esf-consent marker is the ONLY thing that makes a checkbox a
	 * consent. This used to also treat any required checkbox as one, which was
	 * wrong in both directions: an optional "email me" IS an agreement and was
	 * missed, while a required "I have read the instructions" is NOT one and was
	 * recorded as if it were. Whether something is an agreement is the author's
	 * call, so it belongs on the checkbox, not in a guess here.
	 *
	 * Same detection rule as the SaaS's ConsentLog — kept in sync deliberately.
	 *
	 * @return array<int, array{field: string, text: string}>
	 */
	private static function extract_consents( string $html ): array {
		if ( trim( $html ) === '' ) {
			return [];
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$found = [];
		$seen  = [];

		foreach ( $dom->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			if ( ! $name || ! $input->hasAttribute( 'data-esf-consent' ) ) {
				continue;
			}
			if ( isset( $seen[ $name ] ) ) {
				continue; // one record per field name, matching the unique index
			}

			$label = self::closest_label( $input );
			$text  = $label ? self::normalized_text( $label ) : '';
			$href  = $label ? self::first_link_href( $label ) : null;
			$text  = trim( $text . ( $href ? " ({$href})" : '' ) );

			if ( $text === '' ) {
				continue; // no wording to prove — skip rather than log an empty hash
			}

			$seen[ $name ] = true;
			$found[]       = [
				'field' => $name,
				'text'  => $text,
			];
		}

		return $found;
	}

	/**
	 * Required checkboxes in this form that are NOT marked as consents.
	 *
	 * The marker is now the only thing that records a consent, which is the
	 * correct rule but a silent one: a form that relied on "required" simply
	 * stops being logged, and nobody finds out until they need the evidence and
	 * it isn't there.
	 *
	 * Not every required checkbox is a miss — "I have read the instructions" is
	 * a legitimately unmarked required box — so this reports, it never assumes.
	 *
	 * @return array<int, string> field names
	 */
	public static function unmarked_required_checkboxes( string $html ): array {
		if ( trim( $html ) === '' ) {
			return [];
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$names = [];
		foreach ( $dom->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			if ( ! $name || strtolower( $input->getAttribute( 'type' ) ) !== 'checkbox' ) {
				continue;
			}
			if ( $input->hasAttribute( 'required' ) && ! $input->hasAttribute( 'data-esf-consent' ) ) {
				$names[ $name ] = true;
			}
		}

		return array_keys( $names );
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

	/**
	 * The consent recorded for a set of leads, keyed by lead id — the read side
	 * of everything above.
	 *
	 * Recording proof nobody can see is not proof. Until this existed the
	 * evidence could only be reached by querying the database by hand, which is
	 * exactly the situation it was built to avoid: the point is being able to
	 * show, on demand, the wording someone agreed to at the moment they
	 * submitted, not the wording the form carries today.
	 *
	 * A lead simply absent from the result had no consent control on its form,
	 * which is legitimate — a checkbox isn't always the right lawful basis —
	 * and is a different fact from someone declining. Callers must keep those
	 * apart rather than defaulting a missing row to "no".
	 *
	 * Each lead maps to a LIST: a visitor can agree to several separate things
	 * in one submission, and each is recorded against its own checkbox.
	 *
	 * @param array<int,int> $lead_ids
	 * @return array<int, array<int, array{field: string, agreed: bool, text: string}>>
	 */
	public static function for_leads( array $lead_ids ): array {
		$lead_ids = array_values( array_unique( array_filter( array_map( 'intval', $lead_ids ) ) ) );
		if ( ! $lead_ids ) {
			return [];
		}

		global $wpdb;
		$consents = ES_Install::table( 'lead_consents' );
		$texts    = ES_Install::table( 'consent_texts' );
		$in       = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.lead_id, c.field_name, c.checkbox_state, t.text
				 FROM {$consents} c
				 LEFT JOIN {$texts} t ON t.hash = c.consent_text_hash
				 WHERE c.lead_id IN ({$in})
				 ORDER BY c.id",
				...$lead_ids
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['lead_id'] ][] = [
				'field'  => (string) ( $row['field_name'] ?? '' ),
				'agreed' => (bool) (int) $row['checkbox_state'],
				'text'   => (string) ( $row['text'] ?? '' ),
			];
		}

		return $out;
	}
}
