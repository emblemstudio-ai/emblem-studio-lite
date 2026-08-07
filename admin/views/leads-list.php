<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$forms   = ES_Form_Repo::all();
$filters = ES_Leads::parse_filters( $_GET );
$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

$result = ES_Leads::paginated( $paged, $filters );
$rows   = $result['rows'];
$total  = $result['total'];
$pages  = max( 1, (int) ceil( $total / ES_Leads::PER_PAGE ) );

// Consent for everything on this page, in one query rather than one per row.
// Shown in each row's detail panel — recording proof nobody can see isn't
// proof, and the point is showing the wording someone agreed to at the time,
// not the wording the form happens to carry today.
$consents = ES_Consent_Log::for_leads( array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) ) );

// Per-tab counts honor every active filter EXCEPT kind, so the badge
// shows "if you flipped tabs, this is what you'd see".
$counts = ES_Leads::counts_by_kind( $filters );

// Has any filter been applied? Used to gate the "Clear filters" link
// and to switch the export button label between "Export all" / "Export filtered."
$has_active_filter = $filters['form_id'] || $filters['from'] || $filters['to'] || $filters['email'] || ( $filters['status'] && $filters['status'] !== 'all' );

$base_url = admin_url( 'admin.php?page=emblem-studio-leads' );

// Build a URL that carries the current filter set (used for pagination links).
$filter_qs = array_filter( [
	'form_id' => $filters['form_id'],
	'from'    => $filters['from'],
	'to'      => $filters['to'],
	'status'  => $filters['status'] !== 'all' ? $filters['status'] : null,
	'email'   => $filters['email'],
	'kind'    => $filters['kind'] !== 'humans' ? $filters['kind'] : null,
], fn( $v ) => $v !== null && $v !== '' );
$filter_url = add_query_arg( $filter_qs, $base_url );

// Export link carries the same filter — the CSV reflects what's on screen.
$export_args = array_merge( [ 'action' => 'es_export_leads' ], $filter_qs );
$export_url  = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'es_export_leads' );

$status_labels = [
	'all'     => __( 'All',     'emblem-studio' ),
	'synced'  => __( 'Synced',  'emblem-studio' ),
	'failed'  => __( 'Failed',  'emblem-studio' ),
	'pending' => __( 'Pending', 'emblem-studio' ),
];
?>
<div class="wrap emblem-studio-wrap">
	<div class="es-page-header">
		<div class="es-page-header-main">
			<span class="es-eyebrow"><span class="es-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e( 'Leads', 'emblem-studio' ); ?></span>
			<h1 class="es-page-title"><?php esc_html_e( 'Emblem Studio — Leads', 'emblem-studio' ); ?></h1>
			<p class="es-page-subtitle"><?php esc_html_e( 'Every submission captured on your site — humans, bots, and where each one went.', 'emblem-studio' ); ?></p>
		</div>
		<div class="es-page-header-actions">
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action es-action-primary">
				<?php echo $has_active_filter ? esc_html__( 'Export filtered as CSV', 'emblem-studio' ) : esc_html__( 'Export all as CSV', 'emblem-studio' ); ?>
			</a>
		</div>
	</div>
	<hr class="es-horizon" aria-hidden="true">

	<?php
	// Build href for each tab — preserve every other filter, swap the kind.
	$tab_kinds = [
		'humans' => __( 'Humans', 'emblem-studio' ),
		'bots'   => __( 'Bots',   'emblem-studio' ),
		'all'    => __( 'All',    'emblem-studio' ),
	];
	?>
	<ul class="subsubsub es-filter-pills es-leads-tabs">
		<?php foreach ( $tab_kinds as $k => $label ) :
			$kind_qs = array_filter( [
				'form_id' => $filters['form_id'],
				'from'    => $filters['from'],
				'to'      => $filters['to'],
				'status'  => $filters['status'] !== 'all' ? $filters['status'] : null,
				'email'   => $filters['email'],
				'kind'    => $k !== 'humans' ? $k : null,
			], fn( $v ) => $v !== null && $v !== '' );
			$tab_url = add_query_arg( $kind_qs, $base_url );
			$is_current = $filters['kind'] === $k;
			$count = $counts[ $k ] ?? 0;
		?>
			<li>
				<a href="<?php echo esc_url( $tab_url ); ?>"<?php echo $is_current ? ' class="current" aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
					<span class="count"><?php echo (int) $count; ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<div style="clear:both;"></div>

	<form method="get" class="es-leads-filter">
		<input type="hidden" name="page" value="emblem-studio-leads">
		<?php if ( $filters['kind'] !== 'humans' ) : ?>
			<input type="hidden" name="kind" value="<?php echo esc_attr( $filters['kind'] ); ?>">
		<?php endif; ?>

		<div class="es-leads-filter-grid">
			<div class="es-leads-filter-field">
				<label for="es-leads-form"><?php esc_html_e( 'Form', 'emblem-studio' ); ?></label>
				<select id="es-leads-form" name="form_id">
					<option value=""><?php esc_html_e( 'All forms', 'emblem-studio' ); ?></option>
					<?php foreach ( $forms as $f ) : ?>
						<option value="<?php echo (int) $f->id; ?>" <?php selected( (int) $filters['form_id'], (int) $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="es-leads-filter-field">
				<label for="es-leads-from"><?php esc_html_e( 'From', 'emblem-studio' ); ?></label>
				<input type="date" id="es-leads-from" name="from" value="<?php echo esc_attr( (string) $filters['from'] ); ?>">
			</div>

			<div class="es-leads-filter-field">
				<label for="es-leads-to"><?php esc_html_e( 'To', 'emblem-studio' ); ?></label>
				<input type="date" id="es-leads-to" name="to" value="<?php echo esc_attr( (string) $filters['to'] ); ?>">
			</div>

			<div class="es-leads-filter-field">
				<label for="es-leads-status"><?php esc_html_e( 'FunnelKit status', 'emblem-studio' ); ?></label>
				<select id="es-leads-status" name="status">
					<?php foreach ( $status_labels as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="es-leads-filter-field es-leads-filter-email">
				<label for="es-leads-email"><?php esc_html_e( 'Email contains', 'emblem-studio' ); ?></label>
				<input type="search" id="es-leads-email" name="email" value="<?php echo esc_attr( (string) $filters['email'] ); ?>" placeholder="<?php esc_attr_e( 'partial match', 'emblem-studio' ); ?>">
			</div>

			<div class="es-leads-filter-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'emblem-studio' ); ?></button>
				<?php if ( $has_active_filter ) : ?>
					<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear', 'emblem-studio' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<p class="es-leads-filter-count description">
			<?php
			if ( $has_active_filter ) {
				printf(
					/* translators: %d: filtered count */
					esc_html__( '%d matching lead(s) — export reflects the active filter.', 'emblem-studio' ),
					(int) $total
				);
			} else {
				printf(
					/* translators: %d: total leads count */
					esc_html__( '%d total lead(s).', 'emblem-studio' ),
					(int) $total
				);
			}
			?>
		</p>
	</form>

	<?php if ( isset( $_GET['lead_deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lead deleted.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['lead_moved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lead moved to Humans.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['lead_move_failed'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not move lead. It may have been deleted.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['lead_retried'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Retried — check the Status column for the result.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['lead_retry_failed'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not retry lead. It may have been deleted.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped es-data-table">
		<thead>
			<tr>
				<th style="width:18%"><?php esc_html_e( 'Name', 'emblem-studio' ); ?></th>
				<th style="width:22%"><?php esc_html_e( 'Email', 'emblem-studio' ); ?></th>
				<th style="width:18%"><?php esc_html_e( 'Form', 'emblem-studio' ); ?></th>
				<th style="width:11%"><?php esc_html_e( 'Status', 'emblem-studio' ); ?></th>
				<th style="width:17%"><?php esc_html_e( 'Optin date', 'emblem-studio' ); ?></th>
				<th style="width:8%"></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rows ) ) : ?>
			<tr><td colspan="6"><em><?php esc_html_e( 'No leads match the current filter.', 'emblem-studio' ); ?></em></td></tr>
		<?php else : ?>
			<?php foreach ( $rows as $r ) :
				$is_bot_row = ! empty( $r->is_bot );
				if ( $is_bot_row ) {
					$status_class = 'es-lead-bot';
					$status_text  = __( 'Bot — blocked', 'emblem-studio' );
				} elseif ( $r->funnelkit_pushed ) {
					$status_class = 'es-lead-synced';
					$status_text  = __( 'Synced', 'emblem-studio' );
				} elseif ( ! empty( $r->error ) ) {
					$status_class = 'es-lead-failed';
					$status_text  = __( 'Failed', 'emblem-studio' );
				} else {
					$status_class = 'es-lead-pending';
					$status_text  = __( 'Pending', 'emblem-studio' );
				}
				$row_class = $is_bot_row ? 'es-lead-row-bot' : '';
				$row_title = $r->error
					? esc_attr( $r->error )
					: ( $is_bot_row ? esc_attr__( 'Honeypot field was filled — submission blocked. Not sent to FunnelKit or webhooks.', 'emblem-studio' ) : '' );
			?>
				<tr<?php echo $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?><?php echo $row_title ? ' title="' . $row_title . '"' : ''; ?>>
					<td>
						<?php if ( $is_bot_row ) : ?><span class="es-bot-tag" aria-label="<?php esc_attr_e( 'Bot lead', 'emblem-studio' ); ?>" title="<?php esc_attr_e( 'Caught by the hidden honeypot field — not sent to your CRM.', 'emblem-studio' ); ?>"><?php esc_html_e( 'BOT', 'emblem-studio' ); ?></span> <?php endif; ?>
						<button type="button" class="es-lead-toggle" aria-expanded="false" aria-controls="es-lead-detail-<?php echo (int) $r->id; ?>">
							<?php echo esc_html( ES_Leads::display_name( $r->extra_fields ) ); ?>
						</button>
					</td>
					<td><?php echo esc_html( $r->email ); ?></td>
					<td class="es-leads-col-form">
						<?php if ( $r->form_id ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'form_id', (int) $r->form_id, $base_url ) ); ?>"><?php echo esc_html( $r->form_name ); ?></a>
						<?php else : ?>
							<em><?php esc_html_e( '— deleted —', 'emblem-studio' ); ?></em>
						<?php endif; ?>
					</td>
					<td>
						<span class="es-lead-status-pill <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span>
						<?php if ( $is_bot_row ) : ?>
							<div class="es-lead-bot-note"><?php esc_html_e( 'Not sent to CRM', 'emblem-studio' ); ?><?php if ( ! empty( $r->ip ) ) : ?> · <code><?php echo esc_html( (string) $r->ip ); ?></code><?php endif; ?></div>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r->created_at ) ); ?></td>
					<td>
						<?php if ( $is_bot_row ) :
							$move_with_push    = wp_nonce_url( admin_url( 'admin-post.php?action=es_move_lead_to_humans&id=' . (int) $r->id . '&push=1' ), 'es_move_lead_to_humans_' . (int) $r->id );
							$move_without_push = wp_nonce_url( admin_url( 'admin-post.php?action=es_move_lead_to_humans&id=' . (int) $r->id . '&push=0' ), 'es_move_lead_to_humans_' . (int) $r->id );
						?>
							<a href="<?php echo esc_url( $move_with_push ); ?>"
							   onclick="return confirm('<?php esc_attr_e( 'Move this lead to Humans AND push to FunnelKit/webhooks now?', 'emblem-studio' ); ?>');"
							   title="<?php esc_attr_e( 'Move to Humans and trigger your CRM/webhooks (e.g. to deliver a missed freebie).', 'emblem-studio' ); ?>"><?php esc_html_e( 'Move + push', 'emblem-studio' ); ?></a>
							<br>
							<a href="<?php echo esc_url( $move_without_push ); ?>"
							   onclick="return confirm('<?php esc_attr_e( 'Move this lead to Humans without pushing to FunnelKit/webhooks?', 'emblem-studio' ); ?>');"
							   style="color:#666;font-size:11px;"><?php esc_html_e( 'Move only', 'emblem-studio' ); ?></a>
							<br>
						<?php elseif ( ! $r->funnelkit_pushed ) :
							$retry_url = wp_nonce_url( admin_url( 'admin-post.php?action=es_retry_lead&id=' . (int) $r->id ), 'es_retry_lead_' . (int) $r->id );
						?>
							<a href="<?php echo esc_url( $retry_url ); ?>"
							   title="<?php esc_attr_e( 'Try sending this lead to FunnelKit/webhooks again.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Retry', 'emblem-studio' ); ?></a>
							<br>
						<?php endif; ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=es_delete_lead&id=' . (int) $r->id ), 'es_delete_lead_' . (int) $r->id ) ); ?>"
						   onclick="return confirm('<?php esc_attr_e( 'Permanently delete this lead from Emblem Studio? (Contact in FunnelKit is not affected.)', 'emblem-studio' ); ?>');"
						   style="color:var(--es-danger,#b32d2e);"><?php esc_html_e( 'Delete', 'emblem-studio' ); ?></a>
					</td>
				</tr>
				<?php
				// The full submission. Everything here is already loaded for the
				// row above, so opening one costs no extra request — it just
				// unhides. The list only ever showed a derived display name, so
				// every answer beyond name/email was invisible in the admin.
				$detail_extra = $r->extra_fields ? json_decode( $r->extra_fields, true ) : [];
				$detail_extra = is_array( $detail_extra ) ? $detail_extra : [];
				$detail_consent = $consents[ (int) $r->id ] ?? null;
				?>
				<tr id="es-lead-detail-<?php echo (int) $r->id; ?>" class="es-lead-detail" hidden>
					<td colspan="6">
						<div class="es-lead-detail-grid">
							<div>
								<h4><?php esc_html_e( 'Submitted fields', 'emblem-studio' ); ?></h4>
								<?php if ( $detail_extra ) : ?>
									<dl class="es-lead-fields">
										<?php foreach ( $detail_extra as $key => $value ) : ?>
											<dt><?php echo esc_html( ucwords( str_replace( [ '_', '-' ], ' ', (string) $key ) ) ); ?></dt>
											<dd><?php echo esc_html( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value ); ?></dd>
										<?php endforeach; ?>
									</dl>
								<?php else : ?>
									<p class="es-lead-detail-none"><?php esc_html_e( 'Only an email address was captured.', 'emblem-studio' ); ?></p>
								<?php endif; ?>
							</div>

							<div>
								<h4><?php esc_html_e( 'Delivery', 'emblem-studio' ); ?></h4>
								<dl class="es-lead-fields">
									<dt><?php esc_html_e( 'FunnelKit', 'emblem-studio' ); ?></dt>
									<dd>
										<?php
										if ( $r->funnelkit_pushed ) {
											echo esc_html(
												$r->funnelkit_contact_id
													/* translators: %d: FunnelKit contact id. */
													? sprintf( __( 'Synced (contact #%d)', 'emblem-studio' ), (int) $r->funnelkit_contact_id )
													: __( 'Synced', 'emblem-studio' )
											);
										} else {
											echo esc_html( $r->error ? $r->error : __( 'Pending', 'emblem-studio' ) );
										}
										?>
									</dd>
									<?php if ( ! empty( $r->ip ) ) : ?>
										<dt><?php esc_html_e( 'IP', 'emblem-studio' ); ?></dt>
										<dd><code><?php echo esc_html( (string) $r->ip ); ?></code></dd>
									<?php endif; ?>
								</dl>

								<h4><?php esc_html_e( 'Consent', 'emblem-studio' ); ?></h4>
								<?php if ( null === $detail_consent ) : ?>
									<p class="es-lead-detail-none"><?php esc_html_e( 'This form had no consent checkbox, so there is nothing recorded. That is not the same as someone declining.', 'emblem-studio' ); ?></p>
								<?php else : ?>
									<p class="es-lead-consent-state <?php echo $detail_consent['agreed'] ? 'is-agreed' : 'is-declined'; ?>">
										<?php echo esc_html( $detail_consent['agreed'] ? __( 'Agreed at the time of submitting', 'emblem-studio' ) : __( 'Not ticked', 'emblem-studio' ) ); ?>
									</p>
									<?php if ( '' !== $detail_consent['text'] ) : ?>
										<blockquote class="es-lead-consent-text"><?php echo esc_html( $detail_consent['text'] ); ?></blockquote>
									<?php endif; ?>
									<p class="es-lead-detail-none"><?php esc_html_e( 'This is the exact wording shown when they submitted, kept unchanged even if the form was edited since.', 'emblem-studio' ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php printf( esc_html__( 'Page %1$d of %2$d', 'emblem-studio' ), (int) $paged, (int) $pages ); ?></span>
				<span class="pagination-links">
					<?php
					$prev  = $paged > 1 ? add_query_arg( 'paged', $paged - 1, $filter_url ) : '#';
					$next  = $paged < $pages ? add_query_arg( 'paged', $paged + 1, $filter_url ) : '#';
					$first = add_query_arg( 'paged', 1, $filter_url );
					$last  = add_query_arg( 'paged', $pages, $filter_url );
					?>
					<a class="button <?php echo $paged <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url( $first ); ?>">«</a>
					<a class="button <?php echo $paged <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url( $prev ); ?>">‹</a>
					<a class="button <?php echo $paged >= $pages ? 'disabled' : ''; ?>" href="<?php echo esc_url( $next ); ?>">›</a>
					<a class="button <?php echo $paged >= $pages ? 'disabled' : ''; ?>" href="<?php echo esc_url( $last ); ?>">»</a>
				</span>
			</div>
		</div>
	<?php endif; ?>
</div>
