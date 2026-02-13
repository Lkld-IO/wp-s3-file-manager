<?php

/**
 * Access Controller for WP S3 File Manager.
 *
 * Handles the public-facing file access endpoint. Only authenticated users
 * can access files via the generated URLs.
 *
 * @package WPS3FM
 */

namespace WPS3FM;

/**
 * Access Controller class for handling file access requests.
 */
class AccessController
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
		add_action('init', [$this, 'register_rewrite_rules']);
		add_filter('query_vars', [$this, 'add_query_vars']);
		add_action('template_redirect', [$this, 'handle_file_request']);
	}

	/**
	 * Register rewrite rules for the file access endpoint.
	 */
	public function register_rewrite_rules()
	{
		add_rewrite_rule(
			'^wps3fm-file/([a-zA-Z0-9]+)/?$',
			'index.php?wps3fm_token=$matches[1]',
			'top'
		);
	}

	/**
	 * Add custom query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_vars($vars)
	{
		$vars[] = 'wps3fm_token';
		return $vars;
	}

	/**
	 * Handle incoming file access requests.
	 */
	public function handle_file_request()
	{
		$token = get_query_var('wps3fm_token');

		if (empty($token)) {
			return;
		}

		// Look up the file record first so we can check its auth setting.
		$file_manager = new FileManager($this->s3_client, $this->settings);
		$record       = $file_manager->get_file_by_token(sanitize_text_field($token));

		if (! $record) {
			status_header(404);
			wp_die(
				__('File not found.', 'wp-s3-file-manager'),
				__('File Not Found', 'wp-s3-file-manager'),
				['response' => 404]
			);
		}

		// Require authentication only if the file's requires_auth flag is set.
		if ((int) $record->requires_auth && ! is_user_logged_in()) {
			auth_redirect();
			exit;
		}

		// Redirect to a short-lived pre-signed S3 URL instead of proxying the
		// file through PHP memory, which would exhaust memory_limit on large files.
		$presigned_url = $this->s3_client->get_presigned_url($record->s3_key, 300);

		if (is_wp_error($presigned_url)) {
			status_header(500);
			wp_die(
				__('Unable to generate file access URL.', 'wp-s3-file-manager'),
				__('Error', 'wp-s3-file-manager'),
				['response' => 500]
			);
		}

		wp_redirect($presigned_url, 302);
		exit;
	}
}
