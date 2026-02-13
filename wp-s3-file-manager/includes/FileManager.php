<?php
/**
 * File Manager for WP S3 File Manager.
 *
 * Manages the local database records and coordinates S3 operations.
 *
 * @package WPS3FM
 */

namespace WPS3FM;

/**
 * File Manager class for handling file operations.
 */
class FileManager
{

	/**
	 * S3 client instance.
	 *
	 * @var S3Client
	 */
	private $s3_client;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param S3Client $s3_client S3 client instance.
	 * @param Settings $settings  Plugin settings instance.
	 */
	public function __construct(S3Client $s3_client, Settings $settings)
	{
		$this->s3_client = $s3_client;
		$this->settings  = $settings;
	}

	/**
	 * Initialize hooks.
	 */
	public function init()
	{
		// AJAX handlers for admin file operations.
		add_action('wp_ajax_wps3fm_upload_file', [ $this, 'ajax_upload_file' ]);
		add_action('wp_ajax_wps3fm_delete_file', [ $this, 'ajax_delete_file' ]);
		add_action('wp_ajax_wps3fm_list_files', [ $this, 'ajax_list_files' ]);
		add_action('wp_ajax_wps3fm_test_connection', [ $this, 'ajax_test_connection' ]);
		add_action('wp_ajax_wps3fm_sync_files', [ $this, 'ajax_sync_files' ]);
		add_action('wp_ajax_wps3fm_toggle_auth', [ $this, 'ajax_toggle_auth' ]);

		// Chunked upload handlers.
		add_action('wp_ajax_wps3fm_init_chunked_upload', [ $this, 'ajax_init_chunked_upload' ]);
		add_action('wp_ajax_wps3fm_upload_chunk', [ $this, 'ajax_upload_chunk' ]);
		add_action('wp_ajax_wps3fm_complete_chunked_upload', [ $this, 'ajax_complete_chunked_upload' ]);
		add_action('wp_ajax_wps3fm_abort_chunked_upload', [ $this, 'ajax_abort_chunked_upload' ]);
	}

	/**
	 * Upload a file via AJAX.
	 */
	public function ajax_upload_file()
	{
		check_ajax_referer('wps3fm_upload', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		if (empty($_FILES['file'])) {
			wp_send_json_error([ 'message' => __('No file provided.', 'wp-s3-file-manager') ], 400);
		}

		$file = $_FILES['file'];

		if ($file['error'] !== UPLOAD_ERR_OK) {
			wp_send_json_error([ 'message' => __('File upload error.', 'wp-s3-file-manager') ], 400);
		}

		$file_name    = sanitize_file_name($file['name']);
		$content_type = $file['type'] ?: 'application/octet-stream';
		$prefix       = $this->settings->get('s3_path_prefix', '');
		$s3_key       = ( $prefix ? trailingslashit($prefix) : '' ) . wp_unique_filename(sys_get_temp_dir(), $file_name);

		$result = $this->s3_client->upload_file($file['tmp_name'], $s3_key, $content_type);

		if (is_wp_error($result)) {
			wp_send_json_error([ 'message' => $result->get_error_message() ], 500);
		}

		// Generate unique access token.
		$access_token = wp_generate_password(32, false);

		// Store record in database.
		$record = $this->create_file_record([
			'file_name'    => $file_name,
			's3_key'       => $s3_key,
			'file_size'    => $file['size'],
			'mime_type'    => $content_type,
			'access_token' => $access_token,
			'uploaded_by'  => get_current_user_id(),
		]);

		if (! $record) {
			wp_send_json_error([ 'message' => __('Failed to save file record.', 'wp-s3-file-manager') ], 500);
		}

		$access_url = $this->get_access_url($access_token);

		wp_send_json_success([
			'id'          => $record->id,
			'file_name'   => $file_name,
			'file_size'   => $file['size'],
			'mime_type'    => $content_type,
			'access_url'  => $access_url,
			'uploaded_at' => $record->uploaded_at,
		]);
	}

	/**
	 * Delete a file via AJAX.
	 */
	public function ajax_delete_file()
	{
		check_ajax_referer('wps3fm_delete', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$file_id = absint($_POST['file_id'] ?? 0);

		if (! $file_id) {
			wp_send_json_error([ 'message' => __('Invalid file ID.', 'wp-s3-file-manager') ], 400);
		}

		$record = $this->get_file_record($file_id);

		if (! $record) {
			wp_send_json_error([ 'message' => __('File not found.', 'wp-s3-file-manager') ], 404);
		}

		// Delete from S3.
		$result = $this->s3_client->delete_file($record->s3_key);

		if (is_wp_error($result)) {
			wp_send_json_error([ 'message' => $result->get_error_message() ], 500);
		}

		// Delete database record.
		$this->delete_file_record($file_id);

		wp_send_json_success([ 'message' => __('File deleted.', 'wp-s3-file-manager') ]);
	}

	/**
	 * List files via AJAX.
	 */
	public function ajax_list_files()
	{
		check_ajax_referer('wps3fm_list', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$files = $this->get_all_file_records();
		$data  = [];

		foreach ($files as $file) {
			$data[] = [
				'id'            => $file->id,
				'file_name'     => $file->file_name,
				'file_size'     => $file->file_size,
				'mime_type'     => $file->mime_type,
				'access_url'    => $this->get_access_url($file->access_token),
				'requires_auth' => (int) $file->requires_auth,
				'uploaded_by'   => get_userdata($file->uploaded_by)->display_name ?? __('Unknown', 'wp-s3-file-manager'),
				'uploaded_at'   => $file->uploaded_at,
			];
		}

		wp_send_json_success([ 'files' => $data ]);
	}

	/**
	 * Test S3 connection via AJAX.
	 */
	public function ajax_test_connection()
	{
		check_ajax_referer('wps3fm_test_connection', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$result = $this->s3_client->test_connection();

		if (is_wp_error($result)) {
			wp_send_json_error([ 'message' => $result->get_error_message() ]);
		}

		wp_send_json_success([ 'message' => __('Connection successful.', 'wp-s3-file-manager') ]);
	}

	/**
	 * Toggle requires_auth for a file via AJAX.
	 */
	public function ajax_toggle_auth()
	{
		check_ajax_referer('wps3fm_toggle_auth', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$file_id      = absint($_POST['file_id'] ?? 0);
		$requires_auth = isset($_POST['requires_auth']) ? absint($_POST['requires_auth']) : 1;

		if (! $file_id) {
			wp_send_json_error([ 'message' => __('Invalid file ID.', 'wp-s3-file-manager') ], 400);
		}

		$record = $this->get_file_record($file_id);

		if (! $record) {
			wp_send_json_error([ 'message' => __('File not found.', 'wp-s3-file-manager') ], 404);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wps3fm_files';

		$updated = $wpdb->update(
			$table,
			[ 'requires_auth' => $requires_auth ? 1 : 0 ],
			[ 'id' => $file_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ($updated === false) {
			wp_send_json_error([ 'message' => __('Failed to update file.', 'wp-s3-file-manager') ], 500);
		}

		wp_send_json_success([
			'message'       => __('Authentication setting updated.', 'wp-s3-file-manager'),
			'requires_auth' => $requires_auth ? 1 : 0,
		]);
	}

	/**
	 * Initialize a chunked (multipart) upload via AJAX.
	 */
	public function ajax_init_chunked_upload()
	{
		check_ajax_referer('wps3fm_upload', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$file_name    = sanitize_file_name($_POST['file_name'] ?? '');
		$content_type = sanitize_mime_type($_POST['content_type'] ?? '') ?: 'application/octet-stream';

		if (empty($file_name)) {
			wp_send_json_error([ 'message' => __('No file name provided.', 'wp-s3-file-manager') ], 400);
		}

		$prefix = $this->settings->get('s3_path_prefix', '');
		$s3_key = ( $prefix ? trailingslashit($prefix) : '' ) . wp_unique_filename(sys_get_temp_dir(), $file_name);

		$result = $this->s3_client->initiate_multipart_upload($s3_key, $content_type);

		if (is_wp_error($result)) {
			wp_send_json_error([ 'message' => $result->get_error_message() ], 500);
		}

		wp_send_json_success([
			'upload_id' => $result['upload_id'],
			's3_key'    => $result['key'],
		]);
	}

	/**
	 * Upload a single chunk of a multipart upload via AJAX.
	 */
	public function ajax_upload_chunk()
	{
		check_ajax_referer('wps3fm_upload', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$upload_id   = sanitize_text_field($_POST['upload_id'] ?? '');
		$s3_key      = sanitize_text_field($_POST['s3_key'] ?? '');
		$part_number = absint($_POST['part_number'] ?? 0);

		if (empty($upload_id) || empty($s3_key) || ! $part_number) {
			wp_send_json_error([ 'message' => __('Missing required chunk upload parameters.', 'wp-s3-file-manager') ], 400);
		}

		if (empty($_FILES['chunk'])) {
			wp_send_json_error([ 'message' => __('No chunk data provided.', 'wp-s3-file-manager') ], 400);
		}

		$chunk = $_FILES['chunk'];

		if ($chunk['error'] !== UPLOAD_ERR_OK) {
			$error_messages = [
				UPLOAD_ERR_INI_SIZE   => sprintf(
					__('Chunk exceeds server upload limit (%s). Increase upload_max_filesize in php.ini.', 'wp-s3-file-manager'),
					ini_get('upload_max_filesize')
				),
				UPLOAD_ERR_FORM_SIZE  => __('Chunk exceeds form size limit.', 'wp-s3-file-manager'),
				UPLOAD_ERR_PARTIAL    => __('Chunk was only partially uploaded.', 'wp-s3-file-manager'),
				UPLOAD_ERR_NO_FILE    => __('No chunk file was uploaded.', 'wp-s3-file-manager'),
				UPLOAD_ERR_NO_TMP_DIR => __('Server missing temporary folder.', 'wp-s3-file-manager'),
				UPLOAD_ERR_CANT_WRITE => __('Server failed to write chunk to disk.', 'wp-s3-file-manager'),
				UPLOAD_ERR_EXTENSION  => __('A PHP extension blocked the upload.', 'wp-s3-file-manager'),
			];
			$message = isset($error_messages[$chunk['error']])
				? $error_messages[$chunk['error']]
				: __('Chunk upload error.', 'wp-s3-file-manager');
			wp_send_json_error([ 'message' => $message ], 400);
		}

		$result = $this->s3_client->upload_part($s3_key, $upload_id, $part_number, $chunk['tmp_name']);

		if (is_wp_error($result)) {
			wp_send_json_error([ 'message' => $result->get_error_message() ], 500);
		}

		wp_send_json_success([
			'etag'        => $result['etag'],
			'part_number' => $result['part_number'],
		]);
	}

	/**
	 * Complete a chunked (multipart) upload via AJAX.
	 */
	public function ajax_complete_chunked_upload()
	{
		check_ajax_referer('wps3fm_upload', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$upload_id    = sanitize_text_field($_POST['upload_id'] ?? '');
		$s3_key       = sanitize_text_field($_POST['s3_key'] ?? '');
		$parts_json   = wp_unslash($_POST['parts'] ?? '');
		$file_name    = sanitize_file_name($_POST['file_name'] ?? '');
		$file_size    = absint($_POST['file_size'] ?? 0);
		$content_type = sanitize_mime_type($_POST['content_type'] ?? '') ?: 'application/octet-stream';

		if (empty($upload_id) || empty($s3_key) || empty($parts_json) || empty($file_name)) {
			wp_send_json_error([ 'message' => __('Missing required parameters.', 'wp-s3-file-manager') ], 400);
		}

		$parts = json_decode($parts_json, true);

		if (! is_array($parts) || empty($parts)) {
			wp_send_json_error([ 'message' => __('Invalid parts data.', 'wp-s3-file-manager') ], 400);
		}

		$result = $this->s3_client->complete_multipart_upload($s3_key, $upload_id, $parts);

		if (is_wp_error($result)) {
			wp_send_json_error([ 'message' => $result->get_error_message() ], 500);
		}

		// Generate unique access token.
		$access_token = wp_generate_password(32, false);

		// Store record in database.
		$record = $this->create_file_record([
			'file_name'    => $file_name,
			's3_key'       => $s3_key,
			'file_size'    => $file_size,
			'mime_type'    => $content_type,
			'access_token' => $access_token,
			'uploaded_by'  => get_current_user_id(),
		]);

		if (! $record) {
			wp_send_json_error([ 'message' => __('Failed to save file record.', 'wp-s3-file-manager') ], 500);
		}

		$access_url = $this->get_access_url($access_token);

		wp_send_json_success([
			'id'          => $record->id,
			'file_name'   => $file_name,
			'file_size'   => $file_size,
			'mime_type'   => $content_type,
			'access_url'  => $access_url,
			'uploaded_at' => $record->uploaded_at,
		]);
	}

	/**
	 * Abort a chunked (multipart) upload via AJAX.
	 */
	public function ajax_abort_chunked_upload()
	{
		check_ajax_referer('wps3fm_upload', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$upload_id = sanitize_text_field($_POST['upload_id'] ?? '');
		$s3_key    = sanitize_text_field($_POST['s3_key'] ?? '');

		if (empty($upload_id) || empty($s3_key)) {
			wp_send_json_error([ 'message' => __('Missing required parameters.', 'wp-s3-file-manager') ], 400);
		}

		$this->s3_client->abort_multipart_upload($s3_key, $upload_id);

		wp_send_json_success([ 'message' => __('Upload aborted.', 'wp-s3-file-manager') ]);
	}

	/**
	 * Generate the access URL for a file.
	 *
	 * @param string $access_token The file's access token.
	 * @return string
	 */
	public function get_access_url($access_token)
	{
		return home_url('/wps3fm-file/' . $access_token);
	}

	/**
	 * Create a file record in the database.
	 *
	 * @param array $data File data.
	 * @return object|false The record on success, false on failure.
	 */
	public function create_file_record($data)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'wps3fm_files';

		$inserted = $wpdb->insert($table, [
			'file_name'    => $data['file_name'],
			's3_key'       => $data['s3_key'],
			'file_size'    => $data['file_size'],
			'mime_type'    => $data['mime_type'],
			'access_token' => $data['access_token'],
			'uploaded_by'  => $data['uploaded_by'],
		], [ '%s', '%s', '%d', '%s', '%s', '%d' ]);

		if (! $inserted) {
			return false;
		}

		return $this->get_file_record($wpdb->insert_id);
	}

	/**
	 * Get a file record by ID.
	 *
	 * @param int $id File ID.
	 * @return object|null
	 */
	public function get_file_record($id)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'wps3fm_files';

		return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
	}

	/**
	 * Get a file record by access token.
	 *
	 * @param string $token Access token.
	 * @return object|null
	 */
	public function get_file_by_token($token)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'wps3fm_files';

		return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE access_token = %s", $token));
	}

	/**
	 * Get all file records.
	 *
	 * @return array
	 */
	public function get_all_file_records()
	{
		global $wpdb;
		$table = $wpdb->prefix . 'wps3fm_files';

		return $wpdb->get_results("SELECT * FROM $table ORDER BY uploaded_at DESC");
	}

	/**
	 * Delete a file record.
	 *
	 * @param int $id File ID.
	 * @return bool
	 */
	public function delete_file_record($id)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'wps3fm_files';

		return (bool) $wpdb->delete($table, [ 'id' => $id ], [ '%d' ]);
	}

	/**
	 * Sync files from S3 to database via AJAX.
	 */
	public function ajax_sync_files()
	{
		check_ajax_referer('wps3fm_sync', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error([ 'message' => __('Permission denied.', 'wp-s3-file-manager') ], 403);
		}

		$result = $this->sync_from_s3();

		if (is_wp_error($result)) {
			wp_send_json_error([ 'message' => $result->get_error_message() ], 500);
		}

		$response = [
			'message' => sprintf(
				__('Sync completed. Added %d, removed %d files.', 'wp-s3-file-manager'),
				$result['added_count'],
				$result['removed_count']
			),
			'added_count'    => $result['added_count'],
			'removed_count'  => $result['removed_count'],
			'total_s3_files' => $result['total_s3_files'],
		];

		// Check if cron is overdue and reschedule it
		$next_sync = wp_next_scheduled('wps3fm_hourly_sync');
		if ($next_sync && $next_sync < time()) {
			// Cron is overdue, reschedule it
			wp_unschedule_event($next_sync, 'wps3fm_hourly_sync');
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wps3fm_hourly_sync');
			$response['cron_rescheduled'] = true;
		}

		wp_send_json_success($response);
	}

	/**
	 * Sync files from S3 bucket to database.
	 *
	 * @return array|WP_Error Array with sync results on success, WP_Error on failure.
	 */
	public function sync_from_s3()
	{
		// Get all objects from S3
		$s3_objects = $this->s3_client->list_objects();

		if (is_wp_error($s3_objects)) {
			return $s3_objects;
		}

		// Get existing S3 keys from database
		global $wpdb;
		$table = $wpdb->prefix . 'wps3fm_files';
		$existing_keys = $wpdb->get_col("SELECT s3_key FROM $table");

		// Build a set of S3 keys for fast lookup.
		$s3_keys = [];
		foreach ($s3_objects as $object) {
			$s3_keys[ $object['key'] ] = true;
		}

		// Remove database records whose S3 objects no longer exist.
		$removed_count = 0;
		foreach ($existing_keys as $key) {
			if (! isset($s3_keys[ $key ])) {
				$wpdb->delete($table, [ 's3_key' => $key ], [ '%s' ]);
				$removed_count++;
			}
		}

		// Add new S3 objects that aren't yet in the database.
		$added_count = 0;
		$current_user_id = get_current_user_id() ?: 1; // Fallback to admin user

		foreach ($s3_objects as $object) {
			$s3_key = $object['key'];

			// Skip if already exists in database
			if (in_array($s3_key, $existing_keys, true)) {
				continue;
			}

			// Extract file name from S3 key
			$file_name = basename($s3_key);

			// Skip empty file names or folder markers
			if (empty($file_name) || substr($s3_key, -1) === '/') {
				continue;
			}

			// Determine MIME type from file extension
			$mime_type = $this->get_mime_type_from_extension($file_name);

			// Generate access token
			$access_token = wp_generate_password(32, false);

			// Parse last modified date
			$uploaded_at = gmdate('Y-m-d H:i:s', strtotime($object['last_modified']));

			// Create database record
			$record_data = [
				'file_name'    => $file_name,
				's3_key'       => $s3_key,
				'file_size'    => $object['size'],
				'mime_type'    => $mime_type,
				'access_token' => $access_token,
				'uploaded_by'  => $current_user_id,
				'uploaded_at'  => $uploaded_at,
			];

			$record = $this->create_file_record($record_data);

			if ($record) {
				$added_count++;
			} else {
				error_log("WP S3 File Manager: Failed to create record for S3 key: $s3_key");
			}
		}

		return [
			'added_count'     => $added_count,
			'removed_count'   => $removed_count,
			'total_s3_files'  => count($s3_objects),
		];
	}

	/**
	 * Get MIME type from file extension.
	 *
	 * @param string $filename The filename.
	 * @return string The MIME type.
	 */
	private function get_mime_type_from_extension($filename)
	{
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		$mime_types = [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'gif'          => 'image/gif',
			'png'          => 'image/png',
			'bmp'          => 'image/bmp',
			'tiff|tif'     => 'image/tiff',
			'ico'          => 'image/x-icon',
			'pdf'          => 'application/pdf',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'          => 'application/vnd.ms-excel',
			'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'          => 'application/vnd.ms-powerpoint',
			'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'txt'          => 'text/plain',
			'csv'          => 'text/csv',
			'xml'          => 'application/xml',
			'json'         => 'application/json',
			'zip'          => 'application/zip',
			'rar'          => 'application/x-rar-compressed',
			'7z'           => 'application/x-7z-compressed',
			'mp4'          => 'video/mp4',
			'avi'          => 'video/x-msvideo',
			'mov'          => 'video/quicktime',
			'wmv'          => 'video/x-ms-wmv',
			'mp3'          => 'audio/mpeg',
			'wav'          => 'audio/wav',
			'flac'         => 'audio/flac',
		];

		foreach ($mime_types as $exts => $mime) {
			if (in_array($extension, explode('|', $exts), true)) {
				return $mime;
			}
		}

		return 'application/octet-stream'; // Default fallback
	}
}
