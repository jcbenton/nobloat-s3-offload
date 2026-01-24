<?php
/**
 * Helper functions for the Nobloat S3 Offload plugin.
 *
 * @package Nobloat_S3_Offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nbs3_get_view' ) ) {
	/**
	 * Include a view file from the plugin directory.
	 *
	 * @param string $template The template name without .php extension.
	 * @return void
	 */
	function nbs3_get_view( string $template ) {
		if ( file_exists( NBS3_PATH . 'templates/' . $template . '.php' ) ) {
			include NBS3_PATH . 'templates/' . $template . '.php';
		}
	}
}

if ( ! function_exists( 'nbs3_normalize_url' ) ) {
	/**
	 * Normalize and validate a URL, ensuring it has a proper scheme (https://).
	 *
	 * @param string $url The URL to normalize.
	 * @return string The normalized URL or empty string if invalid.
	 */
	function nbs3_normalize_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		$url = trim( $url );

		if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
			$url = 'https://' . $url;
		}

		$validated_url = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( $validated_url && filter_var( $validated_url, FILTER_VALIDATE_URL ) ) {
			return $validated_url;
		}

		return '';
	}
}

if ( ! function_exists( 'nbs3_get_public_url' ) ) {
	/**
	 * Helper function to get the public URL for an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The attachment URL or false on failure.
	 */
	function nbs3_get_public_url( $attachment_id ) {
		return wp_get_attachment_url( $attachment_id );
	}
}

if ( ! function_exists( 'nbs3_is_settings_page' ) ) {
	/**
	 * Check if we're on a plugin settings page.
	 *
	 * @param string $page_name Optional specific page name to check.
	 * @return bool True if on a plugin settings page, false otherwise.
	 */
	function nbs3_is_settings_page( $page_name = '' ): bool {
		$current_screen = get_current_screen();

		if ( ! $current_screen ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a page check for admin menu, not form processing.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		$plugin_pages = array(
			'general'        => 'nbs3',
			'media-overview' => 'nbs3_media_overview',
			'aws-guide'      => 'nbs3_aws_guide',
			'documentation'  => 'nbs3_documentation',
			'about'          => 'nbs3_about',
		);

		if ( ! empty( $page_name ) ) {
			if ( ! isset( $plugin_pages[ $page_name ] ) ) {
				return false;
			}
			return $plugin_pages[ $page_name ] === $current_page;
		}

		return in_array( $current_page, array_values( $plugin_pages ), true );
	}
}

if ( ! function_exists( 'nbs3_get_bulk_offload_data' ) ) {
	/**
	 * Get bulk offload data.
	 *
	 * @return array The bulk offload data with defaults merged.
	 */
	function nbs3_get_bulk_offload_data(): array {
		$defaults = array(
			'total'             => 0,
			'status'            => '',
			'processed'         => 0,
			'errors'            => 0,
			'last_update'       => null,
			'oversized_skipped' => 0,
		);

		// Try transient first (real-time progress data from background process).
		$transient_data = get_transient( 'nbs3_bulk_offload_progress' );
		if ( false !== $transient_data && is_array( $transient_data ) ) {
			return array_merge( $defaults, $transient_data );
		}

		// Fallback to option (persistent data).
		$stored_data = get_option( 'nbs3_bulk_offload_data', array() );

		return array_merge( $defaults, $stored_data );
	}
}

if ( ! function_exists( 'nbs3_update_bulk_offload_data' ) ) {
	/**
	 * Update bulk offload data.
	 *
	 * @param array $new_data The new data to merge with existing data.
	 * @return array The updated bulk offload data.
	 */
	function nbs3_update_bulk_offload_data( array $new_data ): array {
		$allowed_keys = array(
			'total',
			'status',
			'processed',
			'errors',
			'oversized_skipped',
			'last_recovery',
			'last_cleanup',
			'last_update',
		);

		$filtered_new_data = array_intersect_key( $new_data, array_flip( $allowed_keys ) );
		$existing_data     = nbs3_get_bulk_offload_data();
		$updated_data      = array_merge( $existing_data, $filtered_new_data );
		$final_data        = array_intersect_key( $updated_data, array_flip( $allowed_keys ) );

		foreach ( array( 'last_recovery', 'last_cleanup' ) as $timestamp_key ) {
			if ( isset( $final_data[ $timestamp_key ] ) ) {
				$final_data[ $timestamp_key ] = (int) $final_data[ $timestamp_key ];
			}
		}

		if ( ! isset( $filtered_new_data['last_update'] ) ) {
			$final_data['last_update'] = time();
		} else {
			$final_data['last_update'] = (int) $filtered_new_data['last_update'];
		}

		// Set transient for real-time progress tracking (expires in 1 hour).
		set_transient( 'nbs3_bulk_offload_progress', $final_data, HOUR_IN_SECONDS );

		// Also update persistent option.
		update_option( 'nbs3_bulk_offload_data', $final_data );
		update_option( 'nbs3_bulk_offload_last_update', $final_data['last_update'] );

		return $final_data;
	}
}

if ( ! function_exists( 'nbs3_is_media_organized_by_year_month' ) ) {
	/**
	 * Check if media is organized by year and month.
	 *
	 * @return bool True if media is organized by year/month, false otherwise.
	 */
	function nbs3_is_media_organized_by_year_month(): bool {
		return get_option( 'uploads_use_yearmonth_folders' ) ? true : false;
	}
}

if ( ! function_exists( 'nbs3_sanitize_path' ) ) {
	/**
	 * Sanitize a URL path for S3 prefix usage.
	 *
	 * @param string $path The path to sanitize.
	 * @return string The sanitized path.
	 */
	function nbs3_sanitize_path( string $path ): string {
		// Remove any null bytes and control characters.
		$path = preg_replace( '/[\x00-\x1F\x7F]/u', '', $path );

		$path = trim( $path );

		if ( empty( $path ) ) {
			return '';
		}

		// Maximum path length (S3 allows 1024 chars for keys).
		if ( strlen( $path ) > 255 ) {
			$path = substr( $path, 0, 255 );
		}

		// Decode any URL encoding to prevent bypass.
		$path = urldecode( $path );

		// Remove any attempts at path traversal (multiple passes to catch nested attempts).
		$iterations = 0;
		while ( ( false !== strpos( $path, '..' ) || false !== strpos( $path, './' ) ) && $iterations < 10 ) {
			$path = str_replace( array( '../', '..\\', '../', '..', './' ), '', $path );
			++$iterations;
		}

		// Whitelist allowed characters: alphanumeric, dash, underscore, forward slash.
		$path = preg_replace( '/[^a-zA-Z0-9\/_-]/', '', $path );

		// Collapse multiple slashes.
		$path = preg_replace( '#/+#', '/', $path );

		// Remove leading and trailing slashes.
		$path = trim( $path, '/' );

		// Normalize path separators.
		$path = wp_normalize_path( $path );

		// Final validation - ensure no dangerous patterns remain.
		if ( preg_match( '/\.\./', $path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security logging for path traversal attempts.
			error_log( "NBS3 Security: Dangerous path pattern detected and rejected: {$path}" );
			return '';
		}

		return $path;
	}
}

if ( ! function_exists( 'nbs3_clear_bulk_offload_data' ) ) {
	/**
	 * Clear bulk offload data.
	 *
	 * @return void
	 */
	function nbs3_clear_bulk_offload_data(): void {
		delete_transient( 'nbs3_bulk_offload_progress' );
		delete_option( 'nbs3_bulk_offload_data' );
	}
}

if ( ! function_exists( 'nbs3_get_unoffloaded_media_items_count' ) ) {
	/**
	 * Get the count of unoffloaded media items.
	 *
	 * @return int The count of unoffloaded media items.
	 */
	function nbs3_get_unoffloaded_media_items_count(): int {
		global $wpdb;

		$query = "SELECT COUNT(*) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'nbs3_offloaded'
            WHERE p.post_type = 'attachment'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Complex JOIN query counting unoffloaded attachments.
		return (int) $wpdb->get_var( $query );
	}
}

if ( ! function_exists( 'nbs3_get_offloaded_media_items_count' ) ) {
	/**
	 * Get the count of offloaded media items.
	 *
	 * @return int The count of offloaded media items.
	 */
	function nbs3_get_offloaded_media_items_count(): int {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND p.post_status != %s
            AND pm.meta_key = %s
            AND pm.meta_value != %s",
			array(
				'attachment',
				'trash',
				'nbs3_offloaded',
				'',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
		return (int) $wpdb->get_var( $query );
	}
}

if ( ! function_exists( 'nbs3_credential_exists_in_config' ) ) {
	/**
	 * Check if a credential constant exists in wp-config.php.
	 *
	 * @param string $constant_name The constant name to check.
	 * @return bool True if the constant is defined, false otherwise.
	 */
	function nbs3_credential_exists_in_config( string $constant_name ): bool {
		return defined( $constant_name );
	}
}

if ( ! function_exists( 'nbs3_get_credential' ) ) {
	/**
	 * Get credential from constants or saved options.
	 * Constants take priority over saved options.
	 *
	 * @param string $field_name The credential field name.
	 * @return string The credential value or empty string if not found.
	 */
	function nbs3_get_credential( string $field_name ): string {
		$constant_name = 'NBS3_' . strtoupper( $field_name );

		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			return is_string( $value ) ? $value : '';
		}

		$credentials = get_option( 'nbs3_credentials', array() );
		if ( isset( $credentials[ $field_name ] ) ) {
			return $credentials[ $field_name ];
		}

		return '';
	}
}

if ( ! function_exists( 'nbs3_save_credentials' ) ) {
	/**
	 * Save credentials to options.
	 *
	 * @param array $credentials The credentials array to save.
	 * @return bool True on success, false on failure.
	 */
	function nbs3_save_credentials( array $credentials ): bool {
		return update_option( 'nbs3_credentials', $credentials );
	}
}

if ( ! function_exists( 'nbs3_get_credentials' ) ) {
	/**
	 * Get all credentials.
	 *
	 * @return array The credentials array.
	 */
	function nbs3_get_credentials(): array {
		return get_option( 'nbs3_credentials', array() );
	}
}

if ( ! function_exists( 'nbs3_get_setting' ) ) {
	/**
	 * Get a plugin setting value.
	 *
	 * @param string $key           The setting key.
	 * @param mixed  $default_value The default value if setting is not found.
	 * @return mixed The setting value or default.
	 */
	function nbs3_get_setting( string $key, $default_value = null ) {
		$settings = get_option( 'nbs3_settings', array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default_value;
	}
}

if ( ! function_exists( 'nbs3_update_setting' ) ) {
	/**
	 * Update a plugin setting value.
	 *
	 * @param string $key   The setting key.
	 * @param mixed  $value The value to set.
	 * @return bool True on success, false on failure.
	 */
	function nbs3_update_setting( string $key, $value ): bool {
		$settings         = get_option( 'nbs3_settings', array() );
		$settings[ $key ] = $value;
		return update_option( 'nbs3_settings', $settings );
	}
}

if ( ! function_exists( 'nbs3_get_settings' ) ) {
	/**
	 * Get all plugin settings.
	 *
	 * @return array The plugin settings with defaults merged.
	 */
	function nbs3_get_settings(): array {
		$defaults = array(
			's3_object_acl'        => 'none',
			'auto_offload_uploads' => 1,
			'local_file_retention' => 0,
			'sync_bricks_css'      => 0,
		);
		$settings = get_option( 'nbs3_settings', array() );
		return array_merge( $defaults, $settings );
	}
}

if ( ! function_exists( 'nbs3_is_bricks_active' ) ) {
	/**
	 * Check if Bricks Builder is active.
	 *
	 * @return bool True if Bricks is active, false otherwise.
	 */
	function nbs3_is_bricks_active(): bool {
		return defined( 'BRICKS_VERSION' );
	}
}

if ( ! function_exists( 'nbs3_get_bricks_css_path' ) ) {
	/**
	 * Get the Bricks CSS directory path.
	 *
	 * @return string The full path to the Bricks CSS directory.
	 */
	function nbs3_get_bricks_css_path(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'bricks/css';
	}
}

if ( ! function_exists( 'nbs3_get_bricks_sync_status' ) ) {
	/**
	 * Get Bricks CSS sync status.
	 *
	 * @return array The sync status with synced, pending, and total counts.
	 */
	function nbs3_get_bricks_sync_status(): array {
		$synced_files = get_option( 'nbs3_synced_bricks_files', array() );
		$local_path   = nbs3_get_bricks_css_path();

		$local_files = array();
		if ( is_dir( $local_path ) ) {
			$files = glob( $local_path . '/*.css' );
			foreach ( $files as $file ) {
				$local_files[ basename( $file ) ] = filemtime( $file );
			}
		}

		$synced_count  = 0;
		$pending_count = 0;

		foreach ( $local_files as $file => $mtime ) {
			if ( isset( $synced_files[ $file ] ) && $synced_files[ $file ]['mtime'] >= $mtime ) {
				++$synced_count;
			} else {
				++$pending_count;
			}
		}

		return array(
			'synced'  => $synced_count,
			'pending' => $pending_count,
			'total'   => count( $local_files ),
		);
	}
}

if ( ! function_exists( 'nbs3_get_copyright_text' ) ) {
	/**
	 * Get copyright text for admin footer.
	 *
	 * @return string The formatted copyright text.
	 */
	function nbs3_get_copyright_text(): string {
		return sprintf(
			/* translators: %s: plugin version number */
			__( 'Nobloat S3 Offload v%s', 'nobloat-s3-offload' ),
			NBS3_VERSION
		);
	}
}
