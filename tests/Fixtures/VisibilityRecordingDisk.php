<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * A faked local disk that records every ACL the code under test asks for.
 *
 * On an S3-compatible CDN a `visibility` write option rides on PutObject as
 * `x-amz-acl`, `setVisibility()` is a PutObjectAcl that replaces the object
 * with an empty body, and `getVisibility()` is a GetObjectAcl. A local fake
 * performs all three harmlessly, so a test has to look at the calls rather
 * than at the stored bytes.
 */
class VisibilityRecordingDisk extends FilesystemAdapter
{
    /**
     * Every ACL request seen, as `setVisibility:<path>`, `getVisibility:<path>` or `put:<path>:<visibility>`.
     *
     * @var list<string>
     */
    public array $visibilityCalls = [];

    /**
     * Fake the named disk and swap in a recorder over the same root and config.
     */
    public static function fake(string $name): self
    {
        $fake = Storage::fake($name);
        $disk = new self($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());

        Storage::set($name, $disk);

        return $disk;
    }

    /**
     * @param  string  $path
     * @param  mixed  $contents
     * @param  mixed  $options
     */
    public function put($path, $contents, $options = []): string|bool
    {
        $visibility = is_string($options) ? $options : ($options['visibility'] ?? null);

        if ($visibility !== null) {
            $this->visibilityCalls[] = "put:{$path}:{$visibility}";
        }

        return parent::put($path, $contents, $options);
    }

    /**
     * @param  string  $path
     * @param  string  $visibility
     */
    public function setVisibility($path, $visibility): bool
    {
        $this->visibilityCalls[] = "setVisibility:{$path}";

        return parent::setVisibility($path, $visibility);
    }

    /**
     * @param  string  $path
     */
    public function getVisibility($path): string
    {
        $this->visibilityCalls[] = "getVisibility:{$path}";

        return parent::getVisibility($path);
    }
}
