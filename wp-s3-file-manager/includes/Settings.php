<?php
/**
 * Settings management for WP S3 File Manager.
 *
 * Handles S3 credentials and plugin configuration stored in wp_options.
 *
 * @package WPS3FM
 */

namespace WPS3FM;

/**
 * Settings management class.
 */
class Settings
{

	/**
	 * Option key for storing settings in wp_options.
	 */
	const OPTION_KEY = 'wps3fm_settings';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private $defaults = [
		'aws_access_key_id'     => '',
		'aws_secret_access_key' => '',
		'aws_region'            => 'us-east-1',
		's3_bucket'             => '',
		's3_path_prefix'        => '',
	];

	/**
	 * Initialize settings hooks.
	 */
	public function init()
	{
		add_action('admin_init', [ $this, 'register_settings' ]);
	}

	/**
	 * Register settings with WordPress Settings API.
	 */
	public function register_settings()
	{
		register_setting(
			'wps3fm_settings_group',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings($input)
	{
		$sanitized = [];

		$sanitized['aws_access_key_id']     = sanitize_text_field(isset($input['aws_access_key_id']) ? $input['aws_access_key_id'] : '');
		$sanitized['aws_secret_access_key'] = sanitize_text_field(isset($input['aws_secret_access_key']) ? $input['aws_secret_access_key'] : '');
		$sanitized['aws_region']            = sanitize_text_field(isset($input['aws_region']) ? $input['aws_region'] : 'us-east-1');
		$sanitized['s3_bucket']             = sanitize_text_field(isset($input['s3_bucket']) ? $input['s3_bucket'] : '');
		$sanitized['s3_path_prefix']        = sanitize_text_field(isset($input['s3_path_prefix']) ? $input['s3_path_prefix'] : '');

		return $sanitized;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function get_all()
	{
		return wp_parse_args(get_option(self::OPTION_KEY, []), $this->defaults);
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		$settings = $this->get_all();

		if (isset($settings[ $key ])) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Check if S3 credentials are configured.
	 *
	 * @return bool
	 */
	public function is_configured()
	{
		$settings = $this->get_all();

		return ! empty($settings['aws_access_key_id'])
			&& ! empty($settings['aws_secret_access_key'])
			&& ! empty($settings['s3_bucket']);
	}

	/**
	 * Get the list of available AWS regions.
	 *
	 * @return array
	 */
	public static function get_aws_regions()
	{
		return [
			'us-east-1'      => 'US East (N. Virginia)',
			'us-east-2'      => 'US East (Ohio)',
			'us-west-1'      => 'US West (N. California)',
			'us-west-2'      => 'US West (Oregon)',
			'af-south-1'     => 'Africa (Cape Town)',
			'ap-east-1'      => 'Asia Pacific (Hong Kong)',
			'ap-south-1'     => 'Asia Pacific (Mumbai)',
			'ap-northeast-1' => 'Asia Pacific (Tokyo)',
			'ap-northeast-2' => 'Asia Pacific (Seoul)',
			'ap-southeast-1' => 'Asia Pacific (Singapore)',
			'ap-southeast-2' => 'Asia Pacific (Sydney)',
			'ca-central-1'   => 'Canada (Central)',
			'eu-central-1'   => 'Europe (Frankfurt)',
			'eu-west-1'      => 'Europe (Ireland)',
			'eu-west-2'      => 'Europe (London)',
			'eu-west-3'      => 'Europe (Paris)',
			'eu-north-1'     => 'Europe (Stockholm)',
			'me-south-1'     => 'Middle East (Bahrain)',
			'sa-east-1'      => 'South America (São Paulo)',
		];
	}
}
