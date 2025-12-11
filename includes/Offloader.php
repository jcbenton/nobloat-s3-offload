<?php

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

class Offloader
{
    use OffloaderTrait;

    private static $instance = null;
    public $s3Provider;
    private array $observers = [];

    private function __construct(S3Provider $s3Provider)
    {
        $this->s3Provider = $s3Provider;
    }

    public static function get_instance(S3Provider $s3Provider)
    {
        if (self::$instance === null) {
            self::$instance = new self($s3Provider);
        }
        return self::$instance;
    }

    public function initializeHooks()
    {
        $this->attach(new AttachmentUploadObserver($this->s3Provider));
        $this->attach(new ImageSrcsetObserver($this->s3Provider));
        $this->attach(new ImageSrcsetMetaObserver($this->s3Provider));
        $this->attach(new ImageDownsizeObserver($this->s3Provider));
        $this->attach(new AttachmentUrlObserver($this->s3Provider));
        $this->attach(new GetAttachedFileObserver());
        $this->attach(new OffloadStatusObserver($this->s3Provider));
        $this->attach(new AttachmentOffloadButtonObserver($this->s3Provider));
        $this->attach(new AttachmentDeleteObserver($this->s3Provider));
        $this->attach(new PostContentImageTagObserver($this->s3Provider));
        $this->attach(new ThumbnailRegenerationObserver($this->s3Provider));
        $this->attach(new AttachmentUpdateObserver($this->s3Provider));
        $this->attach(new UniqueFilenameObserver($this->s3Provider));

        foreach ($this->observers as $observer) {
            $observer->register();
        }
    }

    public function attach(ObserverInterface $observer)
    {
        $this->observers[] = $observer;
    }
}
