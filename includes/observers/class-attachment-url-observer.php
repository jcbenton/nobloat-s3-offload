<?php
/**
 * Attachment URL Observer.
 *
 * Handles the modification of attachment URLs for offloaded media.
 *
 * @package NBS3
 * @subpackage Observers
 * @since 1.0.0
 */

namespace NBS3\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

/**
 * Observer class for modifying attachment URLs.
 *
 * This class listens to the WordPress wp_get_attachment_url filter and
 * rewrites URLs to point to the cloud storage domain for offloaded media.
 *
 * @since 1.0.0
 */
class AttachmentUrlObserver implements ObserverInterface {

	use OffloaderTrait;

	/**
	 * The S3 provider instance.
	 *
	 * @since 1.0.0
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Modify the attachment URL if the media is offloaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url     The original URL.
	 * @param int    $post_id The attachment ID.
	 * @return string The modified URL.
	 */
	public function run( string $url, int $post_id ): string {
		if ( ! $this->is_offloaded( $post_id ) ) {
			return $url;
		}

		$domain = $this->s3_provider->get_domain();
		if ( empty( $domain ) ) {
			// No custom domain provided, keep the original URL untouched.
			return $url;
		}

		$sub_dir = $this->get_attachment_subdir( $post_id );
		return rtrim( $domain, '/' ) . '/' . ltrim( $sub_dir, '/' ) . basename( $url );
	}
}
