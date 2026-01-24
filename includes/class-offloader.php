<?php
/**
 * Offloader Class.
 *
 * Main orchestrator for media offloading that manages observers and hooks.
 *
 * @package NBS3
 * @since 1.0.0
 */

namespace NBS3;

use NBS3\Traits\OffloaderTrait;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Observers\AttachmentUrlObserver;
use NBS3\Observers\AttachmentDeleteObserver;
use NBS3\Observers\OffloadStatusObserver;
use NBS3\Observers\AttachmentOffloadButtonObserver;
use NBS3\Observers\ImageSrcsetObserver;
use NBS3\Observers\ImageSrcsetMetaObserver;
use NBS3\Observers\ImageDownsizeObserver;
use NBS3\Observers\AttachmentUploadObserver;
use NBS3\Observers\PostContentImageTagObserver;
use NBS3\Observers\GetAttachedFileObserver;
use NBS3\Observers\AttachmentUpdateObserver;
use NBS3\Observers\ThumbnailRegenerationObserver;
use NBS3\Observers\UniqueFilenameObserver;

/**
 * Class Offloader
 *
 * Manages the registration and initialization of all media offloading observers.
 * Implements the singleton pattern to ensure only one instance exists.
 *
 * @since 1.0.0
 */
class Offloader {

	use OffloaderTrait;

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var Offloader|null
	 */
	private static $instance = null;

	/**
	 * The S3 provider instance.
	 *
	 * @since 1.0.0
	 * @var S3Provider
	 */
	public $s3_provider;

	/**
	 * Array of registered observers.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $observers = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param S3Provider $s3_provider The S3 provider instance.
	 */
	private function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 * @param S3Provider $s3_provider The S3 provider instance.
	 * @return Offloader The singleton instance.
	 */
	public static function get_instance( S3Provider $s3_provider ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $s3_provider );
		}
		return self::$instance;
	}

	/**
	 * Initialize all hooks and observers.
	 *
	 * Attaches all observer instances and registers their hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function initialize_hooks() {
		$this->attach( new AttachmentUploadObserver( $this->s3_provider ) );
		$this->attach( new ImageSrcsetObserver( $this->s3_provider ) );
		$this->attach( new ImageSrcsetMetaObserver( $this->s3_provider ) );
		$this->attach( new ImageDownsizeObserver( $this->s3_provider ) );
		$this->attach( new AttachmentUrlObserver( $this->s3_provider ) );
		$this->attach( new GetAttachedFileObserver() );
		$this->attach( new OffloadStatusObserver( $this->s3_provider ) );
		$this->attach( new AttachmentOffloadButtonObserver( $this->s3_provider ) );
		$this->attach( new AttachmentDeleteObserver( $this->s3_provider ) );
		$this->attach( new PostContentImageTagObserver( $this->s3_provider ) );
		$this->attach( new ThumbnailRegenerationObserver( $this->s3_provider ) );
		$this->attach( new AttachmentUpdateObserver( $this->s3_provider ) );
		$this->attach( new UniqueFilenameObserver( $this->s3_provider ) );

		foreach ( $this->observers as $observer ) {
			$observer->register();
		}
	}

	/**
	 * Attach an observer to the offloader.
	 *
	 * @since 1.0.0
	 * @param ObserverInterface $observer The observer to attach.
	 * @return void
	 */
	public function attach( ObserverInterface $observer ) {
		$this->observers[] = $observer;
	}
}
