<?php
/**
 * File management page template.
 *
 * Variables available: $is_configured
 */

if (! defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap wps3fm-wrap">
	<h1><?php esc_html_e('S3 File Manager', 'wp-s3-file-manager'); ?></h1>

	<?php if (! $is_configured) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					__('S3 credentials are not configured. <a href="%s">Configure settings</a> to start managing files.', 'wp-s3-file-manager'),
					esc_url(admin_url('admin.php?page=wps3fm-settings'))
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<!-- Upload Section -->
		<div class="wps3fm-upload-section">
			<h2><?php esc_html_e('Upload File', 'wp-s3-file-manager'); ?></h2>
			<?php if (empty($can_upload)) : ?>
				<div class="notice notice-warning wps3fm-upload-disabled-notice">
					<p>
						<?php
						printf(
							/* translators: %s: PHP upload_max_filesize value */
							__('Uploading through WordPress is disabled because the server\'s PHP upload limit (%s) is too low to support the 5 MB minimum required by S3 multipart uploads. Please upload files directly to your S3 bucket.', 'wp-s3-file-manager'),
							esc_html(ini_get('upload_max_filesize'))
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="wps3fm-upload-area" id="wps3fm-upload-area">
					<p><?php esc_html_e('Drag & drop a file here, or click to select', 'wp-s3-file-manager'); ?></p>
					<input type="file" id="wps3fm-file-input" style="display:none;" />
					<button type="button" class="button button-primary" id="wps3fm-select-file">
						<?php esc_html_e('Select File', 'wp-s3-file-manager'); ?>
					</button>
				</div>
				<div class="wps3fm-upload-progress" id="wps3fm-upload-progress" style="display:none;">
					<div class="wps3fm-progress-bar">
						<div class="wps3fm-progress-fill" id="wps3fm-progress-fill"></div>
					</div>
					<p class="wps3fm-progress-text" id="wps3fm-progress-text"></p>
				</div>
			<?php endif; ?>
		</div>

		<!-- Files Table -->
		<div class="wps3fm-files-section">
			<div class="wps3fm-files-header">
				<h2 style="display: inline-block;"><?php esc_html_e('Managed Files', 'wp-s3-file-manager'); ?></h2>
				<button type="button" class="button button-secondary" id="wps3fm-sync-files" style="margin-left: 20px;">
					<?php esc_html_e('Sync from S3', 'wp-s3-file-manager'); ?>
				</button>
			</div>
			<table class="wp-list-table widefat fixed striped" id="wps3fm-files-table">
				<thead>
					<tr>
						<th class="column-name"><?php esc_html_e('File Name', 'wp-s3-file-manager'); ?></th>
						<th class="column-size"><?php esc_html_e('Size', 'wp-s3-file-manager'); ?></th>
						<th class="column-type"><?php esc_html_e('Type', 'wp-s3-file-manager'); ?></th>
						<th class="column-url"><?php esc_html_e('Access URL', 'wp-s3-file-manager'); ?></th>
						<th class="column-auth"><?php esc_html_e('Auth Required', 'wp-s3-file-manager'); ?></th>
						<th class="column-uploaded"><?php esc_html_e('Uploaded', 'wp-s3-file-manager'); ?></th>
						<th class="column-actions"><?php esc_html_e('Actions', 'wp-s3-file-manager'); ?></th>
					</tr>
				</thead>
				<tbody id="wps3fm-files-body">
					<tr class="wps3fm-loading-row">
						<td colspan="7"><?php esc_html_e('Loading files...', 'wp-s3-file-manager'); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

	<?php endif; ?>
</div>
