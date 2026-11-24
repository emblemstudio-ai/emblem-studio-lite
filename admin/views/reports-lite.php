<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$es_signup_url  = class_exists( 'Emblem_Studio_SaaS_Connector' )
	?Emblem_Studio_SaaS_Connector::signup_url()
	: 'https://emblemstudio.ai/signup';
$es_connect_url = admin_url( 'admin.php?page=emblem-studio-account' );

$es_points = [
	[
		'title' => __( 'Views, sign-ups and conversion rate over time', 'emblem-studio' ),
		'body'  => __( 'Pick any date range and see it by day or by hour, for one form or all of them.', 'emblem-studio' ),
	],
	[
		'title' => __( 'Where people give up', 'emblem-studio' ),
		'body'  => __( 'See which step of a form people leave on, so you know what to fix first.', 'emblem-studio' ),
	],
	[
		'title' => __( 'What brings sign-ups', 'emblem-studio' ),
		'body'  => __( 'Break results down by page, device and traffic source.', 'emblem-studio' ),
	],
	[
		'title' => __( 'Real people, not bots', 'emblem-studio' ),
		'body'  => __( 'Bots and AI scrapers are counted apart, so your numbers mean something.', 'emblem-studio' ),
	],
];
?>
<div class="wrap emblem-studio-wrap">
	<div class="es-page-header">
		<div class="es-page-header-main">
			<span class="es-eyebrow"><span class="es-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e( 'Reports', 'emblem-studio' ); ?></span>
			<h1 class="es-page-title"><?php esc_html_e( 'Reports', 'emblem-studio' ); ?></h1>
		</div>
	</div>
	<hr class="es-horizon" aria-hidden="true">

	<section class="es-reports-ad" aria-labelledby="es-reports-ad-title">
		<div class="es-reports-ad-main">
			<span class="es-lite-upsell-eyebrow"><?php esc_html_e( 'Free with an account', 'emblem-studio' ); ?></span>
			<h2 id="es-reports-ad-title" class="es-reports-ad-title"><?php esc_html_e( 'See what your forms are really doing', 'emblem-studio' ); ?></h2>
			<p class="es-reports-ad-lead"><?php esc_html_e( 'Full reports come with a free Emblem Studio account. Connect this site and they open right here in WordPress.', 'emblem-studio' ); ?></p>

			<p class="es-reports-ad-actions">
				<a class="button button-primary button-hero" href="<?php echo esc_url( $es_signup_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Create a free account', 'emblem-studio' ); ?></a>
				<a class="es-reports-ad-secondary" href="<?php echo esc_url( $es_connect_url ); ?>"><?php esc_html_e( 'I have an account. Connect this site', 'emblem-studio' ); ?> <span aria-hidden="true">&rarr;</span></a>
			</p>

			<p class="es-reports-ad-note"><?php esc_html_e( 'Your stats so far come with you. Connecting copies this site\'s existing stats and leads into your account.', 'emblem-studio' ); ?></p>
		</div>

		<ul class="es-reports-ad-points">
			<?php foreach ( $es_points as $es_point ) : ?>
				<li>
					<strong><?php echo esc_html( $es_point['title'] ); ?></strong>
					<span><?php echo esc_html( $es_point['body'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<p class="es-reports-ad-footnote">
		<?php
		printf(
			/* translators: %s: link to the forms list */
			esc_html__( 'Each form\'s basic numbers are still on the %s.', 'emblem-studio' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=emblem-studio' ) ) . '">' . esc_html__( 'Forms & Assets list', 'emblem-studio' ) . '</a>'
		);
		?>
	</p>
</div>
