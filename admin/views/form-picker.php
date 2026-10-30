<?php
/**
 * Form picker — shown when the user clicks "Add New". Replaces the
 * old direct-to-blank flow. Layout:
 *
 *   ┌─ Blank form (big, prominent) ────────────────────────────────┐
 *   ├─ Filter chips: All / Inline / Click popup / Auto popup / …  ─┤
 *   ├─ Collection: Indie SaaS ─────────────────────────────────────┤
 *   │   [thumb] [thumb] [thumb] [thumb]                             │
 *   ├─ Collection: Therapist (future) ─────────────────────────────┤
 *   │   [thumb] [thumb] [thumb]                                    │
 *   └──────────────────────────────────────────────────────────────┘
 *
 * Each thumb is a live iframe rendering the template via
 * ES_Preview::template_thumb_url(). Click to install ONE form and
 * land on its editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bundles = ES_Templates::bundles();

$shell_labels = [
	'inline'      => __( 'Inline', 'emblem-studio' ),
	'popup-click' => __( 'Click popup', 'emblem-studio' ),
	'popup-auto'  => __( 'Auto popup', 'emblem-studio' ),
	'ribbon'      => __( 'Ribbon', 'emblem-studio' ),
	'slide-in'    => __( 'Slide-in', 'emblem-studio' ),
];

$blank_url = admin_url( 'admin.php?page=emblem-studio-new&blank=1' );
?>
<div class="wrap emblem-studio-wrap">
	<h1><?php esc_html_e( 'Add a new form', 'emblem-studio' ); ?></h1>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Start from a blank form, or pick a template. Filter by shell type to narrow down. Templates create a single new form with the right shell already selected — no bundle install.', 'emblem-studio' ); ?>
	</p>

	<?php if ( isset( $_GET['install_failed'] ) ) : ?>
		<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'That template could not be installed. The file may be missing or invalid.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>

	<!-- Blank form: big, prominent, top of the picker. -->
	<a class="es-picker-blank" href="<?php echo esc_url( $blank_url ); ?>">
		<div class="es-picker-blank-icon" aria-hidden="true">+</div>
		<div class="es-picker-blank-body">
			<h2><?php esc_html_e( 'Blank form', 'emblem-studio' ); ?></h2>
			<p><?php esc_html_e( 'Author the HTML and CSS yourself. Recommended if you already have a design in mind.', 'emblem-studio' ); ?></p>
		</div>
		<span class="es-picker-blank-cta"><?php esc_html_e( 'Start from scratch →', 'emblem-studio' ); ?></span>
	</a>

	<!-- Shell filter chips. Client-side filter via data-shell on each card. -->
	<div class="es-picker-filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter templates by shell', 'emblem-studio' ); ?>">
		<button type="button" class="es-picker-filter is-active" data-shell="all"><?php esc_html_e( 'All', 'emblem-studio' ); ?></button>
		<?php foreach ( $shell_labels as $shell => $label ) : ?>
			<button type="button" class="es-picker-filter" data-shell="<?php echo esc_attr( $shell ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</div>

	<?php if ( empty( $bundles ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'No template collections found. Templates live at emblem-studio/templates/.', 'emblem-studio' ); ?></p></div>
	<?php else : ?>
		<div class="es-picker-collections">
			<?php foreach ( $bundles as $slug => $bundle ) :
				$forms = (array) ( $bundle['resolved_forms'] ?? [] );
				if ( empty( $forms ) ) continue;
			?>
				<section class="es-picker-collection">
					<header class="es-picker-collection-head">
						<div>
							<h2 class="es-picker-collection-title"><?php echo esc_html( $bundle['name'] ?? $slug ); ?></h2>
							<?php if ( ! empty( $bundle['tagline'] ) ) : ?>
								<p class="es-picker-collection-tag"><?php echo esc_html( $bundle['tagline'] ); ?></p>
							<?php endif; ?>
						</div>
					</header>

					<div class="es-picker-grid">
						<?php foreach ( $forms as $form_slug => $form ) :
							$thumb_url   = ES_Preview::template_thumb_url( $slug, (string) $form_slug );
							$preview_url = ES_Preview::template_preview_url( $slug, (string) $form_slug );
							$install_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=es_install_form&bundle=' . rawurlencode( $slug ) . '&form=' . rawurlencode( (string) $form_slug ) ),
								'es_install_form_' . $slug . '_' . $form_slug
							);
							$shell = (string) $form['shell'];
						?>
							<div class="es-picker-card" data-shell="<?php echo esc_attr( $shell ); ?>">
								<div class="es-picker-card-thumb">
									<iframe
										class="es-picker-thumb-iframe"
										src="about:blank"
										data-thumb-src="<?php echo esc_url( $thumb_url ); ?>"
										title="<?php echo esc_attr( $form['name'] . ' — preview' ); ?>"
										loading="lazy"
										sandbox="allow-scripts allow-same-origin"
									></iframe>
								</div>
								<div class="es-picker-card-meta">
									<h3 class="es-picker-card-name"><?php echo esc_html( $form['name'] ); ?></h3>
									<span class="es-shell-pill es-shell-<?php echo esc_attr( $shell ); ?>"><?php echo esc_html( $shell_labels[ $shell ] ?? $shell ); ?></span>
								</div>
								<div class="es-picker-card-actions">
									<a class="button" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'emblem-studio' ); ?></a>
									<a class="button button-primary" href="<?php echo esc_url( $install_url ); ?>"><?php esc_html_e( 'Use this template →', 'emblem-studio' ); ?></a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
