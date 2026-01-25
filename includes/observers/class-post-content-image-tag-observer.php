<?php
/**
 * Post Content Image Tag Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

/**
 * Observer that rewrites image tags in post content for offloaded attachments.
 *
 * @since 1.0.0
 */
class PostContentImageTagObserver implements ObserverInterface {

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
		add_filter( 'wp_content_img_tag', array( $this, 'run' ), 10, 3 );
	}

	/**
	 * Modify the image tag in post content.
	 *
	 * Replaces the src attribute with the offloaded URL if the attachment
	 * has been offloaded to cloud storage.
	 *
	 * @param string $filtered_image The filtered image tag HTML.
	 * @param string $context        The context (e.g., 'the_content').
	 * @param int    $attachment_id  The attachment ID.
	 * @return string The modified image tag HTML.
	 */
	public function run( $filtered_image, $context, $attachment_id ) {
		if ( ! $this->is_offloaded( $attachment_id ) ) {
			return $filtered_image;
		}

		$src_attr = $this->get_image_src( $filtered_image );
		if ( empty( $src_attr ) ) {
			return $filtered_image;
		}

		$offloaded_image_url = wp_get_attachment_url( $attachment_id );
		$filtered_image      = str_replace( $src_attr, $offloaded_image_url, $filtered_image );

		return $filtered_image;
	}

	/**
	 * Extract the src attribute from an image tag.
	 *
	 * @param string $image_tag The image tag HTML.
	 * @return string The src attribute value, or empty string if not found.
	 */
	private function get_image_src( $image_tag ) {
		$src = '';

		if ( preg_match( '/src=[\'"]?([^\'" >]+)[\'"]?/i', $image_tag, $matches ) ) {
			$src = $matches[1];
		}

		return $src;
	}
}
