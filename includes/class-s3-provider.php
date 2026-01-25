<?php
/**
 * S3 Provider class file.
 *
 * @package NBS3
 */

namespace NBS3;

defined( 'ABSPATH' ) || exit;

use NBS3Vendor\Aws\S3\S3Client;

/**
 * S3 Provider - handles all S3-compatible storage operations.
 *
 * @since 1.0.0
 */
class S3Provider {

	/**
	 * S3 client instance.
	 *
	 * @var S3Client|null
	 */
	protected $s3_client;

	/**
	 * Last error message from S3 operations.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * Get or create the S3 client.
	 *
	 * @return S3Client The S3 client instance.
	 */
	public function get_client() {
		// Always create a fresh client to ensure we use current credentials.
		if ( null === $this->s3_client ) {
			$endpoint   = trim( nbs3_get_credential( 'endpoint' ) );
			$region     = trim( nbs3_get_credential( 'region' ) ) ? trim( nbs3_get_credential( 'region' ) ) : 'us-east-1';
			$key        = nbs3_get_credential( 'key' );
			$secret     = nbs3_get_credential( 'secret' );
			$path_style = nbs3_get_credential( 'path_style_endpoint' );

			$config = array(
				'version'                     => 'latest',
				'region'                      => $region,
				'use_aws_shared_config_files' => false,
				'credentials'                 => array(
					'key'    => $key,
					'secret' => $secret,
				),
			);

			// Add endpoint for S3-compatible services (R2, Spaces, MinIO, etc.).
			if ( ! empty( $endpoint ) ) {
				$config['endpoint'] = nbs3_normalize_url( $endpoint );
				// Use path-style endpoints if configured (for MinIO, etc.).
				if ( ! empty( $path_style ) ) {
					$config['use_path_style_endpoint'] = true;
				}
			} else {
				// For AWS S3, use regional endpoint.
				$config['endpoint'] = "https://s3.{$region}.amazonaws.com";
			}

			$this->s3_client = new S3Client( $config );
		}

		return $this->s3_client;
	}

	/**
	 * Get the configured bucket name.
	 *
	 * @return string The bucket name.
	 */
	public function get_bucket() {
		return nbs3_get_credential( 'bucket' );
	}

	/**
	 * Get the provider name.
	 *
	 * @return string The provider name.
	 */
	public function get_provider_name() {
		return 's3';
	}

	/**
	 * Get the domain URL for serving files.
	 *
	 * @return string The domain URL.
	 */
	public function get_domain() {
		$domain = nbs3_get_credential( 'domain' );
		if ( ! empty( $domain ) ) {
			return nbs3_normalize_url( $domain );
		}

		// Fall back to S3 bucket URL if no CDN domain is set.
		$bucket   = $this->get_bucket();
		$region   = nbs3_get_credential( 'region' );
		$endpoint = nbs3_get_credential( 'endpoint' );

		if ( ! empty( $bucket ) ) {
			// If custom endpoint is set (non-AWS S3-compatible), use it.
			if ( ! empty( $endpoint ) ) {
				$endpoint = nbs3_normalize_url( $endpoint );
				// Check if it's path-style or virtual-hosted style.
				if ( nbs3_get_credential( 'path_style_endpoint' ) ) {
					return rtrim( $endpoint, '/' ) . '/' . $bucket;
				}
				// Virtual-hosted style: bucket.endpoint.
				$parsed = wp_parse_url( $endpoint );
				$scheme = $parsed['scheme'] ?? 'https';
				$host   = $parsed['host'] ?? '';
				return $scheme . '://' . $bucket . '.' . $host;
			}

			// Default AWS S3 URL.
			if ( ! empty( $region ) ) {
				return 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com';
			}
		}

		return '';
	}

	/**
	 * Get the last error message.
	 *
	 * @return string The last error message.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Upload a file to S3.
	 *
	 * @param string $file The local file path.
	 * @param string $key  The S3 object key.
	 * @return string|false The object URL on success, false on failure.
	 */
	public function upload_file( $file, $key ) {
		$this->last_error = '';

		// Check if file exists before attempting upload.
		if ( ! file_exists( $file ) ) {
			$this->last_error = "Local file does not exist: {$file}";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( "NBS3: {$this->last_error}" );
			return false;
		}

		// Check if file is readable.
		if ( ! is_readable( $file ) ) {
			$this->last_error = "Local file is not readable: {$file}";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( "NBS3: {$this->last_error}" );
			return false;
		}

		$client = $this->get_client();
		try {
			$params = array(
				'Bucket'     => $this->get_bucket(),
				'Key'        => $key,
				'SourceFile' => $file,
			);

			// Only add ACL if configured (for legacy buckets that allow ACLs).
			// Default is 'none' which relies on bucket policy for access control.
			$acl = nbs3_get_setting( 's3_object_acl', 'none' );
			if ( 'none' !== $acl ) {
				$params['ACL'] = $acl;
			}

			$result = $client->putObject( $params );
			return $client->getObjectUrl( $this->get_bucket(), $key );
		} catch ( \Exception $e ) {
			$this->last_error = $e->getMessage();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for S3 failures.
			error_log( "NBS3: Error uploading file to S3: {$this->last_error}" );
			return false;
		}
	}

	/**
	 * Check if an object exists in the bucket.
	 *
	 * @param string $key The S3 object key.
	 * @return bool True if object exists, false otherwise.
	 */
	public function object_exists( string $key ): bool {
		$client = $this->get_client();
		try {
			$client->headObject(
				array(
					'Bucket' => $this->get_bucket(),
					'Key'    => $key,
				)
			);
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check the connection to S3.
	 *
	 * @throws \Exception If connection fails.
	 * @return bool True if connection is successful.
	 */
	public function check_connection() {
		$client = $this->get_client();
		$bucket = $this->get_bucket();

		// Try to upload and delete a small test file.
		$test_key = '.nbs3-connection-test-' . time();

		$client->putObject(
			array(
				'Bucket' => $bucket,
				'Key'    => $test_key,
				'Body'   => 'test',
				'@http'  => array(
					'timeout' => 10,
				),
			)
		);

		// Clean up test file.
		$client->deleteObject(
			array(
				'Bucket' => $bucket,
				'Key'    => $test_key,
			)
		);

		return true;
	}

	/**
	 * Download a file from S3 to local path.
	 *
	 * @param string $key        The S3 object key.
	 * @param string $local_path The local file path to save to.
	 * @return bool True on success, false on failure.
	 */
	public function download_file( string $key, string $local_path ): bool {
		$client = $this->get_client();
		try {
			$result = $client->getObject(
				array(
					'Bucket' => $this->get_bucket(),
					'Key'    => $key,
					'SaveAs' => $local_path,
				)
			);
			return true;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for S3 failures.
			error_log( "NBS3: Error downloading file from S3: {$e->getMessage()}" );
			return false;
		}
	}

	/**
	 * Delete an attachment from S3.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_attachment( $attachment_id ) {
		try {
			$key = $this->get_attachment_key( $attachment_id );
			$this->delete_s3_object( $key );

			$metadata = wp_get_attachment_metadata( $attachment_id );

			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$base_dir = trailingslashit( dirname( $key ) );
				$this->delete_attachment_sizes( $metadata, $base_dir );
				$this->delete_image_backup_sizes( $attachment_id, $base_dir );
			}

			return true;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for S3 failures.
			error_log( "NBS3: Error deleting file from S3: {$e->getMessage()}" );
			return false;
		}
	}

	/**
	 * Delete all image sizes for an attachment.
	 *
	 * @param array  $metadata The attachment metadata.
	 * @param string $base_dir The base directory path.
	 * @return void
	 */
	private function delete_attachment_sizes( array $metadata, string $base_dir ): void {
		$sizes = $metadata['sizes'];

		foreach ( $sizes as $size => $size_info ) {
			$size_files = $this->get_files_from_size_data( $size_info );

			foreach ( $size_files as $size_file ) {
				$thumbnail_key = $base_dir . $size_file;
				$this->delete_s3_object( $thumbnail_key );
			}
		}

		if ( ! empty( $metadata['original_image'] ) ) {
			$original_image = $base_dir . $metadata['original_image'];
			$this->delete_s3_object( $original_image );
		}

		$root_source_files = $this->get_root_source_files( $metadata );
		foreach ( $root_source_files as $source_file ) {
			$source_key = $base_dir . $source_file;
			$this->delete_s3_object( $source_key );
		}
	}

	/**
	 * Get files from size data including WebP sources.
	 *
	 * @param array $size_data The size data array.
	 * @return array Array of file names.
	 */
	private function get_files_from_size_data( array $size_data ): array {
		$files = array();

		if ( ! empty( $size_data['file'] ) ) {
			$files[] = $size_data['file'];
		}

		if ( ! empty( $size_data['sources'] ) && is_array( $size_data['sources'] ) ) {
			foreach ( $size_data['sources'] as $source ) {
				if ( ! empty( $source['file'] ) && ! in_array( $source['file'], $files, true ) ) {
					$files[] = $source['file'];
				}
			}
		}

		return $files;
	}

	/**
	 * Get root source files from metadata.
	 *
	 * @param array $metadata The attachment metadata.
	 * @return array Array of source file names.
	 */
	private function get_root_source_files( array $metadata ): array {
		$files = array();

		if ( ! empty( $metadata['sources'] ) && is_array( $metadata['sources'] ) ) {
			$main_file = $metadata['file'] ?? '';
			foreach ( $metadata['sources'] as $source ) {
				if ( ! empty( $source['file'] ) && $source['file'] !== $main_file ) {
					$files[] = $source['file'];
				}
			}
		}

		return $files;
	}

	/**
	 * Delete image backup sizes from S3.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $base_dir      The base directory path.
	 * @return void
	 */
	private function delete_image_backup_sizes( $attachment_id, $base_dir ) {
		$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

		if ( ! is_array( $backup_sizes ) ) {
			return;
		}

		foreach ( $backup_sizes as $size => $size_info ) {
			$backup_key = $base_dir . $size_info['file'];
			$this->delete_s3_object( $backup_key );
		}
	}

	/**
	 * Get the S3 key for an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string The S3 object key.
	 * @throws \Exception If attachment file cannot be found.
	 */
	private function get_attachment_key( int $attachment_id ): string {
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$nbs3_path     = get_post_meta( $attachment_id, 'nbs3_path', true );

		if ( empty( $attached_file ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $attachment_id is cast to int, exception caught and sanitized before output.
			throw new \Exception( "Unable to find attached file for attachment ID {$attachment_id}" );
		}

		$file_name = basename( $attached_file );
		$file_name = sanitize_file_name( $file_name );

		if ( empty( $file_name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $attachment_id is cast to int, exception caught and sanitized before output.
			throw new \Exception( "Invalid file name for attachment ID {$attachment_id}" );
		}

		if ( ! empty( $nbs3_path ) ) {
			$nbs3_path = nbs3_sanitize_path( $nbs3_path );
		}

		if ( empty( $nbs3_path ) ) {
			return $file_name;
		}

		return trailingslashit( $nbs3_path ) . $file_name;
	}

	/**
	 * Delete an object from S3.
	 *
	 * @param string $key The S3 object key.
	 * @return void
	 */
	private function delete_s3_object( string $key ): void {
		$client = $this->get_client();
		$bucket = $this->get_bucket();

		$client->deleteObject(
			array(
				'Bucket' => $bucket,
				'Key'    => $key,
			)
		);
	}

	/**
	 * Render credentials form fields.
	 *
	 * @return void
	 */
	public function credentials_field() {
		$fields = array(
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'nobloat-s3-offload' ),
				'type'        => 'text',
				'placeholder' => 'us-east-1',
				'default'     => 'us-east-1',
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'nobloat-s3-offload' ),
				'type'        => 'text',
				'placeholder' => 'my-bucket',
			),
			array(
				'name'        => 'key',
				'label'       => __( 'Access Key', 'nobloat-s3-offload' ),
				'type'        => 'text',
				'placeholder' => 'AKIAIOSFODNN7EXAMPLE',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Secret Key', 'nobloat-s3-offload' ),
				'type'        => 'password',
				'placeholder' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'CloudFront or Custom Domain (CDN)', 'nobloat-s3-offload' ),
				'type'        => 'text',
				'placeholder' => 'https://d1234.cloudfront.net',
				'description' => __( 'Optional. Enter your CloudFront distribution URL or custom CDN domain. If left empty, URLs will use the direct S3 bucket URL.', 'nobloat-s3-offload' ),
			),
			array(
				'name'        => 'endpoint',
				'label'       => __( 'S3 Endpoint', 'nobloat-s3-offload' ),
				'type'        => 'text',
				'placeholder' => 'https://nyc3.digitaloceanspaces.com',
				'description' => __( 'Leave empty for AWS S3, or enter custom endpoint for S3-compatible services.', 'nobloat-s3-offload' ),
			),
			array(
				'name'        => 'path_style_endpoint',
				'label'       => __( 'Use Path-Style Endpoint', 'nobloat-s3-offload' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable for MinIO or other S3-compatible services that require path-style URLs.', 'nobloat-s3-offload' ),
			),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_credentials_field_html() returns pre-escaped HTML.
		echo $this->get_credentials_field_html( $fields );
	}

	/**
	 * Render a single credential field.
	 *
	 * @param string $field_name   The field name.
	 * @param string $field_label  The field label.
	 * @param string $field_type    The field type.
	 * @param string $placeholder   The placeholder text.
	 * @param string $description   The field description.
	 * @param string $default_value The default value.
	 * @return string The HTML for the field.
	 */
	protected function render_credential_field( $field_name, $field_label, $field_type = 'text', $placeholder = '', $description = '', $default_value = '' ) {
		$constant_name       = 'NBS3_' . strtoupper( $field_name );
		$is_constant_defined = nbs3_credential_exists_in_config( $constant_name );
		$field_value         = nbs3_get_credential( $field_name );

		if ( ( null === $field_value || '' === $field_value ) && ! empty( $default_value ) ) {
			$field_value = $default_value;
		}

		$input_name     = "nbs3_credentials[{$field_name}]";
		$input_id       = "nbs3_credential_{$field_name}";
		$disabled       = $is_constant_defined ? 'disabled readonly' : '';
		$disabled_class = $is_constant_defined ? 'nbs3-field-disabled' : '';

		$display_value = $field_value;
		if ( $is_constant_defined && 'password' === $field_type && ! empty( $field_value ) ) {
			$display_value = str_repeat( '•', min( strlen( $field_value ), 20 ) );
		}

		if ( 'checkbox' === $field_type ) {
			$checked = ! empty( $field_value ) ? checked( 1, $field_value, false ) : '';
			$html    = '<div class="nbs3-credential-field nbs3-checkbox-option ' . esc_attr( $disabled_class ) . '">';
			$html   .= '<input type="checkbox" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" value="1" ' . $checked . ' ' . $disabled . ' />';
			$html   .= '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $field_label ) . '</label>';

			if ( ! empty( $description ) ) {
				$html .= '<p class="description">' . esc_html( $description ) . '</p>';
			}

			if ( $is_constant_defined ) {
				$set_in_text = sprintf(
					/* translators: %s: wp-config.php code element */
					esc_html__( 'Set in %s', 'nobloat-s3-offload' ),
					'<code>wp-config.php</code>'
				);
				$html .= '<p class="description">' . $set_in_text . '</p>';
			}

			$html .= '</div>';
			return $html;
		}

		$html  = '<div class="nbs3-credential-field ' . esc_attr( $disabled_class ) . '">';
		$html .= '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $field_label ) . '</label>';

		if ( 'password' === $field_type && ! $is_constant_defined ) {
			$html .= '<div class="nbs3-password-field-wrapper">';
			$html .= '<input type="password" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $field_value ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text nbs3-password-input" autocomplete="new-password" ' . $disabled . ' />';
			$html .= '<button type="button" class="button nbs3-toggle-password" aria-label="' . esc_attr__( 'Toggle password visibility', 'nobloat-s3-offload' ) . '">';
			$html .= '<span class="dashicons dashicons-visibility"></span>';
			$html .= '</button>';
			$html .= '</div>';
		} else {
			$html .= '<input type="' . esc_attr( $field_type ) . '" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $display_value ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" autocomplete="off" ' . $disabled . ' />';
		}

		if ( ! empty( $description ) ) {
			$html .= '<p class="description">' . esc_html( $description ) . '</p>';
		}

		if ( $is_constant_defined ) {
			$set_in_text = sprintf(
				/* translators: %s: wp-config.php code element */
				esc_html__( 'Set in %s', 'nobloat-s3-offload' ),
				'<code>wp-config.php</code>'
			);
			$html .= '<p class="description">' . $set_in_text . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Get the HTML for all credential fields.
	 *
	 * @param array $credential_fields Array of credential field definitions.
	 * @return string The HTML for all fields.
	 */
	public function get_credentials_field_html( $credential_fields ) {
		$html = '<div class="nbs3-credentials-container">';

		$html    .= '<div class="nbs3-credentials-info notice notice-info inline">';
		$tip_text = sprintf(
			/* translators: %1$s: "Tip:" label, %2$s: wp-config.php code element */
			esc_html__( '%1$s Credentials can be set here or in %2$s for security. Constants take priority.', 'nobloat-s3-offload' ),
			'<strong>' . esc_html__( 'Tip:', 'nobloat-s3-offload' ) . '</strong>',
			'<code>wp-config.php</code>'
		);
		$html .= '<p>' . $tip_text . '</p>';
		$html .= '</div>';

		$html .= '<div class="nbs3-credential-fields">';
		foreach ( $credential_fields as $field ) {
			$html .= $this->render_credential_field(
				$field['name'],
				$field['label'],
				$field['type'] ?? 'text',
				$field['placeholder'] ?? '',
				$field['description'] ?? '',
				$field['default'] ?? ''
			);
		}
		$html .= '</div>';

		$html .= '<div class="nbs3-credentials-actions">';
		$html .= '<button type="submit" class="button button-primary nbs3-save-credentials">';
		$html .= '<span class="dashicons dashicons-saved"></span> ';
		$html .= esc_html__( 'Save Credentials', 'nobloat-s3-offload' );
		$html .= '</button>';
		$html .= '<button type="button" class="button nbs3_js_test_connection">';
		$html .= esc_html__( 'Test Connection', 'nobloat-s3-offload' );
		$html .= '</button>';
		$html .= '</div>';

		$html .= '</div>';

		return $html;
	}
}
