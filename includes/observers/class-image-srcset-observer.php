<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

class ImageSrcsetObserver implements ObserverInterface {

	use OffloaderTrait;

	/**
	 * @var S3Provider
	 */
	private S3Provider $s3Provider;

	/**
	 * The base URL for uploads.
	 *
	 * @var string
	 */
	private string $upload_base_url;

	/**
	 * Constructor.
	 *
	 * @param S3Provider $s3Provider
	 */
	public function __construct( S3Provider $s3Provider ) {
		$this->s3Provider      = $s3Provider;
		$upload_dir            = wp_get_upload_dir();
		$this->upload_base_url = trailingslashit( $upload_dir['baseurl'] );
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_calculate_image_srcset', array( $this, 'run' ), 10, 5 );
	}

	/**
	 * Modify the image srcset.
	 *
	 * @param array $sources
	 * @param array $size_array
	 * @param array $image_src
	 * @param array $image_meta
	 * @param int   $attachment_id
	 * @return array
	 */
	public function run( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ) {
		if ( ! $this->is_offloaded( $attachment_id ) ) {
			return $sources;
		}

		$domain = $this->s3Provider->getDomain();
		if ( empty( $domain ) ) {
			// No custom domain provided, leave the original sources intact.
			return $sources;
		}

		$subDir = $this->get_attachment_subdir( $attachment_id );
		$domain = rtrim( $domain, '/' );

		return array_map(
			function ( $source ) use ( $subDir, $domain ) {
				$source['url'] = $domain . '/' . ltrim( $subDir, '/' ) . basename( $source['url'] );
				return $source;
			},
			$sources
		);
	}
}
