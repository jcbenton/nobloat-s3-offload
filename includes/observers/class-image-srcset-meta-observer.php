<?php
/**
 * Image Srcset Meta Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

/**
 * Observer that modifies image srcset metadata for offloaded attachments.
 *
 * Appends the object version to file names in the srcset metadata to ensure
 * proper cache busting when images are updated.
 *
 * @since 1.0.0
 */
class ImageSrcsetMetaObserver implements ObserverInterface {

	use OffloaderTrait;

	/**
	 * Cloud provider instance.
	 *
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * The base URL for uploads.
	 *
	 * @var string
	 */
	private string $upload_base_url;

	/**
	 * Constructor.
	 *
	 * @param S3Provider $s3_provider The cloud provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_calculate_image_srcset_meta', array( $this, 'run' ), 1000, 4 );
	}

	/**
	 * Modify the image srcset metadata.
	 *
	 * Appends the object version to the file names of the image sizes if the
	 * attachment has been offloaded.
	 *
	 * @param array  $image_meta    The metadata of the image.
	 * @param array  $size_array    The array of sizes for the image.
	 * @param string $image_src     The source URL of the image.
	 * @param int    $attachment_id The ID of the attachment.
	 * @return array The modified image metadata with updated sizes.
	 */
	public function run( $image_meta, $size_array, $image_src, $attachment_id ) {

		if ( ! $this->is_offloaded( $attachment_id ) ) {
			return $image_meta;
		}

		$object_version = $this->get_object_version( $attachment_id );

		// Check if ['sizes'] is set and is an array. Bug reported by a user.
		$image_sizes = isset( $image_meta['sizes'] ) && is_array( $image_meta['sizes'] ) ? $image_meta['sizes'] : array();

		$image_sizes = array_map(
			function ( $size ) use ( $object_version ) {
				$size['file'] = $object_version . $size['file'];
				return $size;
			},
			$image_sizes
		);

		$image_meta['sizes'] = $image_sizes;
		return $image_meta;
	}
}
