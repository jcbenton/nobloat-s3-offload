<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

class AttachmentUrlObserver implements ObserverInterface {

	use OffloaderTrait;

	/**
	 * @var S3Provider
	 */
	private S3Provider $s3Provider;

	/**
	 * Constructor.
	 *
	 * @param S3Provider $s3Provider
	 */
	public function __construct( S3Provider $s3Provider ) {
		$this->s3Provider = $s3Provider;
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Modify the attachment URL if the media is offloaded.
	 *
	 * @param string $url      The original URL.
	 * @param int    $post_id  The attachment ID.
	 * @return string          The modified URL.
	 */
	public function run( string $url, int $post_id ): string {
		if ( ! $this->is_offloaded( $post_id ) ) {
			return $url;
		}

		$domain = $this->s3Provider->getDomain();
		if ( empty( $domain ) ) {
			// No custom domain provided, keep the original URL untouched.
			return $url;
		}

		$subDir = $this->get_attachment_subdir( $post_id );
		return rtrim( $domain, '/' ) . '/' . ltrim( $subDir, '/' ) . basename( $url );
	}
}
