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

if ( ! function_exists( 'nbs3_is_safe_endpoint_ip' ) ) {
	/**
	 * Check whether an IP is safe to use as an S3 endpoint target.
	 *
	 * Blocks link-local (cloud metadata IMDS at 169.254.169.254), loopback,
	 * multicast, and other reserved ranges via FILTER_FLAG_NO_RES_RANGE.
	 * RFC1918 private ranges (10/8, 172.16/12, 192.168/16) are still allowed
	 * so legitimate MinIO deployments on private networks continue to work.
	 *
	 * @param string $ip The IPv4 or IPv6 address to check.
	 * @return bool True if the IP is acceptable as an outbound destination.
	 */
	function nbs3_is_safe_endpoint_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE );
	}
}

if ( ! function_exists( 'nbs3_validate_endpoint' ) ) {
	/**
	 * Validate an S3 endpoint URL for SSRF safety.
	 *
	 * Rejects URLs whose host resolves to a reserved IP range (loopback,
	 * link-local IMDS, multicast, etc). RFC1918 private ranges are accepted
	 * so MinIO and similar self-hosted S3-compatible services keep working.
	 * If DNS resolution fails the endpoint is rejected — an unreachable
	 * endpoint is also a sign of a misconfiguration or DNS rebinding setup.
	 *
	 * @param string $url The endpoint URL.
	 * @return string The normalized URL on success, empty string on rejection.
	 */
	function nbs3_validate_endpoint( string $url ): string {
		$url = nbs3_normalize_url( $url );
		if ( '' === $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return '';
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = $parsed['host'];
		if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$a_records = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $a_records ) ) {
				$ips = array_merge( $ips, $a_records );
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $aaaa ) ) {
					foreach ( $aaaa as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return '';
		}

		foreach ( $ips as $ip ) {
			if ( ! nbs3_is_safe_endpoint_ip( $ip ) ) {
				return '';
			}
		}

		return $url;
	}
}

if ( ! function_exists( 'nbs3_resolve_endpoint_pins' ) ) {
	/**
	 * Resolve an endpoint host to validated IPs and build cURL pin entries.
	 *
	 * The nbs3_validate_endpoint() helper only checks DNS at validation time and returns
	 * the host *name* — the AWS SDK / cURL then re-resolves DNS when the request
	 * actually fires. A host that passes validation can be re-pointed at
	 * 169.254.169.254 (cloud IMDS), loopback, or an internal service in that
	 * window (DNS rebinding / TOCTOU). This function resolves the host now,
	 * rejects unless *every* answer is a safe IP, and returns CURLOPT_RESOLVE
	 * entries ("host:port:ip") so cURL connects to the exact addresses we
	 * validated and performs no further DNS lookup. TLS SNI and certificate
	 * verification still use the original hostname.
	 *
	 * @param string $url The already-normalized, already-validated endpoint URL.
	 * @return array|false Pin entries (possibly empty for a literal-IP endpoint),
	 *                     or false if the host is unresolvable or resolves to any
	 *                     unsafe IP.
	 */
	function nbs3_resolve_endpoint_pins( string $url ) {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		$host   = $parsed['host'];
		$scheme = strtolower( $parsed['scheme'] ?? 'https' );
		$port   = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'http' === $scheme ? 80 : 443 );

		if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}

		// Literal IPs are not resolved by cURL; nbs3_validate_endpoint already
		// vetted them, so no pin is needed and none can be rebound.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array();
		}

		$ips       = array();
		$a_records = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $a_records ) ) {
			$ips = array_merge( $ips, $a_records );
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $rec ) {
					if ( ! empty( $rec['ipv6'] ) ) {
						$ips[] = $rec['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return false;
		}

		$pins = array();
		foreach ( $ips as $ip ) {
			if ( ! nbs3_is_safe_endpoint_ip( $ip ) ) {
				return false;
			}
			$pins[] = $host . ':' . $port . ':' . $ip;
		}

		return $pins;
	}
}

if ( ! function_exists( 'nbs3_validate_region' ) ) {
	/**
	 * Validate an S3 region string.
	 *
	 * Region values are concatenated into URLs like
	 * `https://s3.{region}.amazonaws.com` and into request signatures, so they
	 * must be tightly constrained to alphanumerics and dashes. Anything else
	 * could be used to inject hostnames or break URL parsing.
	 *
	 * @param string $region The region string.
	 * @return string The normalized lowercase region or empty string if invalid.
	 */
	function nbs3_validate_region( string $region ): string {
		$region = trim( $region );
		if ( '' === $region ) {
			return '';
		}
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,62}$/i', $region ) ) {
			return '';
		}
		return strtolower( $region );
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
			'plugin-status' => 'nbs3',
			'settings'      => 'nbs3_settings',
			'connection'    => 'nbs3_connection',
			'media'         => 'nbs3_media',
			'bricks'        => 'nbs3_bricks',
			'aws-guide'     => 'nbs3_aws_guide',
			'documentation' => 'nbs3_documentation',
			'about'         => 'nbs3_about',
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

		// Decode percent-encoding first so an encoded payload (%2e%2e, %2f) is
		// subject to the whitelist below rather than slipping through encoded.
		$path = urldecode( $path );

		/*
		 * The whitelist is the authoritative control. Stripping everything but
		 * [A-Za-z0-9/_-] removes the '.' character outright, so '..' traversal
		 * cannot survive and no separate blacklist pass is needed. Dots, null
		 * bytes, backslashes, and any hostname/scheme characters are all gone
		 * after this step.
		 */
		$path = preg_replace( '/[^a-zA-Z0-9\/_-]/', '', $path );

		// Collapse multiple slashes and trim path separators.
		$path = preg_replace( '#/+#', '/', $path );
		$path = trim( $path, '/' );

		// Enforce maximum prefix length after sanitizing (S3 allows 1024 chars
		// for the full key; cap the prefix well under that).
		if ( strlen( $path ) > 255 ) {
			$path = rtrim( substr( $path, 0, 255 ), '/' );
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

		$query = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i p
			LEFT JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = %s
			AND (pm.meta_value IS NULL OR pm.meta_value = %s)',
			$wpdb->posts,
			$wpdb->postmeta,
			'nbs3_offloaded',
			'attachment',
			''
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above.
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
			'SELECT COUNT(DISTINCT p.ID)
			FROM %i p
			INNER JOIN %i pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND p.post_status != %s
			AND pm.meta_key = %s
			AND pm.meta_value != %s',
			$wpdb->posts,
			$wpdb->postmeta,
			'attachment',
			'trash',
			'nbs3_offloaded',
			''
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
		return (int) $wpdb->get_var( $query );
	}
}

if ( ! function_exists( 'nbs3_get_unoffloaded_media_item_ids' ) ) {
	/**
	 * Get IDs of unoffloaded media items.
	 *
	 * @param int $limit Maximum number of IDs to return. Default 0 (no limit).
	 * @return array Array of attachment IDs.
	 */
	function nbs3_get_unoffloaded_media_item_ids( int $limit = 0 ): array {
		global $wpdb;

		if ( $limit > 0 ) {
			$query = $wpdb->prepare(
				'SELECT p.ID FROM %i p
				LEFT JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status != %s
				AND (pm.meta_value IS NULL OR pm.meta_value = %s)
				ORDER BY p.ID ASC
				LIMIT %d',
				$wpdb->posts,
				$wpdb->postmeta,
				'nbs3_offloaded',
				'attachment',
				'trash',
				'',
				$limit
			);
		} else {
			$query = $wpdb->prepare(
				'SELECT p.ID FROM %i p
				LEFT JOIN %i pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status != %s
				AND (pm.meta_value IS NULL OR pm.meta_value = %s)
				ORDER BY p.ID ASC',
				$wpdb->posts,
				$wpdb->postmeta,
				'nbs3_offloaded',
				'attachment',
				'trash',
				''
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above.
		return array_map( 'intval', $wpdb->get_col( $query ) );
	}
}

if ( ! function_exists( 'nbs3_get_offloaded_media_item_ids' ) ) {
	/**
	 * Get IDs of offloaded media items.
	 *
	 * @param int $limit Maximum number of IDs to return. Default 0 (no limit).
	 * @return array Array of attachment IDs.
	 */
	function nbs3_get_offloaded_media_item_ids( int $limit = 0 ): array {
		global $wpdb;

		if ( $limit > 0 ) {
			$query = $wpdb->prepare(
				'SELECT DISTINCT p.ID
				FROM %i p
				INNER JOIN %i pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status != %s
				AND pm.meta_key = %s
				AND pm.meta_value != %s
				ORDER BY p.ID ASC
				LIMIT %d',
				$wpdb->posts,
				$wpdb->postmeta,
				'attachment',
				'trash',
				'nbs3_offloaded',
				'',
				$limit
			);
		} else {
			$query = $wpdb->prepare(
				'SELECT DISTINCT p.ID
				FROM %i p
				INNER JOIN %i pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status != %s
				AND pm.meta_key = %s
				AND pm.meta_value != %s
				ORDER BY p.ID ASC',
				$wpdb->posts,
				$wpdb->postmeta,
				'attachment',
				'trash',
				'nbs3_offloaded',
				''
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above.
		return array_map( 'intval', $wpdb->get_col( $query ) );
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
		return update_option( 'nbs3_credentials', $credentials, false );
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
		return update_option( 'nbs3_settings', $settings, false );
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

if ( ! function_exists( 'nbs3_is_plugin_enabled' ) ) {
	/**
	 * Check if the plugin is enabled via the master toggle.
	 *
	 * @since 1.0.8
	 * @return bool True if the plugin is enabled, false otherwise.
	 */
	function nbs3_is_plugin_enabled(): bool {
		return (bool) nbs3_get_setting( 'plugin_enabled', 0 );
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
