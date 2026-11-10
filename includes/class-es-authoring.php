<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Authoring {

	const PROMPT_BASE = 'https://emblemstudio.ai';

	public static function human_spec(): string {
		return <<<'SPEC'
EMBLEM STUDIO: FORM SPEC

An asset is two blocks you write yourself: HTML and CSS. Everything else
(submitting, spam protection, delivery, stats, the popup / ribbon /
slide-in chrome) is handled for you. Write a fragment: no <html>,
<head> or <body>.

TWO KINDS OF ASSET
- Form asset (default): captures leads. It has the email input, a submit
  button, and (optionally) the thank-you state below.
- Form-less asset: an announcement or CTA (ribbon, slide-in, popup) that
  just presents an offer and links out. The action is a tracked link:
  <a data-es-track="..." href="...">. The root is still one
  <form class="esf-form">, just with no fields.

Which kind is it? Emblem Studio decides by looking for FIELDS, not for the
<form> tag (every asset has that tag). It counts as a form only if it has a
real field: an <input> (any type except hidden / submit / button / image /
reset), a <select>, or a <textarea>. That decides what your reports show and
how an A/B test is scored: a form is scored on conversions, a form-less
asset on clicks. So keep a form-less asset genuinely empty of fields:
- No <input>, <select> or <textarea>, not even a decorative or disabled
  one, and not an email box you mean to wire up later.
- No <button type="submit">. Nothing is submitted. Style a link as a
  button instead: <a class="esf-cta" data-es-track="..." href="...">.
- No data-esf-thankyou element. Nothing can reveal it.
- No consent checkbox (that is an <input>).
A form-less asset is just: the root, your copy, and tracked links.

HTML
- Root element: one <form class="esf-form" novalidate>.
- Inputs carry a name=. Recognised: email, first_name, last_name, phone.
  Any other name becomes a custom field.
- Checkboxes that allow several answers share one name ending in [],
  like name="interests[]".
- Form assets: exactly one <input type="email" name="email" required>.
  (A form-less asset has no fields, so skip this.)
- Don't validate the email yourself: no pattern=, no oninvalid, no "enter a
  valid email" text of your own. Emblem Studio checks the address before it
  posts and refuses one with no @ or no dot-something ending. The message
  goes in a .esf-error element added at the end of the form. Style that
  class if you like, but don't author one.
- required is enforced, so mean it. The form carries novalidate, so the
  browser stays quiet, but Emblem Studio checks every required field itself
  before it posts and stops on the first one left empty. Don't write your own
  "this field is required" text or oninvalid handler; the message shares the
  .esf-error element.
- Pair each input with a <label for="..."> and an autocomplete value.
- Consent: mark EVERY checkbox that asks the visitor to agree to something
  with data-esf-consent, inside a <label> carrying the exact wording (and a
  link to the relevant policy). That marker is the only thing that records
  the agreement. Being required doesn't make a checkbox a consent, and a
  consent needn't be required. Name it whatever fits; consent isn't only GDPR.
- A submit <button type="submit"> (form assets only).
- Thank-you state (required on a form asset): one element marked
  data-esf-thankyou hidden. It shows after a successful submit, with the
  form hidden. Without it the card just disappears. The only alternative is
  the asset's redirect setting, which sends people to a page you wrote.
  It appears the instant someone submits, not when the server answers, so
  write it as a self-contained message. If the submission fails, the form
  comes back with the error.
- No honeypot needed; one is added automatically.

CSS
- Must start with:  [hidden] { display: none !important; }
- The form is style-isolated (Shadow DOM): the theme can't reach it and
  it can't inherit from the theme. Style everything it needs.
- Keep colours, fonts and spacing in --esf-* variables at the top.
- Single-class selectors. No IDs. No !important (except [hidden]).
- No container-type or container queries on the root. In a popup they
  collapse the form to zero width. Use media queries or clamp() instead.
- Text fields get a supplied I-beam mouse pointer (Windows 11's white default
  cursor is invisible over a light field). Override with cursor: if needed.
- While a submit is in flight the form carries .esf-submitting and the button
  is disabled. A dim + spinner busy state comes free; style that class to
  replace it.
- Design ~480px wide, and set it on the root: width: 480px; max-width: 100%.
  With max-width alone, a popup shrinks a short form to 320px.
- A card narrower than its space is centred for you: margin-inline: auto on
  .esf-form, at zero specificity. Set your own margin to override it;
  .esf-form { margin: 0 } pins the card left.
- Add a @media (max-width: 480px) block.

NOTES
- Prefix your own class names with "esf-"; scope every rule to .esf-form.
- Add data-es-track="label" to a link or button to track its clicks. Never
  put it on the submit button: submitting already counts as a conversion,
  so tagging it too counts the same action twice.
- Animations and hover effects are welcome.
- A price or discount from a connected WordPress shop can be live:
  <span data-es-live="coupon.discount" data-es-product="ID"
  data-es-coupon="CODE">a discount</span>. The words inside show whenever
  the number isn't safe to show, so write them without digits.
- An element marked data-es-pointer is told where the cursor is, so CSS
  can react with no script: --es-pointer-dx / --es-pointer-dy (direction,
  -1 to 1), --es-pointer-x / -y (px from its centre), --es-pointer-fx / -fy
  (0 to 1 across it), --es-pointer-drag-x / -y (px dragged while pressed),
  and the attributes data-es-pointer-over, -down and -idle (after
  data-es-pointer-idle-after seconds, default 8). All numbers are plain, so
  multiply by a unit: calc(var(--es-pointer-dx) * 6px). Use it for play,
  never to hide something a phone visitor needs.
- The shell adds the popup backdrop, positioning and close button. Don't
  build your own overlay, modal wrapper, close button or full-bleed
  background. Put the background, padding, border-radius and box-shadow on
  the single root (.esf-form) itself, not on an extra wrapper around it, so
  a rounded design shows no stray box behind it.
- Removed on save: <script>, on* handlers and javascript: URLs. Don't add
  external <link> or <script> tags either.
- One asset works everywhere: inline, click popup, auto popup, ribbon,
  slide-in.
SPEC;
	}

	public static function prompt_page_url(): string {
		return self::PROMPT_BASE . '/prompt';
	}

	public static function ai_preprompt(): string {
		return 'Read ' . self::PROMPT_BASE . '/prompt.md and follow it. I want you to build me an opt-in form for my website.';
	}
}
