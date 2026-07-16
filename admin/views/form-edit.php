<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var ?object $form */
$is_new = empty( $form );
$f = (object) [
	'id'                   => $form->id ?? 0,
	'name'                 => $form->name ?? '',
	'slug'                 => $form->slug ?? '',
	'html'                 => $form->html ?? "<form>\n  <label>Email\n    <input type=\"email\" name=\"email\" required>\n  </label>\n  <button type=\"submit\">Subscribe</button>\n  <div data-esf-thankyou hidden>Thanks! You're subscribed.</div>\n</form>",
	'css'                  => $form->css ?? "form { display: grid; gap: .5rem; max-width: 320px; }\nbutton { padding: .5rem 1rem; }",
	'status'               => $form->status ?? 'inactive',
	'success_mode'         => $form->success_mode ?? 'inline_state',
	'success_message'      => $form->success_message ?? '',
	'success_redirect_url' => $form->success_redirect_url ?? '',
	'funnelkit_list_id'    => $form->funnelkit_list_id ?? '',
	'funnelkit_lists'      => isset( $form->funnelkit_lists ) ? ( json_decode( (string) $form->funnelkit_lists, true ) ?: [] ) : [],
	'funnelkit_tags'       => isset( $form->funnelkit_tags )  ? ( json_decode( (string) $form->funnelkit_tags,  true ) ?: [] ) : [],
	'field_mapping'        => isset( $form->field_mapping )   ? ( json_decode( (string) $form->field_mapping,   true ) ?: [] ) : [],
	'form_types'           => isset( $form ) ? ES_Form_Repo::types_for( $form ) : [ 'inline' ],
	'auto_trigger_config'  => isset( $form ) ? ES_Form_Repo::get_auto_trigger_config( $form ) : [],
	'use_shadow_dom'       => isset( $form ) ? ! empty( $form->use_shadow_dom ) : false,
	'use_design_system'    => isset( $form ) ? ! empty( $form->use_design_system ) : true,
	'popup_max_width'      => isset( $form ) ? (int) ( $form->popup_max_width ?? 720 ) : 720,
	'popup_fullscreen'     => isset( $form ) ? ! empty( $form->popup_fullscreen ) : false,
	'popup_close_theme'    => isset( $form ) ? ( in_array( $form->popup_close_theme ?? 'light', [ 'light', 'dark' ], true ) ? $form->popup_close_theme : 'light' ) : 'light',
	'track_clicks'         => isset( $form ) ? ! empty( $form->track_clicks ) : true,
	'funnelkit_enabled'    => isset( $form ) ? ! empty( $form->funnelkit_enabled ) : false,
	'active_from'          => isset( $form ) ? (string) ( $form->active_from  ?? '' ) : '',
	'active_until'         => isset( $form ) ? (string) ( $form->active_until ?? '' ) : '',
];

// HTML5 datetime-local wants "YYYY-MM-DDTHH:MM" — strip seconds from MySQL DATETIME.
$dtl_from  = $f->active_from  !== '' ? substr( str_replace( ' ', 'T', $f->active_from  ), 0, 16 ) : '';
$dtl_until = $f->active_until !== '' ? substr( str_replace( ' ', 'T', $f->active_until ), 0, 16 ) : '';
$dr_special_labels = [
	'front_page' => __( 'Front page',     'emblem-studio' ),
	'blog'       => __( 'Blog index',     'emblem-studio' ),
	'404'        => __( '404 page',       'emblem-studio' ),
	'search'     => __( 'Search results', 'emblem-studio' ),
	'archive'    => __( 'Archives',       'emblem-studio' ),
];
$dr_post_types = get_post_types( [ 'public' => true ], 'objects' );
unset( $dr_post_types['attachment'] );
// Pre-fetch titles for any saved post/page IDs so the chip pickers can render
// their existing selections without an extra round-trip on page load.
$dr_lookup_titles = function ( string $post_type, array $ids ): array {
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) {
		return [];
	}
	$posts = get_posts( [
		'post_type'      => $post_type,
		'post__in'       => $ids,
		'posts_per_page' => count( $ids ),
		'orderby'        => 'post__in',
		'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
		'no_found_rows'  => true,
	] );
	$out = [];
	foreach ( $posts as $p ) {
		$out[ (int) $p->ID ] = $p->post_title !== '' ? $p->post_title : __( '(no title)', 'emblem-studio' );
	}
	// Preserve any IDs that no longer resolve to a real post (renamed/trashed)
	// so the user can still see + remove them.
	foreach ( $ids as $id ) {
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = sprintf( __( '#%d (not found)', 'emblem-studio' ), $id );
		}
	}
	return $out;
};
// Same idea for taxonomy terms — categories and tags. Picker is the same
// component; we just pre-render chips for the already-selected IDs.
$dr_lookup_term_names = function ( string $taxonomy, array $ids ): array {
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) {
		return [];
	}
	$terms = get_terms( [
		'taxonomy'   => $taxonomy,
		'include'    => $ids,
		'hide_empty' => false,
		'orderby'    => 'include',
	] );
	$out = [];
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$out[ (int) $term->term_id ] = $term->name;
		}
	}
	foreach ( $ids as $id ) {
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = sprintf( __( '#%d (not found)', 'emblem-studio' ), $id );
		}
	}
	return $out;
};
/**
 * Build a short human summary of one filter set (Show or Hide).
 * Singletons are named; pluralities are counted ("3 categories").
 */
$dr_summarize = function ( array $filters ) use ( $dr_special_labels, $dr_post_types ): string {
	$parts = [];
	$specials = (array) ( $filters['specials'] ?? [] );
	if ( count( $specials ) === 1 ) {
		$parts[] = $dr_special_labels[ $specials[0] ] ?? $specials[0];
	} elseif ( count( $specials ) > 1 ) {
		$parts[] = sprintf( __( '%d special pages', 'emblem-studio' ), count( $specials ) );
	}
	$pts = (array) ( $filters['post_types'] ?? [] );
	if ( count( $pts ) === 1 ) {
		$pt   = $pts[0];
		$name = isset( $dr_post_types[ $pt ] ) ? $dr_post_types[ $pt ]->labels->name : $pt;
		$parts[] = $name;
	} elseif ( count( $pts ) > 1 ) {
		$parts[] = sprintf( __( '%d post types', 'emblem-studio' ), count( $pts ) );
	}
	$ncat = count( (array) ( $filters['categories'] ?? [] ) );
	if ( $ncat > 0 ) {
		$parts[] = sprintf( _n( '%d category', '%d categories', $ncat, 'emblem-studio' ), $ncat );
	}
	$ntag = count( (array) ( $filters['tags'] ?? [] ) );
	if ( $ntag > 0 ) {
		$parts[] = sprintf( _n( '%d tag', '%d tags', $ntag, 'emblem-studio' ), $ntag );
	}
	$npost = count( (array) ( $filters['posts'] ?? [] ) );
	if ( $npost > 0 ) {
		$parts[] = sprintf( _n( '%d post', '%d posts', $npost, 'emblem-studio' ), $npost );
	}
	$npage = count( (array) ( $filters['pages'] ?? [] ) );
	if ( $npage > 0 ) {
		$parts[] = sprintf( _n( '%d page', '%d pages', $npage, 'emblem-studio' ), $npage );
	}
	$nurl = count( (array) ( $filters['urls'] ?? [] ) );
	if ( $nurl > 0 ) {
		$parts[] = sprintf( _n( '%d URL pattern', '%d URL patterns', $nurl, 'emblem-studio' ), $nurl );
	}
	$nwc = count( (array) ( $filters['wc_pages'] ?? [] ) );
	if ( $nwc > 0 ) {
		$parts[] = sprintf( _n( '%d WC page', '%d WC pages', $nwc, 'emblem-studio' ), $nwc );
	}
	return implode( ', ', $parts );
};
/**
 * Compose the closed-disclosure summary line for ONE shell's rules.
 */
$dr_summary_html_for = function ( array $rules ) use ( $dr_summarize ): string {
	$show_mode    = $rules['show_mode']    ?? 'all';
	$show_summary = $dr_summarize( $rules['show_filters'] ?? [] );
	$hide_summary = $dr_summarize( $rules['hide_filters'] ?? [] );
	if ( $show_mode === 'all' && $hide_summary === '' ) {
		return '<span class="es-dr-summary is-default">' . esc_html__( 'default: everywhere', 'emblem-studio' ) . '</span>';
	}
	if ( $show_mode === 'all' ) {
		// hide_summary is non-empty here — the all + empty-hide case
		// already returned above.
		return '<span class="es-dr-summary is-custom">' . esc_html__( 'everywhere, except', 'emblem-studio' ) . ' ' . esc_html( $hide_summary ) . '</span>';
	}
	if ( $show_mode === 'selected' && $show_summary === '' ) {
		return '<span class="es-dr-summary is-warning">' . esc_html__( 'nowhere (Show is set to Selected but no filters are picked)', 'emblem-studio' ) . '</span>';
	}
	$html = '<span class="es-dr-summary is-custom">' . esc_html__( 'only', 'emblem-studio' ) . ' ' . esc_html( $show_summary );
	if ( $hide_summary !== '' ) {
		$html .= ' &middot; ' . esc_html__( 'except', 'emblem-studio' ) . ' ' . esc_html( $hide_summary );
	}
	$html .= '</span>';
	return $html;
};

// Pre-fetch per-shell config + rules + chip titles + summary so the partial
// renders without further DB hits.
$shell_data = [];
foreach ( ES_Form_Repo::auto_shells() as $shell ) {
	$cfg   = isset( $form ) ? ES_Form_Repo::get_shell_trigger_config( $form, $shell ) : ES_Form_Repo::sanitize_auto_trigger_config( [] );
	$rules = isset( $form ) ? ES_Display_Rules::get_for_form_shell( $form, $shell ) : ES_Display_Rules::defaults();
	$show  = $rules['show_filters'] ?? [];
	$hide  = $rules['hide_filters'] ?? [];
	$shell_data[ $shell ] = [
		'cfg'          => $cfg,
		'rules'        => $rules,
		'summary_html' => $dr_summary_html_for( $rules ),
		'titles'       => [
			'show' => [
				'posts' => $dr_lookup_titles( 'post', (array) ( $show['posts']      ?? [] ) ),
				'pages' => $dr_lookup_titles( 'page', (array) ( $show['pages']      ?? [] ) ),
				'cats'  => $dr_lookup_term_names( 'category', (array) ( $show['categories'] ?? [] ) ),
				'tags'  => $dr_lookup_term_names( 'post_tag', (array) ( $show['tags']       ?? [] ) ),
			],
			'hide' => [
				'posts' => $dr_lookup_titles( 'post', (array) ( $hide['posts']      ?? [] ) ),
				'pages' => $dr_lookup_titles( 'page', (array) ( $hide['pages']      ?? [] ) ),
				'cats'  => $dr_lookup_term_names( 'category', (array) ( $hide['categories'] ?? [] ) ),
				'tags'  => $dr_lookup_term_names( 'post_tag', (array) ( $hide['tags']       ?? [] ) ),
			],
		],
	];
}
// Carry forward legacy single-list value if the new array is empty.
if ( empty( $f->funnelkit_lists ) && ! empty( $f->funnelkit_list_id ) ) {
	$f->funnelkit_lists = [ (int) $f->funnelkit_list_id ];
}

// A/B testing state — populated only for existing forms. When no test
// runs, $ab_test stays null and the Design tab renders single-version.
// A/B is account-gated (cap 'ab'); the Lite build omits ES_AB, so default to
// no-test there and skip every ES_AB call.
if ( ES_Caps::has( 'ab' ) && ! $is_new ) {
	$ab_test     = ES_AB::running_test_for_form( (int) $f->id );
	$ab_variants = $ab_test ? ES_AB::live_variants_for_form( (int) $f->id ) : [];
	$ab_archived = ES_AB::archived_variants_for_form( (int) $f->id );
} else {
	$ab_test     = null;
	$ab_variants = [];
	$ab_archived = [];
}
$ab_running    = $ab_test && isset( $ab_variants['A'], $ab_variants['B'] );
$ab_day        = ( $ab_running && ES_Caps::has( 'ab' ) ) ? ES_AB::test_day_for( $ab_test ) : 0;
$ab_archive_ct = count( $ab_archived );
?>
<div class="wrap emblem-studio-wrap">
	<h1><?php echo $is_new ? esc_html__( 'Add Form', 'emblem-studio' ) : esc_html__( 'Edit Form', 'emblem-studio' ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="es-toast" role="status" aria-live="polite"><?php esc_html_e( 'Asset saved', 'emblem-studio' ); ?></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['stats_reset'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Stats for this form have been reset.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['duplicated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form duplicated. You\'re now editing the copy. Status is set to Inactive — flip it to Active when ready.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['restored_as_new'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Restored from a revision as a new form. The original form is untouched. Status is set to Inactive — flip it to Active when ready.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['test_started'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A/B test started. Variants A and B are seeded with the current design — switch to variant B in the Design tab to edit it.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['test_ended'] ) ) :
		$ended = sanitize_key( (string) wp_unslash( $_GET['test_ended'] ) );
		$msg = 'A' === $ended || 'B' === $ended
			? sprintf( __( 'A/B test ended. Variant %s won and is now the live design. The other variant is archived.', 'emblem-studio' ), strtoupper( $ended ) )
			: __( 'A/B test ended without a winner. Both variants are archived; the form has reverted to its pre-test design.', 'emblem-studio' );
	?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['variant_restored'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Archived variant restored. A new A/B test is running — current design is slot A, restored variant is slot B.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['variant_restore_failed'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not restore: a test is already running, or the archived variant is missing.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['variant_deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Archived variant deleted.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>

	<?php
	// Concurrent-edit lock data + UI (existing assets only). $lock_* come
	// from ES_Admin::render_edit(). The browser decides (using its per-tab
	// session token) whether to claim the lock or show the take-over modal.
	if ( ! $is_new && isset( $lock_nonce ) ) :
		$es_lock_token = isset( $lock_holder_token ) ? (string) $lock_holder_token : '';
		$es_lock_name  = isset( $lock_holder_name ) ? (string) $lock_holder_name : '';
		$es_lock_isme  = ! empty( $lock_holder_is_me );
	?>
		<script>
		window.EmblemStudioLock = {
			formId: <?php echo (int) $f->id; ?>,
			nonce: <?php echo wp_json_encode( $lock_nonce ); ?>,
			ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			holderToken: <?php echo wp_json_encode( $es_lock_token ); ?>,
			holderName: <?php echo wp_json_encode( $es_lock_name ); ?>,
			holderIsMe: <?php echo $es_lock_isme ? 'true' : 'false'; ?>,
			formsUrl: <?php echo wp_json_encode( admin_url( 'admin.php?page=emblem-studio' ) ); ?>
		};
		</script>
		<div class="es-lock-modal" id="es-lock-modal" hidden>
			<div class="es-lock-card">
				<p class="es-lock-title"><?php esc_html_e( 'This asset is already being edited', 'emblem-studio' ); ?></p>
				<p class="es-lock-text"><span class="es-lock-who"></span> <span class="es-lock-text-rest"><?php esc_html_e( 'has it open. If you take over, that session is locked out and loses unsaved changes.', 'emblem-studio' ); ?></span></p>
				<div class="es-lock-actions">
					<button type="button" class="button es-lock-cancel"><?php esc_html_e( 'Cancel', 'emblem-studio' ); ?></button>
					<button type="button" class="button es-lock-viewonly"><?php esc_html_e( 'View only', 'emblem-studio' ); ?></button>
					<button type="button" class="button button-primary es-lock-takeover"><?php esc_html_e( 'Take over', 'emblem-studio' ); ?></button>
				</div>
			</div>
		</div>
		<div class="es-lock-banner" id="es-lock-banner" hidden>
			<span class="es-lock-banner-text"></span>
			<button type="button" class="button button-small es-lock-takeback"><?php esc_html_e( 'Take editing back', 'emblem-studio' ); ?></button>
			<button type="button" class="button button-small es-lock-reload"><?php esc_html_e( 'Reload', 'emblem-studio' ); ?></button>
		</div>
		<div class="es-lock-modal" id="es-lock-boot-modal" hidden>
			<div class="es-lock-card">
				<p class="es-lock-title"><?php esc_html_e( 'Editing taken over', 'emblem-studio' ); ?></p>
				<p class="es-lock-text es-lock-boot-text"></p>
				<div class="es-lock-actions">
					<button type="button" class="button es-lock-boot-dismiss"><?php esc_html_e( 'Continue read-only', 'emblem-studio' ); ?></button>
					<button type="button" class="button button-primary es-lock-boot-takeback"><?php esc_html_e( 'Take editing back', 'emblem-studio' ); ?></button>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="es-form-editor">
		<?php wp_nonce_field( 'es_save_form' ); ?>
		<input type="hidden" name="action" value="es_save_form">
		<input type="hidden" name="id" value="<?php echo (int) $f->id; ?>">
		<?php // Per-tab session token (set by admin.js from sessionStorage) — the save guard rejects a booted session. ?>
		<input type="hidden" name="lock_token" id="es-lock-token" value="">


		<div class="es-form-header" data-status="<?php echo esc_attr( $f->status ); ?>"<?php echo $ab_running ? ' data-test="running"' : ''; ?>>
			<div class="es-form-header-bar">
				<div class="es-form-header-left">
					<label for="es-name" class="es-form-header-name-label"><?php esc_html_e( 'Name', 'emblem-studio' ); ?></label>
					<?php $es_name_ph_id = $f->id ?: ES_Form_Repo::next_id(); // real id when editing; predicted next id for a new form ?>
					<input id="es-name" name="name" type="text" class="es-form-header-name" value="<?php echo esc_attr( $f->name ); ?>" placeholder="<?php echo esc_attr( sprintf( /* translators: %d: asset id */ __( 'Asset %d', 'emblem-studio' ), $es_name_ph_id ) ); ?>">
				</div>
				<div class="es-form-header-right">
					<?php if ( $ab_running ) : ?>
						<span class="es-test-pill" title="<?php esc_attr_e( 'A/B test is running. Both variants are live; visitors are bucketed 50/50.', 'emblem-studio' ); ?>">
							<span class="es-test-pill-dot" aria-hidden="true"></span>
							<?php
							/* translators: %d: day number, starting at 1 */
							printf( esc_html__( 'TEST · day %d', 'emblem-studio' ), (int) $ab_day );
							?>
						</span>
					<?php endif; ?>
					<?php $is_active = ( $f->status === 'active' ); ?>
					<span class="es-status-toggle">
						<input type="hidden" id="es-status" name="status" value="<?php echo $is_active ? 'active' : 'inactive'; ?>">
						<button type="button" class="es-status-switch" role="switch"
							aria-checked="<?php echo $is_active ? 'true' : 'false'; ?>"
							aria-label="<?php esc_attr_e( 'Active', 'emblem-studio' ); ?>"></button>
						<span class="es-status-text"><?php echo $is_active ? esc_html__( 'Active', 'emblem-studio' ) : esc_html__( 'Inactive', 'emblem-studio' ); ?></span>
					</span>
					<?php
					// Effective-state chip: when this asset is Active but its schedule
					// window says it isn't actually showing, say so right here so the
					// green "Active" toggle isn't misleading. Derived; status untouched.
					$eff_state = ES_Form_Repo::effective_state( $f );
					// Show the effective-status pill for every active-status asset (the
					// toggle already reads "Inactive" for the rest), so it's consistent.
					if ( in_array( $eff_state, array( 'active', 'scheduled', 'expired', 'ending' ), true ) ) :
						$hdr_fmt = get_option( 'date_format' ) . ' g:i A';
						if ( 'scheduled' === $eff_state ) {
							$hdr_label = __( 'Scheduled', 'emblem-studio' );
							$hdr_title = sprintf( __( 'Active, but its schedule starts %s — not showing yet.', 'emblem-studio' ), mysql2date( $hdr_fmt, (string) $f->active_from ) );
						} elseif ( 'expired' === $eff_state ) {
							$hdr_label = __( 'Expired', 'emblem-studio' );
							$hdr_title = sprintf( __( 'Active, but its schedule ended %s — no longer showing.', 'emblem-studio' ), mysql2date( $hdr_fmt, (string) $f->active_until ) );
						} elseif ( 'ending' === $eff_state ) {
							$hdr_label = __( 'Scheduled to end', 'emblem-studio' );
							$hdr_title = sprintf( __( 'Showing now, but scheduled to end %s.', 'emblem-studio' ), mysql2date( $hdr_fmt, (string) $f->active_until ) );
						} else {
							$hdr_label = __( 'Active', 'emblem-studio' );
							$hdr_title = __( 'Active and showing now.', 'emblem-studio' );
						}
					?>
						<span class="es-status-pill es-status-<?php echo esc_attr( $eff_state ); ?>" title="<?php echo esc_attr( $hdr_title ); ?>"><?php echo esc_html( $hdr_label ); ?></span>
					<?php endif; ?>
					<span class="es-save-group">
						<button type="submit" class="button button-primary" name="submit"><?php echo $is_new ? esc_html__( 'Create form', 'emblem-studio' ) : esc_html__( 'Save', 'emblem-studio' ); ?></button>
						<span class="es-dirty-flag" aria-live="polite"><?php esc_html_e( 'Unsaved', 'emblem-studio' ); ?></span>
					</span>
				</div>
			</div>
			<style>
				/* The instant tooltip comes from the global [data-es-tip] handler
				   (admin.js) — no extra CSS bubble here. */
				.es-tab-locked { opacity: .6; }
				.es-tab-locked .es-tab-lock { font-size: 11px; }
				.es-revisions-upsell { text-align: center; padding: 2.5em 1em; }
				.es-revisions-upsell .button-primary { margin-top: .4em; }
			</style>
			<nav class="es-tabs" role="tablist">
				<a href="#design"   class="es-tab is-active" data-tab="design"   role="tab"><?php esc_html_e( 'Design',   'emblem-studio' ); ?></a>
				<a href="#shells"   class="es-tab"           data-tab="shells"   role="tab"><?php esc_html_e( 'Shells',   'emblem-studio' ); ?></a>
				<a href="#connect"  class="es-tab"           data-tab="connect"  role="tab"><?php esc_html_e( 'Connect',  'emblem-studio' ); ?></a>
				<?php if ( ES_Caps::has( 'linked_forms' ) ) : ?>
				<a href="#linked"   class="es-tab"           data-tab="linked"   role="tab"><?php esc_html_e( 'Linked forms', 'emblem-studio' ); ?></a>
				<?php endif; ?>
				<a href="#stats"     class="es-tab"           data-tab="stats"     role="tab"><?php esc_html_e( 'Stats',     'emblem-studio' ); ?></a>
				<?php if ( ES_Caps::has( 'revisions' ) ) : ?>
				<a href="#revisions" class="es-tab"           data-tab="revisions" role="tab"><?php esc_html_e( 'Revisions', 'emblem-studio' ); ?></a>
				<?php else : ?>
				<a href="#revisions" class="es-tab es-tab-locked" data-tab="revisions" role="tab" data-es-tip="<?php esc_attr_e( 'Connect a free account to unlock revision history.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Revisions', 'emblem-studio' ); ?> <span class="es-tab-lock" aria-hidden="true">🔒</span></a>
				<?php endif; ?>
				<a href="#settings"  class="es-tab"           data-tab="settings"  role="tab"><?php esc_html_e( 'Settings',  'emblem-studio' ); ?></a>
			</nav>
		</div>

		<section id="es-tab-design" class="es-tab-panel is-active" data-panel="design">
			<?php if ( $ab_running ) :
				$aw_status = ES_AB::auto_winner_status( (int) $f->id );
				$ab_test_obj = $ab_test;
				$ab_primary_cfg = ! empty( $ab_test->primary_metric ) ? (string) $ab_test->primary_metric : 'conversions';
				$ab_labels      = ES_AB::metric_labels( $ab_primary_cfg );
			?>
				<details class="es-ab-config" open>
					<summary>
						<span class="es-ab-config-summary-icon" aria-hidden="true">⚙</span>
						<span class="es-ab-config-summary-title"><?php esc_html_e( 'Test settings', 'emblem-studio' ); ?></span>
						<span class="es-ab-config-summary-hint description"><?php esc_html_e( 'Traffic split + auto-declare thresholds', 'emblem-studio' ); ?></span>
						<span class="es-ab-primary-metric-badge" title="<?php esc_attr_e( 'Auto-detected at test start based on whether the form has a <form> tag. Locks for the duration of the test.', 'emblem-studio' ); ?>">
							<?php
							if ( $ab_primary_cfg === 'clicks' ) {
								esc_html_e( 'Optimizes for: Clicks', 'emblem-studio' );
							} else {
								esc_html_e( 'Optimizes for: Conversions', 'emblem-studio' );
							}
							?>
						</span>
					</summary>
					<div class="es-ab-config-body">
						<div class="es-ab-config-row">
							<label class="es-ab-config-label" for="es-ab-traffic-a"><?php esc_html_e( 'Traffic split', 'emblem-studio' ); ?></label>
							<div class="es-ab-traffic-fields">
								<span class="es-ab-traffic-slot">A</span>
								<input type="number" id="es-ab-traffic-a" name="test_config[traffic_a]" class="small-text es-ab-traffic-input" data-side="A" min="1" max="99" value="<?php echo (int) $ab_variants['A']->traffic_weight; ?>">
								<span class="es-ab-traffic-unit">%</span>
								<span class="es-ab-traffic-sep">/</span>
								<span class="es-ab-traffic-slot">B</span>
								<input type="number" id="es-ab-traffic-b" class="small-text es-ab-traffic-input" data-side="B" min="1" max="99" value="<?php echo (int) $ab_variants['B']->traffic_weight; ?>">
								<span class="es-ab-traffic-unit">%</span>
								<span class="es-ab-traffic-note description"><?php esc_html_e( '(sum is locked to 100)', 'emblem-studio' ); ?></span>
							</div>
						</div>

						<div class="es-ab-config-row">
							<label class="es-ab-config-label">
								<input type="checkbox" class="es-toggle-input" name="test_config[auto_declare]" value="1" <?php checked( ! empty( $ab_test_obj->auto_declare ) ); ?>>
								<?php esc_html_e( 'Auto-declare winner when all enabled conditions are met', 'emblem-studio' ); ?>
							</label>
						</div>

						<?php
						$conditions = [
							'min_runtime_days' => [
								'label'    => __( 'Minimum runtime (days)', 'emblem-studio' ),
								'value'    => $ab_test_obj->min_runtime_days,
								'step'     => 1,
								'min'      => 1,
								'max'      => 365,
								'status'   => $aw_status['runtime']     ?? null,
							],
							'min_conversions_per_variant' => [
								'label'    => $ab_labels['min_count_label'],
								'value'    => $ab_test_obj->min_conversions_per_variant,
								'step'     => 1,
								'min'      => 1,
								'max'      => 100000,
								'status'   => $aw_status['conversions'] ?? null,
							],
							'min_cr_gap_percent' => [
								'label'    => $ab_labels['gap_label'],
								'value'    => $ab_test_obj->min_cr_gap_percent,
								'step'     => 0.1,
								'min'      => 0,
								'max'      => 999.99,
								'status'   => $aw_status['gap']         ?? null,
							],
							'min_confidence_percent' => [
								'label'    => __( 'Min Bayesian confidence (%)', 'emblem-studio' ),
								'value'    => $ab_test_obj->min_confidence_percent,
								'step'     => 0.1,
								'min'      => 50,
								'max'      => 99.99,
								'status'   => $aw_status['confidence']  ?? null,
							],
						];
						?>
						<div class="es-ab-conditions-grid">
							<?php foreach ( $conditions as $col => $cond ) :
								$enabled = $cond['value'] !== null;
								$status_met = $cond['status']['met'] ?? null;
							?>
								<div class="es-ab-condition<?php echo $status_met === true ? ' is-met' : ''; ?>">
									<label class="es-ab-condition-toggle">
										<input type="checkbox" class="es-toggle-input es-ab-condition-enable" name="test_config[enable_<?php echo esc_attr( $col ); ?>]" value="1" <?php checked( $enabled ); ?>>
										<span class="es-ab-condition-label"><?php echo esc_html( $cond['label'] ); ?></span>
									</label>
									<div class="es-ab-condition-input">
										<input type="number"
											name="test_config[<?php echo esc_attr( $col ); ?>]"
											value="<?php echo esc_attr( $cond['value'] !== null ? (string) $cond['value'] : '' ); ?>"
											step="<?php echo esc_attr( (string) $cond['step'] ); ?>"
											min="<?php echo esc_attr( (string) $cond['min'] ); ?>"
											max="<?php echo esc_attr( (string) $cond['max'] ); ?>"
											class="small-text">
									</div>
									<?php if ( $cond['status'] ) : ?>
										<span class="es-ab-condition-status <?php echo $status_met ? 'is-met' : 'is-not-met'; ?>">
											<span class="es-ab-condition-status-icon" aria-hidden="true"><?php echo $status_met ? '✓' : '·'; ?></span>
											<?php echo esc_html( $cond['status']['label'] ); ?>
										</span>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</details>
				<div class="es-ab-strip es-ab-strip--running">
					<div class="es-ab-tabs" role="tablist" aria-label="<?php esc_attr_e( 'A/B variants', 'emblem-studio' ); ?>">
						<button type="button" class="es-ab-tab is-active" data-variant-slot="A" role="tab" aria-selected="true">
							<span class="es-ab-tab-slot">A</span>
							<span class="es-ab-tab-traffic"><?php echo (int) $ab_variants['A']->traffic_weight; ?>%</span>
						</button>
						<button type="button" class="es-ab-tab" data-variant-slot="B" role="tab" aria-selected="false">
							<span class="es-ab-tab-slot">B</span>
							<span class="es-ab-tab-traffic"><?php echo (int) $ab_variants['B']->traffic_weight; ?>%</span>
						</button>
					</div>
					<div class="es-ab-strip-meta">
						<span class="es-ab-strip-day">
							<?php
							/* translators: %d: day number */
							printf( esc_html__( 'Day %d', 'emblem-studio' ), (int) $ab_day );
							?>
						</span>
						<span class="es-ab-strip-hint description"><?php esc_html_e( 'Editing variant ', 'emblem-studio' ); ?><strong class="es-ab-strip-active-slot">A</strong></span>
						<?php if ( $ab_archive_ct > 0 ) : ?>
							<button type="button" class="button button-link es-ab-archive-toggle" aria-expanded="false" aria-controls="es-ab-archive-panel">
								<span class="dashicons dashicons-archive" aria-hidden="true"></span>
								<?php /* translators: %d: count */
								printf( esc_html__( 'Archive (%d)', 'emblem-studio' ), $ab_archive_ct ); ?>
							</button>
						<?php endif; ?>
						<?php
						// Declare a winner without leaving the Design tab (the full
						// banner with per-variant numbers lives on the Stats tab).
						// Same nonce'd es_end_test endpoint + es-ab-declare-winner
						// confirm handler as the Stats banner.
						$ab_design_end_url = function ( string $winner ) use ( $f ) {
							$path = 'admin-post.php?action=es_end_test&form_id=' . (int) $f->id;
							if ( $winner !== '' ) { $path .= '&winner=' . rawurlencode( $winner ); }
							return wp_nonce_url( admin_url( $path ), 'es_end_test_' . (int) $f->id );
						};
						?>
						<span class="es-ab-strip-declare">
							<span class="es-ab-strip-declare-label description"><?php esc_html_e( 'Declare winner:', 'emblem-studio' ); ?></span>
							<a class="button button-small button-primary es-ab-declare-winner" data-winner="A" href="<?php echo esc_url( $ab_design_end_url( 'A' ) ); ?>">A</a>
							<a class="button button-small button-primary es-ab-declare-winner" data-winner="B" href="<?php echo esc_url( $ab_design_end_url( 'B' ) ); ?>">B</a>
							<a class="button button-small button-link-delete es-ab-declare-winner" data-winner="" href="<?php echo esc_url( $ab_design_end_url( '' ) ); ?>"><?php esc_html_e( 'End', 'emblem-studio' ); ?></a>
						</span>
					</div>
				</div>
				<input type="hidden" name="ab_test_running" value="1">
			<?php elseif ( ! $is_new && ES_Caps::has( 'ab' ) ) : ?>
				<div class="es-ab-strip es-ab-strip--idle">
					<div class="es-ab-strip-meta">
						<strong><?php esc_html_e( 'No A/B test running.', 'emblem-studio' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Start a test to compare a variant against the current design. Stats will reset so the comparison runs apples-to-apples.', 'emblem-studio' ); ?></span>
					</div>
					<div class="es-ab-strip-actions">
						<?php if ( $ab_archive_ct > 0 ) : ?>
							<button type="button" class="button button-link es-ab-archive-toggle" aria-expanded="false" aria-controls="es-ab-archive-panel">
								<span class="dashicons dashicons-archive" aria-hidden="true"></span>
								<?php /* translators: %d: count */
								printf( esc_html__( 'Archive (%d)', 'emblem-studio' ), $ab_archive_ct ); ?>
							</button>
						<?php endif; ?>
						<button type="button" class="button button-secondary es-ab-start-open">
							<?php esc_html_e( 'Start A/B test', 'emblem-studio' ); ?>
						</button>
					</div>
				</div>

				<?php
				// Start-test confirmation modal — graphic + irreversible-reset
				// warning (mirrors the SaaS popup). The "Start A/B test" button on
				// the idle strip opens this; the confirm button submits the form
				// with ab_action=start.
				$ab_start_metric = ES_AB::detect_primary_metric( (string) $f->html );
				?>
				<div class="es-modal es-ab-start-modal" id="es-modal-ab-start" hidden>
					<div class="es-modal-backdrop es-ab-start-dismiss"></div>
					<div class="es-modal-dialog" role="dialog" aria-labelledby="es-ab-start-title" aria-modal="true">
						<button type="button" class="es-modal-close es-ab-start-dismiss" aria-label="<?php esc_attr_e( 'Close', 'emblem-studio' ); ?>">&times;</button>
						<div class="es-ab-start-graphic" aria-hidden="true">
							<div class="es-ab-mini es-ab-mini--a">
								<span class="es-ab-mini-badge">A</span>
								<span class="es-ab-mini-h"></span><span class="es-ab-mini-l"></span><span class="es-ab-mini-l is-short"></span>
								<span class="es-ab-mini-btn"></span>
							</div>
							<div class="es-ab-start-split">
								<svg class="es-ab-start-split-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M12 3v5" /><path d="M12 8c0 3-6 3-6 7v3" /><path d="M12 8c0 3 6 3 6 7v3" />
								</svg>
								<span class="es-ab-start-split-label"><?php esc_html_e( 'Split traffic', 'emblem-studio' ); ?></span>
							</div>
							<div class="es-ab-mini es-ab-mini--b">
								<span class="es-ab-mini-badge">B</span>
								<span class="es-ab-mini-h"></span><span class="es-ab-mini-l"></span><span class="es-ab-mini-l is-short"></span>
								<span class="es-ab-mini-btn"></span>
							</div>
						</div>
						<h3 id="es-ab-start-title"><?php esc_html_e( 'Start an A/B test?', 'emblem-studio' ); ?></h3>
						<p class="es-ab-start-text">
							<?php
							if ( $ab_start_metric === 'clicks' ) {
								esc_html_e( 'Your current design is copied into Variant A and Variant B. Traffic is split between them (50/50 to start — adjustable while it runs) and the winner is decided by click-through rate. You can edit either variant and tune the rules once the test is running.', 'emblem-studio' );
							} else {
								esc_html_e( 'Your current design is copied into Variant A and Variant B. Traffic is split between them (50/50 to start — adjustable while it runs) and the winner is decided by conversion rate. You can edit either variant and tune the rules once the test is running.', 'emblem-studio' );
							}
							?>
						</p>
						<div class="es-ab-start-warning">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Starting permanently resets this asset’s stats to zero so the comparison runs clean — this can’t be undone. Your captured leads are kept.', 'emblem-studio' ); ?></span>
						</div>
						<div class="es-modal-actions es-ab-start-actions">
							<button type="button" class="button es-ab-start-dismiss"><?php esc_html_e( 'Cancel', 'emblem-studio' ); ?></button>
							<button type="submit" class="button button-primary es-ab-start-confirm" name="ab_action" value="start"><?php esc_html_e( 'Start A/B test', 'emblem-studio' ); ?></button>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! $is_new && $ab_archive_ct > 0 ) :
				$restore_url = function ( int $variant_id ) use ( $f ) {
					return wp_nonce_url(
						admin_url( 'admin-post.php?action=es_restore_variant&variant_id=' . $variant_id . '&form_id=' . (int) $f->id ),
						'es_restore_variant_' . $variant_id
					);
				};
				$delete_url = function ( int $variant_id ) use ( $f ) {
					return wp_nonce_url(
						admin_url( 'admin-post.php?action=es_delete_variant&variant_id=' . $variant_id . '&form_id=' . (int) $f->id ),
						'es_delete_variant_' . $variant_id
					);
				};
			?>
				<div id="es-ab-archive-panel" class="es-ab-archive-panel" hidden>
					<h4 class="es-ab-archive-title">
						<?php esc_html_e( 'Archived variants', 'emblem-studio' ); ?>
						<span class="description"><?php esc_html_e( 'Restore an old design to test it against the current one. Restoring starts a new A/B test.', 'emblem-studio' ); ?></span>
					</h4>
					<table class="es-ab-archive-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Variant', 'emblem-studio' ); ?></th>
								<th class="num"><?php esc_html_e( 'CR at archive', 'emblem-studio' ); ?></th>
								<th><?php esc_html_e( 'Archived', 'emblem-studio' ); ?></th>
								<th class="num"><?php esc_html_e( 'Actions', 'emblem-studio' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $ab_archived as $v ) :
								$archived_ts = ! empty( $v->archived_at ) ? strtotime( $v->archived_at ) : 0;
								$archived_label = $archived_ts ? wp_date( get_option( 'date_format', 'Y-m-d' ), $archived_ts ) : '—';
								$cr = $v->archive_conv_rate !== null ? number_format_i18n( (float) $v->archive_conv_rate, 1 ) . '%' : '—';
							?>
								<tr>
									<td>
										<span class="es-ab-archive-id">#<?php echo (int) $v->id; ?></span>
										<?php if ( $v->name ) : ?>
											<strong><?php echo esc_html( $v->name ); ?></strong>
										<?php endif; ?>
									</td>
									<td class="num"><?php echo esc_html( $cr ); ?></td>
									<td><?php echo esc_html( $archived_label ); ?></td>
									<td class="num">
										<?php if ( $ab_running ) : ?>
											<span class="description" title="<?php esc_attr_e( 'End the current test before restoring an archived variant.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Test running', 'emblem-studio' ); ?></span>
										<?php else : ?>
											<a class="button button-small es-ab-archive-restore" href="<?php echo esc_url( $restore_url( (int) $v->id ) ); ?>"><?php esc_html_e( 'Restore', 'emblem-studio' ); ?></a>
										<?php endif; ?>
										<a class="button button-small button-link-delete es-ab-archive-delete" href="<?php echo esc_url( $delete_url( (int) $v->id ) ); ?>"><?php esc_html_e( 'Delete', 'emblem-studio' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="es-authoring-help">
				<p class="es-authoring-help-intro"><?php esc_html_e( 'Authoring this form by hand, or with an outside AI?', 'emblem-studio' ); ?></p>
				<p class="description"><?php esc_html_e( 'Copy the specification to see exactly what an Emblem Studio form supports — or copy the AI pre-prompt, fill in the bracketed blanks, and paste it into ChatGPT or Claude to generate a form.', 'emblem-studio' ); ?></p>
				<p class="es-authoring-help-actions">
					<button type="button" class="es-copy es-authoring-link" data-es-copy="<?php echo esc_attr( ES_Authoring::human_spec() ); ?>"><?php esc_html_e( 'Copy form specifications', 'emblem-studio' ); ?></button>
					<span class="es-authoring-help-sep" aria-hidden="true">·</span>
					<button type="button" class="es-copy es-authoring-link" data-es-copy="<?php echo esc_attr( ES_Authoring::ai_preprompt() ); ?>"><?php esc_html_e( 'Copy AI pre-prompt', 'emblem-studio' ); ?></button>
				</p>
			</div>

			<?php
			// Width controls for the Design preview (mirrors the editor's).
			$design_width_html = '<div class="es-design-width" role="group" aria-label="' . esc_attr__( 'Preview width', 'emblem-studio' ) . '">'
				. '<button type="button" data-w="320">320</button>'
				. '<button type="button" data-w="480">480</button>'
				. '<button type="button" data-w="768">768</button>'
				. '<button type="button" data-w="1024">1024</button>'
				. '<button type="button" data-w="fluid" class="is-active">' . esc_html__( 'Fluid', 'emblem-studio' ) . '</button>'
				. '<input type="range" class="es-design-width-slider" min="280" max="2560" step="10" aria-label="' . esc_attr__( 'Custom width', 'emblem-studio' ) . '">'
				. '<span class="es-design-width-label">' . esc_html__( 'Fluid', 'emblem-studio' ) . '</span>'
				. '</div>';

			// Preview testing toggles. Default preview is a safe sandbox: no click
			// tracking, no required enforcement, nothing sent to the CRM. These two
			// opt-ins let you test the real experience. "Submit for real" implies
			// "Enforce required" (you never want to push invalid leads).
			$design_test_html = '<div class="es-design-test" role="group" aria-label="' . esc_attr__( 'Preview testing', 'emblem-studio' ) . '">'
				. '<label class="es-design-test-opt" title="' . esc_attr__( 'Make required fields block submission, like the live form.', 'emblem-studio' ) . '"><input type="checkbox" class="es-design-validate"> ' . esc_html__( 'Enforce required', 'emblem-studio' ) . '</label>'
				. '<label class="es-design-test-opt" title="' . esc_attr__( 'Actually create a lead and send it to your CRM.', 'emblem-studio' ) . '"><input type="checkbox" class="es-design-send"> ' . esc_html__( 'Submit for real', 'emblem-studio' ) . '</label>'
				. '</div>';
			?>

			<?php if ( $ab_running ) :
				// When an A/B test is running, render TWO complete editor
				// panes — one per slot — each with its own CodeMirror
				// instance. Inactive pane is hidden via CSS. Tabbing
				// just toggles visibility. The textareas submit directly
				// (name="variants[A|B][html|css]") so the data path has
				// no hidden-field intermediaries.
				foreach ( [ 'A', 'B' ] as $slot ) :
					$v          = $ab_variants[ $slot ];
					$pane_class = 'es-ab-pane' . ( $slot === 'A' ? ' is-active' : '' );
			?>
				<div class="<?php echo esc_attr( $pane_class ); ?>" data-es-variant-pane="<?php echo esc_attr( $slot ); ?>">
					<textarea id="es-html-<?php echo esc_attr( $slot ); ?>" name="variants[<?php echo esc_attr( $slot ); ?>][html]" hidden><?php echo esc_textarea( (string) $v->html ); ?></textarea>
					<textarea id="es-css-<?php echo esc_attr( $slot ); ?>" name="variants[<?php echo esc_attr( $slot ); ?>][css]" hidden><?php echo esc_textarea( (string) $v->css ); ?></textarea>
				</div>
			<?php endforeach;
			$design_shells = ES_Form_Repo::types_for( $f );
			if ( empty( $design_shells ) ) { $design_shells = [ 'inline' ]; }
			$shell_label_map = [ 'inline' => __( 'Inline', 'emblem-studio' ), 'popup-click' => __( 'Click popup', 'emblem-studio' ), 'popup-auto' => __( 'Auto popup', 'emblem-studio' ), 'ribbon' => __( 'Ribbon', 'emblem-studio' ), 'slide-in' => __( 'Slide-in', 'emblem-studio' ) ];
			?>
				<?php // One preview for whichever variant the tabs have active; the editor reads/writes that variant's hidden fields. ?>
				<div class="es-design-preview" data-preview-url="<?php echo esc_url( ES_Preview::url_for( (int) $f->id ) ); ?>" data-is-new="0" data-ab="1">
					<div class="es-design-preview-bar">
						<span class="es-design-preview-tag" aria-hidden="true"><?php esc_html_e( 'Preview', 'emblem-studio' ); ?></span>
						<div class="es-design-shells" role="group" aria-label="<?php esc_attr_e( 'Preview shell', 'emblem-studio' ); ?>">
							<?php $first = true; foreach ( $design_shells as $sk ) : ?>
								<button type="button" class="es-design-shell<?php echo $first ? ' is-active' : ''; ?>" data-shell="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $shell_label_map[ $sk ] ?? $sk ); ?></button>
							<?php $first = false; endforeach; ?>
						</div>
						<?php echo $design_width_html; // phpcs:ignore WordPress.Security.EscapeOutput — built from esc_*() above ?>
						<?php echo $design_test_html; // phpcs:ignore WordPress.Security.EscapeOutput — built from esc_*() above ?>
						<span class="es-design-preview-spacer"></span>
						<button type="button" class="button es-design-replay" id="es-design-replay" data-es-tip="<?php esc_attr_e( 'Replay entry animations', 'emblem-studio' ); ?>"><span aria-hidden="true">↻</span> <?php esc_html_e( 'Replay', 'emblem-studio' ); ?></button>
						<button type="button" class="button button-primary es-inspector-open" id="es-inspector-open"><?php esc_html_e( 'Open editor', 'emblem-studio' ); ?> <span aria-hidden="true">⤢</span></button>
					</div>
					<div class="es-design-preview-stage">
						<iframe name="es-inline-preview-iframe" id="es-inline-preview-iframe" class="es-design-preview-frame" src="about:blank" title="<?php esc_attr_e( 'Asset preview', 'emblem-studio' ); ?>"></iframe>
						<div class="es-design-placeholder" hidden></div>
						<div class="es-design-shell-hint" hidden></div>
						<div class="es-design-loader"><span class="es-design-spinner" aria-hidden="true"></span></div>
					</div>
				</div>
			<?php else : // Single-version asset (new or existing) — preview-first; editing happens in the takeover editor. ?>
				<?php
				$design_shells = ES_Form_Repo::types_for( $f );
				if ( empty( $design_shells ) ) { $design_shells = [ 'inline' ]; }
				$shell_label_map = [ 'inline' => __( 'Inline', 'emblem-studio' ), 'popup-click' => __( 'Click popup', 'emblem-studio' ), 'popup-auto' => __( 'Auto popup', 'emblem-studio' ), 'ribbon' => __( 'Ribbon', 'emblem-studio' ), 'slide-in' => __( 'Slide-in', 'emblem-studio' ) ];
				$design_empty = ( trim( (string) $f->html ) === '' );
				?>
				<textarea id="es-html" name="html" hidden><?php echo esc_textarea( (string) $f->html ); ?></textarea>
				<textarea id="es-css" name="css" hidden><?php echo esc_textarea( (string) $f->css ); ?></textarea>
				<div class="es-design-preview" data-preview-url="<?php echo $is_new ? '' : esc_url( ES_Preview::url_for( (int) $f->id ) ); ?>" data-is-new="<?php echo $is_new ? '1' : '0'; ?>" data-empty="<?php echo $design_empty ? '1' : '0'; ?>">
					<div class="es-design-preview-bar">
						<span class="es-design-preview-tag" aria-hidden="true"><?php esc_html_e( 'Preview', 'emblem-studio' ); ?></span>
						<div class="es-design-shells" role="group" aria-label="<?php esc_attr_e( 'Preview shell', 'emblem-studio' ); ?>">
							<?php $first = true; foreach ( $design_shells as $sk ) : ?>
								<button type="button" class="es-design-shell<?php echo $first ? ' is-active' : ''; ?>" data-shell="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $shell_label_map[ $sk ] ?? $sk ); ?></button>
							<?php $first = false; endforeach; ?>
						</div>
						<?php echo $design_width_html; // phpcs:ignore WordPress.Security.EscapeOutput — built from esc_*() above ?>
						<?php echo $design_test_html; // phpcs:ignore WordPress.Security.EscapeOutput — built from esc_*() above ?>
						<span class="es-design-preview-spacer"></span>
						<button type="button" class="button es-design-replay" id="es-design-replay" data-es-tip="<?php esc_attr_e( 'Replay entry animations', 'emblem-studio' ); ?>"><span aria-hidden="true">↻</span> <?php esc_html_e( 'Replay', 'emblem-studio' ); ?></button>
						<?php if ( $is_new ) : ?>
							<button type="button" class="button button-primary es-design-create-edit"><?php esc_html_e( 'Open editor', 'emblem-studio' ); ?> <span aria-hidden="true">⤢</span></button>
						<?php else : ?>
							<button type="button" class="button button-primary es-inspector-open" id="es-inspector-open"><?php esc_html_e( 'Open editor', 'emblem-studio' ); ?> <span aria-hidden="true">⤢</span></button>
						<?php endif; ?>
					</div>
					<div class="es-design-preview-stage">
						<iframe name="es-inline-preview-iframe" id="es-inline-preview-iframe" class="es-design-preview-frame" src="about:blank" title="<?php esc_attr_e( 'Asset preview', 'emblem-studio' ); ?>"></iframe>
						<div class="es-design-placeholder" hidden></div>
						<div class="es-design-shell-hint" hidden></div>
						<div class="es-design-loader"><span class="es-design-spinner" aria-hidden="true"></span></div>
					</div>
				</div>
			<?php endif; ?>
		</section>

		<section id="es-tab-shells" class="es-tab-panel" data-panel="shells">
			<p class="description es-shells-intro">
				<?php esc_html_e( 'Pick where this form can be embedded. Each enabled shell gets its own shortcode and its own stats.', 'emblem-studio' ); ?>
			</p>

			<?php
			// Popup sizing options (max-width + full-screen). Shared by the click
			// popup (Lite) AND the auto shells, so it's defined here in the view —
			// before the gated auto-shell partial — not inside it.
			$popup_options_html = ( function () use ( $f ) {
				ob_start();
				?>
				<div class="es-popup-options">
					<div class="es-popup-option-label">
						<span class="es-dr-label"><?php esc_html_e( 'Popup max width', 'emblem-studio' ); ?></span>
						<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'Applies to click popup, auto popup, and slide-in. Capped at 92% of the viewport on small screens. Default: 720. Ignored when Full-screen is on.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s this?', 'emblem-studio' ); ?>">?</button>
					</div>
					<div class="es-popup-option-label">
						<span class="es-dr-label"><?php esc_html_e( 'Full-screen popup', 'emblem-studio' ); ?></span>
						<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'When on, the modal drops its max-width cap, padding, and rounded corners; your form HTML/CSS controls the full layout. The close button still overlays at the top-right.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s this?', 'emblem-studio' ); ?>">?</button>
					</div>
					<div class="es-popup-option-field">
						<input type="number" name="popup_max_width" class="es-formlevel-sync es-popup-max-width" min="100" max="2000" step="10" value="<?php echo esc_attr( (string) $f->popup_max_width ); ?>" <?php disabled( $f->popup_fullscreen ); ?>> <span class="es-popup-unit">px</span>
					</div>
					<div class="es-popup-option-field">
						<label class="es-popup-toggle-label">
							<input type="checkbox" class="es-toggle-input es-popup-fullscreen" name="popup_fullscreen" value="1" <?php checked( $f->popup_fullscreen ); ?>>
							<span><?php esc_html_e( 'Fill the viewport', 'emblem-studio' ); ?></span>
						</label>
					</div>
				</div>
				<?php
				return ob_get_clean();
			} )();

			// Auto shells (popup-auto / ribbon / slide-in) are account-gated; their
			// selector machinery (the $shell_meta + $render_shell_selector used by
			// the gated cards below) lives in a partial the Lite build omits.
			if ( ES_Caps::has( 'auto_shells' ) ) {
				include EMBLEM_STUDIO_PATH . 'admin/views/_partials/shells-auto-selector.php';
			}
			/** @var array<string,array<string,string>> $shell_meta   Defined in the partial above (auto_shells only). */
			/** @var \Closure $render_shell_selector */
			/** @var \Closure $render_shell_settings */
			?>

			<div class="es-shells-list">

				<div class="es-shells-col es-shells-col-main">
					<div class="es-shells-col-head">
						<strong><?php esc_html_e( 'Can be combined', 'emblem-studio' ); ?></strong>
						<span><?php esc_html_e( 'Pick any mix — these can all run on the same form.', 'emblem-studio' ); ?></span>
					</div>

					<div class="es-shell-card" data-shell="inline" data-enabled="<?php echo in_array( 'inline', $f->form_types, true ) ? 'true' : 'false'; ?>">
						<label class="es-shell-card-header">
							<input type="checkbox" class="es-shell-toggle" name="form_types[]" value="inline" <?php checked( in_array( 'inline', $f->form_types, true ) ); ?>>
							<div class="es-shell-card-title">
								<strong><?php esc_html_e( 'Inline', 'emblem-studio' ); ?></strong>
								<span class="es-shell-tagline"><?php esc_html_e( 'Paste anywhere in your content.', 'emblem-studio' ); ?></span>
							</div>
						</label>
						<div class="es-shell-card-body">
							<?php if ( $is_new ) : ?>
								<em class="description"><?php esc_html_e( 'Shortcode appears here after you save the form.', 'emblem-studio' ); ?></em>
							<?php else : $sc_inline = '[es-inline-' . (int) $f->id . ']'; ?>
								<code class="es-copy" data-es-copy="<?php echo esc_attr( $sc_inline ); ?>" title="<?php esc_attr_e( 'Click to copy', 'emblem-studio' ); ?>"><?php echo esc_html( $sc_inline ); ?></code>
							<?php endif; ?>
						</div>
					</div>

					<div class="es-shell-card" data-shell="popup-click" data-enabled="<?php echo in_array( 'popup-click', $f->form_types, true ) ? 'true' : 'false'; ?>">
						<label class="es-shell-card-header">
							<input type="checkbox" class="es-shell-toggle" name="form_types[]" value="popup-click" id="es-form-type-popup-click" <?php checked( in_array( 'popup-click', $f->form_types, true ) ); ?>>
							<div class="es-shell-card-title">
								<strong><?php esc_html_e( 'Click popup', 'emblem-studio' ); ?></strong>
								<span class="es-shell-tagline"><?php esc_html_e( 'Wrap a button, link, or text; clicking opens the form in a modal.', 'emblem-studio' ); ?></span>
							</div>
						</label>
						<div class="es-shell-card-body">
							<?php if ( $is_new ) : ?>
								<em class="description"><?php esc_html_e( 'Shortcode appears here after you save the form.', 'emblem-studio' ); ?></em>
							<?php else : $sc_popup = '[es-popup-' . (int) $f->id . ']Click here[/es-popup-' . (int) $f->id . ']'; ?>
								<code class="es-copy" data-es-copy="<?php echo esc_attr( $sc_popup ); ?>" title="<?php esc_attr_e( 'Click to copy', 'emblem-studio' ); ?>"><?php echo esc_html( $sc_popup ); ?></code>
							<?php endif; ?>
							<?php // Click popup's own modal options live right here in the card
							// (it has no triggers / page rules). Form-level fields, kept in
							// sync with the auto popup's copies by admin.js. ?>
							<div class="es-shell-card-options">
								<?php echo $popup_options_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<div class="es-close-theme-row es-close-theme-row--bare">
									<label class="es-dr-label" for="es-close-theme-popup-click" style="margin-bottom:4px;">
										<?php esc_html_e( 'Close button style', 'emblem-studio' ); ?>
										<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'Pick the close-button color that contrasts with your form\'s background. Light = white-on-dark glass (default, for dark forms). Dark = dark-on-light glass (for light forms). Applies to every close button this form renders.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s this?', 'emblem-studio' ); ?>">?</button>
									</label>
									<select id="es-close-theme-popup-click" name="popup_close_theme" class="es-formlevel-sync es-close-theme-select">
										<option value="light" <?php selected( $f->popup_close_theme ?? 'light', 'light' ); ?>><?php esc_html_e( 'Light (for dark form backgrounds)', 'emblem-studio' ); ?></option>
										<option value="dark"  <?php selected( $f->popup_close_theme ?? 'light', 'dark'  ); ?>><?php esc_html_e( 'Dark (for light form backgrounds)', 'emblem-studio' ); ?></option>
									</select>
								</div>
							</div>
						</div>
					</div>

					<?php if ( ES_Caps::has( 'auto_shells' ) ) { $render_shell_selector( 'popup-auto', $shell_meta['popup-auto'] ); } ?>
				</div>

				<?php if ( ES_Caps::has( 'auto_shells' ) ) : ?>
				<div class="es-shells-col es-shells-col-side">
					<div class="es-shells-col-head">
						<strong><?php esc_html_e( 'Pick one', 'emblem-studio' ); ?></strong>
						<span><?php esc_html_e( 'Choosing one of these turns every other shell off.', 'emblem-studio' ); ?></span>
					</div>

					<?php
					$render_shell_selector( 'ribbon', $shell_meta['ribbon'] );
					$render_shell_selector( 'slide-in', $shell_meta['slide-in'] );
					?>
				</div>
				<?php else : ?>
				<div class="es-shells-col es-shells-col-side">
					<div class="es-lite-upsell">
						<span class="es-lite-upsell-eyebrow"><?php esc_html_e( 'Free with an account', 'emblem-studio' ); ?></span>
						<strong><?php esc_html_e( 'Auto popup, ribbon &amp; slide-in', 'emblem-studio' ); ?></strong>
						<p><?php esc_html_e( 'Show a form automatically — on a delay, on scroll, or on exit-intent — as a pop-up, a sticky ribbon, or a slide-in. Unlocks free when you connect an Emblem Studio account.', 'emblem-studio' ); ?></p>
					</div>
				</div>
				<?php endif; ?>

			</div>

			<?php if ( ES_Caps::has( 'auto_shells' ) ) : ?>
			<?php // Shared settings container — appears only when Auto popup,
			// Ribbon, or Slide-in is selected (toggled by initShellSettings
			// container in admin.js). Inside, each shell's panel shows itself
			// when its own checkbox is on. (Auto-shell-only — gated.) ?>
			<div class="es-shell-settings-wrap" id="es-shell-settings-wrap">
				<?php
				foreach ( $shell_meta as $shell => $meta ) {
					$render_shell_settings( $shell, $meta );
				}
				?>
			</div>
			<?php endif; ?>

			<?php // Connector drawn by initShellConnector() — visually links the
			// selected shell card to its display-rules container below. ?>
			<svg class="es-shell-connector" id="es-shell-connector" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
				<path class="es-connector-path" fill="none"></path>
				<circle class="es-connector-dot" r="4.5"></circle>
				<polygon class="es-connector-arrow"></polygon>
			</svg>
		</section>

	<section id="es-tab-connect" class="es-tab-panel" data-panel="connect">
		<p class="description" style="max-width:680px;"><?php esc_html_e( 'Pick which destinations receive this form\'s leads. Each destination has its own active toggle — enable any combination. When FunnelKit is active, it pushes first and its contact ID is included in any webhook payload that fires after.', 'emblem-studio' ); ?></p>

		<?php $fk_enabled = ! empty( $f->funnelkit_enabled ); ?>
		<div class="es-connect-card <?php echo $fk_enabled ? '' : 'is-destination-off'; ?>">
			<header class="es-connect-card-head">
				<label class="es-connect-card-active">
					<input type="checkbox" class="es-toggle-input es-destination-toggle" name="funnelkit_enabled" value="1" <?php checked( $fk_enabled ); ?>>
					<span class="screen-reader-text"><?php esc_html_e( 'FunnelKit Automations active', 'emblem-studio' ); ?></span>
				</label>
				<div class="es-connect-card-titles">
					<h2 class="es-connect-card-title"><?php esc_html_e( 'FunnelKit Automations', 'emblem-studio' ); ?></h2>
					<span class="es-connect-card-tag"><?php esc_html_e( 'Direct PHP integration', 'emblem-studio' ); ?></span>
				</div>
			</header>
			<div class="es-connect-card-body">
		<?php
		$fk_lists = ES_FunnelKit::get_lists();
		$fk_tags  = ES_FunnelKit::get_tags();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="es-fk-lists"><?php esc_html_e( 'Add to lists', 'emblem-studio' ); ?></label></th>
				<td>
					<?php if ( empty( $fk_lists ) ) : ?>
						<p><em><?php esc_html_e( 'No FunnelKit lists detected. Create some in FunnelKit → Contacts → Lists.', 'emblem-studio' ); ?></em></p>
					<?php else : ?>
						<div class="es-checkbox-group" id="es-fk-lists">
							<?php foreach ( $fk_lists as $l ) :
								$lid = (int) $l['id'];
								$lch = in_array( $lid, (array) $f->funnelkit_lists, true );
							?>
								<label class="es-checkbox-pill<?php echo $lch ? ' is-checked' : ''; ?>">
									<input type="checkbox" name="funnelkit_lists[]" value="<?php echo $lid; ?>" <?php checked( $lch ); ?>>
									<span><?php echo esc_html( $l['name'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Tick every list this form should add contacts to. Leave all unchecked to create the contact without joining a list.', 'emblem-studio' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="es-fk-tags-typer"><?php esc_html_e( 'Add tags', 'emblem-studio' ); ?></label></th>
				<td>
					<div class="es-tag-input"
						data-suggestions="<?php echo esc_attr( wp_json_encode( array_column( $fk_tags, 'name' ) ) ); ?>"
						data-value="<?php echo esc_attr( wp_json_encode( array_values( (array) $f->funnelkit_tags ) ) ); ?>">
						<div class="es-tag-chips"></div>
						<input id="es-fk-tags-typer" type="text" class="es-tag-typer"
							placeholder="<?php esc_attr_e( 'Type a tag…', 'emblem-studio' ); ?>"
							autocomplete="off">
						<div class="es-tag-suggestions" style="display:none;"></div>
						<input type="hidden" name="funnelkit_tags_csv" value="<?php echo esc_attr( implode( ',', (array) $f->funnelkit_tags ) ); ?>">
					</div>
					<p class="description">
						<?php esc_html_e( 'Type to search existing tags. Enter, comma, or Tab adds. Backspace on empty removes. New tag names are auto-created in FunnelKit on first submission. Tags are what FunnelKit automations listen for as triggers.', 'emblem-studio' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2>
			<?php esc_html_e( 'Field mapping', 'emblem-studio' ); ?>
			<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'Click a form input on the right to select it, then click an empty FunnelKit slot on the left to assign it. Click ✕ to unmap. Auto-detection by name still applies when no mapping is set.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s this?', 'emblem-studio' ); ?>">?</button>
		</h2>
		<?php
		$fk_grouped     = ES_FunnelKit::get_all_fields_grouped();
		$initial_fields = ES_Form_Parser::extract_fields( (string) $f->html );
		?>
		<div class="es-mapper"
			data-fk-groups="<?php echo esc_attr( wp_json_encode( $fk_grouped ) ); ?>"
			data-initial-fields="<?php echo esc_attr( wp_json_encode( $initial_fields ) ); ?>"
			data-mapping="<?php echo esc_attr( wp_json_encode( (object) $f->field_mapping ) ); ?>"
			data-html-source="#es-html">
			<div class="es-mapper-cols">
				<div class="es-mapper-fk"></div>
				<div class="es-mapper-form"></div>
			</div>
			<input type="hidden" name="field_mapping_json" value="<?php echo esc_attr( wp_json_encode( (object) $f->field_mapping ) ); ?>">
			</div>
			</div><!-- /.es-connect-card-body -->

		</div><!-- /.es-connect-card (FunnelKit) -->

		<?php if ( ES_Caps::has( 'webhooks' ) ) : ?>
		<?php
		$webhook_rows = ES_Webhooks::for_form( $f );
		$test_nonce   = wp_create_nonce( 'es_test_webhook' );
		?>
		<div class="es-connect-card">
			<header class="es-connect-card-head">
				<div class="es-connect-card-titles">
					<h2 class="es-connect-card-title"><?php esc_html_e( 'Webhooks', 'emblem-studio' ); ?></h2>
					<span class="es-connect-card-tag"><?php esc_html_e( 'POST submission to any URL', 'emblem-studio' ); ?></span>
				</div>
			</header>
			<p class="description" style="margin-top:0;"><?php esc_html_e( 'Add as many destinations as you need. Each fires independently after the lead is saved. Use HTTPS endpoints. Set a secret to enable HMAC-SHA256 signature verification (X-ES-Signature header).', 'emblem-studio' ); ?></p>

			<div class="es-webhook-list" data-test-nonce="<?php echo esc_attr( $test_nonce ); ?>">
				<?php foreach ( $webhook_rows as $i => $row ) : ?>
					<div class="es-webhook-row">
						<div class="es-webhook-row-head">
							<label class="es-webhook-active">
								<input type="checkbox" class="es-toggle-input" name="webhooks[<?php echo (int) $i; ?>][active]" value="1" <?php checked( ! empty( $row['active'] ) ); ?>>
								<span><?php esc_html_e( 'Active', 'emblem-studio' ); ?></span>
							</label>
							<button type="button" class="button button-link-delete es-webhook-remove" title="<?php esc_attr_e( 'Remove this webhook', 'emblem-studio' ); ?>">×</button>
						</div>
						<div class="es-webhook-grid">
							<div class="es-webhook-field es-webhook-field-url">
								<label><?php esc_html_e( 'URL', 'emblem-studio' ); ?></label>
								<input type="url" name="webhooks[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $row['url'] ); ?>" placeholder="https://hooks.example.com/abc">
							</div>
							<div class="es-webhook-field es-webhook-field-secret">
								<label><?php esc_html_e( 'Signing secret', 'emblem-studio' ); ?> <span class="es-webhook-opt">(<?php esc_html_e( 'optional', 'emblem-studio' ); ?>)</span></label>
								<input type="text" name="webhooks[<?php echo (int) $i; ?>][secret]" value="<?php echo esc_attr( $row['secret'] ); ?>" placeholder="<?php esc_attr_e( 'any string; leave blank to skip HMAC', 'emblem-studio' ); ?>">
							</div>
						</div>
						<div class="es-webhook-field">
							<label><?php esc_html_e( 'Custom headers', 'emblem-studio' ); ?> <span class="es-webhook-opt">(<?php esc_html_e( 'one per line, "Key: value"', 'emblem-studio' ); ?>)</span></label>
							<textarea name="webhooks[<?php echo (int) $i; ?>][headers]" rows="2" placeholder="Authorization: Bearer xxxxx&#10;X-Custom-Header: foo"><?php echo esc_textarea( $row['headers'] ); ?></textarea>
						</div>
						<div class="es-webhook-actions">
							<button type="button" class="button es-webhook-test"><?php esc_html_e( 'Send test', 'emblem-studio' ); ?></button>
							<span class="es-webhook-test-result"></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<button type="button" class="button es-webhook-add">+ <?php esc_html_e( 'Add webhook', 'emblem-studio' ); ?></button>

			<!-- Hidden template the JS clones when "Add webhook" is clicked.
			     __INDEX__ is replaced with the next available index so the
			     server reads multiple rows from $_POST['webhooks']. -->
			<template class="es-webhook-template">
				<div class="es-webhook-row">
					<div class="es-webhook-row-head">
						<label class="es-webhook-active">
							<input type="checkbox" class="es-toggle-input" name="webhooks[__INDEX__][active]" value="1" checked>
							<span><?php esc_html_e( 'Active', 'emblem-studio' ); ?></span>
						</label>
						<button type="button" class="button button-link-delete es-webhook-remove" title="<?php esc_attr_e( 'Remove this webhook', 'emblem-studio' ); ?>">×</button>
					</div>
					<div class="es-webhook-grid">
						<div class="es-webhook-field es-webhook-field-url">
							<label><?php esc_html_e( 'URL', 'emblem-studio' ); ?></label>
							<input type="url" name="webhooks[__INDEX__][url]" placeholder="https://hooks.example.com/abc">
						</div>
						<div class="es-webhook-field es-webhook-field-secret">
							<label><?php esc_html_e( 'Signing secret', 'emblem-studio' ); ?> <span class="es-webhook-opt">(<?php esc_html_e( 'optional', 'emblem-studio' ); ?>)</span></label>
							<input type="text" name="webhooks[__INDEX__][secret]" placeholder="<?php esc_attr_e( 'any string; leave blank to skip HMAC', 'emblem-studio' ); ?>">
						</div>
					</div>
					<div class="es-webhook-field">
						<label><?php esc_html_e( 'Custom headers', 'emblem-studio' ); ?> <span class="es-webhook-opt">(<?php esc_html_e( 'one per line, "Key: value"', 'emblem-studio' ); ?>)</span></label>
						<textarea name="webhooks[__INDEX__][headers]" rows="2" placeholder="Authorization: Bearer xxxxx&#10;X-Custom-Header: foo"></textarea>
					</div>
					<div class="es-webhook-actions">
						<button type="button" class="button es-webhook-test"><?php esc_html_e( 'Send test', 'emblem-studio' ); ?></button>
						<span class="es-webhook-test-result"></span>
					</div>
				</div>
			</template>
		</div><!-- /.es-connect-card (Webhooks) -->
		<?php endif; ?>
		</section>

		<?php if ( ES_Caps::has( 'linked_forms' ) ) : ?>
		<section id="es-tab-linked" class="es-tab-panel" data-panel="linked">
			<?php
			$conn_cfg    = ES_Connections::for_form( $f );
			$conn_supp   = array_map( 'intval', (array) $conn_cfg['suppress'] );
			$other_forms = array_filter(
				ES_Form_Repo::all( 'all' ),
				function ( $o ) use ( $f ) {
					return (int) $o->id !== (int) ( $f->id ?? 0 );
				}
			);
			?>
			<div class="es-connect-card">
				<h3 class="es-connect-card-title"><?php esc_html_e( 'Linked forms', 'emblem-studio' ); ?></h3>
				<p class="description" style="margin:0 0 14px;">
					<?php esc_html_e( 'Connect this form to others so that once a visitor subscribes here, the connected forms stop appearing — or show a different message instead. Works for anonymous visitors via a cookie, and for logged-in users via their saved lead.', 'emblem-studio' ); ?>
				</p>

				<?php if ( empty( $other_forms ) ) : ?>
					<p><em><?php esc_html_e( 'No other forms yet. Create another form and it will appear here to connect.', 'emblem-studio' ); ?></em></p>
				<?php else : ?>
					<table class="form-table"><tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'When this form is submitted, also silence:', 'emblem-studio' ); ?></th>
							<td>
								<?php foreach ( $other_forms as $o ) : $oid = (int) $o->id; ?>
									<label class="es-checkbox-pill<?php echo in_array( $oid, $conn_supp, true ) ? ' is-checked' : ''; ?>" style="display:block; margin-bottom:6px;">
										<input type="checkbox" name="connections[suppress][]" value="<?php echo $oid; ?>" <?php checked( in_array( $oid, $conn_supp, true ) ); ?>>
										<?php echo esc_html( $o->name ); ?> <span class="es-webhook-opt">#<?php echo $oid; ?></span>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Make it mutual', 'emblem-studio' ); ?></th>
							<td>
								<label style="display:inline-flex; align-items:center; gap:.5rem;">
									<input type="checkbox" name="connections[mutual]" value="1" <?php checked( ! empty( $conn_cfg['mutual'] ) ); ?>>
									<?php esc_html_e( 'The selected forms also silence THIS one — a symmetric group where completing any one silences the rest.', 'emblem-studio' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'When already subscribed, this form', 'emblem-studio' ); ?></th>
							<td>
								<label style="display:block; margin-bottom:4px;"><input type="radio" name="connections[behavior]" value="hide" <?php checked( $conn_cfg['behavior'], 'hide' ); ?>> <?php esc_html_e( 'Hides — doesn\'t render at all', 'emblem-studio' ); ?></label>
								<label style="display:block;"><input type="radio" name="connections[behavior]" value="replace" <?php checked( $conn_cfg['behavior'], 'replace' ); ?>> <?php esc_html_e( 'Shows its “already subscribed” block instead', 'emblem-studio' ); ?></label>
								<p class="description" style="margin:8px 0 0;">
									<?php
									printf(
										/* translators: %s: the data attribute name, shown as code */
										esc_html__( 'For “shows instead”, add an element with the %s attribute to this form\'s HTML — it stays hidden normally and is revealed when the visitor is already subscribed.', 'emblem-studio' ),
										'<code>data-esf-subscribed</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="es-conn-days"><?php esc_html_e( 'Remember for', 'emblem-studio' ); ?></label></th>
							<td>
								<input type="number" id="es-conn-days" name="connections[duration_days]" min="0" max="3650" value="<?php echo (int) $conn_cfg['duration_days']; ?>" class="small-text">
								<?php esc_html_e( 'days', 'emblem-studio' ); ?>
								<span class="es-webhook-opt">(<?php esc_html_e( '0 = forever', 'emblem-studio' ); ?>)</span>
							</td>
						</tr>
					</tbody></table>
				<?php endif; ?>
			</div>
		</section>
		<?php endif; ?>

		<section id="es-tab-stats" class="es-tab-panel" data-panel="stats">
			<?php if ( $is_new ) : ?>
				<div class="es-tab-empty">
					<p><em><?php esc_html_e( 'Save the form first, then come back here.', 'emblem-studio' ); ?></em></p>
				</div>
			<?php else :
				$stats_enabled_types = $f->form_types;
				$stats_data_types    = ES_Reports::shells_with_data( (int) $f->id );
				$stats_render_types  = array_values( array_unique( array_merge( $stats_enabled_types, $stats_data_types ) ) );
				usort( $stats_render_types, function ( $a, $b ) {
					$order = [ 'inline' => 0, 'popup-click' => 1, 'popup-auto' => 2, 'ribbon' => 3, 'slide-in' => 4 ];
					return ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 );
				} );
				$stats_shell_labels = [
					'inline'      => __( 'Inline', 'emblem-studio' ),
					'popup-click' => __( 'Click popup', 'emblem-studio' ),
					'popup-auto'  => __( 'Auto popup', 'emblem-studio' ),
					'ribbon'      => __( 'Ribbon', 'emblem-studio' ),
					'slide-in'    => __( 'Slide-in', 'emblem-studio' ),
				];
			?>
				<?php if ( $ab_running ) :
					$ab_totals      = ES_AB::variant_stats_for_form( (int) $f->id );
					$ab_leader      = ES_AB::leader_for_form( (int) $f->id );
					$ab_primary     = ! empty( $ab_test->primary_metric ) ? (string) $ab_test->primary_metric : 'conversions';
					$ab_label_count = $ab_primary === 'clicks' ? __( 'Clicks', 'emblem-studio' ) : __( 'Conv', 'emblem-studio' );
					$end_url = function ( string $winner ) use ( $f ) {
						$path = 'admin-post.php?action=es_end_test&form_id=' . (int) $f->id;
						if ( $winner !== '' ) { $path .= '&winner=' . rawurlencode( $winner ); }
						return wp_nonce_url( admin_url( $path ), 'es_end_test_' . (int) $f->id );
					};
				?>
					<div class="es-ab-banner">
						<div class="es-ab-banner-head">
							<span class="es-test-pill">
								<span class="es-test-pill-dot" aria-hidden="true"></span>
								<?php /* translators: %d: day number */
								printf( esc_html__( 'TEST · day %d', 'emblem-studio' ), (int) $ab_day ); ?>
							</span>
							<h3 class="es-ab-banner-title"><?php esc_html_e( 'A/B test running', 'emblem-studio' ); ?></h3>
						</div>
						<div class="es-ab-banner-variants">
							<?php foreach ( [ 'A', 'B' ] as $slot ) :
								$kv = $ab_totals[ $slot ];
								$is_leader = $ab_leader && $ab_leader['slot'] === $slot;
							?>
								<div class="es-ab-variant-card<?php echo $is_leader ? ' is-leader' : ''; ?>">
									<div class="es-ab-variant-card-head">
										<span class="es-ab-variant-letter"><?php echo esc_html( $slot ); ?></span>
										<?php if ( $is_leader ) : ?>
											<span class="es-ab-leader-tag">
												<?php /* translators: %s: lift percent */
												printf( esc_html__( 'Leader · +%s%%', 'emblem-studio' ), esc_html( (string) $ab_leader['lift'] ) ); ?>
											</span>
										<?php endif; ?>
									</div>
									<div class="es-ab-variant-card-stats">
										<div class="es-ab-stat">
											<span class="es-ab-stat-label"><?php echo esc_html( $ab_label_count ); ?></span>
											<span class="es-ab-stat-value"><?php echo (int) ES_AB::effective_successes( (array) $kv, $ab_primary ); ?></span>
										</div>
										<div class="es-ab-stat">
											<span class="es-ab-stat-label"><?php esc_html_e( 'Views', 'emblem-studio' ); ?></span>
											<span class="es-ab-stat-value"><?php echo (int) $kv['views']; ?></span>
										</div>
										<div class="es-ab-stat">
											<span class="es-ab-stat-label"><?php esc_html_e( 'Rate', 'emblem-studio' ); ?></span>
											<span class="es-ab-stat-value"><?php echo esc_html( number_format_i18n( ES_AB::effective_rate( (array) $kv, $ab_primary ), 1 ) ); ?>%</span>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="es-ab-banner-actions">
							<a class="button button-primary es-ab-declare-winner" data-winner="A" href="<?php echo esc_url( $end_url( 'A' ) ); ?>">
								<?php esc_html_e( 'Declare A winner', 'emblem-studio' ); ?>
							</a>
							<a class="button button-primary es-ab-declare-winner" data-winner="B" href="<?php echo esc_url( $end_url( 'B' ) ); ?>">
								<?php esc_html_e( 'Declare B winner', 'emblem-studio' ); ?>
							</a>
							<a class="button button-link-delete es-ab-declare-winner" data-winner="" href="<?php echo esc_url( $end_url( '' ) ); ?>">
								<?php esc_html_e( 'End without a winner', 'emblem-studio' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( empty( $stats_render_types ) ) : ?>
					<div class="es-tab-empty">
						<p><?php esc_html_e( 'No shells enabled yet and no historical data. Enable a shell on the Shells tab to start collecting stats.', 'emblem-studio' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					// Form-vs-asset detection for the conv columns; click tracking
					// for the click columns — matches the Forms & Assets list logic
					// so per-shell totals here read the same way as the list page.
					$has_form       = ES_Form_Repo::primary_metric( (string) $f->html ) === 'conversions';
					$clicks_tracked = ! empty( $f->track_clicks );
					$vis            = ES_Reports::asset_visitors( (int) $f->id );
					?>
					<h3 class="es-stats-section-heading"><?php esc_html_e( 'Per shell', 'emblem-studio' ); ?></h3>
						<?php if ( $ab_running ) : ?>
						<p style="margin:0 0 8px; font-size:12px; color:#0369a1;"><?php printf( esc_html__( 'An A/B test is running across the %s — the A+B split appears under each shell that has traffic.', 'emblem-studio' ), '<strong>' . esc_html__( 'whole asset', 'emblem-studio' ) . '</strong>' ); ?></p>
						<?php endif; ?>
						<p style="margin:0 0 10px; font-size:12px; color:#6c7180;"><?php esc_html_e( 'Visitors (whole asset):', 'emblem-studio' ); ?>
							<strong style="color:#353a52;"><?php echo esc_html( number_format_i18n( (int) $vis['unique_visitors'] ) ); ?></strong> <?php esc_html_e( 'unique', 'emblem-studio' ); ?> ·
							<strong style="color:#353a52;"><?php echo esc_html( number_format_i18n( (int) $vis['new_visitors'] ) ); ?></strong> <?php esc_html_e( 'new', 'emblem-studio' ); ?> ·
							<strong style="color:#353a52;"><?php echo esc_html( number_format_i18n( (int) $vis['returning_visitors'] ) ); ?></strong> <?php esc_html_e( 'returning', 'emblem-studio' ); ?> ·
							<strong style="color:#353a52;"><?php echo esc_html( number_format_i18n( (int) $vis['unknown_views'] ) ); ?></strong> <?php esc_html_e( 'unknown', 'emblem-studio' ); ?>
							<span style="color:#9aa0b0;">· <?php esc_html_e( 'anonymous, no IP · builds up from now · ~7-day estimate', 'emblem-studio' ); ?></span></p>
					<table class="es-stats-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Shell', 'emblem-studio' ); ?></th>
								<th class="num"><?php esc_html_e( 'Page views', 'emblem-studio' ); ?></th>
								<th class="num"><?php esc_html_e( 'Trigger views', 'emblem-studio' ); ?></th>
								<th class="num"><?php esc_html_e( 'Asset views', 'emblem-studio' ); ?></th>
								<th class="num" title="<?php esc_attr_e( 'Distinct people who saw it (anonymous tag, no IP). Builds up from now on; ~7-day estimate.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Unique', 'emblem-studio' ); ?></th>
								<th class="num es-col-conv"><?php esc_html_e( 'Form conv.', 'emblem-studio' ); ?></th>
								<th class="num es-col-conv"><?php esc_html_e( 'Conv. rate', 'emblem-studio' ); ?></th>
								<th class="num es-col-conv" title="<?php esc_attr_e( 'Visitors who started filling in the form (typed or picked an option) but never submitted. Count, then the share of engaged visitors who dropped off.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Drop-offs', 'emblem-studio' ); ?></th>
								<th class="num es-col-conv" title="<?php esc_attr_e( 'Drop-offs vs asset views / vs unique visitors', 'emblem-studio' ); ?>"><?php esc_html_e( 'Drop-off rate', 'emblem-studio' ); ?></th>
								<th class="num es-col-click"><?php esc_html_e( 'Clicks', 'emblem-studio' ); ?></th>
								<th class="num es-col-click"><?php esc_html_e( 'Click rate', 'emblem-studio' ); ?></th>
								<th class="num" title="<?php esc_attr_e( 'Bot user-agents dropped from view counts.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Bot Views', 'emblem-studio' ); ?></th>
								<th class="num" title="<?php esc_attr_e( 'Submissions caught by the hidden honeypot field. Not sent to FunnelKit / webhooks.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Blocked Subs.', 'emblem-studio' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stats_render_types as $shell ) :
								$k = ES_Reports::all_time( (int) $f->id, $shell );
							?>
								<tr>
									<td><span class="es-shell-pill es-shell-<?php echo esc_attr( $shell ); ?>"><?php echo esc_html( $stats_shell_labels[ $shell ] ?? $shell ); ?></span></td>
									<td class="num"><?php echo (int) $k['page_views']; ?></td>
									<td class="num"><?php echo $shell === 'popup-click' ? (int) $k['trigger_views'] : '<span class="dim">—</span>'; ?></td>
									<td class="num"><?php echo (int) $k['views']; ?></td>
									<td class="num"><?php echo (int) ( $k['unique_visitors'] ?? 0 ); ?></td>
									<td class="num es-col-conv"><?php echo $has_form ? (int) $k['conversions'] : '<span class="dim">—</span>'; ?></td>
									<td class="num es-col-conv"><?php echo $has_form ? ES_Reports::rate_pair_html( (float) $k['conv_rate'], (float) $k['conv_rate_unique'], __( 'Conversion rate vs asset views — of the times shown, the share that converted', 'emblem-studio' ), __( 'Conversion rate vs unique visitors — the share of people who converted', 'emblem-studio' ) ) : '<span class="dim">—</span>'; ?></td>
									<td class="num es-col-conv"><?php echo $has_form ? (int) $k['dropoffs'] : '<span class="dim">—</span>'; ?></td>
									<td class="num es-col-conv"><?php echo $has_form ? ES_Reports::rate_pair_html( (float) $k['dropoff_rate_views'], (float) $k['dropoff_rate_unique'], __( 'Drop-off rate vs asset views — of the times shown, the share that started then left', 'emblem-studio' ), __( 'Drop-off rate vs unique visitors — the share of people who started then left', 'emblem-studio' ) ) : '<span class="dim">—</span>'; ?></td>
									<td class="num es-col-click"><?php echo $clicks_tracked ? (int) $k['meaningful_clicks'] : '<span class="dim">—</span>'; ?></td>
									<td class="num es-col-click"><?php echo $clicks_tracked ? ES_Reports::rate_pair_html( (float) $k['click_rate'], (float) $k['click_rate_unique'], __( 'Click rate vs asset views — engaged clicks per time shown', 'emblem-studio' ), __( 'Click rate vs unique visitors — engaged clicks per person (can exceed 100%)', 'emblem-studio' ) ) : '<span class="dim">—</span>'; ?></td>
									<td class="num"><?php
										$bf_n = (int) ( $k['bot_filtered'] ?? 0 );
										if ( $bf_n > 0 ) {
											$bot_url = add_query_arg(
												[ 'page' => 'emblem-studio-reports', 'form_id' => (int) $f->id, 'range' => 'all' ],
												admin_url( 'admin.php' )
											) . '#bots';
											printf(
												'<a href="%s" title="%s">%s</a>',
												esc_url( $bot_url ),
												esc_attr__( 'See which bots — opens the breakdown filtered to this asset', 'emblem-studio' ),
												esc_html( number_format_i18n( $bf_n ) )
											);
										} else {
											echo '<span class="dim">—</span>';
										}
									?></td>
									<td class="num"><?php
										if ( ! $has_form ) {
											echo '<span class="dim">—</span>';
										} else {
											$hp_n = (int) ( $k['honeypot_blocked'] ?? 0 );
											echo $hp_n > 0 ? esc_html( number_format_i18n( $hp_n ) ) : '<span class="dim">—</span>';
										}
									?></td>
								</tr>
								<?php if ( $ab_running ) :
									foreach ( [ 'A', 'B' ] as $slot ) :
										$kv = ES_Reports::all_time( (int) $f->id, $shell, $slot );
								?>
									<tr class="es-stats-variant-row">
										<td class="es-stats-variant-label">
											<span class="es-stats-variant-arrow" aria-hidden="true">↪</span>
											<span class="es-ab-variant-letter es-ab-variant-letter--inline"><?php echo esc_html( $slot ); ?></span>
										</td>
										<td class="num"><?php echo (int) $kv['page_views']; ?></td>
										<td class="num"><?php echo $shell === 'popup-click' ? (int) $kv['trigger_views'] : '<span class="dim">—</span>'; ?></td>
										<td class="num"><?php echo (int) $kv['views']; ?></td>
										<td class="num"><?php echo (int) ( $kv['unique_visitors'] ?? 0 ); ?></td>
										<td class="num es-col-conv"><?php echo $has_form ? (int) $kv['conversions'] : '<span class="dim">—</span>'; ?></td>
										<td class="num es-col-conv"><?php echo $has_form ? ES_Reports::rate_pair_html( (float) $kv['conv_rate'], (float) $kv['conv_rate_unique'], __( 'Conversion rate vs asset views — of the times shown, the share that converted', 'emblem-studio' ), __( 'Conversion rate vs unique visitors — the share of people who converted', 'emblem-studio' ) ) : '<span class="dim">—</span>'; ?></td>
										<td class="num es-col-conv"><?php echo $has_form ? (int) $kv['dropoffs'] : '<span class="dim">—</span>'; ?></td>
										<td class="num es-col-conv"><?php echo $has_form ? ES_Reports::rate_pair_html( (float) $kv['dropoff_rate_views'], (float) $kv['dropoff_rate_unique'], __( 'Drop-off rate vs asset views — of the times shown, the share that started then left', 'emblem-studio' ), __( 'Drop-off rate vs unique visitors — the share of people who started then left', 'emblem-studio' ) ) : '<span class="dim">—</span>'; ?></td>
										<td class="num es-col-click"><?php echo $clicks_tracked ? (int) $kv['meaningful_clicks'] : '<span class="dim">—</span>'; ?></td>
										<td class="num es-col-click"><?php echo $clicks_tracked ? ES_Reports::rate_pair_html( (float) $kv['click_rate'], (float) $kv['click_rate_unique'], __( 'Click rate vs asset views — engaged clicks per time shown', 'emblem-studio' ), __( 'Click rate vs unique visitors — engaged clicks per person (can exceed 100%)', 'emblem-studio' ) ) : '<span class="dim">—</span>'; ?></td>
										<td class="num"><span class="dim">—</span></td>
										<td class="num"><span class="dim">—</span></td>
									</tr>
								<?php endforeach; endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-reports&form_id=' . (int) $f->id ) ); ?>">
							<?php esc_html_e( 'Time series + filters in Reports', 'emblem-studio' ); ?> <span aria-hidden="true">→</span>
						</a>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-reports&form_id=' . (int) $f->id . '&range=all' ) . '#bots' ); ?>">
							<?php esc_html_e( 'Bot traffic breakdown', 'emblem-studio' ); ?> <span aria-hidden="true">→</span>
						</a>
					</p>
				<?php endif; ?>

				<?php
				$clicks = ES_Reports::clicks_for_form( (int) $f->id );
				// Summary tallies — only over real targets, excluding the sentinel
				// rows for close/backdrop/untagged so authors see the signal that
				// reflects their own work.
				$total_clicks   = 0;
				$tagged_count   = 0;
				$untagged_count = 0;
				foreach ( $clicks as $r ) {
					$total_clicks += (int) $r['clicks'];
					$t = $r['target'];
					if ( $t === '__close__' || $t === '__backdrop__' ) {
						continue;
					}
					if ( $t === '__untagged__' ) {
						$untagged_count += (int) $r['clicks'];
					} else {
						$tagged_count += (int) $r['clicks'];
					}
				}
				$named_total = $tagged_count + $untagged_count;
				$untag_pct   = $named_total > 0 ? round( $untagged_count / $named_total * 100 ) : 0;
				?>
				<h3 class="es-stats-section-heading"><?php esc_html_e( 'Clicks', 'emblem-studio' ); ?></h3>
				<div class="es-clicks-toggle-row">
					<label class="es-clicks-toggle">
						<input type="checkbox" class="es-toggle-input" name="track_clicks" value="1" <?php checked( $f->track_clicks ); ?>>
						<?php esc_html_e( 'Track clicks on this form', 'emblem-studio' ); ?>
					</label>
					<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'Click tracking is for non-form assets — links and buttons that aren\'t part of a form. Those (plus the shell close button) are tracked automatically. The form itself — its submit button, fields and labels — is not tracked, because submitting and filling it in are already measured as conversions and engagement. To track a specific in-form element anyway, add a data-es-track=&quot;my-label&quot; attribute to it: that opts it in and names it in the table below. On auto-tracked elements the attribute just sets the name; without it, the visible text or element id is used.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s this?', 'emblem-studio' ); ?>">?</button>
				</div>
				<?php if ( empty( $f->track_clicks ) ) : ?>
					<p><em><?php esc_html_e( 'Click tracking is off for this form. Turn it on above to start collecting.', 'emblem-studio' ); ?></em></p>
				<?php elseif ( empty( $clicks ) ) : ?>
					<p><em><?php esc_html_e( 'No clicks recorded yet.', 'emblem-studio' ); ?></em></p>
				<?php else : ?>
					<p class="es-clicks-summary">
						<strong><?php echo esc_html( number_format_i18n( $total_clicks ) ); ?></strong>
						<?php echo esc_html( _n( 'click', 'clicks', $total_clicks, 'emblem-studio' ) ); ?>
						<span class="es-clicks-summary-sep">·</span>
						<strong><?php echo esc_html( number_format_i18n( $tagged_count ) ); ?></strong>
						<?php esc_html_e( 'on tagged elements', 'emblem-studio' ); ?>
						<?php if ( $untagged_count > 0 ) : ?>
							<span class="es-clicks-summary-sep">·</span>
							<strong><?php echo esc_html( (string) $untag_pct ); ?>%</strong>
							<?php esc_html_e( 'untagged', 'emblem-studio' ); ?>
							<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'Clicks on elements that don\'t have a data-es-track attribute and that the auto-labeller couldn\'t name from visible text or id. Add data-es-track=&quot;my-label&quot; to those elements to bring them out of the untagged bucket.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s untagged?', 'emblem-studio' ); ?>">?</button>
						<?php endif; ?>
					</p>
					<table class="es-stats-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Element', 'emblem-studio' ); ?></th>
								<th><?php esc_html_e( 'Shell', 'emblem-studio' ); ?></th>
								<th class="num"><?php esc_html_e( 'Clicks', 'emblem-studio' ); ?></th>
								<th><?php esc_html_e( 'Last seen', 'emblem-studio' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $clicks as $row ) :
								$target      = $row['target'];
								$is_close    = $target === '__close__';
								$is_back     = $target === '__backdrop__';
								$is_untag    = $target === '__untagged__';
								// Close + backdrop are dismissals: they're listed here but
								// kept OUT of the Clicks numbers on the Stats tab + Forms list.
								$is_excluded = $is_close || $is_back;
								$classes     = [];
								if ( $is_untag )    { $classes[] = 'es-click-row-untagged'; }
								if ( $is_excluded ) { $classes[] = 'es-click-row-excluded'; }
								$row_class   = $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';
								if ( $is_close ) {
									$label = '<span class="dashicons dashicons-no-alt" style="font-size:1em; width:1em; height:1em;"></span> ' . esc_html__( 'Close button', 'emblem-studio' );
								} elseif ( $is_back ) {
									$label = esc_html__( 'Backdrop (click outside)', 'emblem-studio' );
								} elseif ( $is_untag ) {
									$label = esc_html__( '(untagged element)', 'emblem-studio' );
								} else {
									$label = esc_html( $target );
								}
								if ( $is_excluded ) {
									$label .= ' <span class="es-click-excluded-tag" title="' . esc_attr__( 'A dismissal, not engagement — left out of the Clicks number on the Stats tab and the Forms & Assets list.', 'emblem-studio' ) . '">' . esc_html__( 'not in Clicks total', 'emblem-studio' ) . '</span>';
								}
							?>
								<tr<?php echo $row_class; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
									<td><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
									<td><span class="es-shell-pill es-shell-<?php echo esc_attr( $row['shell'] ); ?>"><?php echo esc_html( $stats_shell_labels[ $row['shell'] ] ?? $row['shell'] ); ?></span></td>
									<td class="num"><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['last_at'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="es-clicks-legend">
						<?php esc_html_e( 'Rows marked “not in Clicks total” (the close button and clicking the backdrop) are dismissals. They’re listed here for reference but are deliberately left out of the Clicks numbers shown on the Stats tab and the Forms & Assets list.', 'emblem-studio' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<section id="es-tab-revisions" class="es-tab-panel" data-panel="revisions">
			<?php if ( ! ES_Caps::has( 'revisions' ) ) : ?>
				<div class="es-tab-empty es-revisions-upsell">
					<p><strong><?php esc_html_e( 'Revision history is a free-account feature', 'emblem-studio' ); ?></strong></p>
					<p><?php esc_html_e( 'Every save that changes your design can keep a snapshot you can compare against the current version and restore with one click.', 'emblem-studio' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-account' ) ); ?>"><?php esc_html_e( 'Connect a free account', 'emblem-studio' ); ?></a></p>
					<p class="description"><?php esc_html_e( 'Good news: your revisions are already being saved quietly in the background — they’ll be waiting here the moment you connect.', 'emblem-studio' ); ?></p>
				</div>
			<?php elseif ( $is_new ) : ?>
				<div class="es-tab-empty">
					<p><em><?php esc_html_e( 'Save the form once and your revision history starts here.', 'emblem-studio' ); ?></em></p>
				</div>
			<?php else :
				$revisions = ES_Form_Revisions::list_for_form( (int) $f->id );
				if ( isset( $_GET['restored'] ) ) :
			?>
				<div class="notice notice-success inline" style="margin:0 0 1em;"><p><?php esc_html_e( 'Revision restored. The previous current version is preserved at the top of the list.', 'emblem-studio' ); ?></p></div>
			<?php endif; ?>
				<?php if ( empty( $revisions ) ) : ?>
					<div class="es-tab-empty">
						<p><?php esc_html_e( 'No revisions yet. The first one will appear here after your next save that changes HTML or CSS.', 'emblem-studio' ); ?></p>
					</div>
				<?php else :
					$preview_url = ES_Preview::url_for( (int) $f->id );
				?>
					<p class="description es-revisions-intro">
						<?php
						printf(
							/* translators: %d = number of past revisions */
							esc_html( _n( '%d past version. Click to compare it against the current saved version.', '%d past versions. Click any to compare it against the current saved version.', count( $revisions ), 'emblem-studio' ) ),
							count( $revisions )
						);
						?>
					</p>
					<textarea class="es-revisions-current-html" hidden aria-hidden="true"><?php echo esc_textarea( (string) $f->html ); ?></textarea>
					<textarea class="es-revisions-current-css" hidden aria-hidden="true"><?php echo esc_textarea( (string) $f->css ); ?></textarea>
					<ul class="es-revisions-list" data-preview-url="<?php echo esc_attr( $preview_url ); ?>" data-form-id="<?php echo (int) $f->id; ?>">
						<?php
						foreach ( $revisions as $rev ) :
							$author = $rev->created_by ? get_userdata( (int) $rev->created_by ) : null;
							$author_name = $author && $author->display_name ? $author->display_name : __( 'system', 'emblem-studio' );
							$when = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rev->created_at );
							$restore_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=es_restore_revision&form_id=' . (int) $f->id . '&revision_id=' . (int) $rev->id ),
								'es_restore_revision_' . (int) $f->id . '_' . (int) $rev->id
							);
							$restore_as_new_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=es_restore_revision_as_new&form_id=' . (int) $f->id . '&revision_id=' . (int) $rev->id ),
								'es_restore_revision_as_new_' . (int) $f->id . '_' . (int) $rev->id
							);
							$export_revision_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=es_export_revision&form_id=' . (int) $f->id . '&revision_id=' . (int) $rev->id ),
								'es_export_revision_' . (int) $f->id . '_' . (int) $rev->id
							);
							$restore_as_variant_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=es_restore_revision_as_variant&form_id=' . (int) $f->id . '&revision_id=' . (int) $rev->id ),
								'es_restore_revision_as_variant_' . (int) $f->id . '_' . (int) $rev->id
							);
							$note_nonce = wp_create_nonce( 'es_revision_note_' . (int) $rev->id );
						?>
							<li class="es-revision" data-revision-id="<?php echo (int) $rev->id; ?>">
								<button type="button" class="es-revision-summary">
									<span class="es-revision-when"><?php echo esc_html( $when ); ?></span>
									<span class="es-revision-by"><?php
										/* translators: %s = author display name */
										printf( esc_html__( 'by %s', 'emblem-studio' ), esc_html( $author_name ) );
									?></span>
									<?php if ( $rev->note ) : ?>
										<span class="es-revision-note-display">&ldquo;<?php echo esc_html( $rev->note ); ?>&rdquo;</span>
									<?php endif; ?>
									<span class="es-revision-chevron" aria-hidden="true">▾</span>
								</button>
								<div class="es-revision-body" hidden>
									<div class="es-revision-diff-block">
										<div class="es-revision-diff-section-label"><?php esc_html_e( 'HTML', 'emblem-studio' ); ?></div>
										<div class="es-revision-pane-labels">
											<div class="es-revision-pane-label es-revision-pane-label-old">
												<strong><?php esc_html_e( 'Previous version', 'emblem-studio' ); ?></strong>
												<span class="es-revision-pane-sub"><?php echo esc_html( $when ); ?></span>
											</div>
											<div class="es-revision-pane-label es-revision-pane-label-new">
												<strong><?php esc_html_e( 'Current version', 'emblem-studio' ); ?></strong>
											</div>
										</div>
										<div class="es-revision-diff-scroll">
											<div class="es-revision-diff-grid" data-which="html"></div>
										</div>
									</div>
									<div class="es-revision-diff-block">
										<div class="es-revision-diff-section-label"><?php esc_html_e( 'CSS', 'emblem-studio' ); ?></div>
										<div class="es-revision-pane-labels">
											<div class="es-revision-pane-label es-revision-pane-label-old">
												<strong><?php esc_html_e( 'Previous version', 'emblem-studio' ); ?></strong>
												<span class="es-revision-pane-sub"><?php echo esc_html( $when ); ?></span>
											</div>
											<div class="es-revision-pane-label es-revision-pane-label-new">
												<strong><?php esc_html_e( 'Current version', 'emblem-studio' ); ?></strong>
											</div>
										</div>
										<div class="es-revision-diff-scroll">
											<div class="es-revision-diff-grid" data-which="css"></div>
										</div>
									</div>
									<div class="es-revision-previews">
										<div class="es-revision-preview-col">
											<div class="es-revision-pane-label es-revision-pane-label-old">
												<strong><?php esc_html_e( 'Previous version', 'emblem-studio' ); ?></strong>
												<span class="es-revision-pane-sub"><?php echo esc_html( $when ); ?></span>
											</div>
											<iframe name="es-rev-old-<?php echo (int) $rev->id; ?>" class="es-revision-preview-old" data-revision-id="<?php echo (int) $rev->id; ?>" title="<?php esc_attr_e( 'Previous version preview', 'emblem-studio' ); ?>" loading="lazy"></iframe>
										</div>
										<div class="es-revision-preview-col">
											<div class="es-revision-pane-label es-revision-pane-label-new">
												<strong><?php esc_html_e( 'Current version', 'emblem-studio' ); ?></strong>
												<span class="es-revision-pane-sub"><?php esc_html_e( 'what your form looks like right now', 'emblem-studio' ); ?></span>
											</div>
											<iframe name="es-rev-cur-<?php echo (int) $rev->id; ?>" class="es-revision-preview-current" data-revision-id="<?php echo (int) $rev->id; ?>" title="<?php esc_attr_e( 'Current version preview', 'emblem-studio' ); ?>" loading="lazy"></iframe>
										</div>
									</div>
									<div class="es-revision-actions">
										<a href="<?php echo esc_url( $restore_url ); ?>" class="button button-primary" onclick="return confirm('<?php esc_attr_e( 'Replace the current HTML/CSS with this revision? The version you have now will be saved as a new revision at the top of the list, so you can undo this.', 'emblem-studio' ); ?>');"><?php esc_html_e( 'Replace current with this', 'emblem-studio' ); ?></a>
										<a href="<?php echo esc_url( $restore_as_new_url ); ?>" class="button" title="<?php esc_attr_e( 'Create a new form using this revision\'s HTML/CSS combined with the source form\'s current settings. The original form is untouched.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Restore as new form', 'emblem-studio' ); ?></a>
										<?php if ( ! $ab_running ) : ?>
											<a href="<?php echo esc_url( $restore_as_variant_url ); ?>" class="button es-revision-restore-as-variant" title="<?php esc_attr_e( 'Start a new A/B test using this revision as variant B; the current design becomes variant A.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Restore as variant', 'emblem-studio' ); ?></a>
										<?php endif; ?>
										<a href="<?php echo esc_url( $export_revision_url ); ?>" class="button" title="<?php esc_attr_e( 'Download this revision as a JSON file. Uses the revision\'s HTML/CSS plus the form\'s current settings.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Export', 'emblem-studio' ); ?></a>
										<button type="button" class="button es-revision-copy" data-copy-source="html" data-revision-id="<?php echo (int) $rev->id; ?>"><?php esc_html_e( 'Copy HTML', 'emblem-studio' ); ?></button>
										<button type="button" class="button es-revision-copy" data-copy-source="css"  data-revision-id="<?php echo (int) $rev->id; ?>"><?php esc_html_e( 'Copy CSS',  'emblem-studio' ); ?></button>
										<span class="es-revision-note-edit">
											<input type="text" class="es-revision-note-input" value="<?php echo esc_attr( $rev->note ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Add a note (optional)', 'emblem-studio' ); ?>" data-revision-id="<?php echo (int) $rev->id; ?>" data-nonce="<?php echo esc_attr( $note_nonce ); ?>" maxlength="191">
										</span>
									</div>
									<textarea class="es-revision-data-html" data-revision-id="<?php echo (int) $rev->id; ?>" hidden aria-hidden="true"><?php echo esc_textarea( (string) $rev->html ); ?></textarea>
									<textarea class="es-revision-data-css" data-revision-id="<?php echo (int) $rev->id; ?>" hidden aria-hidden="true"><?php echo esc_textarea( (string) $rev->css ); ?></textarea>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<section id="es-tab-settings" class="es-tab-panel" data-panel="settings">
			<table class="form-table" role="presentation">
				<tr>
					<th>
						<label for="es-use-shadow-dom"><?php esc_html_e( 'Shadow DOM', 'emblem-studio' ); ?></label>
						<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'Off (default): the form inherits theme typography and button styles; your form CSS can also leak out to the page. On: complete style isolation — theme CSS can\'t reach in and your form CSS stays contained.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s this?', 'emblem-studio' ); ?>">?</button>
					</th>
					<td>
						<label style="display:inline-flex; align-items:center; gap:0.5rem;">
							<input type="checkbox" class="es-toggle-input" id="es-use-shadow-dom" name="use_shadow_dom" value="1" <?php checked( $f->use_shadow_dom ); ?>>
							<?php esc_html_e( 'Isolate this form from theme styles', 'emblem-studio' ); ?>
						</label>
					</td>
				</tr>
				<?php if ( ES_Caps::has( 'design_system' ) ) : ?>
				<tr>
					<th>
						<label for="es-use-design-system"><?php esc_html_e( 'Design System', 'emblem-studio' ); ?></label>
						<button type="button" class="es-help" data-tip="<?php esc_attr_e( 'On (default): this form inherits the shared Design System CSS (buttons, containers, backgrounds) edited under Emblem Studio → Design System. Off: style this form entirely on its own.', 'emblem-studio' ); ?>" aria-label="<?php esc_attr_e( 'What\'s this?', 'emblem-studio' ); ?>">?</button>
					</th>
					<td>
						<label style="display:inline-flex; align-items:center; gap:0.5rem;">
							<input type="checkbox" class="es-toggle-input" id="es-use-design-system" name="use_design_system" value="1" <?php checked( $f->use_design_system ); ?>>
							<?php esc_html_e( 'Inherit the shared Design System CSS', 'emblem-studio' ); ?>
						</label>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( ES_Caps::has( 'schedule_window' ) ) : ?>
			<?php include EMBLEM_STUDIO_PATH . 'admin/views/_partials/schedule-window.php'; ?>
			<?php else : ?>
			<h2><?php esc_html_e( 'Schedule', 'emblem-studio' ); ?></h2>
			<div class="es-lite-upsell" style="max-width:520px;">
				<span class="es-lite-upsell-eyebrow"><?php esc_html_e( 'Free with an account', 'emblem-studio' ); ?></span>
				<strong><?php esc_html_e( 'Schedule when a form shows', 'emblem-studio' ); ?></strong>
				<p><?php esc_html_e( 'Set a start and end date so a form appears and disappears on its own. Unlocks free when you connect an Emblem Studio account.', 'emblem-studio' ); ?></p>
			</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'After submit', 'emblem-studio' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Thank-you behavior', 'emblem-studio' ); ?></th>
					<td>
						<label><input type="radio" name="success_mode" value="inline_state"   <?php checked( $f->success_mode, 'inline_state' );   ?> data-es-success-mode> <?php esc_html_e( 'Show the form\'s inline thank-you state (element with data-esf-thankyou)', 'emblem-studio' ); ?></label><br>
						<label><input type="radio" name="success_mode" value="custom_message" <?php checked( $f->success_mode, 'custom_message' ); ?> data-es-success-mode> <?php esc_html_e( 'Replace the form with a thank-you message', 'emblem-studio' ); ?></label><br>
						<label><input type="radio" name="success_mode" value="redirect"       <?php checked( $f->success_mode, 'redirect' );       ?> data-es-success-mode> <?php esc_html_e( 'Redirect to a URL', 'emblem-studio' ); ?></label>
					</td>
				</tr>
				<tr class="es-success-field es-success-field-custom_message">
					<th><label for="es-success-message"><?php esc_html_e( 'Thank-you message', 'emblem-studio' ); ?></label></th>
					<td><textarea id="es-success-message" name="success_message" rows="4" class="large-text"><?php echo esc_textarea( $f->success_message ); ?></textarea></td>
				</tr>
				<tr class="es-success-field es-success-field-redirect">
					<th><label for="es-success-redirect-url"><?php esc_html_e( 'Redirect URL', 'emblem-studio' ); ?></label></th>
					<td><input id="es-success-redirect-url" name="success_redirect_url" type="url" class="regular-text" value="<?php echo esc_attr( $f->success_redirect_url ); ?>" placeholder="https://"></td>
				</tr>
			</table>

			<?php if ( ! $is_new ) : ?>
				<h2><?php esc_html_e( 'Export this form', 'emblem-studio' ); ?></h2>
				<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Download the form\'s HTML, CSS, and every per-form setting as a JSON file. Import it on another site (or this one) to recreate the form. Stats and leads are not included.', 'emblem-studio' ); ?></p>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_export_form&id=' . (int) $f->id ), 'es_export_form_' . (int) $f->id ) ); ?>">
						<?php esc_html_e( 'Download JSON', 'emblem-studio' ); ?> <span aria-hidden="true">↓</span>
					</a>
				</p>
			<?php endif; ?>

			<?php if ( ! $is_new ) :
				$danger_shells_with_data = ES_Reports::shells_with_data( (int) $f->id );
				$danger_shell_labels = [
					'inline'      => __( 'Inline', 'emblem-studio' ),
					'popup-click' => __( 'Click popup', 'emblem-studio' ),
				];
			?>
				<div class="es-danger-zone">
					<h2><?php esc_html_e( 'Danger zone', 'emblem-studio' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Wipes page-view, view, and conversion counts. Leads are kept.', 'emblem-studio' ); ?></p>
					<p>
						<button type="button" class="button button-secondary es-danger-open">
							<?php esc_html_e( 'Reset stats…', 'emblem-studio' ); ?>
						</button>
					</p>
				</div>

				<div class="es-modal" id="es-modal-danger" hidden>
					<div class="es-modal-backdrop" data-es-modal-close></div>
					<div class="es-modal-dialog" role="dialog" aria-labelledby="es-modal-danger-title" aria-modal="true">
						<button type="button" class="es-modal-close" data-es-modal-close aria-label="<?php esc_attr_e( 'Close', 'emblem-studio' ); ?>">&times;</button>
						<h3 id="es-modal-danger-title"><?php esc_html_e( 'Reset stats', 'emblem-studio' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Pick what to wipe. Leads are always kept.', 'emblem-studio' ); ?></p>
						<div class="es-modal-actions">
							<?php foreach ( $danger_shells_with_data as $danger_shell ) :
								$danger_label = $danger_shell_labels[ $danger_shell ] ?? $danger_shell;
								$danger_url = wp_nonce_url(
									admin_url( 'admin-post.php?action=es_reset_stats&form_id=' . (int) $f->id . '&shell=' . rawurlencode( $danger_shell ) ),
									'es_reset_stats_' . (int) $f->id . '_' . $danger_shell
								);
							?>
								<a href="<?php echo esc_url( $danger_url ); ?>"
								   class="button"
								   onclick="return confirm('<?php echo esc_attr( sprintf( __( 'Reset %s stats for this form? Leads will NOT be deleted.', 'emblem-studio' ), $danger_label ) ); ?>');">
									<?php echo esc_html( sprintf( __( 'Reset %s stats', 'emblem-studio' ), $danger_label ) ); ?>
								</a>
							<?php endforeach; ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_reset_stats&form_id=' . (int) $f->id ), 'es_reset_stats_' . (int) $f->id ) ); ?>"
							   class="button es-button-danger"
							   onclick="return confirm('<?php esc_attr_e( 'Reset ALL stats for this form? Leads will NOT be deleted.', 'emblem-studio' ); ?>');">
								<?php esc_html_e( 'Reset all stats for this form', 'emblem-studio' ); ?>
							</a>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</section>
	</form>
</div>
