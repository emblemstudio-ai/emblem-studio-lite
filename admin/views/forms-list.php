<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $forms */
/** @var array $counts */
/** @var string $filter */
?>
<?php
$tz   = wp_timezone();
$now  = new DateTimeImmutable( 'now', $tz );
$cur_to    = $now->format( 'Y-m-d' );
$cur_from  = $now->modify( '-6 days' )->format( 'Y-m-d' );
$prev_to   = $now->modify( '-7 days' )->format( 'Y-m-d' );
$prev_from = $now->modify( '-13 days' )->format( 'Y-m-d' );

$cur  = ES_Reports::aggregate_kpis( $cur_from, $cur_to );
$prev = ES_Reports::aggregate_kpis( $prev_from, $prev_to );
$cur_leads  = ES_Reports::leads_in_range( $cur_from, $cur_to );
$prev_leads = ES_Reports::leads_in_range( $prev_from, $prev_to );

// Form IDs with a running A/B test — one query, used to badge rows below.
// A/B is account-gated; the Lite build omits ES_AB, so there are simply none.
$ab_running_ids = class_exists( 'ES_AB' ) ? ES_AB::form_ids_with_running_tests() : [];

/**
 * Render a delta sub-line: arrow + percent + tooltip with prior period value.
 * No prior data → render "—".
 */
function es_delta_line( int $current, int $prior ): string {
	if ( $prior === 0 && $current === 0 ) {
		return '<div class="es-kpi-sub es-kpi-delta es-kpi-delta-flat">— ' . esc_html__( 'no prior data', 'emblem-studio' ) . '</div>';
	}
	if ( $prior === 0 ) {
		return '<div class="es-kpi-sub es-kpi-delta es-kpi-delta-up" title="' . esc_attr( sprintf( __( 'Prior 7 days: %d', 'emblem-studio' ), $prior ) ) . '">▲ ' . esc_html__( 'new', 'emblem-studio' ) . '</div>';
	}
	$pct  = round( ( ( $current - $prior ) / $prior ) * 100, 1 );
	$cls  = $pct > 0 ? 'es-kpi-delta-up' : ( $pct < 0 ? 'es-kpi-delta-down' : 'es-kpi-delta-flat' );
	$arr  = $pct > 0 ? '▲' : ( $pct < 0 ? '▼' : '■' );
	$sign = $pct > 0 ? '+' : '';
	$tip  = sprintf( __( 'Prior 7 days: %d', 'emblem-studio' ), $prior );
	return '<div class="es-kpi-sub es-kpi-delta ' . esc_attr( $cls ) . '" title="' . esc_attr( $tip ) . '">' . $arr . ' ' . esc_html( $sign . number_format_i18n( $pct, 1 ) ) . '% ' . esc_html__( 'vs prev 7d', 'emblem-studio' ) . '</div>';
}
?>
<div class="wrap emblem-studio-wrap">
	<div class="es-page-header">
		<div class="es-page-header-main">
			<span class="es-eyebrow"><span class="es-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e( 'Forms & Assets', 'emblem-studio' ); ?></span>
			<h1 class="es-page-title"><?php esc_html_e( 'Emblem Studio — Forms & Assets', 'emblem-studio' ); ?></h1>
			<p class="es-page-subtitle"><?php esc_html_e( 'Your forms, popups, ribbons and slide-ins — and how they’re converting.', 'emblem-studio' ); ?></p>
		</div>
		<div class="es-page-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-new' ) ); ?>" class="page-title-action es-action-primary"><?php esc_html_e( 'Add New', 'emblem-studio' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-reports' ) ); ?>" class="page-title-action"><?php esc_html_e( 'View Reports', 'emblem-studio' ); ?></a>
			<button type="button" class="page-title-action" id="es-import-open"><?php esc_html_e( 'Import', 'emblem-studio' ); ?></button>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_export_all' ), 'es_export_all' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Export all', 'emblem-studio' ); ?></a>
		</div>
	</div>
	<hr class="es-horizon" aria-hidden="true">

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="es-toast" role="status" aria-live="polite"><?php esc_html_e( 'Asset saved', 'emblem-studio' ); ?></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['imported'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php echo esc_html( sprintf( _n( '%d form imported.', '%d forms imported.', (int) $_GET['imported'], 'emblem-studio' ), (int) $_GET['imported'] ) ); ?>
			<?php if ( ! empty( $_GET['import_failed'] ) ) : ?>
				<?php echo esc_html( sprintf( _n( '%d entry failed to import.', '%d entries failed to import.', (int) $_GET['import_failed'], 'emblem-studio' ), (int) $_GET['import_failed'] ) ); ?>
			<?php endif; ?>
			<?php esc_html_e( 'Imported forms are Inactive — flip them Active once reviewed.', 'emblem-studio' ); ?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['import_error'] ) ) :
		$err = sanitize_key( (string) $_GET['import_error'] );
		$msg = $err === 'missing_file'
			? __( 'No file selected for import.', 'emblem-studio' )
			: ( $err === 'unreadable'
				? __( 'Could not read the uploaded file.', 'emblem-studio' )
				: __( 'The file does not look like an Emblem Studio export. Look for a JSON file with an emblem_studio_export_version key.', 'emblem-studio' )
			);
	?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>

	<div id="es-import-panel" hidden style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 18px;margin:12px 0;max-width:720px;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'es_import_forms' ); ?>
			<input type="hidden" name="action" value="es_import_forms">
			<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Import forms from JSON', 'emblem-studio' ); ?></strong></p>
			<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Pick a .json file exported from Emblem Studio. Imported forms are set to Inactive so you can review before going live.', 'emblem-studio' ); ?></p>
			<input type="file" name="import_file" accept="application/json,.json" required>
			<?php submit_button( __( 'Import', 'emblem-studio' ), 'primary', 'submit', false, [ 'style' => 'margin-left:8px;' ] ); ?>
			<button type="button" class="button-link" id="es-import-cancel" style="margin-left:8px;"><?php esc_html_e( 'Cancel', 'emblem-studio' ); ?></button>
		</form>
	</div>
	<script>
		(function () {
			var openBtn = document.getElementById('es-import-open');
			var panel   = document.getElementById('es-import-panel');
			var cancel  = document.getElementById('es-import-cancel');
			if (openBtn && panel) {
				openBtn.addEventListener('click', function () { panel.hidden = false; });
			}
			if (cancel && panel) {
				cancel.addEventListener('click', function () { panel.hidden = true; });
			}
		})();
	</script>
	<?php if ( isset( $_GET['trashed'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form moved to Trash.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['restored'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form restored.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form permanently deleted.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['bulk_applied'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( '%d form updated.', '%d forms updated.', (int) $_GET['bulk_applied'], 'emblem-studio' ), (int) $_GET['bulk_applied'] ) ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['stats_reset'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All stats reset.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['duplicate_failed'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not duplicate the form.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>

	<?php
	// Schedule-window heads-up: assets set Active but outside their schedule
	// window render nowhere, yet still list as Active. Surface them once at the
	// top so "why isn't my popup showing?" is answered without opening settings.
	$sched_out = [];
	foreach ( ES_Form_Repo::all( 'active' ) as $af ) {
		$st = ES_Form_Repo::effective_state( $af );
		if ( 'scheduled' === $st || 'expired' === $st ) {
			$sched_out[] = $af;
		}
	}
	if ( $sched_out ) :
		$links = [];
		foreach ( $sched_out as $af ) {
			$label = ES_Form_Repo::effective_state( $af ) === 'scheduled'
				? __( 'Scheduled', 'emblem-studio' )
				: __( 'Expired', 'emblem-studio' );
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . (int) $af->id ) ) . '">'
				. esc_html( $af->name ) . '</a> <em>(' . esc_html( $label ) . ')</em>';
		}
	?>
		<div class="notice notice-warning is-dismissible"><p>
			<strong><?php echo esc_html( sprintf( _n( '%d asset is set Active but is outside its schedule window, so it isn’t showing:', '%d assets are set Active but are outside their schedule window, so they aren’t showing:', count( $sched_out ), 'emblem-studio' ), count( $sched_out ) ) ); ?></strong>
			<?php echo wp_kses_post( implode( ', ', $links ) ); ?>
		</p></div>
	<?php endif; ?>

	<?php
	$views = [
		'all'      => __( 'All',      'emblem-studio' ),
		'active'   => __( 'Active',   'emblem-studio' ),
		'inactive' => __( 'Inactive', 'emblem-studio' ),
		'trash'    => __( 'Trash',    'emblem-studio' ),
	];
	?>
	<ul class="subsubsub es-filter-pills">
		<?php foreach ( $views as $key => $label ) :
			$count   = (int) ( $counts[ $key ] ?? 0 );
			$url     = $key === 'all'
				? admin_url( 'admin.php?page=emblem-studio' )
				: admin_url( 'admin.php?page=emblem-studio&filter=' . $key );
			$active  = ( $filter === $key ) ? ' class="current" aria-current="page"' : '';
		?>
			<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $active; ?>><?php echo esc_html( $label ); ?> <span class="count"><?php echo (int) $count; ?></span></a></li>
		<?php endforeach; ?>
	</ul>

	<div class="es-kpis" style="margin:1em 0 1.5em;">
		<div class="es-kpi">
			<div class="es-kpi-label"><?php esc_html_e( 'Leads (7d)', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo (int) $cur_leads; ?></div>
			<?php echo es_delta_line( $cur_leads, $prev_leads ); ?>
		</div>
		<div class="es-kpi">
			<div class="es-kpi-label"><?php esc_html_e( 'Page Views (7d)', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo (int) $cur['page_views']; ?></div>
			<?php echo es_delta_line( (int) $cur['page_views'], (int) $prev['page_views'] ); ?>
		</div>
		<div class="es-kpi">
			<div class="es-kpi-label"><?php esc_html_e( 'Form Impressions (7d)', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo (int) $cur['views']; ?></div>
			<?php echo es_delta_line( (int) $cur['views'], (int) $prev['views'] ); ?>
		</div>
		<div class="es-kpi">
			<div class="es-kpi-label"><?php esc_html_e( 'Form Conversions (7d)', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo (int) $cur['conversions']; ?></div>
			<?php echo es_delta_line( (int) $cur['conversions'], (int) $prev['conversions'] ); ?>
		</div>
	</div>

	<?php
	$shell_labels = [
		'inline'      => __( 'Inline', 'emblem-studio' ),
		'popup-click' => __( 'Click popup', 'emblem-studio' ),
		'popup-auto'  => __( 'Auto popup', 'emblem-studio' ),
		'ribbon'      => __( 'Ribbon', 'emblem-studio' ),
		'slide-in'    => __( 'Slide-in', 'emblem-studio' ),
	];
	$shortcode_for = function ( int $form_id, string $shell ): string {
		if ( $shell === 'popup-click' ) {
			return '[es-popup-' . $form_id . ']Click here[/es-popup-' . $form_id . ']';
		}
		if ( $shell === 'popup-auto' || $shell === 'ribbon' || $shell === 'slide-in' ) {
			return ''; // no shortcode — these inject via wp_footer
		}
		return '[es-inline-' . $form_id . ']';
	};
	$is_trash = ( $filter === 'trash' );
	$bulk_options = $is_trash
		? [
			''                 => __( 'Bulk actions', 'emblem-studio' ),
			'restore'          => __( 'Restore', 'emblem-studio' ),
			'delete_permanent' => __( 'Delete permanently', 'emblem-studio' ),
		]
		: [
			''         => __( 'Bulk actions', 'emblem-studio' ),
			'activate' => __( 'Set Active', 'emblem-studio' ),
			'inactive' => __( 'Set Inactive', 'emblem-studio' ),
			'trash'    => __( 'Move to Trash', 'emblem-studio' ),
		];
	$bulk_confirm_js = "var sel=this.querySelector('select[name=bulk_action]'); if(!sel||!sel.value){return false;} if(sel.value==='delete_permanent'){return confirm('" . esc_js( __( 'Permanently delete the selected forms? This cannot be undone.', 'emblem-studio' ) ) . "');} return true;";
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="<?php echo esc_attr( $bulk_confirm_js ); ?>">
		<?php wp_nonce_field( 'es_bulk_forms' ); ?>
		<input type="hidden" name="action" value="es_bulk_forms">
		<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">

		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<label for="es-bulk-action-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'emblem-studio' ); ?></label>
				<select name="bulk_action" id="es-bulk-action-top">
					<?php foreach ( $bulk_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'emblem-studio' ); ?>">
			</div>
			<br class="clear">
		</div>

	<?php $reorder_nonce = $filter === 'all' ? wp_create_nonce( 'es_reorder_forms' ) : ''; ?>
	<div class="es-forms-table-scroll">
	<table class="wp-list-table widefat fixed striped es-forms-table<?php echo $filter === 'all' ? ' es-forms-reorderable' : ''; ?>"<?php echo $reorder_nonce ? ' data-reorder-nonce="' . esc_attr( $reorder_nonce ) . '"' : ''; ?>>
		<thead>
			<tr>
				<?php if ( $filter === 'all' && ! empty( $forms ) ) : ?>
					<th class="col-drag" aria-label="<?php esc_attr_e( 'Drag to reorder', 'emblem-studio' ); ?>"></th>
				<?php endif; ?>
				<th class="col-cb"><input type="checkbox" id="es-cb-select-all-1" aria-label="<?php esc_attr_e( 'Select all forms', 'emblem-studio' ); ?>"></th>
				<th class="col-name"><?php esc_html_e( 'Name', 'emblem-studio' ); ?></th>
				<th class="col-shell"><?php esc_html_e( 'Shell', 'emblem-studio' ); ?></th>
				<th class="col-shortcode"><?php esc_html_e( 'Shortcode', 'emblem-studio' ); ?></th>
				<th class="col-status"><?php esc_html_e( 'Status', 'emblem-studio' ); ?></th>
				<th class="col-stat" title="<?php esc_attr_e( 'Page loads with this form on them', 'emblem-studio' ); ?>"><?php esc_html_e( 'Page Views', 'emblem-studio' ); ?></th>
				<th class="col-stat" title="<?php esc_attr_e( 'Click popup only — trigger element seen ≥10% in viewport', 'emblem-studio' ); ?>"><?php esc_html_e( 'Trigger Views', 'emblem-studio' ); ?></th>
				<th class="col-stat" title="<?php esc_attr_e( 'Asset actually became visible (viewport for inline / ribbon / slide-in; click for popup)', 'emblem-studio' ); ?>"><?php esc_html_e( 'Asset Views', 'emblem-studio' ); ?></th>
				<th class="col-stat" title="<?php esc_attr_e( 'Distinct people who saw it (anonymous tag, no IP). Builds up from now on; ~7-day estimate.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Unique', 'emblem-studio' ); ?></th>
				<th class="col-stat es-col-conv" title="<?php esc_attr_e( 'Conversions', 'emblem-studio' ); ?>"><?php esc_html_e( 'Form Conv.', 'emblem-studio' ); ?></th>
				<th class="col-stat-narrow es-col-conv" title="<?php esc_attr_e( 'Conversions vs Form Views / vs unique visitors', 'emblem-studio' ); ?>"><?php esc_html_e( 'Conv. Rate', 'emblem-studio' ); ?></th>
				<th class="col-stat es-col-conv" title="<?php esc_attr_e( 'Visitors who started filling in the form but left without submitting', 'emblem-studio' ); ?>"><?php esc_html_e( 'Drop-offs', 'emblem-studio' ); ?></th>
				<th class="col-stat-narrow es-col-conv" title="<?php esc_attr_e( 'Drop-offs vs Form Views / vs unique visitors', 'emblem-studio' ); ?>"><?php esc_html_e( 'Drop-off Rate', 'emblem-studio' ); ?></th>
				<th class="col-stat es-col-click" title="<?php esc_attr_e( 'Engaged clicks inside the form — links and buttons, excluding the close button and backdrop', 'emblem-studio' ); ?>"><?php esc_html_e( 'Clicks', 'emblem-studio' ); ?></th>
				<th class="col-stat-narrow es-col-click" title="<?php esc_attr_e( 'Clicks ÷ Form Views', 'emblem-studio' ); ?>"><?php esc_html_e( 'Click Rate', 'emblem-studio' ); ?></th>
				<th class="col-stat" title="<?php esc_attr_e( 'Bot user-agents (Googlebot, scrapers, etc.) dropped from view counts so the page-view metric stays trustworthy.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Bot Views', 'emblem-studio' ); ?></th>
				<th class="col-stat" title="<?php esc_attr_e( 'Submissions caught by the hidden honeypot field — almost always bots. Not sent to FunnelKit / webhooks.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Blocked Subs.', 'emblem-studio' ); ?></th>
				<th class="col-date"><?php esc_html_e( 'Updated', 'emblem-studio' ); ?></th>
				<th class="col-actions"></th>
			</tr>
		</thead>
		<?php if ( empty( $forms ) ) : ?>
			<tbody>
				<tr><td colspan="18"><em><?php
					echo esc_html( $is_trash
						? __( 'Trash is empty.', 'emblem-studio' )
						: ( $filter === 'all' ? __( 'No forms yet. Click “Add New” to create one.', 'emblem-studio' ) : __( 'No forms in this view.', 'emblem-studio' ) )
					);
				?></em></td></tr>
			</tbody>
		<?php else : ?>
			<tbody aria-hidden="true"><tr class="es-form-topspacer"><td colspan="99"></td></tr></tbody>
			<?php $form_index = 0; foreach ( $forms as $f ) :
				$form_index++;
				$enabled_types = ES_Form_Repo::types_for( $f );
				// Copy-down: shells the SaaS uses that Lite can't run (shown struck
				// through), and whether the whole asset is Lite-deactivated. Set by
				// the connector's pull; empty for locally-authored / never-synced forms.
				$lite_meta   = json_decode( (string) ( $f->lite_disabled ?? '' ), true );
				$lite_shells = ( is_array( $lite_meta ) && ! empty( $lite_meta['shells'] ) && is_array( $lite_meta['shells'] ) ) ? $lite_meta['shells'] : [];
				$lite_off    = is_array( $lite_meta ) && ! empty( $lite_meta['asset_off'] );
				$lite_shell_labels = [];
				foreach ( $lite_shells as $ls ) {
					$lite_shell_labels[] = $shell_labels[ $ls ] ?? $ls;
				}
				// Form-vs-asset: a non-form asset (no <form> element) has no
				// conversions, so its conversion cells read "—" not "0".
				$has_form      = ES_Form_Repo::primary_metric( (string) $f->html ) === 'conversions';
				$data_types    = ES_Reports::shells_with_data( (int) $f->id );
				// Render row for every enabled shell plus every shell with historical data.
				$render_types  = array_values( array_unique( array_merge( $enabled_types, $data_types ) ) );
				// Keep canonical order: inline, then click popup, then auto popup, then ribbon, then slide-in.
				usort( $render_types, function ( $a, $b ) {
					$order = [ 'inline' => 0, 'popup-click' => 1, 'popup-auto' => 2, 'ribbon' => 3, 'slide-in' => 4 ];
					return ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 );
				} );
				$is_blank = empty( $render_types );
				if ( $is_blank ) {
					$render_types = [ '' ];
				}
				$row_count = count( $render_types );
				$first_row = true;
				$updated_str = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $f->updated_at );
				if ( $is_trash ) {
					$actions_html = '<div class="es-actions-icons">'
						. '<a class="es-action-icon" title="' . esc_attr__( 'Restore', 'emblem-studio' ) . '" aria-label="' . esc_attr__( 'Restore', 'emblem-studio' ) . '" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_restore_form&id=' . $f->id ), 'es_restore_form_' . $f->id ) ) . '"><span class="dashicons dashicons-undo" aria-hidden="true"></span></a>'
						. '<a class="es-action-icon es-action-danger" title="' . esc_attr__( 'Delete permanently', 'emblem-studio' ) . '" aria-label="' . esc_attr__( 'Delete permanently', 'emblem-studio' ) . '" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_delete_form&id=' . $f->id ), 'es_delete_form_' . $f->id ) ) . '" onclick="return confirm(\'' . esc_attr__( 'Permanently delete this form? This cannot be undone.', 'emblem-studio' ) . '\');"><span class="dashicons dashicons-trash" aria-hidden="true"></span></a>'
						. '</div>';
				} else {
					$actions_html = '<div class="es-actions-icons">'
						. '<a class="es-action-icon" title="' . esc_attr__( 'Edit', 'emblem-studio' ) . '" aria-label="' . esc_attr__( 'Edit', 'emblem-studio' ) . '" href="' . esc_url( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $f->id ) ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span></a>'
						. '<a class="es-action-icon" title="' . esc_attr__( 'Duplicate', 'emblem-studio' ) . '" aria-label="' . esc_attr__( 'Duplicate', 'emblem-studio' ) . '" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_duplicate_form&id=' . $f->id ), 'es_duplicate_form_' . $f->id ) ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></a>'
						. '<a class="es-action-icon es-action-danger" title="' . esc_attr__( 'Trash', 'emblem-studio' ) . '" aria-label="' . esc_attr__( 'Trash', 'emblem-studio' ) . '" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_trash_form&id=' . $f->id ), 'es_trash_form_' . $f->id ) ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></a>'
						. '</div>';
				}
			?>
			<tbody class="es-form-group"<?php echo $filter === 'all' ? ' data-form-id="' . (int) $f->id . '"' : ''; ?>>
				<?php foreach ( $render_types as $shell ) :
					$row_blank = ( $shell === '' );
					$k = $row_blank ? null : ES_Reports::all_time( (int) $f->id, $shell );
					$has_data = $k && ( (int) $k['page_views'] + (int) $k['trigger_views'] + (int) $k['views'] + (int) $k['conversions'] ) > 0;
					$is_enabled = ! $row_blank && in_array( $shell, $enabled_types, true );
					// Status semantics: if form isn't "active", it's inactive — that label
					// applies to every shell row. For active forms, enabled shells read
					// "Active" — unless the schedule window says otherwise, in which case
					// the *effective* state (Scheduled before active_from / Expired after
					// active_until) is surfaced so an admin isn't told "Active" while the
					// asset silently isn't rendering. Derived only; stored status is untouched.
					$eff          = ES_Form_Repo::effective_state( $f );
					$status_class = 'es-status-inactive';
					$status_title = '';
					$dt_fmt       = get_option( 'date_format' ) . ' g:i A';
					if ( $f->status !== 'active' ) {
						$status_label = ucfirst( $f->status );
					} elseif ( $row_blank || ! $is_enabled ) {
						$status_label = __( 'Inactive', 'emblem-studio' );
					} elseif ( $eff === 'scheduled' ) {
						$status_label = __( 'Scheduled', 'emblem-studio' );
						$status_class = 'es-status-scheduled';
						$status_title = sprintf( __( 'Set Active, but its schedule starts %s — not showing yet.', 'emblem-studio' ), mysql2date( $dt_fmt, (string) $f->active_from ) );
					} elseif ( $eff === 'expired' ) {
						$status_label = __( 'Expired', 'emblem-studio' );
						$status_class = 'es-status-expired';
						$status_title = sprintf( __( 'Set Active, but its schedule ended %s — no longer showing.', 'emblem-studio' ), mysql2date( $dt_fmt, (string) $f->active_until ) );
					} elseif ( $eff === 'ending' ) {
						$status_label = __( 'Scheduled to end', 'emblem-studio' );
						$status_class = 'es-status-ending';
						$status_title = sprintf( __( 'Showing now, but scheduled to end %s.', 'emblem-studio' ), mysql2date( $dt_fmt, (string) $f->active_until ) );
					} else {
						$status_label = __( 'Active', 'emblem-studio' );
						$status_class = 'es-status-active';
					}
					// A row gets the "inactive" greyed-out treatment whenever the
					// shell won't actually render on the frontend — Inactive overall,
					// a data-only (unchecked) row, a blank row, OR Active-but-outside
					// its schedule window (scheduled / expired). "ending" still renders.
					$row_will_render = ( in_array( $eff, array( 'active', 'ending' ), true ) && ! $row_blank && $is_enabled );
					$row_class = $row_will_render ? '' : 'es-shell-row-inactive';
				?>
					<tr<?php echo $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?>>
						<?php
						// Pinned identity lane (drag / checkbox / name): a cell on EVERY shell row,
						// content on the first only — NOT one rowspanned cell. A sticky + rowspan
						// cell does not stay opaque over the rows it spans when the table scrolls
						// sideways, so an asset's extra shell rows would show through the pinned
						// column. A real cell per row can't. (Empty sub-row cells keep the lane
						// opaque; border-collapse hides their seams so it still reads as one block.)
						?>
						<?php if ( $filter === 'all' ) : ?>
							<td class="col-drag es-form-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder', 'emblem-studio' ); ?>" title="<?php esc_attr_e( 'Drag to reorder', 'emblem-studio' ); ?>">
								<?php if ( $first_row ) : ?><span class="dashicons dashicons-menu" aria-hidden="true"></span><?php endif; ?>
							</td>
						<?php endif; ?>
						<?php if ( $first_row ) : ?>
							<td class="col-cb">
								<input type="checkbox" name="form_ids[]" value="<?php echo (int) $f->id; ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'emblem-studio' ), $f->name ) ); ?>">
							</td>
							<td class="col-name">
								<strong>
									<?php if ( $is_trash ) : ?>
										<?php echo esc_html( $f->name ); ?>
									<?php else : ?>
										<a class="es-asset-name<?php echo $lite_off ? ' es-lite-off-name' : ''; ?>" data-es-preview-id="<?php echo (int) $f->id; ?>" data-es-preview-wide="<?php echo in_array( 'ribbon', $enabled_types, true ) ? '1' : '0'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $f->id ) ); ?>"><?php echo esc_html( $f->name ); ?></a>
									<?php endif; ?>
								</strong>
								<?php if ( ! $is_trash && $lite_off ) : ?>
										<span class="es-lite-badge es-lite-badge-off" title="<?php esc_attr_e( 'This asset only uses shells Lite can’t run, so it’s off here. It stays active in your Emblem Studio account.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Off in Lite', 'emblem-studio' ); ?></span>
									<?php elseif ( ! $is_trash && $lite_shell_labels ) : ?>
										<span class="es-lite-badge" title="<?php esc_attr_e( 'These shells are active in your Emblem Studio account, but Lite can’t run them.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Off in Lite:', 'emblem-studio' ); ?> <s><?php echo esc_html( implode( ', ', $lite_shell_labels ) ); ?></s></span>
									<?php endif; ?>
									<?php if ( ! $is_trash && in_array( (int) $f->id, $ab_running_ids, true ) ) : ?>
									<a class="es-ab-badge"
									   href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio&action=edit&id=' . $f->id ) . '#stats' ); ?>"
									   title="<?php esc_attr_e( 'An A/B test is running on this form. Click to open its Stats tab.', 'emblem-studio' ); ?>">
										<span class="es-ab-badge-dot" aria-hidden="true"></span>
										<?php esc_html_e( 'A/B test', 'emblem-studio' ); ?>
									</a>
								<?php endif; ?>
								<?php
								// "Edit lock" indicator — someone currently has this asset open.
								$lock_uid = $is_trash ? 0 : ES_Locks::holder_of_row( $f );
								if ( $lock_uid ) :
									$lock_is_me = ( $lock_uid === get_current_user_id() );
									$lock_name  = ES_Locks::user_label( $lock_uid );
								?>
									<span class="es-lock-badge<?php echo $lock_is_me ? ' is-you' : ''; ?>"
									      title="<?php echo esc_attr( $lock_is_me
									          ? __( 'You have this asset open in the editor.', 'emblem-studio' )
									          : sprintf( __( '%s is editing this asset right now.', 'emblem-studio' ), $lock_name ) ); ?>">
										<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										<?php echo esc_html( $lock_is_me ? __( 'You’re editing', 'emblem-studio' ) : sprintf( __( '%s is editing', 'emblem-studio' ), $lock_name ) ); ?>
									</span>
								<?php endif; ?>
							</td>
						<?php else : ?>
							<td class="col-cb" aria-hidden="true"></td>
							<td class="col-name" aria-hidden="true"></td>
						<?php endif; ?>
						<td class="col-shell">
							<?php if ( $row_blank ) : ?>
								<em><?php esc_html_e( '— not embedded —', 'emblem-studio' ); ?></em>
							<?php else : ?>
								<span class="es-shell-pill es-shell-<?php echo esc_attr( $shell ); ?>"><?php echo esc_html( $shell_labels[ $shell ] ?? $shell ); ?></span>
							<?php endif; ?>
						</td>
						<td class="col-shortcode">
							<?php $sc = $row_blank ? '' : $shortcode_for( (int) $f->id, $shell ); ?>
							<?php if ( $row_blank ) : ?>
								<span style="color:#8a8a8a;">—</span>
							<?php elseif ( $sc === '' ) : ?>
								<em style="color:#646970;"><?php esc_html_e( 'auto-injected', 'emblem-studio' ); ?></em>
							<?php else : ?>
								<code class="es-copy"
								      data-es-copy="<?php echo esc_attr( $sc ); ?>"
								      title="<?php esc_attr_e( 'Click to copy', 'emblem-studio' ); ?>"><?php echo esc_html( $sc ); ?></code>
							<?php endif; ?>
						</td>
						<td class="col-status"><span class="es-status-pill <?php echo esc_attr( $status_class ); ?>"<?php echo $status_title ? ' title="' . esc_attr( $status_title ) . '"' : ''; ?>><?php echo esc_html( $status_label ); ?></span></td>
						<td class="col-stat"><?php echo $k ? (int) $k['page_views'] : '—'; ?></td>
						<td class="col-stat"><?php echo $shell === 'popup-click' ? (int) $k['trigger_views'] : '<span style="color:#8a8a8a;">—</span>'; ?></td>
						<td class="col-stat"><?php echo $k ? (int) $k['views'] : '—'; ?></td>
						<td class="col-stat"><?php echo $k ? (int) ( $k['unique_visitors'] ?? 0 ) : '—'; ?></td>
						<td class="col-stat es-col-conv"><?php echo ! $k ? '—' : ( $has_form ? (int) $k['conversions'] : '<span style="color:#8a8a8a;">—</span>' ); ?></td>
						<td class="col-stat-narrow es-col-conv"><?php echo ! $k ? '—' : ( $has_form ? ES_Reports::rate_pair_html( (float) $k['conv_rate'], (float) $k['conv_rate_unique'], __( 'Conversion rate vs asset views — of the times shown, the share that converted', 'emblem-studio' ), __( 'Conversion rate vs unique visitors — the share of people who converted', 'emblem-studio' ) ) : '<span style="color:#8a8a8a;">—</span>' ); ?></td>
						<td class="col-stat es-col-conv"><?php echo ! $k ? '—' : ( $has_form ? (int) $k['dropoffs'] : '<span style="color:#8a8a8a;">—</span>' ); ?></td>
						<td class="col-stat-narrow es-col-conv"><?php echo ! $k ? '—' : ( $has_form ? ES_Reports::rate_pair_html( (float) $k['dropoff_rate_views'], (float) $k['dropoff_rate_unique'], __( 'Drop-off rate vs asset views — of the times shown, the share that started then left', 'emblem-studio' ), __( 'Drop-off rate vs unique visitors — the share of people who started then left', 'emblem-studio' ) ) : '<span style="color:#8a8a8a;">—</span>' ); ?></td>
						<?php
						// Clicks + click rate. Per-form click tracking can be off
						// (the track_clicks toggle); when it is there's no data, so
						// show an em dash rather than a misleading 0.
						$clicks_tracked = ! empty( $f->track_clicks );
						?>
						<td class="col-stat es-col-click"><?php
							echo ! $k ? '—' : ( $clicks_tracked ? (int) $k['meaningful_clicks'] : '<span style="color:#8a8a8a;">—</span>' );
						?></td>
						<td class="col-stat-narrow es-col-click"><?php
							echo ! $k ? '—' : ( $clicks_tracked ? ES_Reports::rate_pair_html( (float) $k['click_rate'], (float) $k['click_rate_unique'], __( 'Click rate vs asset views — engaged clicks per time shown', 'emblem-studio' ), __( 'Click rate vs unique visitors — engaged clicks per person (can exceed 100%)', 'emblem-studio' ) ) : '<span style="color:#8a8a8a;">—</span>' );
						?></td>
						<td class="col-stat"><?php
							echo ! $k ? '—' : ( (int) $k['bot_filtered'] > 0 ? esc_html( number_format_i18n( (int) $k['bot_filtered'] ) ) : '<span style="color:#8a8a8a;">—</span>' );
						?></td>
						<td class="col-stat"><?php
							// Honeypot blocks only make sense when the asset has a
							// <form>; non-form assets get em-dash regardless.
							if ( ! $k ) {
								echo '—';
							} elseif ( ! $has_form ) {
								echo '<span style="color:#8a8a8a;">—</span>';
							} else {
								$hp_n = (int) $k['honeypot_blocked'];
								echo $hp_n > 0 ? esc_html( number_format_i18n( $hp_n ) ) : '<span style="color:#8a8a8a;">—</span>';
							}
						?></td>
						<?php if ( $first_row ) : ?>
							<td class="col-date" rowspan="<?php echo (int) $row_count; ?>"><?php echo esc_html( $updated_str ); ?></td>
							<td class="col-actions" rowspan="<?php echo (int) $row_count; ?>"><?php echo $actions_html; ?></td>
						<?php endif; ?>
					</tr>
				<?php $first_row = false; endforeach; ?>
				<tr class="es-form-spacer-row" aria-hidden="true"><td colspan="99"></td></tr>
			</tbody>
			<?php endforeach; ?>
		<?php endif; ?>
	</table>
	</div>

		<div class="tablenav bottom">
			<div class="alignleft actions bulkactions">
				<label for="es-bulk-action-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'emblem-studio' ); ?></label>
				<select name="bulk_action_bottom" id="es-bulk-action-bottom" onchange="var t=document.getElementById('es-bulk-action-top'); if(t){t.value=this.value;}">
					<?php foreach ( $bulk_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'emblem-studio' ); ?>">
			</div>
			<br class="clear">
		</div>
	</form>
</div>
