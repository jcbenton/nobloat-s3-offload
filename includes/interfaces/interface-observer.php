<?php
/**
 * Observer interface.
 *
 * @package NoBloat_S3_Offload
 * @subpackage Interfaces
 */

namespace NBS3\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ObserverInterface
 *
 * Defines the contract for observer classes that register WordPress hooks.
 */
interface ObserverInterface {

	/**
	 * Register hooks and actions for the observer.
	 *
	 * @return void
	 */
	public function register(): void;
}
