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
		'es_stamp',
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

		// 2. no_email: no email field the runtime can find. That is more than a
		// field named `email`: ES_Ajax also takes the usual other names and the
		// only email-type box on the form. This used to be an error for those,
		// although signups through them worked (SER-1430). They hear about the
		// name in email_misnamed at the end instead, as a warning.
		$email_field = null;
		if ( $is_form ) {
			$input_names = [];
			$typed       = [];
			foreach ( $xpath->query( '//input' ) ?: [] as $input ) {
				if ( ! $input instanceof DOMElement ) {
					continue;
				}
				$name = trim( $input->getAttribute( 'name' ) );
				if ( $name === '' ) {
					continue;
				}
				$input_names[] = $name;
				if ( strtolower( trim( $input->getAttribute( 'type' ) ) ) === 'email' && ! in_array( $name, $typed, true ) ) {
					$typed[] = $name;
				}
			}
			$email_field = ES_Field_Matcher::find_by_name( $input_names, ES_Field_Matcher::EMAIL_NAME_VARIANTS );
			if ( $email_field === null && count( $typed ) === 1 ) {
				$email_field = $typed[0];
			}
			if ( $email_field === null ) {
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

		// 4. unnamed_field. A hidden checkbox that the CSS reads with :checked is
		// an open/close switch, not a field: nobody types in it and it has
		// nothing to send, so it needs no name (TST's account drawer).
		$unnamed = 0;
		foreach ( $fields as $field ) {
			if ( trim( $field->getAttribute( 'name' ) ) === '' && ! self::is_css_switch( $field, $css ) ) {
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

		// 9 to 13, and 21. Live values (SER-1352, the SaaS repo's docs/LIVE-VALUES.md).
		// Each rule is one item however many markers break it.
		$live     = self::live_problems( $xpath );
		$live_msg = [
			'live_unknown_field'  => [
				__( 'A live value names a field that does not exist, so it always shows its fallback words.', 'emblem-studio' ),
				__( 'Use one of price.today, price.regular, price.sale, sale.ends, coupon.discount, coupon.percent_off, coupon.price_after, coupon.expires or coupon.uses_left.', 'emblem-studio' ),
			],
			'live_no_product'     => [
				__( 'A live value has no data-es-product, so the shop is never asked for it.', 'emblem-studio' ),
				__( 'Add data-es-product with the WooCommerce product id.', 'emblem-studio' ),
			],
			'live_no_coupon'      => [
				__( 'A coupon live value has no data-es-coupon, so the shop is never asked for it.', 'emblem-studio' ),
				__( 'Add data-es-coupon with the coupon code.', 'emblem-studio' ),
			],
			'live_empty_fallback' => [
				__( 'A live value has no fallback words, so it shows nothing whenever its number is not safe to show.', 'emblem-studio' ),
				__( 'Write words inside it that read well without the number, like "a discount".', 'emblem-studio' ),
			],
			'live_nested'         => [
				__( 'A live value sits inside another live value, so it is replaced before it can show.', 'emblem-studio' ),
				__( 'Move it out of the other live value.', 'emblem-studio' ),
			],
		];
		foreach ( $live_msg as $id => $text ) {
			if ( isset( $live[ $id ] ) ) {
				$items[] = self::item( $id, 'error', $text[0], $text[1] );
			}
		}

		// 14. checkbox_group_no_brackets (per name, warning)
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

		// 15. unmarked_consent (per name) — the consent log's own check, so the
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

		// 16. consent_without_label (per name)
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

		// 17. no_width
		if ( self::max_width_without_width( $css ) ) {
			$items[] = self::item(
				'no_width',
				'warning',
				__( 'The form sets a max-width but no width, so a popup with little text shrinks to 320px.', 'emblem-studio' ),
				__( 'Add width: 480px; max-width: 100%; to .esf-form, or use your own width.', 'emblem-studio' )
			);
		}

		// 17b. has_only_reveal
		if ( self::has_only_reveal( $css ) ) {
			$items[] = self::item(
				'has_only_reveal',
				'warning',
				__( 'A rule uses :has() to make something appear. iPhones before iOS 15.4 and older Firefox skip that rule, so there it never shows.', 'emblem-studio' ),
				__( 'Show it by default and hide it with :not(:has(...)) instead. Older browsers then simply always show it.', 'emblem-studio' )
			);
		}

		// 18. tracked_submit (one item)
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

		// 19. no_hidden_rule
		$thankyou = $xpath->query( '//*[@data-esf-thankyou]' );
		if ( $thankyou && $thankyou->length > 0 && ! preg_match( '/\[hidden\][^{]*\{[^}]*display\s*:\s*none/i', $css ) ) {
			$items[] = self::item(
				'no_hidden_rule',
				'warning',
				__( 'The CSS is missing the [hidden] rule, so the thank-you message can show before anyone signs up.', 'emblem-studio' ),
				__( 'Start the CSS with [hidden] { display: none !important; }', 'emblem-studio' )
			);
		}

		// 20. stripped_code (one item)
		if ( self::has_stripped_code( $xpath ) ) {
			$items[] = self::item(
				'stripped_code',
				'warning',
				__( 'The HTML has a <script> or an event handler like onclick. Both are removed on save, so they won\'t run.', 'emblem-studio' ),
				__( 'Remove them. Emblem Studio runs the form for you.', 'emblem-studio' )
			);
		}

		// 21. live_number_in_fallback
		if ( isset( $live['live_number_in_fallback'] ) ) {
			$items[] = self::item(
				'live_number_in_fallback',
				'warning',
				__( 'A live value\'s fallback words contain a number. They show exactly when the number is not safe, so they can state an old one.', 'emblem-studio' ),
				__( 'Write words without a number, like "a discount" or "less".', 'emblem-studio' )
			);
		}

		// 22. no_thankyou: a form that says nothing back.
		//
		// On a successful submit the runtime hides the form and reveals the element
		// marked data-esf-thankyou. With no such element the card simply vanishes,
		// which reads as a bug to the person who just handed over their address
		// (SER-1419, item 13 of Catalina's test pass). The plain "custom message"
		// option used to paper over this and is gone, because it had no design of
		// its own and landed as black text in the corner of the page.
		//
		// A warning, never an error. An asset that redirects after a signup is right
		// to have no thank-you state, and only the settings know that, while this
		// runs on the HTML and CSS alone. The legacy markers count: the runtime
		// still reveals them, so an asset written before the rename works.
		if ( $is_form ) {
			$thanks = $xpath->query( '//*[@data-esf-thankyou or @data-esf-success or @data-osf-thankyou or @data-osf-success]' );
			if ( ! $thanks || 0 === $thanks->length ) {
				$items[] = self::item(
					'no_thankyou',
					'warning',
					__( 'This form has no thank-you state, so it just disappears when someone signs up.', 'emblem-studio' ),
					__( 'Add a hidden element with data-esf-thankyou. Ignore this if the asset redirects instead.', 'emblem-studio' )
				);
			}
		}

		// 23. email_misnamed: the address works, under a name the rules don't use.
		// Both apps accept it (SER-1430) and nothing is lost, but every tool,
		// mapping and AI prompt expects `email`.
		if ( $email_field !== null && $email_field !== 'email' ) {
			$items[] = self::item(
				'email_misnamed',
				'warning',
				/* translators: %s: the email field's name */
				sprintf( __( 'The email field is named "%s". Signups still work, but the rules expect "email".', 'emblem-studio' ), $email_field ),
				__( 'Rename it to name="email".', 'emblem-studio' )
			);
		}

		return $items;
	}

	/**
	 * Which live-value rules at least one marker breaks. Every condition is judged
	 * on its own, so a marker with no product AND no fallback reports both.
	 *
	 * @return array<string, true>
	 */
	private static function live_problems( DOMXPath $xpath ): array {
		$found = [];
		foreach ( $xpath->query( '//*[@data-es-live]' ) ?: [] as $el ) {
			if ( ! $el instanceof DOMElement ) {
				continue;
			}
			$field    = strtolower( trim( $el->getAttribute( 'data-es-live' ) ) );
			$product  = trim( $el->getAttribute( 'data-es-product' ) );
			$fallback = trim( (string) preg_replace( '/\s+/u', ' ', $el->textContent ) );

			if ( ! isset( ES_Live_Values::FIELDS[ $field ] ) ) {
				$found['live_unknown_field'] = true;
			}
			if ( ! preg_match( '/^\d+$/', $product ) || (int) $product < 1 ) {
				$found['live_no_product'] = true;
			}
			if ( strpos( $field, 'coupon.' ) === 0 && trim( $el->getAttribute( 'data-es-coupon' ) ) === '' ) {
				$found['live_no_coupon'] = true;
			}
			if ( $fallback === '' ) {
				$found['live_empty_fallback'] = true;
			} elseif ( preg_match( '/\d/', $fallback ) ) {
				$found['live_number_in_fallback'] = true;
			}
			for ( $p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode ) {
				if ( $p->hasAttribute( 'data-es-live' ) ) {
					$found['live_nested'] = true;
					break;
				}
			}
		}

		return $found;
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

	/**
	 * A checkbox or radio carrying the `hidden` attribute, whose id or one of
	 * whose classes the CSS selects with :checked. `hidden` is what keeps a real
	 * consent box out: one a visitor has to tick can't be hidden, and a styled
	 * one that hides the native box does it with CSS, not the attribute.
	 */
	private static function is_css_switch( DOMElement $el, string $css ): bool {
		if ( strtolower( $el->tagName ) !== 'input' || ! in_array( self::input_type( $el ), [ 'checkbox', 'radio' ], true ) || ! $el->hasAttribute( 'hidden' ) ) {
			return false;
		}
		$selectors = [];
		foreach ( self::class_tokens( $el ) as $class ) {
			$selectors[] = '\.' . preg_quote( $class, '~' );
		}
		$id = trim( $el->getAttribute( 'id' ) );
		if ( $id !== '' ) {
			$selectors[] = '#' . preg_quote( $id, '~' );
		}
		if ( ! $selectors ) {
			return false;
		}

		return (bool) preg_match( '~(?:' . implode( '|', $selectors ) . ')(?![\w-])[^\s{},>+\~]*:checked~', $css );
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

	/**
	 * A positive :has() rule that sets a visible display is the only way that thing
	 * appears, and browsers without :has() (Safari before 15.4, Firefox before 121)
	 * drop the whole rule. A rule that also sets visibility: hidden only holds space.
	 */
	private static function has_only_reveal( string $css ): bool {
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $blocks as $block ) {
			if ( preg_match( '/(?<!:not\():has\(/i', $block[1] )
				&& preg_match( '/(?<![-\w])display\s*:\s*(?!none\b)[a-z-]+/i', $block[2] )
				&& ! preg_match( '/visibility\s*:\s*hidden/i', $block[2] ) ) {
				return true;
			}
		}
		return false;
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
