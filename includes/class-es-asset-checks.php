<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Asset_Checks {

	const NON_FIELD_INPUT_TYPES = [ 'hidden', 'submit', 'button', 'image', 'reset' ];

	const RESERVED_SKIP_TYPES = [ 'submit', 'button', 'reset', 'image' ];

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

		$forms = $xpath->query( '//form' );
		if ( ! $forms || 0 === $forms->length ) {
			$items[] = self::item(
				'no_root',
				'error',
				__( 'The HTML has no <form> root, so Emblem Studio can\'t run the form.', 'emblem-studio' ),
				__( 'Wrap everything in one <form class="esf-form" novalidate>.', 'emblem-studio' )
			);
		}

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

		if ( $is_form && ! $submits ) {
			$items[] = self::item(
				'no_submit',
				'error',
				__( 'There is no submit button, so visitors can\'t send the form.', 'emblem-studio' ),
				__( 'Add <button type="submit">.', 'emblem-studio' )
			);
		}

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

		if ( preg_match( '/position\s*:\s*fixed\b/i', $css ) ) {
			$items[] = self::item(
				'own_overlay',
				'error',
				__( 'The CSS uses position: fixed, so the form covers the page instead of sitting in its popup, ribbon or slide-in.', 'emblem-studio' ),
				__( 'Remove position: fixed. Emblem Studio places the form for you.', 'emblem-studio' )
			);
		}

		if ( self::has_own_close( $xpath ) ) {
			$items[] = self::item(
				'own_close',
				'error',
				__( 'The form has its own close button. It won\'t work, because scripts are removed, and the popup already adds one.', 'emblem-studio' ),
				__( 'Remove the close button.', 'emblem-studio' )
			);
		}

		if ( preg_match( '/container-type\s*:|@container\b/i', $css ) ) {
			$items[] = self::item(
				'container_query',
				'error',
				__( 'The CSS uses container queries. In a popup they can squeeze the form to its narrowest width.', 'emblem-studio' ),
				__( 'Use @media queries or clamp() instead.', 'emblem-studio' )
			);
		}

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

		if ( self::max_width_without_width( $css ) ) {
			$items[] = self::item(
				'no_width',
				'warning',
				__( 'The form sets a max-width but no width, so a popup with little text shrinks to 320px.', 'emblem-studio' ),
				__( 'Add width: 480px; max-width: 100%; to .esf-form, or use your own width.', 'emblem-studio' )
			);
		}

		if ( self::has_only_reveal( $css ) ) {
			$items[] = self::item(
				'has_only_reveal',
				'warning',
				__( 'A rule uses :has() to make something appear. iPhones before iOS 15.4 and older Firefox skip that rule, so there it never shows.', 'emblem-studio' ),
				__( 'Show it by default and hide it with :not(:has(...)) instead. Older browsers then simply always show it.', 'emblem-studio' )
			);
		}

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

		$thankyou = $xpath->query( '//*[@data-esf-thankyou]' );
		if ( $thankyou && $thankyou->length > 0 && ! preg_match( '/\[hidden\][^{]*\{[^}]*display\s*:\s*none/i', $css ) ) {
			$items[] = self::item(
				'no_hidden_rule',
				'warning',
				__( 'The CSS is missing the [hidden] rule, so the thank-you message can show before anyone signs up.', 'emblem-studio' ),
				__( 'Start the CSS with [hidden] { display: none !important; }', 'emblem-studio' )
			);
		}

		if ( self::has_stripped_code( $xpath ) ) {
			$items[] = self::item(
				'stripped_code',
				'warning',
				__( 'The HTML has a <script> or an event handler like onclick. Both are removed on save, so they won\'t run.', 'emblem-studio' ),
				__( 'Remove them. Emblem Studio runs the form for you.', 'emblem-studio' )
			);
		}

		if ( isset( $live['live_number_in_fallback'] ) ) {
			$items[] = self::item(
				'live_number_in_fallback',
				'warning',
				__( 'A live value\'s fallback words contain a number. They show exactly when the number is not safe, so they can state an old one.', 'emblem-studio' ),
				__( 'Write words without a number, like "a discount" or "less".', 'emblem-studio' )
			);
		}

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

	private static function parse( string $html ): DOMDocument {
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $dom;
	}

	private static function item( string $id, string $level, string $message, string $fix ): array {
		return [
			'id'      => $id,
			'level'   => $level,
			'message' => $message,
			'fix'     => $fix,
		];
	}

	private static function input_type( DOMElement $input ): string {
		$type = strtolower( trim( $input->getAttribute( 'type' ) ) );

		return $type === '' ? 'text' : $type;
	}

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

	private static function class_tokens( DOMElement $el ): array {
		$tokens = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );

		return array_values( array_filter( $tokens ?: [], static function ( $t ) {
			return $t !== '';
		} ) );
	}

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
