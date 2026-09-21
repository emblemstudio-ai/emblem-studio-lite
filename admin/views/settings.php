<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status = ES_FunnelKit::detection_status();
$lists  = ES_FunnelKit::get_lists();
$logs   = ES_Logger::recent( 50 );
?>
<div class="wrap emblem-studio-wrap">
	<div class="es-page-header">
		<div class="es-page-header-main">
			<span class="es-eyebrow"><span class="es-eyebrow-dot" aria-hidden="true"></span><?php esc_html_e( 'Settings', 'emblem-studio' ); ?></span>
			<h1 class="es-page-title"><?php esc_html_e( 'Emblem Studio — Settings', 'emblem-studio' ); ?></h1>
			<p class="es-page-subtitle"><?php esc_html_e( 'Connect FunnelKit, tune stats integrity, and review recent activity.', 'emblem-studio' ); ?></p>
		</div>
	</div>
	<hr class="es-horizon" aria-hidden="true">

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'emblem-studio' ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'FunnelKit connection', 'emblem-studio' ); ?></h2>
	<table class="widefat" style="max-width:700px;">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'FunnelKit Automations plugin', 'emblem-studio' ); ?></th>
				<td><?php echo $status['fk_plugin_active'] ? '✅ ' . esc_html__( 'Active', 'emblem-studio' ) : '⚠️ ' . esc_html__( 'Not detected', 'emblem-studio' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Contact API (direct)', 'emblem-studio' ); ?></th>
				<td><?php echo $status['crm_class_present'] ? '✅ ' . esc_html__( 'Available (preferred)', 'emblem-studio' ) : '— ' . esc_html__( 'Not available', 'emblem-studio' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'REST credentials', 'emblem-studio' ); ?></th>
				<td><?php echo $status['rest_configured'] ? '✅ ' . esc_html__( 'Configured', 'emblem-studio' ) : '— ' . esc_html__( 'Not configured (only needed if direct is unavailable)', 'emblem-studio' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Lists detected', 'emblem-studio' ); ?></th>
				<td><?php echo count( $lists ) ? esc_html( count( $lists ) . ' ' . _n( 'list', 'lists', count( $lists ), 'emblem-studio' ) ) : esc_html__( 'None — create one in FunnelKit → Contacts → Lists', 'emblem-studio' ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'REST credentials (optional)', 'emblem-studio' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Only needed if the direct PHP integration fails. Generate a key/secret in FunnelKit → Settings → REST API.', 'emblem-studio' ); ?>
	</p>
	<form method="post" data-es-leave-guard action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'es_save_settings' ); ?>
		<input type="hidden" name="action" value="es_save_settings">

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="es-fk-key"><?php esc_html_e( 'API Key', 'emblem-studio' ); ?></label></th>
				<td><input id="es-fk-key" name="fk_api_key" type="text" class="regular-text" value="<?php echo esc_attr( get_option( ES_FunnelKit::OPT_API_KEY, '' ) ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th><label for="es-fk-secret"><?php esc_html_e( 'API Secret', 'emblem-studio' ); ?></label></th>
				<td><input id="es-fk-secret" name="fk_api_secret" type="password" class="regular-text" value="" placeholder="<?php echo get_option( ES_FunnelKit::OPT_API_SECRET, '' ) ? esc_attr__( 'Leave blank to keep saved secret', 'emblem-studio' ) : ''; ?>" autocomplete="new-password"></td>
			</tr>
			<tr>
				<th><label for="es-fk-base"><?php esc_html_e( 'API Base URL', 'emblem-studio' ); ?></label></th>
				<td><input id="es-fk-base" name="fk_api_base" type="url" class="regular-text" value="<?php echo esc_attr( get_option( ES_FunnelKit::OPT_API_BASE, home_url() ) ); ?>" placeholder="<?php echo esc_attr( home_url() ); ?>">
					<p class="description"><?php esc_html_e( 'Defaults to this site. Change only if FunnelKit lives on a different site.', 'emblem-studio' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:2em;"><?php esc_html_e( 'Stats integrity', 'emblem-studio' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Bot filter', 'emblem-studio' ); ?></th>
				<td>
					<p class="description">
						<?php esc_html_e( 'Always on. Page-view / view / trigger-view events from bot user-agents (Googlebot, GPTBot, ClaudeBot, PerplexityBot, headless browsers, semrush, ahrefs, etc.) are never counted as visitors — they are recorded separately under "Bots filtered" so they can\'t pollute your metrics, while you can still see how much traffic they account for.', 'emblem-studio' ); ?>
					</p>
					<?php
					global $wpdb;
					$events_table = ES_Install::table( 'events' );
					$bot_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $events_table WHERE event_type = 'bot_filtered'" );
					if ( $bot_total > 0 ) :
					?>
						<p style="margin-top:8px; font-size:13px;">
							<strong><?php echo esc_html( number_format_i18n( $bot_total ) ); ?></strong>
							<?php esc_html_e( 'bot events filtered to date across all forms.', 'emblem-studio' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=emblem-studio-reports&range=all' ) . '#bots' ); ?>"><?php esc_html_e( 'See the breakdown by bot →', 'emblem-studio' ); ?></a>
						</p>
					<?php endif; ?>
				</td>
			</tr>
				<tr>
					<th><?php esc_html_e( 'Datacenter detection', 'emblem-studio' ); ?></th>
					<td>
						<label style="display:inline-flex; align-items:center; gap:0.5rem;">
							<input type="checkbox" class="es-toggle-input" name="detect_datacenter" value="1" <?php checked( ES_Event_Recorder::detect_datacenter_enabled() ); ?>>
							<?php esc_html_e( 'Treat browsers from cloud datacenters as bots', 'emblem-studio' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Recommended ON. No real visitor browses from an Azure / AWS / GCP / DigitalOcean VM, so a hit from a known cloud range that claims to be a browser is dropped from your visitor metrics and counted under Bots filtered (labelled by provider). Catches browser-spoofing crawlers that rotate IPs, which the user-agent filter cannot. Behind a CDN this needs the proxy setting below so the real IP is checked.', 'emblem-studio' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th><?php esc_html_e( 'Behind a CDN / proxy', 'emblem-studio' ); ?></th>
					<td>
						<?php $proxy_forced = defined( 'EMBLEM_STUDIO_TRUST_PROXY' ) && EMBLEM_STUDIO_TRUST_PROXY; ?>
						<label style="display:inline-flex; align-items:center; gap:0.5rem;">
							<input type="checkbox" class="es-toggle-input" name="trust_proxy" value="1" <?php checked( ES_Event_Recorder::trust_proxy_enabled() ); ?> <?php disabled( $proxy_forced ); ?>>
							<?php esc_html_e( 'Resolve the real visitor IP from proxy headers (Cloudflare, reverse proxy)', 'emblem-studio' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Enable ONLY if this site is behind Cloudflare or a trusted reverse proxy. It reads CF-Connecting-IP / X-Forwarded-For so the IP used for rate-limiting and the stored bot IP is the real visitor, not the CDN edge. Leave OFF on directly-exposed sites — the header could be spoofed.', 'emblem-studio' ); ?>
						</p>
						<?php if ( $proxy_forced ) : ?>
							<p style="margin-top:8px; font-size:13px;"><em><?php esc_html_e( 'Force-enabled by the EMBLEM_STUDIO_TRUST_PROXY constant in wp-config.', 'emblem-studio' ); ?></em></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th><?php esc_html_e( 'Exclude IP addresses', 'emblem-studio' ); ?></th>
					<td>
						<textarea name="exclude_ips" rows="3" cols="40" class="large-text code" placeholder="159.203.93.67"><?php echo esc_textarea( implode( "\n", ES_Event_Recorder::excluded_ips() ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One IP per line. Hits from these IPs are dropped from ALL stats (page views, impressions, clicks) — use it to mute your own uptime / synthetic monitors and load tests, including headless-browser monitors that spoof a real browser UA the bot filter can\'t catch. Behind Cloudflare, enable "Behind a CDN / proxy" above so the real IP is matched.', 'emblem-studio' ); ?>
						</p>
						<p class="description"><?php printf( esc_html__( 'IP the plugin sees for YOUR current request: %s', 'emblem-studio' ), '<code>' . esc_html( ES_Event_Recorder::client_ip() ) . '</code>' ); ?></p>
					</td>
				</tr>

		</table>

		<?php if ( class_exists( 'ES_Commerce' ) && ES_Commerce::available() ) : ?>
			<h2 style="margin-top:2em;"><?php esc_html_e( 'Shop', 'emblem-studio' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Sales after an asset', 'emblem-studio' ); ?></th>
					<td>
						<label style="display:inline-flex; align-items:center; gap:0.5rem;">
							<input type="checkbox" class="es-toggle-input" name="track_purchases_site" value="1" <?php checked( get_option( ES_Purchases::ENABLED_OPTION ) === '1' ); ?>>
							<?php esc_html_e( 'Count sales that follow an asset', 'emblem-studio' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default. When on, Emblem Studio can say how many of the people an asset brought in went on to buy. It reads your orders and never writes to them, and a customer sees nothing new. No email address leaves this site. Each asset has its own switch too, on its Stats tab.', 'emblem-studio' ); ?>
						</p>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:2em;"><?php esc_html_e( 'Data &amp; uninstall', 'emblem-studio' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'When the plugin is deleted', 'emblem-studio' ); ?></th>
				<td>
					<label style="display:inline-flex; align-items:center; gap:0.5rem;">
						<input type="checkbox" class="es-toggle-input" name="delete_data_on_uninstall" value="1" <?php checked( get_option( 'emblem_studio_delete_data_on_uninstall' ) === '1' ); ?>>
						<?php esc_html_e( 'Also delete all Emblem Studio data (forms, leads, stats, revisions, A/B tests)', 'emblem-studio' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off by default — deleting the plugin keeps your data, so you can reinstall later without losing anything. Turn this ON only if you want every form, lead and stat permanently removed when the plugin is deleted. This cannot be undone.', 'emblem-studio' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'emblem-studio' ) ); ?>
	</form>

	<h2 style="margin-top:2em;"><?php esc_html_e( 'Recent log entries', 'emblem-studio' ); ?></h2>
	<?php if ( empty( $logs ) ) : ?>
		<p><em><?php esc_html_e( 'No log entries yet.', 'emblem-studio' ); ?></em></p>
	<?php else : ?>
		<table class="wp-list-table widefat striped es-data-table">
			<thead><tr>
				<th style="width:140px;"><?php esc_html_e( 'When', 'emblem-studio' ); ?></th>
				<th style="width:70px;"><?php esc_html_e( 'Level', 'emblem-studio' ); ?></th>
				<th><?php esc_html_e( 'Message', 'emblem-studio' ); ?></th>
				<th><?php esc_html_e( 'Context', 'emblem-studio' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $logs as $l ) : ?>
				<tr>
					<td><?php echo esc_html( $l->created_at ); ?></td>
					<td><?php echo esc_html( $l->level ); ?></td>
					<td><?php echo esc_html( $l->message ); ?></td>
					<td><code style="font-size:11px;"><?php echo $l->context ? esc_html( $l->context ) : ''; ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
