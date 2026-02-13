<?php

/**
 * Plugin Name: WP S3 File Manager
 * Plugin URI: https://github.com/Lkld-IO/wp-s3-file-manager
 * Description: Connect an Amazon S3 bucket for large file storage with authenticated access URLs.
 * Version: 1.0.0
 * Author: Joe Cruz<joe@lkld.io>
 * Author URI: https://lkld.io
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-s3-file-manager
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPS3FM_VERSION', '1.0.0' );
define( 'WPS3FM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS3FM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPS3FM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader for plugin classes.
spl_autoload_register(
	function ( $class ) {
		$prefix = 'WPS3FM\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = WPS3FM_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Initialize the plugin.
 */
function wps3fm_init() {
	// Load settings manager.
	$settings = new WPS3FM\Settings();
	$settings->init();

	// Load S3 client wrapper.
	$s3_client = new WPS3FM\S3Client( $settings );

	// Load file manager.
	$file_manager = new WPS3FM\FileManager( $s3_client, $settings );
	$file_manager->init();

	// Load admin interface.
	if ( is_admin() ) {
		$admin = new WPS3FM\Admin( $settings, $file_manager );
		$admin->init();
	}

	// Load access controller for front-end file access.
	$access_controller = new WPS3FM\AccessController( $s3_client, $settings );
	$access_controller->init();
}
add_action( 'plugins_loaded', 'wps3fm_init' );

/**
 * Plugin activation hook.
 */
function wps3fm_activate() {
	// Create the database table for file records.
	global $wpdb;
	$table_name      = $wpdb->prefix . 'wps3fm_files';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		file_name varchar(255) NOT NULL,
		s3_key varchar(512) NOT NULL,
		file_size bigint(20) unsigned NOT NULL DEFAULT 0,
		mime_type varchar(100) NOT NULL DEFAULT '',
		access_token varchar(64) NOT NULL,
		requires_auth tinyint(1) NOT NULL DEFAULT 1,
		uploaded_by bigint(20) unsigned NOT NULL,
		uploaded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY access_token (access_token),
		KEY s3_key (s3_key(191))
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);

	// Flush rewrite rules for the access endpoint.
	flush_rewrite_rules();

	// Schedule hourly S3 sync cron job.
	if ( ! wp_next_scheduled( 'wps3fm_hourly_sync' ) ) {
		wp_schedule_event( time(), 'hourly', 'wps3fm_hourly_sync' );
	}
}
register_activation_hook( __FILE__, 'wps3fm_activate' );

/**
 * Plugin deactivation hook.
 */
function wps3fm_deactivate() {
	flush_rewrite_rules();

	// Unschedule the S3 sync cron job.
	wp_clear_scheduled_hook( 'wps3fm_hourly_sync' );
}
register_deactivation_hook( __FILE__, 'wps3fm_deactivate' );

/**
 * Hook for the hourly S3 sync cron job.
 */
add_action( 'wps3fm_hourly_sync', 'wps3fm_do_hourly_sync' );

/**
 * Perform the hourly S3 sync.
 * This function runs automatically every hour to sync files from S3 to the database.
 */
function wps3fm_do_hourly_sync() {
	// Only run if S3 is properly configured.
	$settings = new WPS3FM\Settings();
	if ( ! $settings->is_configured() ) {
		error_log( 'WP S3 File Manager: Skipping hourly sync - S3 not configured' );
		return;
	}

	try {
		$s3_client    = new WPS3FM\S3Client( $settings );
		$file_manager = new WPS3FM\FileManager( $s3_client, $settings );

		$result = $file_manager->sync_from_s3();

		if ( is_wp_error( $result ) ) {
			error_log( 'WP S3 File Manager: Hourly sync failed - ' . $result->get_error_message() );
		} else {
			$added_count = isset( $result['added_count'] ) ? $result['added_count'] : 0;
			$total_files = isset( $result['total_s3_files'] ) ? $result['total_s3_files'] : 0;

			if ( $added_count > 0 ) {
				error_log( sprintf( 'WP S3 File Manager: Hourly sync completed - Added %d new files (Total S3 files: %d)', $added_count, $total_files ) );
			}
			// Note: We don't log when no new files are added to avoid log spam.
		}
	} catch ( Exception $e ) {
		error_log( 'WP S3 File Manager: Hourly sync exception - ' . $e->getMessage() );
	}
}
