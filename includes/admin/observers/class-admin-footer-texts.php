<?php
/**
 * Admin footer texts observer.
 *
 * @package NoBloat_S3_Offload
 * @subpackage Admin\Observers
 */

namespace NBS3\Admin\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\Interfaces\ObserverInterface;

/**
 * Class AdminFooterTexts
 *
 * Handles customization of the admin footer text and version display
 * on plugin settings pages.
 */
class AdminFooterTexts implements ObserverInterface {

	/**
	 * Singleton instance.
	 *
	 * @var AdminFooterTexts|null
	 */
	private static $instance = null;

	/**
	 * Private constructor to enforce singleton pattern.
	 */
	private function __construct() {
		$this->register();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self The singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks and filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_footer_text', array( $this, 'run' ) );
		add_filter( 'update_footer', array( $this, 'admin_footer_version_text' ) );
	}

	/**
	 * Filter the admin footer text.
	 *
	 * @param string $text The default footer text.
	 * @return string The custom footer text.
	 */
	public function run( $text ) {
		return nbs3_get_copyright_text();
	}

	/**
	 * Filter the admin footer version text.
	 *
	 * @param string $text The default version text.
	 * @return string The plugin version text.
	 */
	public function admin_footer_version_text( $text ) {
		return 'Version ' . NBS3_VERSION;
	}

	/**
	 * Prevent cloning of the instance.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing of the instance.
	 *
	 * @return void
	 */
	public function __wakeup() {}
}
