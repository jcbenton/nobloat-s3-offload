<?php
/**
 * Current screen observer.
 *
 * @package NoBloat_S3_Offload
 * @subpackage Admin\Observers
 */

namespace NBS3\Admin\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\Admin\Observers\AdminHeader;
use NBS3\Admin\Observers\AdminFooterTexts;
use NBS3\Interfaces\ObserverInterface;

/**
 * Class CurrentScreen
 *
 * Observes the current admin screen and initializes appropriate
 * admin UI components when on plugin settings pages.
 */
class CurrentScreen implements ObserverInterface {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register();
	}

	/**
	 * Register hooks and actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'run' ) );
	}

	/**
	 * Run the screen observer.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 * @return void
	 */
	public function run( $screen ) {
		if ( nbs3_is_settings_page() ) {
			AdminHeader::get_instance();
			AdminFooterTexts::get_instance();
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
