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

		$offloaded_image_url = wp_get_attachment_url( $attachment_id );
		if ( empty( $offloaded_image_url ) ) {
			return $filtered_image;
		}

		/*
		 * Use WP_HTML_Tag_Processor (WP 6.2+) for safe attribute mutation
		 * rather than regex extraction + str_replace. The previous
		 * implementation could mangle markup when the attachment URL
		 * substring also appeared inside alt text or data-* attributes,
		 * because str_replace replaced every occurrence in the tag.
		 */
		if ( class_exists( '\WP_HTML_Tag_Processor' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $filtered_image );
			if ( $tags->next_tag( 'img' ) ) {
				$tags->set_attribute( 'src', $offloaded_image_url );
				return $tags->get_updated_html();
			}
			return $filtered_image;
		}

		// Fallback for environments without the tag processor (should not happen on WP >= 6.2).
		return $filtered_image;
	}
}
