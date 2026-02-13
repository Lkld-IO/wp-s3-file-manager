<?php
/**
 * S3 Client wrapper for WP S3 File Manager.
 *
 * Communicates with Amazon S3 using the AWS SDK or wp_remote_* as fallback.
 * Uses the AWS SDK if available via Composer, otherwise uses direct REST API calls
 * with WordPress HTTP API.
 *
 * @package WPS3FM
 */

namespace WPS3FM;

/**
 * S3 Client class for AWS S3 operations.
 */
class S3Client
{

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings instance.
	 */
	public function __construct(Settings $settings)
	{
		$this->settings = $settings;
	}

	/**
	 * Upload a file to S3.
	 *
	 * @param string $local_path  Absolute path to the local file.
	 * @param string $s3_key      The S3 object key (path inside bucket).
	 * @param string $content_type MIME type of the file.
	 * @return array|WP_Error Array with 'key' and 'etag' on success, WP_Error on failure.
	 */
	public function upload_file($local_path, $s3_key, $content_type = 'application/octet-stream')
	{
		if (! file_exists($local_path)) {
			return new \WP_Error('file_not_found', __('Local file not found.', 'wp-s3-file-manager'));
		}

		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		// Sanitize S3 key for security
		$s3_key = $this->sanitize_s3_key($s3_key);
		if (false === $s3_key) {
			return new \WP_Error('invalid_s3_key', __('Invalid S3 key provided.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$body     = file_get_contents($local_path);
		$date     = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
			$url = "https://$host/$s3_key";
		} else {
			$host = "s3.$region.amazonaws.com";
			$url = "https://$host/$bucket/$s3_key";
		}

		$content_hash = hash('sha256', $body);

		// Build canonical request for AWS Signature v4.
		$headers = [
			'Host'                 => $host,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
			'Content-Type'        => $content_type,
		];

		$signed_headers_list = 'content-type;host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "content-type:$content_type\nhost:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		if ($region === 'us-east-1') {
			$canonical_request = "PUT\n/$s3_key\n\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "PUT\n/$bucket/$s3_key\n\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope             = "$dateshort/$region/s3/aws4_request";
		$string_to_sign    = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_request($url, [
			'method'  => 'PUT',
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'body'    => $body,
			'timeout' => 120,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 200) {
			$body_response = wp_remote_retrieve_body($response);
			// Log the full error for debugging but show generic message to users
			error_log("WP S3 File Manager upload failed: Status $code, Response: $body_response");
			return new \WP_Error(
				's3_upload_failed',
				sprintf(__('File upload failed with status %d. Please contact administrator.', 'wp-s3-file-manager'), $code)
			);
		}

		$etag = wp_remote_retrieve_header($response, 'etag');

		return [
			'key'  => $s3_key,
			'etag' => $etag,
		];
	}

	/**
	 * Delete a file from S3.
	 *
	 * @param string $s3_key The S3 object key.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file($s3_key)
	{
		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		// Sanitize S3 key for security
		$s3_key = $this->sanitize_s3_key($s3_key);
		if (false === $s3_key) {
			return new \WP_Error('invalid_s3_key', __('Invalid S3 key provided.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
			$url = "https://$host/$s3_key";
		} else {
			$host = "s3.$region.amazonaws.com";
			$url = "https://$host/$bucket/$s3_key";
		}

		$content_hash = hash('sha256', '');

		$headers = [
			'Host'                 => $host,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
		];

		$signed_headers_list = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "host:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		if ($region === 'us-east-1') {
			$canonical_request = "DELETE\n/$s3_key\n\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "DELETE\n/$bucket/$s3_key\n\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope          = "$dateshort/$region/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_request($url, [
			'method'  => 'DELETE',
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'timeout' => 30,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 204 && $code !== 200) {
			$body_response = wp_remote_retrieve_body($response);
			// Log the full error for debugging but show generic message to users
			error_log("WP S3 File Manager delete failed: Status $code, Response: $body_response");
			return new \WP_Error(
				's3_delete_failed',
				sprintf(__('File deletion failed with status %d. Please contact administrator.', 'wp-s3-file-manager'), $code)
			);
		}

		return true;
	}

	/**
	 * Generate a pre-signed URL for temporary file access from S3.
	 *
	 * @param string $s3_key    The S3 object key.
	 * @param int    $expires_in Seconds until the URL expires (default 3600).
	 * @return string|WP_Error Pre-signed URL on success, WP_Error on failure.
	 */
	public function get_presigned_url($s3_key, $expires_in = 3600)
	{
		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		// Sanitize S3 key for security
		$s3_key = $this->sanitize_s3_key($s3_key);
		if (false === $s3_key) {
			return new \WP_Error('invalid_s3_key', __('Invalid S3 key provided.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
		} else {
			$host = "s3.$region.amazonaws.com";
		}

		$scope             = "$dateshort/$region/s3/aws4_request";
		$signed_headers    = 'host';
		$credential        = "$access_key/$scope";

		$query_params = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $credential,
			'X-Amz-Date'          => $date,
			'X-Amz-Expires'       => $expires_in,
			'X-Amz-SignedHeaders'  => $signed_headers,
		];

		ksort($query_params);
		$canonical_querystring = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

		$canonical_headers = "host:$host\n";
		$payload_hash      = 'UNSIGNED-PAYLOAD';

		if ($region === 'us-east-1') {
			$canonical_request = "GET\n/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers\n$payload_hash";
		} else {
			$canonical_request = "GET\n/$bucket/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers\n$payload_hash";
		}

		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		if ($region === 'us-east-1') {
			return "https://$host/$s3_key?$canonical_querystring&X-Amz-Signature=$signature";
		} else {
			return "https://$host/$bucket/$s3_key?$canonical_querystring&X-Amz-Signature=$signature";
		}
	}

	/**
	 * Derive the signing key for AWS Signature v4.
	 *
	 * @param string $secret_key AWS secret key.
	 * @param string $date       Date in Ymd format.
	 * @param string $region     AWS region.
	 * @param string $service    AWS service name.
	 * @return string Binary signing key.
	 */
	private function get_signing_key($secret_key, $date, $region, $service)
	{
		$k_date    = hash_hmac('sha256', $date, 'AWS4' . $secret_key, true);
		$k_region  = hash_hmac('sha256', $region, $k_date, true);
		$k_service = hash_hmac('sha256', $service, $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

		return $k_signing;
	}

	/**
	 * Sanitize S3 key to prevent path traversal and other security issues.
	 *
	 * @param string $s3_key The S3 object key to sanitize.
	 * @return string Sanitized S3 key.
	 */
	private function sanitize_s3_key($s3_key)
	{
		// Remove any path traversal attempts
		$s3_key = str_replace([ '..', '//' ], '', $s3_key);
		
		// Remove leading slashes
		$s3_key = ltrim($s3_key, '/');
		
		// Ensure key doesn't exceed S3 limits (1024 characters)
		if (strlen($s3_key) > 1024) {
			return false;
		}
		
		// Basic character validation (allow alphanumeric, hyphen, underscore, slash, dot)
		if (! preg_match('/^[a-zA-Z0-9\-_\/\.]+$/', $s3_key)) {
			return false;
		}
		
		return $s3_key;
	}

	/**
	 * Test the S3 connection by listing the bucket.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection()
	{
		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
			$url = "https://$host/?max-keys=1";
		} else {
			$host = "s3.$region.amazonaws.com";
			$url = "https://$host/$bucket?max-keys=1";
		}

		$content_hash = hash('sha256', '');

		$headers = [
			'Host'                 => $host,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
		];

		$signed_headers_list = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "host:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		if ($region === 'us-east-1') {
			$canonical_request = "GET\n/\nmax-keys=1\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "GET\n/$bucket\nmax-keys=1\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope          = "$dateshort/$region/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_get($url, [
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'timeout' => 15,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 200) {
			$body = wp_remote_retrieve_body($response);
			// Log the full error for debugging but show generic message to users
			error_log("WP S3 File Manager connection test failed: Status $code, Response: $body");
			return new \WP_Error(
				's3_connection_failed',
				sprintf(__('S3 connection test failed with status %d. Please check your credentials and try again.', 'wp-s3-file-manager'), $code)
			);
		}

		return true;
	}

	/**
	 * Initiate a multipart upload on S3.
	 *
	 * @param string $s3_key       The S3 object key.
	 * @param string $content_type MIME type of the file.
	 * @return array|WP_Error Array with 'upload_id' and 'key' on success, WP_Error on failure.
	 */
	public function initiate_multipart_upload($s3_key, $content_type = 'application/octet-stream')
	{
		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		$s3_key = $this->sanitize_s3_key($s3_key);
		if (false === $s3_key) {
			return new \WP_Error('invalid_s3_key', __('Invalid S3 key provided.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
			$url  = "https://$host/$s3_key?uploads";
		} else {
			$host = "s3.$region.amazonaws.com";
			$url  = "https://$host/$bucket/$s3_key?uploads";
		}

		$content_hash = hash('sha256', '');

		$headers = [
			'Host'                 => $host,
			'Content-Type'         => $content_type,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
		];

		$signed_headers_list = 'content-type;host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "content-type:$content_type\nhost:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		$canonical_querystring = 'uploads=';

		if ($region === 'us-east-1') {
			$canonical_request = "POST\n/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "POST\n/$bucket/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope          = "$dateshort/$region/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_request($url, [
			'method'  => 'POST',
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'body'    => '',
			'timeout' => 30,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 200) {
			$body = wp_remote_retrieve_body($response);
			error_log("WP S3 File Manager initiate multipart failed: Status $code, Response: $body");
			return new \WP_Error(
				's3_multipart_init_failed',
				sprintf(__('Failed to initiate multipart upload (status %d).', 'wp-s3-file-manager'), $code)
			);
		}

		$body = wp_remote_retrieve_body($response);
		$xml  = simplexml_load_string($body);

		if (false === $xml || empty($xml->UploadId)) {
			return new \WP_Error('xml_parse_error', __('Failed to parse S3 multipart upload response.', 'wp-s3-file-manager'));
		}

		return [
			'upload_id' => (string) $xml->UploadId,
			'key'       => $s3_key,
		];
	}

	/**
	 * Upload a single part of a multipart upload.
	 *
	 * @param string $s3_key      The S3 object key.
	 * @param string $upload_id   The multipart upload ID.
	 * @param int    $part_number The part number (1-based).
	 * @param string $local_path  Path to the chunk file.
	 * @return array|WP_Error Array with 'etag' and 'part_number' on success, WP_Error on failure.
	 */
	public function upload_part($s3_key, $upload_id, $part_number, $local_path)
	{
		if (! file_exists($local_path)) {
			return new \WP_Error('file_not_found', __('Chunk file not found.', 'wp-s3-file-manager'));
		}

		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		$s3_key = $this->sanitize_s3_key($s3_key);
		if (false === $s3_key) {
			return new \WP_Error('invalid_s3_key', __('Invalid S3 key provided.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$body      = file_get_contents($local_path);
		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
			$url  = "https://$host/$s3_key?partNumber=$part_number&uploadId=" . rawurlencode($upload_id);
		} else {
			$host = "s3.$region.amazonaws.com";
			$url  = "https://$host/$bucket/$s3_key?partNumber=$part_number&uploadId=" . rawurlencode($upload_id);
		}

		$content_hash = hash('sha256', $body);

		$headers = [
			'Host'                 => $host,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
		];

		$signed_headers_list = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "host:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		$canonical_querystring = 'partNumber=' . $part_number . '&uploadId=' . rawurlencode($upload_id);

		if ($region === 'us-east-1') {
			$canonical_request = "PUT\n/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "PUT\n/$bucket/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope          = "$dateshort/$region/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_request($url, [
			'method'  => 'PUT',
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'body'    => $body,
			'timeout' => 300,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 200) {
			$body_response = wp_remote_retrieve_body($response);
			error_log("WP S3 File Manager upload part failed: Status $code, Response: $body_response");
			return new \WP_Error(
				's3_upload_part_failed',
				sprintf(__('Upload part %d failed with status %d.', 'wp-s3-file-manager'), $part_number, $code)
			);
		}

		$etag = wp_remote_retrieve_header($response, 'etag');

		return [
			'etag'        => $etag,
			'part_number' => $part_number,
		];
	}

	/**
	 * Complete a multipart upload on S3.
	 *
	 * @param string $s3_key    The S3 object key.
	 * @param string $upload_id The multipart upload ID.
	 * @param array  $parts     Array of ['part_number' => int, 'etag' => string].
	 * @return array|WP_Error Array with 'key' and 'etag' on success, WP_Error on failure.
	 */
	public function complete_multipart_upload($s3_key, $upload_id, $parts)
	{
		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		$s3_key = $this->sanitize_s3_key($s3_key);
		if (false === $s3_key) {
			return new \WP_Error('invalid_s3_key', __('Invalid S3 key provided.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
			$url  = "https://$host/$s3_key?uploadId=" . rawurlencode($upload_id);
		} else {
			$host = "s3.$region.amazonaws.com";
			$url  = "https://$host/$bucket/$s3_key?uploadId=" . rawurlencode($upload_id);
		}

		// Build XML body.
		usort($parts, function ($a, $b) {
			return $a['part_number'] - $b['part_number'];
		});

		$xml_body = '<CompleteMultipartUpload>';
		foreach ($parts as $part) {
			$xml_body .= '<Part>';
			$xml_body .= '<PartNumber>' . (int) $part['part_number'] . '</PartNumber>';
			$xml_body .= '<ETag>' . htmlspecialchars($part['etag'], ENT_XML1) . '</ETag>';
			$xml_body .= '</Part>';
		}
		$xml_body .= '</CompleteMultipartUpload>';

		$content_hash = hash('sha256', $xml_body);

		$headers = [
			'Host'                 => $host,
			'Content-Type'         => 'application/xml',
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
		];

		$signed_headers_list = 'content-type;host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "content-type:application/xml\nhost:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		$canonical_querystring = 'uploadId=' . rawurlencode($upload_id);

		if ($region === 'us-east-1') {
			$canonical_request = "POST\n/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "POST\n/$bucket/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope          = "$dateshort/$region/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_request($url, [
			'method'  => 'POST',
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'body'    => $xml_body,
			'timeout' => 60,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 200) {
			$body_response = wp_remote_retrieve_body($response);
			error_log("WP S3 File Manager complete multipart failed: Status $code, Response: $body_response");
			return new \WP_Error(
				's3_complete_multipart_failed',
				sprintf(__('Complete multipart upload failed with status %d.', 'wp-s3-file-manager'), $code)
			);
		}

		$etag = wp_remote_retrieve_header($response, 'etag');

		return [
			'key'  => $s3_key,
			'etag' => $etag,
		];
	}

	/**
	 * Abort a multipart upload on S3.
	 *
	 * @param string $s3_key    The S3 object key.
	 * @param string $upload_id The multipart upload ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function abort_multipart_upload($s3_key, $upload_id)
	{
		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		$s3_key = $this->sanitize_s3_key($s3_key);
		if (false === $s3_key) {
			return new \WP_Error('invalid_s3_key', __('Invalid S3 key provided.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
			$url  = "https://$host/$s3_key?uploadId=" . rawurlencode($upload_id);
		} else {
			$host = "s3.$region.amazonaws.com";
			$url  = "https://$host/$bucket/$s3_key?uploadId=" . rawurlencode($upload_id);
		}

		$content_hash = hash('sha256', '');

		$headers = [
			'Host'                 => $host,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
		];

		$signed_headers_list = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "host:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		$canonical_querystring = 'uploadId=' . rawurlencode($upload_id);

		if ($region === 'us-east-1') {
			$canonical_request = "DELETE\n/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "DELETE\n/$bucket/$s3_key\n$canonical_querystring\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope          = "$dateshort/$region/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_request($url, [
			'method'  => 'DELETE',
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'timeout' => 30,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 204 && $code !== 200) {
			$body = wp_remote_retrieve_body($response);
			error_log("WP S3 File Manager abort multipart failed: Status $code, Response: $body");
			return new \WP_Error(
				's3_abort_multipart_failed',
				sprintf(__('Abort multipart upload failed with status %d.', 'wp-s3-file-manager'), $code)
			);
		}

		return true;
	}

	/**
	 * List objects in the S3 bucket.
	 *
	 * @param string $prefix    Optional prefix to filter objects.
	 * @param int    $max_keys  Maximum number of keys to return (default 1000).
	 * @return array|WP_Error Array of objects on success, WP_Error on failure.
	 */
	public function list_objects($prefix = '', $max_keys = 1000)
	{
		if (! $this->settings->is_configured()) {
			return new \WP_Error('not_configured', __('S3 credentials are not configured.', 'wp-s3-file-manager'));
		}

		$bucket     = $this->settings->get('s3_bucket');
		$region     = $this->settings->get('aws_region', 'us-east-1');
		$access_key = $this->settings->get('aws_access_key_id');
		$secret_key = $this->settings->get('aws_secret_access_key');

		$date      = gmdate('Ymd\THis\Z');
		$dateshort = gmdate('Ymd');

		if ($region === 'us-east-1') {
			$host = "$bucket.s3.amazonaws.com";
		} else {
			$host = "s3.$region.amazonaws.com";
		}

		// Build query parameters
		$query_params = [
			'max-keys' => min($max_keys, 1000), // S3 limit is 1000
		];

		if (! empty($prefix)) {
			$query_params['prefix'] = $prefix;
		}

		$query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

		if ($region === 'us-east-1') {
			$url = "https://$host/?" . $query_string;
		} else {
			$url = "https://$host/$bucket?" . $query_string;
		}

		$content_hash = hash('sha256', '');

		$headers = [
			'Host'                 => $host,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'          => $date,
		];

		$signed_headers_list = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_headers   = "host:$host\nx-amz-content-sha256:$content_hash\nx-amz-date:$date\n";

		if ($region === 'us-east-1') {
			$canonical_request = "GET\n/\n$query_string\n$canonical_headers\n$signed_headers_list\n$content_hash";
		} else {
			$canonical_request = "GET\n/$bucket\n$query_string\n$canonical_headers\n$signed_headers_list\n$content_hash";
		}

		$scope          = "$dateshort/$region/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical_request);

		$signing_key = $this->get_signing_key($secret_key, $dateshort, $region, 's3');
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers_list, Signature=$signature";

		$response = wp_remote_get($url, [
			'headers' => array_merge($headers, [ 'Authorization' => $authorization ]),
			'timeout' => 30,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code !== 200) {
			$body = wp_remote_retrieve_body($response);
			error_log("WP S3 File Manager list objects failed: Status $code, Response: $body");
			return new \WP_Error(
				's3_list_failed',
				sprintf(__('S3 list objects failed with status %d. Please check your credentials and try again.', 'wp-s3-file-manager'), $code)
			);
		}

		$body = wp_remote_retrieve_body($response);
		
		// Parse XML response
		$xml = simplexml_load_string($body);
		if (false === $xml) {
			return new \WP_Error('xml_parse_error', __('Failed to parse S3 response.', 'wp-s3-file-manager'));
		}

		$objects = [];
		if (isset($xml->Contents)) {
			foreach ($xml->Contents as $object) {
				$objects[] = [
					'key'           => (string) $object->Key,
					'size'          => (int) $object->Size,
					'last_modified' => (string) $object->LastModified,
					'etag'          => trim((string) $object->ETag, '"'),
				];
			}
		}

		return $objects;
	}
}
