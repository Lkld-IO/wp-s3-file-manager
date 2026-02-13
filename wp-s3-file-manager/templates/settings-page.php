<?php
/**
 * Settings page template.
 *
 * Variables available: $current_settings, $regions, $is_configured
 */

if (! defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap wps3fm-wrap">
	<h1><?php esc_html_e('S3 File Manager Settings', 'wp-s3-file-manager'); ?></h1>

	<?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e('Settings saved.', 'wp-s3-file-manager'); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields('wps3fm_settings_group'); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wps3fm_aws_access_key_id">
						<?php esc_html_e('AWS Access Key ID', 'wp-s3-file-manager'); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						id="wps3fm_aws_access_key_id"
						name="<?php echo esc_attr(\WPS3FM\Settings::OPTION_KEY); ?>[aws_access_key_id]"
						value="<?php echo esc_attr($current_settings['aws_access_key_id']); ?>"
						class="regular-text"
						autocomplete="off"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wps3fm_aws_secret_access_key">
						<?php esc_html_e('AWS Secret Access Key', 'wp-s3-file-manager'); ?>
					</label>
				</th>
				<td>
					<input
						type="password"
						id="wps3fm_aws_secret_access_key"
						name="<?php echo esc_attr(\WPS3FM\Settings::OPTION_KEY); ?>[aws_secret_access_key]"
						value="<?php echo esc_attr($current_settings['aws_secret_access_key']); ?>"
						class="regular-text"
						autocomplete="off"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wps3fm_aws_region">
						<?php esc_html_e('AWS Region', 'wp-s3-file-manager'); ?>
					</label>
				</th>
				<td>
					<select id="wps3fm_aws_region" name="<?php echo esc_attr(\WPS3FM\Settings::OPTION_KEY); ?>[aws_region]">
						<?php foreach ($regions as $code => $label) : ?>
							<option value="<?php echo esc_attr($code); ?>" <?php selected($current_settings['aws_region'], $code); ?>>
								<?php echo esc_html("$label ($code)"); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wps3fm_s3_bucket">
						<?php esc_html_e('S3 Bucket Name', 'wp-s3-file-manager'); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						id="wps3fm_s3_bucket"
						name="<?php echo esc_attr(\WPS3FM\Settings::OPTION_KEY); ?>[s3_bucket]"
						value="<?php echo esc_attr($current_settings['s3_bucket']); ?>"
						class="regular-text"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wps3fm_s3_path_prefix">
						<?php esc_html_e('Path Prefix (optional)', 'wp-s3-file-manager'); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						id="wps3fm_s3_path_prefix"
						name="<?php echo esc_attr(\WPS3FM\Settings::OPTION_KEY); ?>[s3_path_prefix]"
						value="<?php echo esc_attr($current_settings['s3_path_prefix']); ?>"
						class="regular-text"
					/>
					<p class="description">
						<?php esc_html_e('Optional folder prefix for uploaded files (e.g. "uploads/").', 'wp-s3-file-manager'); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php if ($is_configured) : ?>
			<p>
				<button type="button" id="wps3fm-test-connection" class="button button-secondary">
					<?php esc_html_e('Test Connection', 'wp-s3-file-manager'); ?>
				</button>
				<span id="wps3fm-connection-status"></span>
			</p>
		<?php endif; ?>

		<!-- Auto-sync Status -->
		<h3><?php esc_html_e('Automatic Sync', 'wp-s3-file-manager'); ?></h3>
		<p><?php esc_html_e('Files are automatically synced from your S3 bucket every hour.', 'wp-s3-file-manager'); ?></p>
		
		<?php if ($is_configured) : ?>
			<p>
				<button type="button" id="wps3fm-manual-sync" class="button button-secondary">
					<?php esc_html_e('Run Sync Now', 'wp-s3-file-manager'); ?>
				</button>
				<span id="wps3fm-sync-status"></span>
			</p>
		<?php endif; ?>
		
		<?php
		$next_sync = wp_next_scheduled('wps3fm_hourly_sync');
		$wp_cron_disabled = defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON');
		
		if ($next_sync) :
			// Convert UTC timestamp to WordPress timezone
			$timezone = wp_timezone();
			$next_sync_datetime = new DateTime('@' . $next_sync); // Create DateTime from timestamp
			$next_sync_datetime->setTimezone($timezone); // Convert to site timezone
			
			$date_format = get_option('date_format');
			$time_format = get_option('time_format');
			$next_sync_formatted = $next_sync_datetime->format($date_format . ' ' . $time_format);
			
			$is_overdue = $next_sync < time();
			?>
			<p>
				<strong><?php esc_html_e('Next automatic sync:', 'wp-s3-file-manager'); ?></strong>
				<?php echo esc_html($next_sync_formatted); ?>
				<?php if ($is_overdue) : ?>
					<span style="color: #d63638;">
						<?php esc_html_e('(Overdue - WordPress cron may not be running)', 'wp-s3-file-manager'); ?>
					</span>
				<?php endif; ?>
			</p>
			
			<?php if ($is_overdue && $is_configured) : ?>
				<p>
					<button type="button" id="wps3fm-run-overdue-sync" class="button button-secondary">
						<?php esc_html_e('Run Overdue Sync Now', 'wp-s3-file-manager'); ?>
					</button>
					<span id="wps3fm-overdue-sync-status"></span>
				</p>
			<?php endif; ?>
			
			<?php if ($wp_cron_disabled) : ?>
				<p style="color: #d63638;">
					<strong><?php esc_html_e('Note:', 'wp-s3-file-manager'); ?></strong>
					<?php esc_html_e('WordPress cron is disabled (DISABLE_WP_CRON is true). You may need to set up a real cron job for automatic syncing.', 'wp-s3-file-manager'); ?>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<p style="color: #d63638;">
				<strong><?php esc_html_e('Auto-sync is not scheduled.', 'wp-s3-file-manager'); ?></strong>
				<?php esc_html_e('Try deactivating and reactivating the plugin.', 'wp-s3-file-manager'); ?>
			</p>
		<?php endif; ?>

		<?php submit_button(); ?>
	</form>
</div>
