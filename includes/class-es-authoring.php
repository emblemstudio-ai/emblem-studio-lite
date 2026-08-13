<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy-paste authoring aids for the form editor.
 *
 * Until the in-product AI generator ships, the Design tab offers two
 * "copy" links backed by this class:
 *   - human_spec()   a tight, scannable reference a person reads to see
 *                    what an Emblem Studio asset must contain and may use;
 *   - ai_preprompt() a ready-to-fill ChatGPT / Claude prompt, which
 *                    embeds the fuller, instruction-style ai_spec().
 * The two specs are deliberately different: people want terse, an AI
 * wants explicit. Admin-only — loaded behind the is_admin() gate.
 */
class ES_Authoring {

	/**
	 * Tight, scannable form reference for a person to read. Backs the
	 * "Copy form specifications" link. Deliberately terse — the fuller,
	 * instruction-style spec for an AI lives in ai_spec().
	 */
	public static function human_spec(): string {
		return <<<'SPEC'
EMBLEM STUDIO — FORM SPEC

An asset is two blocks you write yourself: HTML and CSS. Everything else
— submitting, spam protection, delivery, stats, the popup / ribbon /
slide-in chrome — is handled for you. Write a fragment: no <html>,
<head> or <body>.

TWO KINDS OF ASSET
- Form asset (default): captures leads — has the email input, a submit
  button, and (optionally) the thank-you state below.
- Form-less asset: an announcement / CTA (ribbon, slide-in, popup) that
  just presents an offer and links out. The action is a tracked link:
  <a data-es-track="..." href="...">. The root is still one
  <form class="esf-form"> — that never changes — just with no fields.

Which kind is it? Emblem Studio decides by looking for FIELDS, not for the
<form> tag (every asset has that tag). It counts as a form only if it has a
real field: an <input> (any type except hidden / submit / button / image /
reset), a <select>, or a <textarea>. That decides what your reports show and
how an A/B test is scored — a form is scored on conversions, a form-less
asset on clicks. So keep a form-less asset genuinely empty of fields:
- No <input>, <select> or <textarea> — not even a decorative or disabled
  one, and not an email box you mean to wire up later.
- No <button type="submit"> — nothing is submitted. Style a link as a
  button instead: <a class="esf-cta" data-es-track="..." href="...">.
- No data-esf-thankyou element — nothing can reveal it.
- No GDPR consent checkbox (that is an <input>).
A form-less asset is just: the root, your copy, and tracked links.

HTML
- Root element: one <form class="esf-form" novalidate>.
- Inputs carry a name=. Recognised: email, first_name, last_name, phone.
  Any other name becomes a custom field.
- A form asset includes exactly one <input type="email" name="email"
  required>; a form-less announcement has no fields — skip it.
- Pair each input with a <label for="..."> and an autocomplete value.
- Consent: mark EVERY checkbox that asks the visitor to agree to something
  with data-esf-consent, inside a <label> carrying the exact wording (and a
  link to the relevant policy). That marker is the only thing that records
  the agreement — being "required" doesn't make a checkbox a consent, and a
  consent needn't be required. Name it whatever fits; consent isn't only GDPR.
- A submit <button type="submit"> (form assets only).
- Thank-you state (optional, form assets only): one element marked
  data-esf-thankyou hidden — shown after a successful submit, with the
  form hidden. Optional: the asset's success behaviour can instead show a
  custom message or redirect. It appears the instant someone submits, not
  when the server answers, so write it as a self-contained message. If the
  submission fails the form comes back with the error.
- No honeypot needed; one is added automatically.

CSS
- Must start with:  [hidden] { display: none !important; }
- The form is style-isolated (Shadow DOM): the theme can't reach it and
  it can't inherit from the theme — style everything it needs.
- Keep colours, fonts and spacing in --esf-* variables at the top.
- Single-class selectors. No IDs. No !important (except [hidden]).
- Text fields get a supplied I-beam mouse pointer (Windows 11's white default
  cursor is invisible over a light field). Override with `cursor:` if needed.
- While a submit is in flight the form carries .esf-submitting and the button
  is disabled. A dim + spinner busy state comes free; style that class to
  replace it.
- Design ~480px wide; add a @media (max-width: 480px) block.

NOTES
- Prefix your own class names with "esf-"; scope every rule to .esf-form.
- Add data-es-track="label" to a link or button to track its clicks — never
  to the submit button itself (submitting already counts as a conversion;
  tagging it too double-counts the same action).
- Animations and hover effects are welcome.
- The shell adds the popup backdrop, positioning and close button — don't
  build your own overlay, modal wrapper or full-bleed background. Put the
  background, padding, border-radius and box-shadow on the single root
  (.esf-form) itself, not on an extra wrapper around it, so a rounded design
  shows no stray box behind it as a popup / ribbon / slide-in.
- Removed on save: <script>, on* handlers, javascript: URLs, external
  <link> / <script> tags.
- One asset works everywhere: inline, click popup, auto popup, ribbon,
  slide-in.
SPEC;
	}

	/**
	 * Fuller, explicit form-authoring spec written for an AI to follow
	 * to the letter. Embedded in ai_preprompt(); never shown on its own.
	 */
	public static function ai_spec(): string {
		return <<<'SPEC'
EMBLEM STUDIO — FORM SPECIFICATION
What a hand-authored Emblem Studio asset must contain and may use.

THE TWO BLOCKS
You author two things: one block of HTML and one block of CSS. Emblem
Studio handles submission, spam protection, lead delivery, analytics and
the popup / ribbon / slide-in chrome — you only build the form itself.
Author a fragment: no <html>, <head> or <body> tags.

ASSET TYPES — two kinds
- FORM asset (the default): captures leads. Has the email input + submit
  button + (optionally) the thank-you state below.
- FORM-LESS asset: an announcement / CTA (ribbon, slide-in, popup) that
  just presents an offer and links out. The action is a tracked link:
  <a data-es-track="..." href="...">. The root is still one
  <form class="esf-form"> — that never changes — just with no fields.

WHICH KIND IS IT? — the platform decides by FIELDS, not by the <form> tag
Every asset has the <form class="esf-form"> root, so the tag proves
nothing. An asset counts as a FORM only if it contains a real field: an
<input> (any type except hidden / submit / button / image / reset), a
<select>, or a <textarea>. That single answer drives what the reports show
and how an A/B test is scored — a form asset is scored on conversions, a
form-less asset on clicks. Get it wrong and the asset is measured on a
metric it can never move.

A FORM-LESS ASSET MUST NOT CONTAIN — each one makes it read as a form:
- NO <input> of any visible type, NO <select>, NO <textarea>. Not even a
  decorative or disabled one, and not an email box you "leave for later".
- NO <button type="submit"> — there is nothing to submit. If you want a
  button-shaped CTA, use a link styled as a button:
  <a class="esf-cta" data-es-track="..." href="...">.
- NO data-esf-thankyou element — nothing can ever reveal it, and it makes
  the asset look like it captures leads.
- NO GDPR consent checkbox (it is an <input>), and no honeypot (never
  author one anyway — Emblem Studio injects its own).
A form-less asset is therefore: the <form class="esf-form"> root, your
copy, and one or more tracked links. Nothing a visitor could fill in.

HTML — STRUCTURE
- Include exactly one <form> element, wrapped as
  <form class="esf-form" novalidate> ... </form>.
- Give your own elements "esf-"-prefixed class names (esf-header,
  esf-field, esf-cta, ...). Scope every CSS rule under .esf-form.
- Do not add a honeypot or spam field — Emblem Studio injects its own.

HTML — FIELDS
- Every input needs a "name". That name is what gets delivered to your
  email tool / CRM. Standard names: email, first_name, last_name, phone.
  Any other name is treated as a custom field you can map later.
- A form asset always includes exactly one
  <input type="email" name="email" required>. A form-less announcement
  asset has no fields — skip this.
- Do NOT write your own email validation: no pattern=, no oninvalid handler,
  no "please enter a valid email" text of your own. Emblem Studio checks the
  address before it posts, and refuses one with no @ or no dot-something
  ending — "name@examle" is undeliverable, and the browser's own type="email"
  lets it through. Yours would only be a second, disagreeing opinion. The
  message appears in a .esf-error element added at the end of the form; style
  that class to match your design, but do not author the element.
- Give every input a matching <label for="..."> and an "autocomplete"
  value (email, given-name, family-name, tel).
- Consent — mark every checkbox that asks the visitor to AGREE to something,
  inside a <label> carrying the exact wording plus a link to the relevant
  policy:
  <input type="checkbox" name="marketing_consent" required data-esf-consent>
  The data-esf-consent marker is the ONLY thing that records the agreement as
  proof. "required" alone does not make a checkbox a consent, and a consent
  does not have to be required. A form may ask for several separate
  agreements — mark each one; each is recorded separately. The name is yours
  to choose; consent is not only about GDPR.
- Submit with <button type="submit">.

HTML — THE THANK-YOU STATE (optional; form assets only)
- A form asset MAY include one element marked  data-esf-thankyou hidden
  — your "you're in" confirmation. It stays hidden until a successful
  submit, then Emblem Studio reveals it and hides the form. Example:
  <div class="esf-success" data-esf-thankyou hidden> ... </div>
  It is NOT mandatory: the asset's success behaviour can instead show a
  custom message or redirect. A form-less announcement asset omits it.
- It appears the INSTANT the visitor submits, not when the server answers.
  The submission almost always succeeds, and making someone watch a spinner
  to be told "thanks" is the wrong trade. If it does fail, the form comes
  back and the error shows. Write it as a self-contained message: nothing in
  there can depend on a server reply, because it is on screen before there
  is one.
- Only for the default `inline_state` behaviour. Custom-message and redirect
  each need something that arrives WITH the reply (the text, the URL), so
  those still wait — one more reason to prefer a thank-you element.

CSS
- The FIRST CSS rule must be:  [hidden] { display: none !important; }
  This makes the hidden thank-you state reliable.
- The form renders inside an isolated Shadow DOM: the site theme cannot
  style it, and your CSS cannot borrow from the theme. Style everything
  the form needs.
- Put colours, fonts and spacing in CSS custom properties (--esf-bg,
  --esf-accent, --esf-radius, ...) in a ".esf-form { }" block at the top,
  so they are easy to retune in one place.
- Use single-class selectors. No IDs. No !important (except the
  [hidden] rule above).
- Text fields get a supplied I-beam MOUSE POINTER, because Windows 11's white
  default cursor is invisible over a light field. It's drawn dark with a white
  halo so it works on any background. Override with `cursor:` if you must.
- While a submission is in flight the <form> carries  .esf-submitting , and the
  submit button is disabled so it cannot be sent twice. You get a busy state for
  free (dimmed button, progress cursor, a small spinner after the label); style
  `.esf-submitting` yourself to replace it — your rules always win. Mostly this
  shows on the slow submissions, since a thank-you appears immediately.
- The shell supplies the popup backdrop, positioning and close button —
  never add your own overlay, modal wrapper or full-bleed background.
  Style the single .esf-form root directly: put its background, padding,
  border-radius and box-shadow on that root (not on an extra wrapper
  around it), so it renders cleanly as a popup / ribbon / slide-in with no
  stray box showing behind its rounded corners.
- Design at about 480px wide and include a
  @media (max-width: 480px) { } block — assets often show in narrow
  popups and on phones.

OPTIONAL
- Add  data-es-track="a short label"  to a link or button you want counted
  separately in click stats — but NEVER on the form's own submit button.
  Submitting is already measured as a conversion; tagging the submit button
  too double-counts the same action as a separate "click". Only add it
  there if the user explicitly asks you to track the submit button
  specifically — don't do it by default or "for completeness".
- Entrance animations and hover effects are fine — keep them tasteful.

NOT ALLOWED (stripped automatically on save)
- <script> tags, inline event handlers (onclick, onerror, ...),
  javascript: URLs, and external <link> / <script> references.

WHERE IT RUNS
The same HTML/CSS works inline (via shortcode), as a click popup, an
auto popup, a ribbon bar, or a slide-in. Pick the placement in the
Shells tab — design once, it adapts.
SPEC;
	}

	/**
	 * A ready-to-use generation prompt for ChatGPT / Claude / etc. The
	 * author fills the [BRACKET] placeholders and pastes the whole thing
	 * into an AI chat to get a form's HTML + CSS. The full ai_spec() is
	 * appended so the prompt is self-contained — one copy, one paste.
	 */
	public static function ai_preprompt(): string {
		$intro = <<<'PROMPT'
ROLE
You are a senior conversion designer and front-end developer. Design a
beautiful, high-converting opt-in asset (a form) for the project below,
then write its HTML and CSS.

MY PROJECT — replace every [BRACKET] before you send this
- Brand / product name: [YOUR BRAND]
- What I give people for signing up: [THE OFFER — e.g. 10% off, a PDF guide, a weekly newsletter]
- Who my audience is: [DESCRIBE THEM IN A SENTENCE]
- The one job of this asset: [e.g. collect emails for a launch waitlist]
- Mood / visual style: [e.g. warm and editorial / bold and modern / calm and minimal]
- Brand colours: [HEX CODES — or write "you choose"]
- Fonts: [FONT NAMES — or write "you choose"]
- Headline: [WRITE ONE — or write "you choose"]
- Fields to collect: [e.g. first name + email]
- Privacy policy URL: [PASTE URL — or leave as #]

WHAT TO RETURN
Return exactly two fenced code blocks and nothing else: first the HTML,
then the CSS. No commentary before or after.

DESIGN BAR
Make it look crafted by a senior designer: a deliberate type scale,
generous whitespace, one confident accent colour, a clear visual
hierarchy, and a tasteful entrance animation. The submit button must be
impossible to miss. Avoid the generic default-form look.

TECHNICAL SPEC — follow every rule below exactly.
PROMPT;

		return $intro . "\n\n" . self::ai_spec();
	}
}
