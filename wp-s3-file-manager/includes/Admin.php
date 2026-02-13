<?php
/**
 * Admin interface for WP S3 File Manager.
 *
 * Registers the admin menu, settings page, and file management page.
 *
 * @package WPS3FM
 */

namespace WPS3FM;

/**
 * Admin class for managing WordPress admin interface.
 */
class Admin
{

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * File manager instance.
	 *
	 * @var FileManager
	 */
	private $file_manager;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Plugin settings instance.
	 * @param FileManager $file_manager File manager instance.
	 */
	public function __construct(Settings $settings, FileManager $file_manager)
	{
		$this->settings     = $settings;
		$this->file_manager = $file_manager;
	}

	/**
	 * Initialize admin hooks.
	 */
	public function init()
	{
		add_action('admin_menu', [ $this, 'add_admin_menu' ]);
		add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
	}

	/**
	 * Register the admin menu pages.
	 */
	public function add_admin_menu()
	{
		add_menu_page(
			__('S3 File Manager', 'wp-s3-file-manager'),
			__('S3 Files', 'wp-s3-file-manager'),
			'manage_options',
			'wps3fm-files',
			[ $this, 'render_files_page' ],
			'dashicons-cloud-upload',
			25
		);

		add_submenu_page(
			'wps3fm-files',
			__('S3 File Manager - Files', 'wp-s3-file-manager'),
			__('Manage Files', 'wp-s3-file-manager'),
			'manage_options',
			'wps3fm-files',
			[ $this, 'render_files_page' ]
		);

		add_submenu_page(
			'wps3fm-files',
			__('S3 File Manager - Settings', 'wp-s3-file-manager'),
			__('Settings', 'wp-s3-file-manager'),
			'manage_options',
			'wps3fm-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets($hook_suffix)
	{
		if (strpos($hook_suffix, 'wps3fm') === false) {
			return;
		}

		wp_enqueue_style(
			'wps3fm-admin',
			WPS3FM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WPS3FM_VERSION
		);

		wp_enqueue_script(
			'wps3fm-admin',
			WPS3FM_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WPS3FM_VERSION,
			true
		);

		wp_localize_script(
			'wps3fm-admin',
			'wps3fm',
			[
				'ajax_url'     => admin_url('admin-ajax.php'),
				'upload_nonce' => wp_create_nonce('wps3fm_upload'),
				'delete_nonce' => wp_create_nonce('wps3fm_delete'),
				'list_nonce'   => wp_create_nonce('wps3fm_list'),
				'test_nonce'   => wp_create_nonce('wps3fm_test_connection'),
				'sync_nonce'        => wp_create_nonce('wps3fm_sync'),
				'toggle_auth_nonce' => wp_create_nonce('wps3fm_toggle_auth'),
				'max_upload_size'   => wp_max_upload_size(),
				'can_upload'        => ( wp_max_upload_size() * 0.8 ) >= ( 5 * 1024 * 1024 ) ? 1 : 0,
				'strings'      => [
					'confirm_delete' => __('Are you sure you want to delete this file?', 'wp-s3-file-manager'),
					'uploading'      => __('Uploading...', 'wp-s3-file-manager'),
					'upload_success' => __('File uploaded successfully.', 'wp-s3-file-manager'),
					'upload_error'   => __('Upload failed.', 'wp-s3-file-manager'),
					'delete_success' => __('File deleted.', 'wp-s3-file-manager'),
					'delete_error'   => __('Delete failed.', 'wp-s3-file-manager'),
					'copied'         => __('URL copied to clipboard.', 'wp-s3-file-manager'),
				],
			]
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page()
	{
		if (! current_user_can('manage_options')) {
			wp_die(__('You do not have permission to access this page.', 'wp-s3-file-manager'));
		}

		$current_settings = $this->settings->get_all();
		$regions          = Settings::get_aws_regions();
		$is_configured    = $this->settings->is_configured();

		include WPS3FM_PLUGIN_DIR . 'templates/settings-page.php';
	}

	/**
	 * Render the file management page.
	 */
	public function render_files_page()
	{
		if (! current_user_can('manage_options')) {
			wp_die(__('You do not have permission to access this page.', 'wp-s3-file-manager'));
		}

		$is_configured = $this->settings->is_configured();
		$can_upload    = ( wp_max_upload_size() * 0.8 ) >= ( 5 * 1024 * 1024 );

		include WPS3FM_PLUGIN_DIR . 'templates/files-page.php';
	}
}
