<?php

namespace NBS3\Admin\Observers;

use NBS3\Interfaces\ObserverInterface;

class AdminFooterTexts implements ObserverInterface {

	private static $instance = null;

	private function __construct() {
		$this->register();
	}

	public static function getInstance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function register(): void {
		add_action( 'admin_footer_text', array( $this, 'run' ) );
		add_filter( 'update_footer', array( $this, 'admin_footer_version_text' ) );
	}

	public function run( $text ) {
		return nbs3_get_copyright_text();
	}

	public function admin_footer_version_text( $text ) {
		return 'Version ' . NBS3_VERSION;
	}

	// Prevent cloning of the instance
	private function __clone() {}

	// Prevent unserializing of the instance
	public function __wakeup() {}
}
