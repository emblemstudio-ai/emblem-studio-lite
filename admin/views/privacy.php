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
			<p class="es-page-subtitle"><?php esc_html_e( 'One cookie worth mentioning in your cookie banner — here\'s exactly what to say.', 'emblem-studio' ); ?></p>
		</div>
	</div>
	<hr class="es-horizon" aria-hidden="true">

	<h2><?php esc_html_e( 'The cookie in question', 'emblem-studio' ); ?></h2>
	<p>
		<?php esc_html_e( 'Your forms and popups set one cookie worth mentioning: esf_visitor. It\'s a random, anonymous id — no name, no email, no IP — that just tells your stats a new visitor from a returning one, and it never leaves your site.', 'emblem-studio' ); ?>
	</p>
	<p>
		<?php esc_html_e( '"Already subscribed" memory and our spam trap only start after a visitor has already acted (submitting a form), so neither needs mentioning.', 'emblem-studio' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'One exception: if you\'re running an A/B test on a form, we also set a cookie (esf_ab_<form id>) on page load — same timing as esf_visitor above — to keep a visitor seeing the same version. Worth listing alongside it in your cookie policy if you use A/B testing.', 'emblem-studio' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'It\'s your site and your banner, so the call is yours. We just make sure our code follows whatever your visitors decide.', 'emblem-studio' ); ?>
	</p>

	<h2><?php esc_html_e( 'Already have a cookie banner?', 'emblem-studio' ); ?></h2>
	<p>
		<?php esc_html_e( 'Paste this into whichever category covers analytics or performance:', 'emblem-studio' ); ?>
	</p>
	<?php
	// Click-to-copy boxes, the same as the web app's CopyBox. They used to be
	// read-only textareas, and a textarea's corner handle let the box be dragged
	// to any size (item 17 of Catalina's Lite test pass). These grow with the
	// text and have nothing to drag.
	$es_copybox = static function ( string $text ): void {
		?>
		<button type="button" class="es-copybox es-copy" data-es-copy="<?php echo esc_attr( $text ); ?>" title="<?php esc_attr_e( 'Click to copy', 'emblem-studio' ); ?>">
			<code class="es-copybox-text"><?php echo esc_html( $text ); ?></code>
			<span class="es-copybox-btn"><?php esc_html_e( 'Copy', 'emblem-studio' ); ?></span>
		</button>
		<?php
	};
	$es_copybox( __( 'esf_visitor — an anonymous, first-party cookie (about 30 days) that lets us count unique visitors and tell new visitors from returning ones. No personal data.', 'emblem-studio' ) );
	?>
	<p>
		<?php esc_html_e( 'Most WordPress banners already speak a shared standard (the WP Consent API) — if yours does, this is handled automatically, nothing else to do.', 'emblem-studio' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'If not, paste this one line into your banner\'s "on accept" script instead:', 'emblem-studio' ); ?>
	</p>
	<?php $es_copybox( 'EmblemStudio.setAnalyticsConsent(true);' ); ?>

	<h2><?php esc_html_e( 'No banner yet?', 'emblem-studio' ); ?></h2>
	<p>
		<?php esc_html_e( "Then there's nothing to change — the cookie works exactly as it does today.", 'emblem-studio' ); ?>
	</p>
</div>
