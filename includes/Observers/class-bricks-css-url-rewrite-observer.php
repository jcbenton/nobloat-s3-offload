<?php

namespace NBS3\Observers;

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
 */
class BricksCssUrlRewriteObserver implements ObserverInterface {

	private ?array $syncedFilesCache       = null;
	private ?array $syncedThemeAssetsCache = null;

	public function register(): void {
		// Defer registration until after theme is loaded (Bricks defines BRICKS_VERSION in theme)
		add_action( 'after_setup_theme', array( $this, 'registerHooks' ), 20 );
	}

	/**
	 * Register hooks after theme is loaded so we can detect Bricks.
	 */
	public function registerHooks(): void {
		// Only register if Bricks is active
		if ( ! nbs3_is_bricks_active() ) {
			return;
		}

		// Check if at least one Bricks sync option is enabled
		$css_sync_enabled          = nbs3_get_setting( 'sync_bricks_css', false );
		$theme_assets_sync_enabled = nbs3_get_setting( 'sync_bricks_theme_assets', false );

		if ( ! $css_sync_enabled && ! $theme_assets_sync_enabled ) {
			return;
		}

		// Get CDN/S3 domain - no point registering if not available
		$s3Provider = new \NBS3\S3Provider();
		if ( empty( $s3Provider->getDomain() ) ) {
			return;
		}

		// Bricks outputs CSS directly, so we need output buffering
		add_action( 'template_redirect', array( $this, 'startOutputBuffer' ), 1 );
	}

	/**
	 * Start output buffering to catch Bricks CSS URLs.
	 */
	public function startOutputBuffer(): void {
		// Don't buffer admin, AJAX, or REST requests
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start( array( $this, 'rewriteOutputBuffer' ) );
	}

	/**
	 * Process the output buffer and rewrite Bricks CSS URLs.
	 */
	public function rewriteOutputBuffer( string $html ): string {
		// Only process if we have HTML content
		if ( empty( $html ) || strpos( $html, '</html>' ) === false ) {
			return $html;
		}

		$s3Provider = new \NBS3\S3Provider();
		$cdn_domain = trailingslashit( $s3Provider->getDomain() );

		// Rewrite generated CSS files (/uploads/bricks/css/)
		if ( nbs3_get_setting( 'sync_bricks_css', false ) && strpos( $html, '/uploads/bricks/css/' ) !== false ) {
			$html = $this->rewriteGeneratedCssUrls( $html, $cdn_domain );
		}

		// Rewrite theme assets (/themes/bricks/assets/)
		if ( nbs3_get_setting( 'sync_bricks_theme_assets', false ) && strpos( $html, '/themes/bricks/assets/' ) !== false ) {
			$html = $this->rewriteThemeAssetUrls( $html, $cdn_domain );
		}

		return $html;
	}

	/**
	 * Rewrite generated Bricks CSS URLs (from /uploads/bricks/css/).
	 */
	private function rewriteGeneratedCssUrls( string $html, string $cdn_domain ): string {
		// Match Bricks CSS URLs in link tags
		// Pattern matches href="...uploads/bricks/css/filename.css..."
		$pattern = '/(href=["\'])([^"\']*\/uploads\/bricks\/css\/([a-zA-Z0-9._()-]+\.css))([^"\']*["\'])/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $cdn_domain ) {
				$prefix   = $matches[1];        // href="
				$full_url = $matches[2];      // full URL to CSS file
				$filename = $matches[3];      // just the filename
				$suffix   = $matches[4];        // query string + closing quote

				// Check if this file is synced
				if ( ! $this->isFileSynced( $filename ) ) {
					return $matches[0]; // Return unchanged
				}

				// Build CDN URL
				$cdn_url = $cdn_domain . 'uploads/bricks/css/' . $filename;

				return $prefix . $cdn_url . $suffix;
			},
			$html
		);
	}

	/**
	 * Rewrite theme asset URLs (from /themes/bricks/assets/).
	 */
	private function rewriteThemeAssetUrls( string $html, string $cdn_domain ): string {
		// Match theme asset URLs in href/src attributes
		// Pattern matches href/src="...themes/bricks/assets/..."
		$pattern = '/((href|src)=["\'])([^"\']*\/themes\/bricks\/assets\/([^"\'?#]+))([^"\']*["\'])/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $cdn_domain ) {
				$prefix        = $matches[1];           // href=" or src="
				$attr          = $matches[2];             // href or src
				$full_url      = $matches[3];         // full URL to asset
				$relative_path = $matches[4];    // path relative to assets/ folder
				$suffix        = $matches[5];           // query string + closing quote

				// Check if this file is synced
				if ( ! $this->isThemeAssetSynced( $relative_path ) ) {
					return $matches[0]; // Return unchanged
				}

				// Build CDN URL
				$cdn_url = $cdn_domain . 'themes/bricks/assets/' . $relative_path;

				return $prefix . $cdn_url . $suffix;
			},
			$html
		);
	}

	/**
	 * Check if a generated CSS file is in the synced files list.
	 */
	private function isFileSynced( string $file_name ): bool {
		// Cache the synced files for this request
		if ( $this->syncedFilesCache === null ) {
			$this->syncedFilesCache = get_option( 'nbs3_synced_bricks_files', array() );
		}

		return isset( $this->syncedFilesCache[ $file_name ] );
	}

	/**
	 * Check if a theme asset is in the synced files list.
	 */
	private function isThemeAssetSynced( string $relative_path ): bool {
		// Cache the synced theme assets for this request
		if ( $this->syncedThemeAssetsCache === null ) {
			$this->syncedThemeAssetsCache = get_option( 'nbs3_synced_bricks_theme_assets', array() );
		}

		return isset( $this->syncedThemeAssetsCache[ $relative_path ] );
	}
}
