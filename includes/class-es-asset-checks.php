<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The editor's "Checks" list (SER-1358): mistakes in an asset's HTML and CSS
 * that break it, or quietly make it worse, found before a visitor finds them.
 *
 * ONE CONTRACT, TWO IMPLEMENTATIONS. The contract is the SaaS repo's
 * docs/ASSET-CHECKS.md; the web app runs it in app/Support/AssetChecks.php.
 * Rule ids, levels, order, detection and the English message and fix text are
 * identical in both, and tests/unit/AssetChecksTest.php carries the same
 * fixtures as the web app's test. Change a rule there first, then here and in
 * the test, in the same change.
 *
 * The checks never block a save. They warn: a person may know better, and a
 * half-written asset is a normal thing to save.
 *
 * The English strings are the contract text. They are wrapped for translation
 * only so a translated admin reads naturally; without a translation loaded the
 * output is the contract text, character for character.
 *
 * Pure PHP (DOMDocument + regex), no database. Loaded in every edition, behind
 * the is_admin() gate like ES_Authoring, because only the editor uses it.
 */
class ES_Asset_Checks {

	/** <input> types that capture nothing. Same list as ES_Form_Repo::has_fields(). */
	const NON_FIELD_INPUT_TYPES = [ 'hidden', 'submit', 'button', 'image', 'reset' ];

	/** <input> types a reserved name is ignored on (they send no value of their own worth keeping). */
	const RESERVED_SKIP_TYPES = [ 'submit', 'button', 'reset', 'image' ];

	/** Names Emblem Studio's own submission uses. A field with one of these loses its answer. */
	const RESERVED_NAMES = [
		'action',
		'_wpnonce',
		'_wp_http_referer',
		'form_id',
		'post_id',
		'type',
		'shell',
		'variant',
		'page',
		'vid',
		'esf_hp',
		'es_ts',
		'es_el',
		'es_preview',
		'es_preview_token',
	];

	/**
	 * Run every check, in contract order (all errors before all warnings).
	 *
	 * @return list<array{id: string, level: string, message: string, fix: string}>
	 */
	public static function run( string $html, string $css ): array {
		if ( trim( $html ) === '' ) {
			return [];
		}

		$dom   = self::parse( $html );
		$xpath = new DOMXPath( $dom );
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		$fields  = self::real_fields( $xpath );
		$is_form = count( $fields ) > 0;
		$submits = self::submit_controls( $xpath );
		$items   = [];

		// 1. no_root: the runtime takes the first <form> in the asset, whatever its
		// class, so only a missing <form> breaks it.
		$forms = $xpath->query( '//form' );
		if ( ! $forms || 0 === $forms->length ) {
			$items[] = self::item(
				'no_root',
				'error',
				__( 'The HTML has no <form> root, so Emblem Studio can\'t run the form.', 'emblem-studio' ),
				__( 'Wrap everything in one <form class="esf-form" novalidate>.', 'emblem-studio' )
			);
		}

		// 2. no_email
		if ( $is_form ) {
			$has_email = false;
			foreach ( $xpath->query( '//input' ) ?: [] as $input ) {
				if ( $input instanceof DOMElement && $input->getAttribute( 'name' ) === 'email' ) {
					$has_email = true;
					break;
				}
			}
			if ( ! $has_email ) {
				$items[] = self::item(
					'no_email',
					'error',
					__( 'There is no field named "email", so every signup will fail.', 'emblem-studio' ),
					__( 'Add <input type="email" name="email" required>.', 'emblem-studio' )
				);
			}
		}

		// 3. no_submit
		if ( $is_form && ! $submits ) {
			$items[] = self::item(
				'no_submit',
				'error',
				__( 'There is no submit button, so visitors can\'t send the form.', 'emblem-studio' ),
				__( 'Add <button type="submit">.', 'emblem-studio' )
			);
		}

		// 4. unnamed_field
		$unnamed = 0;
		foreach ( $fields as $field ) {
			if ( trim( $field->getAttribute( 'name' ) ) === '' ) {
				++$unnamed;
			}
		}
		if ( $unnamed > 0 ) {
			$items[] = self::item(
				'unnamed_field',
				'error',
				sprintf(
					/* translators: %d: how many fields have no name */
					_n(
						'A field has no name, so what visitors type in it is thrown away.',
						'%d fields have no name, so what visitors type in them is thrown away.',
						$unnamed,
						'emblem-studio'
					),
					$unnamed
				),
				__( 'Give every field a name="...".', 'emblem-studio' )
			);
		}

		// 5. reserved_name (per name)
		$reserved = [];
		foreach ( $xpath->query( '//input | //select | //textarea' ) ?: [] as $el ) {
			if ( ! $el instanceof DOMElement ) {
				continue;
			}
			if ( strtolower( $el->tagName ) === 'input' && in_array( self::input_type( $el ), self::RESERVED_SKIP_TYPES, true ) ) {
				continue;
			}
			$name = $el->getAttribute( 'name' );
			$base = substr( $name, -2 ) === '[]' ? substr( $name, 0, -2 ) : $name;
			if ( in_array( $base, self::RESERVED_NAMES, true ) ) {
				$reserved[ $name ] = true;
			}
		}
		foreach ( array_keys( $reserved ) as $name ) {
			$items[] = self::item(
				'reserved_name',
				'error',
				sprintf(
					/* translators: %s: the field name as written in the HTML */
					__( 'A field is named "%s". Emblem Studio uses that name itself, so the answer is lost.', 'emblem-studio' ),
					(string) $name
				),
				__( 'Rename the field.', 'emblem-studio' )
			);
		}

		// 6. own_overlay
		if ( preg_match( '/position\s*:\s*fixed\b/i', $css ) ) {
			$items[] = self::item(
				'own_overlay',
				'error',
				__( 'The CSS uses position: fixed, so the form covers the page instead of sitting in its popup, ribbon or slide-in.', 'emblem-studio' ),
				__( 'Remove position: fixed. Emblem Studio places the form for you.', 'emblem-studio' )
			);
		}

		// 7. own_close (one item)
		if ( self::has_own_close( $xpath ) ) {
			$items[] = self::item(
				'own_close',
				'error',
				__( 'The form has its own close button. It won\'t work, because scripts are removed, and the popup already adds one.', 'emblem-studio' ),
				__( 'Remove the close button.', 'emblem-studio' )
			);
		}

		// 8. container_query
		if ( preg_match( '/container-type\s*:|@container\b/i', $css ) ) {
			$items[] = self::item(
				'container_query',
				'error',
				__( 'The CSS uses container queries. In a popup they can squeeze the form to its narrowest width.', 'emblem-studio' ),
				__( 'Use @media queries or clamp() instead.', 'emblem-studio' )
			);
		}

		// 9. checkbox_group_no_brackets (per name, warning)
		$checkbox_counts = [];
		foreach ( $xpath->query( '//input' ) ?: [] as $input ) {
			if ( ! $input instanceof DOMElement || self::input_type( $input ) !== 'checkbox' ) {
				continue;
			}
			$name = $input->getAttribute( 'name' );
			if ( trim( $name ) === '' || substr( $name, -2 ) === '[]' ) {
				continue;
			}
			$checkbox_counts[ $name ] = ( $checkbox_counts[ $name ] ?? 0 ) + 1;
		}
		foreach ( $checkbox_counts as $name => $count ) {
			if ( $count < 2 ) {
				continue;
			}
			$items[] = self::item(
				'checkbox_group_no_brackets',
				'warning',
				sprintf(
					/* translators: %s: the shared checkbox name as written in the HTML */
					__( 'Several checkboxes share the name "%s". Only one of the ticked answers is kept.', 'emblem-studio' ),
					(string) $name
				),
				sprintf(
					/* translators: %s: the shared checkbox name as written in the HTML */
					__( 'Name them "%s[]".', 'emblem-studio' ),
					(string) $name
				)
			);
		}

		// 10. unmarked_consent (per name) — the consent log's own check, so the
		// two can never disagree about which boxes go unrecorded.
		foreach ( ES_Consent_Log::unmarked_required_checkboxes( $html ) as $name ) {
			$items[] = self::item(
				'unmarked_consent',
				'warning',
				sprintf(
					/* translators: %s: the checkbox name as written in the HTML */
					__( 'The required checkbox "%s" has no data-esf-consent. If it asks visitors to agree to something, their agreement is not recorded.', 'emblem-studio' ),
					(string) $name
				),
				__( 'Add data-esf-consent. Ignore this if the box is not an agreement.', 'emblem-studio' )
			);
		}

		// 11. consent_without_label (per name)
		$unlabelled = [];
		foreach ( $xpath->query( '//input' ) ?: [] as $input ) {
			if ( ! $input instanceof DOMElement || ! $input->hasAttribute( 'data-esf-consent' ) ) {
				continue;
			}
			$name = $input->getAttribute( 'name' );
			if ( trim( $name ) === '' ) {
				continue;
			}
			$label = self::closest_label( $input );
			$text  = $label ? trim( (string) preg_replace( '/\s+/', ' ', $label->textContent ) ) : '';
			if ( $text === '' ) {
				$unlabelled[ $name ] = true;
			}
		}
		foreach ( array_keys( $unlabelled ) as $name ) {
			$items[] = self::item(
				'consent_without_label',
				'warning',
				sprintf(
					/* translators: %s: the consent checkbox name as written in the HTML */
					__( 'The consent box "%s" has no <label> around its wording, so the wording is not recorded.', 'emblem-studio' ),
					(string) $name
				),
				__( 'Put the checkbox and its wording inside one <label>.', 'emblem-studio' )
			);
		}

		// 12. no_width
		if ( self::max_width_without_width( $css ) ) {
			$items[] = self::item(
				'no_width',
				'warning',
				__( 'The form sets a max-width but no width, so a popup with little text shrinks to 320px.', 'emblem-studio' ),
				__( 'Add width: 480px; max-width: 100%; to .esf-form, or use your own width.', 'emblem-studio' )
			);
		}

		// 13. tracked_submit (one item)
		foreach ( $submits as $submit ) {
			if ( $submit->hasAttribute( 'data-es-track' ) ) {
				$items[] = self::item(
					'tracked_submit',
					'warning',
					__( 'The submit button has data-es-track, so every signup is also counted as a click.', 'emblem-studio' ),
					__( 'Remove data-es-track from the submit button.', 'emblem-studio' )
				);
				break;
			}
		}

		// 14. no_hidden_rule
		$thankyou = $xpath->query( '//*[@data-esf-thankyou]' );
		if ( $thankyou && $thankyou->length > 0 && ! preg_match( '/\[hidden\][^{]*\{[^}]*display\s*:\s*none/i', $css ) ) {
			$items[] = self::item(
				'no_hidden_rule',
				'warning',
				__( 'The CSS is missing the [hidden] rule, so the thank-you message can show before anyone signs up.', 'emblem-studio' ),
				__( 'Start the CSS with [hidden] { display: none !important; }', 'emblem-studio' )
			);
		}

		// 15. stripped_code (one item)
		if ( self::has_stripped_code( $xpath ) ) {
			$items[] = self::item(
				'stripped_code',
				'warning',
				__( 'The HTML has a <script> or an event handler like onclick. Both are removed on save, so they won\'t run.', 'emblem-studio' ),
				__( 'Remove them. Emblem Studio runs the form for you.', 'emblem-studio' )
			);
		}

		return $items;
	}

	/**
	 * Parsed exactly the way ES_Consent_Log parses, so the two agree about what
	 * a field is.
	 */
	private static function parse( string $html ): DOMDocument {
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $dom;
	}

	/**
	 * @return array{id: string, level: string, message: string, fix: string}
	 */
	private static function item( string $id, string $level, string $message, string $fix ): array {
		return [
			'id'      => $id,
			'level'   => $level,
			'message' => $message,
			'fix'     => $fix,
		];
	}

	/** An <input>'s type: lowercased, trimmed, `text` when absent or blank. */
	private static function input_type( DOMElement $input ): string {
		$type = strtolower( trim( $input->getAttribute( 'type' ) ) );

		return $type === '' ? 'text' : $type;
	}

	/** @return list<string> */
	private static function class_tokens( DOMElement $el ): array {
		$tokens = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );

		return array_values( array_filter( $tokens ?: [], static function ( $t ) {
			return $t !== '';
		} ) );
	}

	/**
	 * Every <select>, <textarea> and capturing <input>, in document order.
	 *
	 * @return list<DOMElement>
	 */
	private static function real_fields( DOMXPath $xpath ): array {
		$out = [];
		foreach ( $xpath->query( '//input | //select | //textarea' ) ?: [] as $el ) {
			if ( ! $el instanceof DOMElement ) {
				continue;
			}
			if ( strtolower( $el->tagName ) === 'input' && in_array( self::input_type( $el ), self::NON_FIELD_INPUT_TYPES, true ) ) {
				continue;
			}
			$out[] = $el;
		}

		return $out;
	}

	/**
	 * A <button> whose type is absent or `submit`, or an <input> of type
	 * `submit` or `image`, in document order.
	 *
	 * @return list<DOMElement>
	 */
	private static function submit_controls( DOMXPath $xpath ): array {
		$out = [];
		foreach ( $xpath->query( '//button | //input' ) ?: [] as $el ) {
			if ( ! $el instanceof DOMElement ) {
				continue;
			}
			if ( strtolower( $el->tagName ) === 'button' ) {
				if ( ! $el->hasAttribute( 'type' ) || strtolower( trim( $el->getAttribute( 'type' ) ) ) === 'submit' ) {
					$out[] = $el;
				}
				continue;
			}
			$type = strtolower( trim( $el->getAttribute( 'type' ) ) );
			if ( $type === 'submit' || $type === 'image' ) {
				$out[] = $el;
			}
		}

		return $out;
	}

	private static function has_own_close( DOMXPath $xpath ): bool {
		foreach ( $xpath->query( '//button | //a | //*[@role="button"]' ) ?: [] as $el ) {
			if ( ! $el instanceof DOMElement ) {
				continue;
			}
			foreach ( self::class_tokens( $el ) as $token ) {
				if ( preg_match( '/^(?:.*[-_])?(close|dismiss)(?:[-_].*)?$/i', $token ) ) {
					return true;
				}
			}
			if ( preg_match( '/\b(close|dismiss)\b/i', $el->getAttribute( 'aria-label' ) ) ) {
				return true;
			}
			$tag = strtolower( $el->tagName );
			if ( ( $tag === 'button' || $tag === 'a' ) && in_array( trim( $el->textContent ), [ '×', '✕', '✖', 'x', 'X' ], true ) ) {
				return true;
			}
		}

		return false;
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

	/**
	 * Across every block whose selector list holds exactly `.esf-form` (a rule
	 * inside @media counts too): at least one sets max-width, and none sets width.
	 * $css has its comments stripped already.
	 */
	private static function max_width_without_width( string $css ): bool {
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER ) ) {
			return false;
		}

		$max_width = false;
		foreach ( $blocks as $block ) {
			$selectors = array_map( 'trim', explode( ',', $block[1] ) );
			if ( ! in_array( '.esf-form', $selectors, true ) ) {
				continue;
			}
			if ( preg_match( '/(?<![-\w])width\s*:/i', $block[2] ) ) {
				return false;
			}
			if ( preg_match( '/max-width\s*:/i', $block[2] ) ) {
				$max_width = true;
			}
		}

		return $max_width;
	}

	/** A <script>, an on* attribute, or a javascript: URL — all removed on save. */
	private static function has_stripped_code( DOMXPath $xpath ): bool {
		$scripts = $xpath->query( '//script' );
		if ( $scripts && $scripts->length > 0 ) {
			return true;
		}

		foreach ( $xpath->query( '//*' ) ?: [] as $el ) {
			if ( ! $el instanceof DOMElement ) {
				continue;
			}
			foreach ( $el->attributes as $attr ) {
				$name = strtolower( $attr->nodeName );
				if ( strpos( $name, 'on' ) === 0 ) {
					return true;
				}
				if ( in_array( $name, [ 'href', 'src', 'action', 'formaction' ], true ) && preg_match( '/^\s*javascript:/i', (string) $attr->nodeValue ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
