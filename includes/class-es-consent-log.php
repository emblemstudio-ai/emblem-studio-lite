<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Consent_Log {

	public static function record( int $form_id, string $form_html, int $lead_id, array $fields, ?int $post_id, ?string $variant_slot = null ): void {
		$html     = self::rendered_html( $form_id, $form_html, $variant_slot );
		$consents = self::extract_consents( $html );
		if ( ! $consents ) {
			return;
		}

		global $wpdb;
		$texts_table   = Emblem_Studio_Install::table( 'consent_texts' );
		$consent_table = Emblem_Studio_Install::table( 'lead_consents' );
		$revision_id = self::latest_revision_id( $form_id );
		$page_url    = $post_id ? (string) get_permalink( $post_id ) : null;
		if ( $page_url !== null && strlen( $page_url ) > 255 ) {
			$page_url = function_exists( 'mb_strcut' ) ? mb_strcut( $page_url, 0, 255, 'UTF-8' ) : substr( $page_url, 0, 255 );
		}
		$leads_table = Emblem_Studio_Install::table( 'leads' );
		$email       = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$leads_table} WHERE id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$email       = is_string( $email ) && $email !== '' ? $email : null;

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

			$already = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$consent_table} WHERE lead_id = %d AND field_name = %s",
				$lead_id,
				$consent['field']
			) );
			if ( $already ) {
				continue;
			}

			$checked = Emblem_Studio_Ajax::checkbox_value_is_ticked( Emblem_Studio_Ajax::posted_value( $fields, $consent['field'] ) );

			$saved = $wpdb->insert( $consent_table, [
				'lead_id'           => $lead_id,
				'email'             => $email,
				'field_name'        => $consent['field'],
				'consent_text_hash' => $hash,
				'checkbox_state'    => $checked ? 1 : 0,
				'page_url'          => $page_url,
				'form_revision_id'  => $revision_id,
				'created_at'        => current_time( 'mysql' ),
			] );
			if ( $saved === false && class_exists( 'Emblem_Studio_Logger' ) ) {
				Emblem_Studio_Logger::error( 'Consent record could not be saved', [ 'lead_id' => $lead_id, 'field' => $consent['field'], 'db_error' => (string) $wpdb->last_error ] );
			}
		}
	}

	public static function erase( string $email, array $lead_ids ) {
		global $wpdb;
		$table = Emblem_Studio_Install::table( 'lead_consents' );
		$email = strtolower( trim( $email ) );
		$ids   = array_values( array_filter( array_map( 'intval', $lead_ids ) ) );

		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $removed === false ) { return false; }
		if ( $ids ) {
			$in       = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE lead_id IN ({$in})", ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $count === false ) { return false; }
			$removed += $count;
		}

		return $removed;
	}

	private static function rendered_html( int $form_id, string $form_html, ?string $variant_slot ): string {
		if ( $variant_slot ) {
			global $wpdb;
			$variants = Emblem_Studio_Install::table( 'variants' );
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
		$revisions = Emblem_Studio_Install::table( 'form_revisions' );
		$id        = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$revisions} WHERE form_id = %d ORDER BY id DESC LIMIT 1", $form_id ) );

		return $id ? (int) $id : null;
	}

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
				continue;
			}

			$label = self::closest_label( $input );
			$text  = $label ? self::normalized_text( $label ) : '';
			$href  = $label ? self::first_link_href( $label ) : null;
			$text  = trim( $text . ( $href ? " ({$href})" : '' ) );

			if ( $text === '' ) {
				continue;
			}

			$seen[ $name ] = true;
			$found[]       = [
				'field' => $name,
				'text'  => $text,
			];
		}

		return $found;
	}

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

	public static function for_leads( array $lead_ids ): array {
		$lead_ids = array_values( array_unique( array_filter( array_map( 'intval', $lead_ids ) ) ) );
		if ( ! $lead_ids ) {
			return [];
		}

		global $wpdb;
		$consents = Emblem_Studio_Install::table( 'lead_consents' );
		$texts    = Emblem_Studio_Install::table( 'consent_texts' );
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
