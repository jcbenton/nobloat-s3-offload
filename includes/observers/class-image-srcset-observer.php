<?php
/**
 * Image Srcset Observer.
 *
 * Handles the modification of image srcset URLs for offloaded media.
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
 * Observer class for modifying image srcset URLs.
 *
 * This class listens to the WordPress wp_calculate_image_srcset filter and
 * rewrites URLs to point to the cloud storage domain for offloaded media.
 *
 * @since 1.0.0
 */
class ImageSrcsetObserver implements ObserverInterface {

	use OffloaderTrait;

	/**
	 * The S3 provider instance.
	 *
	 * @since 1.0.0
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * The base URL for uploads.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $upload_base_url;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider     = $s3_provider;
		$upload_dir            = wp_get_upload_dir();
		$this->upload_base_url = trailingslashit( $upload_dir['baseurl'] );
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_calculate_image_srcset', array( $this, 'run' ), 10, 5 );
	}

	/**
	 * Modify the image srcset URLs for offloaded attachments.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $sources       Array of image sources for the srcset.
	 * @param array  $size_array    Array of width and height values in pixels.
	 * @param string $image_src     The src attribute value for the image.
	 * @param array  $image_meta    The image metadata.
	 * @param int    $attachment_id The attachment ID.
	 * @return array Modified array of image sources.
	 */
	public function run( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
		if ( ! $this->is_offloaded( $attachment_id ) ) {
			return $sources;
		}

		$domain = $this->s3_provider->get_domain();
		if ( empty( $domain ) ) {
			// No custom domain provided, leave the original sources intact.
			return $sources;
		}

		$sub_dir = $this->get_attachment_subdir( $attachment_id );
		$domain  = rtrim( $domain, '/' );

		return array_map(
			function ( $source ) use ( $sub_dir, $domain ) {
				$source['url'] = $domain . '/' . ltrim( $sub_dir, '/' ) . basename( $source['url'] );
				return $source;
			},
			$sources
		);
	}
}
