<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap emblem-studio-wrap">
	<div class="es-page-header">
		<div class="es-page-header-main">
			<span class="es-eyebrow"><span class="es-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e( 'Privacy', 'emblem-studio' ); ?></span>
			<h1 class="es-page-title"><?php esc_html_e( 'Privacy & Cookies', 'emblem-studio' ); ?></h1>
			<p class="es-page-subtitle"><?php esc_html_e( 'What Emblem Studio stores in a visitor\'s browser, and what you need to tell your own cookie banner about it.', 'emblem-studio' ); ?></p>
		</div>
	</div>
	<hr class="es-horizon" aria-hidden="true">

	<h2><?php esc_html_e( 'The one cookie that might need disclosing', 'emblem-studio' ); ?></h2>
	<p>
		<?php esc_html_e( 'Your forms and popups set a small anonymous cookie called esf_visitor. It holds a random id — no name, no email, no IP address — so your stats can tell a new visitor from a returning one. It never leaves your own site.', 'emblem-studio' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'Everything else Emblem Studio sets — the A/B testing cookie, "already subscribed" memory, a closed popup remembering not to reopen — only happens after a visitor has already acted, so none of it needs a separate mention here.', 'emblem-studio' ); ?>
	</p>

	<h2><?php esc_html_e( 'Whose job this is', 'emblem-studio' ); ?></h2>
	<p>
		<?php esc_html_e( 'This is your site, so disclosing this cookie and getting consent for it is your call — the same as the consent checkbox on your own forms. We can\'t reach into your cookie banner and add anything for you. What we can do is tell you exactly what to add, and make sure our code respects whatever your visitors decide.', 'emblem-studio' ); ?>
	</p>

	<h2><?php esc_html_e( 'If you already run a cookie banner', 'emblem-studio' ); ?></h2>
	<p>
		<?php esc_html_e( 'Paste this into the description for whichever category on your banner covers analytics or performance cookies:', 'emblem-studio' ); ?>
	</p>
	<p>
		<textarea readonly rows="3" class="large-text code" onclick="this.select();"><?php echo esc_textarea( __( 'esf_visitor — an anonymous, first-party cookie (about 30 days) that lets us count unique visitors and tell new visitors from returning ones. No personal data.', 'emblem-studio' ) ); ?></textarea>
	</p>
	<p>
		<?php esc_html_e( 'Most WordPress consent-banner plugins already speak a shared standard called the WP Consent API. If yours does, this cookie now automatically waits for a visitor to accept analytics cookies — there is nothing else to set up.', 'emblem-studio' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'If your banner doesn\'t support that standard, paste this one line wherever your banner lets you run a script "when this category is accepted":', 'emblem-studio' ); ?>
	</p>
	<p>
		<textarea readonly rows="1" class="large-text code" onclick="this.select();">EmblemStudio.setAnalyticsConsent(true);</textarea>
	</p>

	<h2><?php esc_html_e( "If you don't run a cookie banner at all", 'emblem-studio' ); ?></h2>
	<p>
		<?php esc_html_e( 'Nothing to do. The cookie behaves exactly as it always has.', 'emblem-studio' ); ?>
	</p>
</div>
