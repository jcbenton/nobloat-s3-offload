<?php
/**
 * Bricks CSS URL Rewrite Observer.
 *
 * Rewrites Bricks Builder CSS and asset URLs to use CDN/S3 domain.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\Interfaces\ObserverInterface;

/**
 * Observer for rewriting Bricks CSS URLs to CDN.
 *
 * Bricks Builder outputs CSS link tags directly without using wp_enqueue_style(),
 * so we use output buffering to catch and rewrite URLs in the final HTML.
 *
 * This observer handles two types of Bricks files:
 * 1. Generated CSS files in /uploads/bricks/css/ (per-page styles)
 * 2. Static theme assets in /themes/bricks/assets/ (CSS, JS, fonts)
 *
 * @since 1.0.0
 */
class BricksCssUrlRewriteObserver implements ObserverInterface {

	/**
	 * Cache of synced generated CSS files.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private ?array $synced_files_cache = null;

	/**
	 * Cache of synced theme assets.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private ?array $synced_theme_assets_cache = null;

	/**
	 * Register the observer hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Defer registration until after theme is loaded (Bricks defines BRICKS_VERSION in theme).
		add_action( 'after_setup_theme', array( $this, 'register_hooks' ), 20 );
	}

	/**
	 * Register hooks after theme is loaded so we can detect Bricks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Only register if Bricks is active.
		if ( ! nbs3_is_bricks_active() ) {
			return;
		}

		// Check if at least one Bricks sync option is enabled.
		$css_sync_enabled          = nbs3_get_setting( 'sync_bricks_css', false );
		$theme_assets_sync_enabled = nbs3_get_setting( 'sync_bricks_theme_assets', false );

		if ( ! $css_sync_enabled && ! $theme_assets_sync_enabled ) {
			return;
		}

		// Get CDN/S3 domain - no point registering if not available.
		$s3_provider = new \NBS3\S3Provider();
		if ( empty( $s3_provider->get_domain() ) ) {
			return;
		}

		// Bricks outputs CSS directly, so we need output buffering.
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ), 1 );
	}

	/**
	 * Start output buffering to catch Bricks CSS URLs.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function start_output_buffer(): void {
		// Don't buffer admin, AJAX, or REST requests.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start( array( $this, 'rewrite_output_buffer' ) );
	}

	/**
	 * Process the output buffer and rewrite Bricks CSS URLs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html The HTML output buffer content.
	 * @return string The modified HTML with rewritten URLs.
	 */
	public function rewrite_output_buffer( string $html ): string {
		// Only process if we have HTML content.
		if ( empty( $html ) || false === strpos( $html, '</html>' ) ) {
			return $html;
		}

		$s3_provider = new \NBS3\S3Provider();
		$cdn_domain  = trailingslashit( $s3_provider->get_domain() );

		// Rewrite generated CSS files (/uploads/bricks/css/).
		if ( nbs3_get_setting( 'sync_bricks_css', false ) && false !== strpos( $html, '/uploads/bricks/css/' ) ) {
			$html = $this->rewrite_generated_css_urls( $html, $cdn_domain );
		}

		// Rewrite theme assets (/themes/bricks/assets/).
		if ( nbs3_get_setting( 'sync_bricks_theme_assets', false ) && false !== strpos( $html, '/themes/bricks/assets/' ) ) {
			$html = $this->rewrite_theme_asset_urls( $html, $cdn_domain );
		}

		return $html;
	}

	/**
	 * Rewrite generated Bricks CSS URLs (from /uploads/bricks/css/).
	 *
	 * @since 1.0.0
	 *
	 * @param string $html       The HTML content.
	 * @param string $cdn_domain The CDN domain with trailing slash.
	 * @return string The HTML with rewritten CSS URLs.
	 */
	private function rewrite_generated_css_urls( string $html, string $cdn_domain ): string {
		// Match Bricks CSS URLs in link tags.
		// Pattern matches href="...uploads/bricks/css/filename.css...".
		$pattern   = '/(href=["\'])([^"\']*\/uploads\/bricks\/css\/([a-zA-Z0-9._()-]+\.css))([^"\']*["\'])/i';
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $cdn_domain, $site_host ) {
				$prefix   = $matches[1]; // href=".
				$full_url = $matches[2]; // Full URL to CSS file.
				$filename = $matches[3]; // Just the filename.
				$suffix   = $matches[4]; // Query string + closing quote.

				/*
				 * Anchor the rewrite to the current site's host. The path
				 * regex contains a leading [^"\']* that would otherwise
				 * match an attacker domain ending in /uploads/bricks/css/...,
				 * causing the CDN rewrite to take over a third-party URL.
				 * Allow same-site absolute URLs and protocol-relative or
				 * site-relative URLs (no host); reject everything else.
				 */
				$matched_host = wp_parse_url( $full_url, PHP_URL_HOST );
				if ( $matched_host && $site_host && strcasecmp( $matched_host, $site_host ) !== 0 ) {
					return $matches[0];
				}

				// Check if this file is synced.
				if ( ! $this->is_file_synced( $filename ) ) {
					return $matches[0]; // Return unchanged.
				}

				// Build CDN URL.
				$cdn_url = $cdn_domain . 'uploads/bricks/css/' . $filename;

				return $prefix . $cdn_url . $suffix;
			},
			$html
		);
	}

	/**
	 * Rewrite theme asset URLs (from /themes/bricks/assets/).
	 *
	 * @since 1.0.0
	 *
	 * @param string $html       The HTML content.
	 * @param string $cdn_domain The CDN domain with trailing slash.
	 * @return string The HTML with rewritten asset URLs.
	 */
	private function rewrite_theme_asset_urls( string $html, string $cdn_domain ): string {
		// Match theme asset URLs in href/src attributes.
		// Pattern matches href/src="...themes/bricks/assets/...".
		$pattern   = '/((href|src)=["\'])([^"\']*\/themes\/bricks\/assets\/([^"\'?#]+))([^"\']*["\'])/i';
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $cdn_domain, $site_host ) {
				$prefix        = $matches[1]; // Opening attribute with quote.
				$attr          = $matches[2]; // Attribute name.
				$full_url      = $matches[3]; // Full URL to asset.
				$relative_path = $matches[4]; // Path relative to assets folder.
				$suffix        = $matches[5]; // Query string and closing quote.

				/*
				 * Anchor to the site's host (see rewrite_generated_css_urls
				 * for the full rationale).
				 */
				$matched_host = wp_parse_url( $full_url, PHP_URL_HOST );
				if ( $matched_host && $site_host && strcasecmp( $matched_host, $site_host ) !== 0 ) {
					return $matches[0];
				}

				// Check if this file is synced.
				if ( ! $this->is_theme_asset_synced( $relative_path ) ) {
					return $matches[0]; // Return unchanged.
				}

				// Build CDN URL.
				$cdn_url = $cdn_domain . 'themes/bricks/assets/' . $relative_path;

				return $prefix . $cdn_url . $suffix;
			},
			$html
		);
	}

	/**
	 * Check if a generated CSS file is in the synced files list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_name The CSS filename to check.
	 * @return bool True if the file is synced.
	 */
	private function is_file_synced( string $file_name ): bool {
		// Cache the synced files for this request.
		if ( null === $this->synced_files_cache ) {
			$this->synced_files_cache = get_option( 'nbs3_synced_bricks_files', array() );
		}

		return isset( $this->synced_files_cache[ $file_name ] );
	}

	/**
	 * Check if a theme asset is in the synced files list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path The relative path of the asset.
	 * @return bool True if the asset is synced.
	 */
	private function is_theme_asset_synced( string $relative_path ): bool {
		// Cache the synced theme assets for this request.
		if ( null === $this->synced_theme_assets_cache ) {
			$this->synced_theme_assets_cache = get_option( 'nbs3_synced_bricks_theme_assets', array() );
		}

		return isset( $this->synced_theme_assets_cache[ $relative_path ] );
	}
}
