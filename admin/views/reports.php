<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$forms = ES_Form_Repo::all();
$tz    = wp_timezone();
$today = new DateTimeImmutable( 'now', $tz );

// "All time" starts at the first real data point, not a hardcoded epoch —
// so the From field reads e.g. 2026-05-20 instead of a confusing 2000-01-01.
// Falls back to a 2-week window when there's no data yet.
$first_event = ES_Reports::first_event_date();
$all_start   = $first_event
	? new DateTimeImmutable( $first_event . ' 00:00:00', $tz )
	: $today->modify( '-13 days' );

/**
 * Each preset: [ label, from-DateTimeImmutable, to-DateTimeImmutable, granularity ]
 */
$presets = [
	'last24h'    => [ __( 'Last 24h', 'emblem-studio' ),           $today->modify( '-24 hours' ), $today, 'hour' ],
	'yesterday'  => [ __( 'Yesterday', 'emblem-studio' ),          $today->modify( '-1 day' )->setTime( 0, 0, 0 ), $today->modify( '-1 day' )->setTime( 23, 59, 59 ), 'hour' ],
	'last7'      => [ __( 'Last 7 days', 'emblem-studio' ),        $today->modify( '-6 days' ),  $today, 'day' ],
	'last14'     => [ __( 'Last 2 weeks', 'emblem-studio' ),       $today->modify( '-13 days' ), $today, 'day' ],
	'last30'     => [ __( 'Last 30 days', 'emblem-studio' ),       $today->modify( '-29 days' ), $today, 'day' ],
	'last90'     => [ __( 'Last 90 days', 'emblem-studio' ),       $today->modify( '-89 days' ), $today, 'day' ],
	'this_month' => [ __( 'This month', 'emblem-studio' ),         $today->modify( 'first day of this month' ), $today, 'day' ],
	'last_month' => [ __( 'Last calendar month', 'emblem-studio' ), $today->modify( 'first day of last month' ), $today->modify( 'last day of last month' ), 'day' ],
	'last365'    => [ __( 'Last 365 days', 'emblem-studio' ),      $today->modify( '-364 days' ), $today, 'day' ],
	'ytd'        => [ __( 'Year to date', 'emblem-studio' ),       new DateTimeImmutable( $today->format( 'Y' ) . '-01-01', $tz ), $today, 'day' ],
	'last_year'  => [ __( 'Last calendar year', 'emblem-studio' ), new DateTimeImmutable( ( (int) $today->format( 'Y' ) - 1 ) . '-01-01', $tz ), new DateTimeImmutable( ( (int) $today->format( 'Y' ) - 1 ) . '-12-31', $tz ), 'day' ],
	'all'        => [ __( 'All time', 'emblem-studio' ),           $all_start, $today, 'day' ],
];

$form_id   = isset( $_GET['form_id'] ) && (int) $_GET['form_id'] > 0 ? (int) $_GET['form_id'] : 0;
$shell     = isset( $_GET['shell'] ) ? sanitize_key( $_GET['shell'] ) : '';
$valid_shells = ES_Form_Repo::valid_types();
if ( $shell && ! in_array( $shell, $valid_shells, true ) ) {
	$shell = '';
}
$range_key = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '';
$has_custom = isset( $_GET['from'] ) || isset( $_GET['to'] );

if ( $has_custom ) {
	$from_str = sanitize_text_field( $_GET['from'] ?? $today->modify( '-13 days' )->format( 'Y-m-d' ) );
	$to_str   = sanitize_text_field( $_GET['to']   ?? $today->format( 'Y-m-d' ) );
	$from_dt  = new DateTimeImmutable( $from_str . ' 00:00:00', $tz );
	$to_dt    = new DateTimeImmutable( $to_str   . ' 23:59:59', $tz );
	$granularity = 'day';
	$active_preset = '';
} elseif ( $range_key && isset( $presets[ $range_key ] ) ) {
	[ , $from_dt, $to_dt, $granularity ] = $presets[ $range_key ];
	$active_preset = $range_key;
} else {
	[ , $from_dt, $to_dt, $granularity ] = $presets['last14'];
	$active_preset = 'last14';
}

$from_sql = $from_dt->format( 'Y-m-d H:i:s' );
$to_sql   = $to_dt->format( 'Y-m-d H:i:s' );

if ( $form_id ) {
	$kpis = ES_Reports::kpis( $form_id, $from_sql, $to_sql, $shell ?: null );
} else {
	$agg  = ES_Reports::aggregate_kpis( $from_sql, $to_sql );
	$kpis = [
		'page_views'    => $agg['page_views'],
		'trigger_views' => 0,
		'views'         => $agg['views'],
		'engagements'   => $agg['engagements'],
		'conversions'   => $agg['conversions'],
		'bot_filtered'  => $agg['bot_filtered'],
		'view_rate'     => $agg['view_rate'],
		'conv_rate'     => $agg['conv_rate'],
		'conv_rate_pv'  => $agg['page_views'] > 0 ? round( $agg['conversions'] / $agg['page_views'] * 100, 2 ) : 0.0,
		'dismissals'    => $agg['dismissals'],
		'close_rate'    => $agg['close_rate'],
		'ctr'           => 0,
	];
}

// Prior period of equal length, immediately before the current one — for the
// "vs previous period" deltas on the headline numbers.
$span_secs = max( 1, $to_dt->getTimestamp() - $from_dt->getTimestamp() );
$prev_to   = $from_dt->modify( '-1 second' );
$prev_from = $from_dt->modify( '-' . ( $span_secs + 1 ) . ' seconds' );
if ( $form_id ) {
	$prev = ES_Reports::kpis( $form_id, $prev_from->format( 'Y-m-d H:i:s' ), $prev_to->format( 'Y-m-d H:i:s' ), $shell ?: null );
} else {
	$prev = ES_Reports::aggregate_kpis( $prev_from->format( 'Y-m-d H:i:s' ), $prev_to->format( 'Y-m-d H:i:s' ) );
}

$ts        = ES_Reports::timeseries( $form_id ?: null, $from_sql, $to_sql, $granularity, $shell ?: null );
$bot_break = ES_Reports::bot_breakdown( $form_id ?: null, $from_sql, $to_sql, $shell ?: null );
$device_break = ES_Reports::device_breakdown( $form_id ?: null, $from_sql, $to_sql, $shell ?: null );
$page_break   = ES_Reports::page_breakdown( $form_id ?: null, $from_sql, $to_sql, $shell ?: null );
$visitor_stats = ES_Reports::visitor_stats( $form_id ?: null, $from_sql, $to_sql, $shell ?: null );

$device_labels = [
	'mobile'  => __( 'Mobile', 'emblem-studio' ),
	'tablet'  => __( 'Tablet', 'emblem-studio' ),
	'desktop' => __( 'Desktop', 'emblem-studio' ),
	'unknown' => __( 'Unknown', 'emblem-studio' ),
];
$device_total = 0;
foreach ( $device_break as $d ) { $device_total += (int) $d['views']; }

// --- Layout + tier preview --------------------------------------------------
// One reports page; each card shows real content or a locked teaser depending on
// the viewer's tier (catalogue rule #5). These switchers are PREVIEW scaffolding
// until real accounts/billing exist — the plugin's true tier is "Lite".
$design = ( isset( $_GET['design'] ) && $_GET['design'] === 'grid' ) ? 'grid' : 'journey';
// The Lite build's real tier IS Lite — lock it (advanced cards become upsell
// teasers via $es_unlocked below). The full plugin keeps the preview switcher
// (fake until real accounts/billing exist).
$tier = ES_Caps::is_lite()
	? 'lite'
	: ( ( isset( $_GET['tier'] ) && in_array( $_GET['tier'], [ 'lite', 'free', 'paid' ], true ) ) ? $_GET['tier'] : 'paid' );
$tier_rank  = [ 'lite' => 0, 'free' => 1, 'paid' => 2 ];
$tier_label = [ 'lite' => __( 'Lite', 'emblem-studio' ), 'free' => __( 'Free', 'emblem-studio' ), 'paid' => __( 'Paid', 'emblem-studio' ) ];
$es_unlocked = static function ( $required ) use ( $tier, $tier_rank ) {
	return $tier_rank[ $tier ] >= $tier_rank[ $required ];
};

$scope_label = $form_id
	? esc_html__( 'Single form', 'emblem-studio' )
	: esc_html__( 'All forms (aggregated)', 'emblem-studio' );

$range_label = $granularity === 'hour'
	? sprintf( '%s → %s · %s', $from_dt->format( 'Y-m-d H:i' ), $to_dt->format( 'Y-m-d H:i' ), esc_html__( 'hourly', 'emblem-studio' ) )
	: sprintf( '%s → %s · %s', $from_dt->format( 'Y-m-d' ), $to_dt->format( 'Y-m-d' ), esc_html__( 'daily', 'emblem-studio' ) );

$base_args = [ 'page' => 'emblem-studio-reports' ];
if ( $form_id ) { $base_args['form_id'] = $form_id; }

// Current query state, so switcher links preserve form/shell/range/dates.
$cur_args = $base_args;
if ( $shell )       { $cur_args['shell'] = $shell; }
if ( $active_preset ) { $cur_args['range'] = $active_preset; }
if ( $has_custom )  { $cur_args['from'] = $from_dt->format( 'Y-m-d' ); $cur_args['to'] = $to_dt->format( 'Y-m-d' ); }
$cur_args['design'] = $design;
$cur_args['tier']   = $tier;
$switch_url = static function ( array $overrides ) use ( $cur_args ) {
	return esc_url( add_query_arg( array_merge( $cur_args, $overrides ), admin_url( 'admin.php' ) ) );
};

// Delta sub-line: up/down % vs the previous period. $invert flips good/bad
// colour (a rising close-rate is bad).
$es_delta = static function ( $cur, $prev_val, $invert = false ) {
	$cur = (float) $cur; $prev_val = (float) $prev_val;
	if ( $prev_val == 0.0 ) {
		if ( $cur == 0.0 ) { return ''; }
		$up = true; $pct = 100;
	} else {
		$dd  = ( $cur - $prev_val ) / $prev_val * 100;
		$up  = $dd >= 0; $pct = abs( (int) round( $dd ) );
	}
	$good  = $invert ? ! $up : $up;
	$cls   = $good ? 'es-kpi-delta-up' : 'es-kpi-delta-down';
	$arrow = $up ? '↑' : '↓';
	return '<div class="es-kpi-delta ' . $cls . '">' . esc_html( $arrow . ' ' . $pct . '% ' )
		. '<span class="es-rep-vs">' . esc_html__( 'vs prev', 'emblem-studio' ) . '</span></div>';
};

// --- Card renderers (defined once, arranged differently per layout) ---------
$render_funnel = static function () use ( $kpis ) {
	$steps = [
		[ 'label' => __( 'Reached', 'emblem-studio' ),   'n' => (int) $kpis['page_views'],  'cls' => 'r' ],
		[ 'label' => __( 'Shown', 'emblem-studio' ),     'n' => (int) $kpis['views'],       'cls' => 's' ],
		[ 'label' => __( 'Engaged', 'emblem-studio' ),   'n' => (int) ( $kpis['engagements'] ?? 0 ), 'cls' => 'e' ],
		[ 'label' => __( 'Converted', 'emblem-studio' ), 'n' => (int) $kpis['conversions'], 'cls' => 'c' ],
	];
	$top = max( 1, $steps[0]['n'] );
	?>
	<div class="es-rep-card">
		<div class="es-rep-card-head">
			<h2><?php esc_html_e( 'The journey', 'emblem-studio' ); ?></h2>
			<span class="es-rep-card-sub"><?php esc_html_e( 'where people drop off', 'emblem-studio' ); ?></span>
		</div>
		<div class="es-rep-funnel">
			<?php foreach ( $steps as $i => $st ) :
				$pct_top = (int) round( $st['n'] / $top * 100 );
				$of_prev = ( $i > 0 && $steps[ $i - 1 ]['n'] > 0 ) ? (int) round( $st['n'] / $steps[ $i - 1 ]['n'] * 100 ) : null;
			?>
				<div class="es-rep-funnel-row">
					<div class="es-rep-funnel-top">
						<span class="es-rep-funnel-label"><?php echo esc_html( $st['label'] ); ?></span>
						<span class="es-rep-funnel-n">
							<?php echo esc_html( number_format_i18n( $st['n'] ) ); ?>
							<?php if ( $of_prev !== null ) : ?>
								<span class="es-rep-vs">· <?php echo esc_html( (string) $of_prev ); ?>% <?php echo esc_html( sprintf( __( 'of %s', 'emblem-studio' ), strtolower( $steps[ $i - 1 ]['label'] ) ) ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="es-rep-funnel-bar"><span class="es-rep-fill es-rep-fill-<?php echo esc_attr( $st['cls'] ); ?>" style="width:<?php echo esc_attr( (string) max( 2, $pct_top ) ); ?>%"></span></div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
};

$render_chart = static function () use ( $ts ) {
	?>
	<div class="es-rep-card">
		<div class="es-rep-card-head">
			<h2><?php esc_html_e( 'Over time', 'emblem-studio' ); ?></h2>
			<span class="es-rep-card-sub"><?php esc_html_e( 'page views · shown · conversions', 'emblem-studio' ); ?></span>
		</div>
		<div class="es-chart" data-es-chart='[{"key":"page_views","label":"Page views","color":"#9ca3af","area":"rgba(156,163,175,0.10)"},{"key":"views","label":"Shown","color":"#E27D40","area":"rgba(226,125,64,0.12)"},{"key":"conversions","label":"Conversions","color":"#16a34a","area":"rgba(22,163,74,0.10)"}]'></div>
		<script type="application/json" id="es-reports-data"><?php echo wp_json_encode( $ts, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
	</div>
	<?php
};

$render_device = static function () use ( $device_break, $device_total, $device_labels ) {
	?>
	<div class="es-rep-card">
		<div class="es-rep-card-head">
			<h2><?php esc_html_e( 'By device', 'emblem-studio' ); ?></h2>
			<span class="es-rep-card-sub"><?php esc_html_e( 'views in range', 'emblem-studio' ); ?></span>
		</div>
		<?php if ( 0 === $device_total ) : ?>
			<p class="es-rep-empty"><?php esc_html_e( 'No device data yet — it builds up from now on.', 'emblem-studio' ); ?></p>
		<?php else : ?>
			<div class="es-rep-bars">
				<?php foreach ( $device_break as $d ) :
					$d_pct = (int) round( (int) $d['views'] / max( 1, $device_total ) * 100 );
					$d_cr  = (int) $d['views'] > 0 ? round( (int) $d['conversions'] / (int) $d['views'] * 100, 1 ) : 0.0;
				?>
					<div class="es-rep-bar-row">
						<div class="es-rep-bar-top">
							<span><?php echo esc_html( $device_labels[ $d['device'] ] ?? $d['device'] ); ?></span>
							<span class="es-rep-vs"><?php echo esc_html( number_format_i18n( (int) $d['views'] ) ); ?> · <?php echo esc_html( (string) $d_pct ); ?>% ·<?php echo esc_html( number_format_i18n( $d_cr, 1 ) ); ?>% <?php esc_html_e( 'conv', 'emblem-studio' ); ?></span>
						</div>
						<div class="es-rep-bar"><span style="width:<?php echo esc_attr( (string) $d_pct ); ?>%"></span></div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
};

$render_page = static function () use ( $page_break ) {
	?>
	<div class="es-rep-card">
		<div class="es-rep-card-head">
			<h2><?php esc_html_e( 'By page', 'emblem-studio' ); ?></h2>
			<span class="es-rep-card-sub"><?php esc_html_e( 'top pages by views', 'emblem-studio' ); ?></span>
		</div>
		<?php if ( empty( $page_break ) ) : ?>
			<p class="es-rep-empty"><?php esc_html_e( 'No per-page data yet — it builds up from now on.', 'emblem-studio' ); ?></p>
		<?php else : ?>
			<div class="es-rep-rows">
				<?php foreach ( $page_break as $p ) : ?>
					<div class="es-rep-row">
						<span class="es-rep-row-label">
							<?php if ( ! empty( $p['post_id'] ) ) : ?>
								<a href="<?php echo esc_url( get_permalink( (int) $p['post_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $p['label'] ); ?>
							<?php endif; ?>
						</span>
						<span class="es-rep-vs"><?php echo esc_html( number_format_i18n( (int) $p['views'] ) ); ?> <?php esc_html_e( 'views', 'emblem-studio' ); ?> · <strong><?php echo esc_html( number_format_i18n( (float) $p['conv_rate'], 1 ) ); ?>%</strong></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
};

$render_bot = static function () use ( $bot_break ) {
	?>
	<div class="es-rep-card" id="bots">
		<div class="es-rep-card-head">
			<h2><?php esc_html_e( 'Bot traffic filtered', 'emblem-studio' ); ?></h2>
			<span class="es-rep-card-sub"><?php esc_html_e( 'kept out of the numbers above', 'emblem-studio' ); ?></span>
		</div>
		<?php if ( empty( $bot_break['total'] ) ) : ?>
			<p class="es-rep-empty"><?php esc_html_e( 'No bot traffic recorded in this range.', 'emblem-studio' ); ?></p>
		<?php else :
			$bb_total = max( 1, (int) $bot_break['total'] );
		?>
			<p class="es-rep-card-note">
				<strong><?php echo esc_html( number_format_i18n( (int) $bot_break['total'] ) ); ?></strong>
				<?php esc_html_e( 'bot hits caught and counted separately.', 'emblem-studio' ); ?>
			</p>
			<div class="es-bot-cats">
				<?php foreach ( $bot_break['categories'] as $cat_i => $cat ) :
					$cat_pct = round( $cat['total'] / $bb_total * 100, 1 );
				?>
					<details class="es-bot-cat"<?php echo 0 === $cat_i ? ' open' : ''; ?>>
						<summary>
							<span class="es-bot-cat-name"><?php echo esc_html( $cat['category'] ); ?></span>
							<span class="es-bot-cat-bar"><span style="width:<?php echo esc_attr( (string) $cat_pct ); ?>%"></span></span>
							<span class="es-bot-cat-count"><?php echo esc_html( number_format_i18n( (int) $cat['total'] ) ); ?> <span class="es-bot-pct"><?php echo esc_html( (string) $cat_pct ); ?>%</span></span>
						</summary>
						<table class="es-bot-table">
							<tbody>
								<?php foreach ( $cat['bots'] as $b ) :
									$b_pct = round( $b['count'] / $bb_total * 100, 1 );
								?>
									<tr>
										<td class="es-bot-name"><?php echo esc_html( $b['label'] ); ?></td>
										<td class="es-bot-count"><?php echo esc_html( number_format_i18n( (int) $b['count'] ) ); ?></td>
										<td class="es-bot-pct-cell"><?php echo esc_html( (string) $b_pct ); ?>%</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endforeach; ?>
				<?php if ( ! empty( $bot_break['unattributed'] ) ) :
					$u_pct = round( $bot_break['unattributed'] / $bb_total * 100, 1 );
				?>
					<div class="es-bot-cat es-bot-unattributed">
						<span class="es-bot-cat-name"><?php esc_html_e( 'Unattributed', 'emblem-studio' ); ?></span>
						<span class="es-bot-cat-count"><?php echo esc_html( number_format_i18n( (int) $bot_break['unattributed'] ) ); ?> <span class="es-bot-pct"><?php echo esc_html( (string) $u_pct ); ?>%</span></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
};

// Gate wrapper: render real card if the tier unlocks it, else a teaser.
$render_visitors = static function () use ( $visitor_stats ) {
	$cells = [
		[ __( 'Unique visitors', 'emblem-studio' ), (int) $visitor_stats['unique_visitors'] ],
		[ __( 'New', 'emblem-studio' ),             (int) $visitor_stats['new_visitors'] ],
		[ __( 'Returning', 'emblem-studio' ),       (int) $visitor_stats['returning_visitors'] ],
		[ __( 'Unknown', 'emblem-studio' ),         (int) $visitor_stats['unknown_views'] ],
	];
	?>
	<div class="es-rep-card">
		<div class="es-rep-card-head">
			<h2><?php esc_html_e( 'Visitors', 'emblem-studio' ); ?></h2>
			<span class="es-rep-card-sub"><?php esc_html_e( 'this period · anonymous, no IP · ~7-day estimate', 'emblem-studio' ); ?></span>
		</div>
		<div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px;">
			<?php foreach ( $cells as $c ) : ?>
				<div>
					<div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#9aa0b0;"><?php echo esc_html( $c[0] ); ?></div>
					<div style="font-family:var(--es-font-display); font-size:22px; font-weight:700; color:#22273f; font-variant-numeric:tabular-nums; margin-top:2px;"><?php echo esc_html( number_format_i18n( $c[1] ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
};

$render_gated = static function ( $required, $title, callable $render ) use ( $es_unlocked, $tier_label ) {
	if ( $es_unlocked( $required ) ) { $render(); return; }
	$msg = $required === 'free'
		? __( 'Create a free account to unlock', 'emblem-studio' )
		: __( 'Upgrade to unlock', 'emblem-studio' );
	$sub = $required === 'free'
		? __( 'Free with a linked account.', 'emblem-studio' )
		: __( 'Part of a paid plan.', 'emblem-studio' );
	?>
	<div class="es-rep-card es-rep-locked">
		<div class="es-rep-card-head">
			<h2 class="es-rep-locked-title"><?php echo esc_html( $title ); ?></h2>
			<span class="es-rep-tier-badge es-rep-tier-<?php echo esc_attr( $required ); ?>"><?php echo esc_html( $tier_label[ $required ] ); ?></span>
		</div>
		<div class="es-rep-lock-body">
			<span class="es-rep-lock-ico" aria-hidden="true">🔒</span>
			<span class="es-rep-lock-msg"><?php echo esc_html( $msg ); ?></span>
			<span class="es-rep-vs"><?php echo esc_html( $sub ); ?></span>
		</div>
	</div>
	<?php
};
?>
<div class="wrap emblem-studio-wrap">
	<div class="es-page-header">
		<div class="es-page-header-main">
			<span class="es-eyebrow"><span class="es-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e( 'Reports', 'emblem-studio' ); ?></span>
			<h1 class="es-page-title"><?php esc_html_e( 'Emblem Studio — Reports', 'emblem-studio' ); ?></h1>
			<p class="es-page-subtitle"><?php esc_html_e( 'How your assets are doing — and where people drop off.', 'emblem-studio' ); ?></p>
		</div>
		<div class="es-page-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Forms & Assets', 'emblem-studio' ); ?></a>
		</div>
	</div>
	<hr class="es-horizon" aria-hidden="true">

	<?php if ( empty( $forms ) ) : ?>
		<p><em><?php esc_html_e( 'No forms yet — create one first.', 'emblem-studio' ); ?></em></p>
	<?php else : ?>

	<div class="es-filter-bar">
	<form method="get" class="es-reports-filters">
		<input type="hidden" name="page" value="emblem-studio-reports">
		<input type="hidden" name="design" value="<?php echo esc_attr( $design ); ?>">
		<input type="hidden" name="tier" value="<?php echo esc_attr( $tier ); ?>">
		<label><?php esc_html_e( 'Form', 'emblem-studio' ); ?>
			<select name="form_id">
				<option value="0" <?php selected( $form_id, 0 ); ?>><?php esc_html_e( 'All forms (aggregated)', 'emblem-studio' ); ?></option>
				<?php foreach ( $forms as $f ) : ?>
					<option value="<?php echo (int) $f->id; ?>" <?php selected( (int) $form_id, (int) $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label><?php esc_html_e( 'Shell', 'emblem-studio' ); ?>
			<select name="shell">
				<option value="" <?php selected( $shell, '' ); ?>><?php esc_html_e( 'All shells', 'emblem-studio' ); ?></option>
				<?php
				$shell_labels = [
					'inline'      => __( 'Inline', 'emblem-studio' ),
					'popup-click' => __( 'Click popup', 'emblem-studio' ),
					'popup-auto'  => __( 'Auto popup', 'emblem-studio' ),
					'ribbon'      => __( 'Ribbon', 'emblem-studio' ),
					'slide-in'    => __( 'Slide-in', 'emblem-studio' ),
				];
				foreach ( $valid_shells as $sv ) : ?>
					<option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $shell, $sv ); ?>><?php echo esc_html( $shell_labels[ $sv ] ?? $sv ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
		$min_attr = '';
		$tip_attr = '';
		if ( $first_event ) {
			$min_attr = sprintf( ' min="%s"', esc_attr( $first_event ) );
			/* translators: %s: first date with recorded data (Y-m-d) */
			$tip_attr = sprintf( ' data-tip="%s"', esc_attr( sprintf( __( 'No data prior to %s', 'emblem-studio' ), $first_event ) ) );
		}
		?>
		<label><?php esc_html_e( 'From', 'emblem-studio' ); ?>
			<input type="date" class="es-dp-input" name="from" value="<?php echo esc_attr( $from_dt->format( 'Y-m-d' ) ); ?>"<?php echo $min_attr . $tip_attr; ?>>
		</label>
		<label><?php esc_html_e( 'To', 'emblem-studio' ); ?>
			<input type="date" class="es-dp-input" name="to" value="<?php echo esc_attr( $to_dt->format( 'Y-m-d' ) ); ?>"<?php echo $min_attr . $tip_attr; ?>>
		</label>
		<button class="button button-primary"><?php esc_html_e( 'Update', 'emblem-studio' ); ?></button>
	</form>

		<div class="es-presets">
			<label class="es-presets-label" for="es-quick-range"><?php esc_html_e( 'Quick ranges', 'emblem-studio' ); ?></label>
			<select id="es-quick-range" class="es-quick-range">
				<?php if ( '' === $active_preset ) : ?>
					<option value="" selected><?php esc_html_e( 'Custom range', 'emblem-studio' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $presets as $key => $p ) :
					$url = add_query_arg( array_merge( $base_args, [ 'range' => $key, 'design' => $design, 'tier' => $tier ] ), admin_url( 'admin.php' ) );
				?>
					<option value="<?php echo esc_url( $url ); ?>" <?php selected( $active_preset, $key ); ?>><?php echo esc_html( $p[0] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>

	<?php // Preview switchers — temporary scaffolding until real accounts/billing land.
	// Hidden in the Lite build: its tier is fixed to Lite, so there's nothing to switch. ?>
	<?php if ( ! ES_Caps::is_lite() ) : ?>
	<div class="es-rep-switcher">
		<span class="es-rep-switcher-eyebrow"><?php esc_html_e( 'Preview', 'emblem-studio' ); ?></span>
		<span class="es-rep-seg-label"><?php esc_html_e( 'Tier', 'emblem-studio' ); ?></span>
		<span class="es-rep-seg">
			<?php foreach ( [ 'lite', 'free', 'paid' ] as $t ) : ?>
				<a href="<?php echo $switch_url( [ 'tier' => $t ] ); ?>" class="<?php echo $tier === $t ? 'is-active' : ''; ?>"><?php echo esc_html( $tier_label[ $t ] ); ?></a>
			<?php endforeach; ?>
		</span>
		<span class="es-rep-seg-label"><?php esc_html_e( 'Layout', 'emblem-studio' ); ?></span>
		<span class="es-rep-seg">
			<a href="<?php echo $switch_url( [ 'design' => 'journey' ] ); ?>" class="<?php echo $design === 'journey' ? 'is-active' : ''; ?>"><?php esc_html_e( 'Journey', 'emblem-studio' ); ?></a>
			<a href="<?php echo $switch_url( [ 'design' => 'grid' ] ); ?>" class="<?php echo $design === 'grid' ? 'is-active' : ''; ?>"><?php esc_html_e( 'Grid', 'emblem-studio' ); ?></a>
		</span>
		<span class="es-rep-switcher-now"><?php printf( esc_html__( 'Showing the %s view', 'emblem-studio' ), '<strong>' . esc_html( $tier_label[ $tier ] ) . '</strong>' ); ?></span>
	</div>
	<?php endif; ?>

	<p class="es-range-caption"><?php echo esc_html( $scope_label ); ?> · <?php echo esc_html( $range_label ); ?></p>

	<?php // Headline stat row (Lite) with vs-previous deltas; close rate is Free. ?>
	<div class="es-kpis es-rep-stats">
		<div class="es-kpi">
			<div class="es-kpi-label"><?php esc_html_e( 'Reached', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo esc_html( number_format_i18n( (int) $kpis['page_views'] ) ); ?></div>
			<?php echo $es_delta( $kpis['page_views'], $prev['page_views'] ); ?>
		</div>
		<div class="es-kpi">
			<div class="es-kpi-label"><?php esc_html_e( 'Shown', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo esc_html( number_format_i18n( (int) $kpis['views'] ) ); ?></div>
			<?php echo $es_delta( $kpis['views'], $prev['views'] ); ?>
		</div>
		<div class="es-kpi">
			<div class="es-kpi-label"><?php esc_html_e( 'Conversions', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo esc_html( number_format_i18n( (int) $kpis['conversions'] ) ); ?></div>
			<?php echo $es_delta( $kpis['conversions'], $prev['conversions'] ); ?>
		</div>
		<div class="es-kpi es-kpi-accent">
			<div class="es-kpi-label"><?php esc_html_e( 'Conversion rate', 'emblem-studio' ); ?></div>
			<div class="es-kpi-value"><?php echo esc_html( number_format_i18n( (float) $kpis['conv_rate'], 2 ) ); ?>%</div>
			<?php echo $es_delta( $kpis['conv_rate'], $prev['conv_rate'] ); ?>
		</div>
		<?php if ( $es_unlocked( 'free' ) ) : ?>
			<div class="es-kpi">
				<div class="es-kpi-label" title="<?php esc_attr_e( 'How often a shown asset is dismissed. Lower is better.', 'emblem-studio' ); ?>"><?php esc_html_e( 'Close rate', 'emblem-studio' ); ?></div>
				<div class="es-kpi-value"><?php echo esc_html( number_format_i18n( (float) $kpis['close_rate'], 2 ) ); ?>%</div>
				<?php echo $es_delta( $kpis['close_rate'], $prev['close_rate'] ?? 0, true ); ?>
			</div>
		<?php else : ?>
			<div class="es-kpi es-rep-kpi-locked">
				<div class="es-kpi-label"><?php esc_html_e( 'Close rate', 'emblem-studio' ); ?></div>
				<div class="es-rep-kpi-lock">🔒 <?php echo esc_html( $tier_label['free'] ); ?></div>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $design === 'journey' ) : ?>
		<?php $render_funnel(); ?>
		<?php $render_gated( 'free', __( 'Visitors', 'emblem-studio' ), $render_visitors ); ?>
		<?php $render_chart(); ?>
		<div class="es-rep-grid es-rep-grid-2">
			<?php $render_gated( 'free', __( 'By device', 'emblem-studio' ), $render_device ); ?>
			<?php $render_gated( 'paid', __( 'By page', 'emblem-studio' ), $render_page ); ?>
		</div>
		<?php $render_gated( 'free', __( 'Bot traffic filtered', 'emblem-studio' ), $render_bot ); ?>
	<?php else : ?>
		<?php $render_gated( 'free', __( 'Visitors', 'emblem-studio' ), $render_visitors ); ?>
		<?php $render_chart(); ?>
		<div class="es-rep-grid es-rep-grid-3">
			<?php $render_gated( 'free', __( 'By device', 'emblem-studio' ), $render_device ); ?>
			<?php $render_gated( 'paid', __( 'By page', 'emblem-studio' ), $render_page ); ?>
			<?php $render_funnel(); ?>
		</div>
		<?php $render_gated( 'free', __( 'Bot traffic filtered', 'emblem-studio' ), $render_bot ); ?>
	<?php endif; ?>

	<?php endif; ?>
</div>
