<?php
/**
 * Admin header observer.
 *
 * @package NoBloat_S3_Offload
 * @subpackage Admin\Observers
 */

namespace NBS3\Admin\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\Interfaces\ObserverInterface;

/**
 * Class AdminHeader
 *
 * Handles rendering of the custom admin header on plugin settings pages
 * and manages admin notice suppression.
 */
class AdminHeader implements ObserverInterface {

	/**
	 * Singleton instance.
	 *
	 * @var AdminHeader|null
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
	 * Register hooks and actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'in_admin_header', array( $this, 'run' ) );
	}

	/**
	 * Run the header observer.
	 *
	 * @return void
	 */
	public function run() {

		$this->disable_admin_notices();
		nbs3_get_view( 'admin/header' );
	}

	/**
	 * Disable admin notices from other plugins.
	 *
	 * @return void
	 */
	protected function disable_admin_notices() {
		if ( nbs3_is_settings_page() ) {
			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'admin_notices' );
		}
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
