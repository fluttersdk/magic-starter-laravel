<?php

namespace FlutterSdk\MagicStarter\Actions;

use ErrorException;
use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAttachmentRules;
use GdImage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The one write path for files on a support message.
 *
 * Work is split in two so nothing is written for a refused file: `prepare()`
 * validates every file, decodes and re-encodes each image and checks the
 * sender's daily allowance, all before the message exists; `store()` then
 * writes the prepared bytes inside the transaction that creates the message.
 *
 * An image is decoded only after {@see SupportAttachmentRules} read its size
 * from the header. It is turned upright from its EXIF orientation and
 * re-encoded with GD in its own format, which drops every metadata block
 * (GPS included), with its long edge capped at `support.attachments.stored_max_edge`.
 * A PDF is kept byte for byte. Files land on the private
 * `support.attachments.disk` under a generated name; the sender's name is
 * display text only.
 *
 * The daily allowance (`daily_files` files and `daily_kb` kilobytes per user
 * and application day) is a cache counter, raised once the message commits. Two
 * uploads racing the last slot may both pass; the support write limiter keeps
 * that overshoot to a handful of files.
 *
 * A PDF is read through a short-lived `temporaryUrl()`. On a `local` disk that
 * needs `'serve' => true` in the disk's config, or Laravel refuses to build one.
 */
class StoreSupportAttachments
{
    /**
     * Image type to the stored mime type and extension.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const IMAGE_FORMATS = [
        IMAGETYPE_JPEG => [
            'image/jpeg',
            'jpg',
        ],
        IMAGETYPE_PNG => [
            'image/png',
            'png',
        ],
        IMAGETYPE_WEBP => [
            'image/webp',
            'webp',
        ],
    ];

    /**
     * The quality a re-encoded JPEG or WebP is written at.
     */
    private const LOSSY_QUALITY = 85;

    /**
     * Validate, re-encode and admit the files of one message.
     *
     * @param  Authenticatable  $sender  whose daily allowance the files count against
     * @param  array<int, mixed>  $files  the `attachments` the sender picked
     * @param  UploadedFile|null  $screenshot  the app's capture, stored as {@see SupportAttachmentOrigin::AUTO_SCREENSHOT}
     * @param  SupportAttachmentOrigin  $origin  where `$files` came from
     * @return list<array{origin: SupportAttachmentOrigin, name: string, mime: string, extension: string,
     *                    contents: string, width: int|null, height: int|null}>
     *
     * @throws ValidationException a refused file, keyed `attachments.N` or `screenshot`, or the
     *                             allowance, keyed `attachments`
     */
    public function prepare(
        Authenticatable $sender,
        array $files,
        ?UploadedFile $screenshot = null,
        SupportAttachmentOrigin $origin = SupportAttachmentOrigin::UPLOAD,
    ): array {
        // 1. Type, extension, size and header dimensions, before any pixel is decoded.
        Validator::make(
            [
                'attachments' => $files,
                'screenshot' => $screenshot,
            ],
            [
                ...SupportAttachmentRules::attachments(),
                ...($screenshot === null ? [] : SupportAttachmentRules::screenshot()),
            ],
        )->validate();

        // 2. Re-encode each image, keep each PDF as sent.
        $prepared = [];

        foreach (array_values($files) as $index => $file) {
            /** @var UploadedFile $file */
            $prepared[] = $this->prepareOne($file, "attachments.{$index}", $origin);
        }

        if ($screenshot !== null) {
            $prepared[] = $this->prepareOne($screenshot, 'screenshot', SupportAttachmentOrigin::AUTO_SCREENSHOT);
        }

        // 3. The allowance counts what would be stored, so a re-encode that shrinks a photo costs less.
        //    Staff files are exempt: the allowance caps what a customer can push onto the disk.
        $this->assertWithinAllowance($sender, $this->countable($prepared));

        return $prepared;
    }

    /**
     * Write the prepared files onto `$message` and count them against the sender's allowance.
     *
     * Call inside the transaction that creates the message: the written files
     * are deleted if it rolls back, and the allowance moves only once it commits.
     *
     * @param  list<array{origin: SupportAttachmentOrigin, name: string, mime: string, extension: string,
     *                    contents: string, width: int|null, height: int|null}>  $prepared  from {@see self::prepare()}
     *
     * @throws RuntimeException when the disk refuses a write
     */
    public function store(SupportMessage $message, array $prepared, Authenticatable $sender): void
    {
        if ($prepared === []) {
            return;
        }

        $diskName = (string) config('magic-starter.support.attachments.disk', 'local');
        $disk = Storage::disk($diskName);
        $written = [];

        DB::afterRollBack(static function () use ($disk, &$written): void {
            $disk->delete($written);
        });

        foreach ($prepared as $file) {
            $path = "support/{$message->conversation_id}/" . Str::uuid() . ".{$file['extension']}";

            if (! $disk->put($path, $file['contents'])) {
                throw new RuntimeException("The support attachment disk [{$diskName}] refused a write.");
            }

            $written[] = $path;

            $message->attachments()->create([
                'conversation_id' => $message->conversation_id,
                'origin' => $file['origin'],
                'disk' => $diskName,
                'path' => $path,
                'original_name' => $file['name'],
                'mime' => $file['mime'],
                'size' => strlen($file['contents']),
                'width' => $file['width'],
                'height' => $file['height'],
            ]);
        }

        DB::afterCommit(function () use ($sender, $prepared): void {
            $this->countAgainstAllowance($sender, $this->countable($prepared));
        });
    }

    /**
     * The prepared files that count against a daily allowance: every one but a staff file.
     *
     * @param  list<array{origin: SupportAttachmentOrigin, name: string, mime: string, extension: string,
     *                    contents: string, width: int|null, height: int|null}>  $prepared
     * @return list<array{origin: SupportAttachmentOrigin, name: string, mime: string, extension: string,
     *                    contents: string, width: int|null, height: int|null}>
     */
    private function countable(array $prepared): array
    {
        return array_values(array_filter(
            $prepared,
            static fn (array $file): bool => $file['origin'] !== SupportAttachmentOrigin::STAFF,
        ));
    }

    /**
     * @return array{origin: SupportAttachmentOrigin, name: string, mime: string, extension: string,
     *               contents: string, width: int|null, height: int|null}
     *
     * @throws ValidationException
     */
    private function prepareOne(UploadedFile $file, string $attribute, SupportAttachmentOrigin $origin): array
    {
        $name = $this->displayName($file);

        if ($file->getMimeType() === 'application/pdf') {
            return [
                'origin' => $origin,
                'name' => $name,
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'contents' => (string) file_get_contents($file->getRealPath()),
                'width' => null,
                'height' => null,
            ];
        }

        return [
            'origin' => $origin,
            'name' => $name,
            ...$this->reencode($file, $attribute),
        ];
    }

    /**
     * Decode, turn upright, cap and re-encode one image in its own format.
     *
     * @return array{mime: string, extension: string, contents: string, width: int, height: int}
     *
     * @throws ValidationException when the bytes behind a valid header do not decode
     */
    private function reencode(UploadedFile $file, string $attribute): array
    {
        // 1. The header was checked against the bounds; only now are the pixels decoded.
        $size = getimagesize($file->getRealPath());
        $type = $size === false ? null : $size[2];

        if (! isset(self::IMAGE_FORMATS[$type])) {
            throw $this->unreadable($attribute);
        }

        $image = $this->decode($file, $attribute);

        // 2. Apply the orientation the camera recorded, since the EXIF that carried it is about to go.
        if ($type === IMAGETYPE_JPEG) {
            $image = $this->upright($image, $this->orientation($file));
        }

        // 3. Cap the long edge, then write the pixels alone in the original format.
        $image = $this->capped($image, (int) config('magic-starter.support.attachments.stored_max_edge', 2048));
        [$mime, $extension] = self::IMAGE_FORMATS[$type];

        return [
            'mime' => $mime,
            'extension' => $extension,
            'contents' => $this->encode($image, $type),
            'width' => imagesx($image),
            'height' => imagesy($image),
        ];
    }

    /**
     * @throws ValidationException
     */
    private function decode(UploadedFile $file, string $attribute): GdImage
    {
        // GD reports a corrupt stream as a warning, which Laravel raises as an
        // ErrorException; either way it is the sender's file that is bad.
        try {
            $image = imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        } catch (ErrorException) {
            throw $this->unreadable($attribute);
        }

        if (! $image instanceof GdImage) {
            throw $this->unreadable($attribute);
        }

        imagepalettetotruecolor($image);

        return $image;
    }

    /**
     * The EXIF orientation of a JPEG, 1 (upright) when it records none.
     *
     * A malformed EXIF block is read as upright: the orientation is advisory,
     * the block is dropped on re-encode, and refusing the photo over it would
     * refuse what most cameras write.
     */
    private function orientation(UploadedFile $file): int
    {
        try {
            $exif = exif_read_data($file->getRealPath());
        } catch (ErrorException) {
            return 1;
        }

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /**
     * Rotate and mirror an image so it displays as the camera meant it to.
     *
     * The angles are counter-clockwise, as `imagerotate()` takes them.
     */
    private function upright(GdImage $image, int $orientation): GdImage
    {
        [$angle, $mirror] = match ($orientation) {
            2 => [0, true],
            3 => [180, false],
            4 => [180, true],
            5 => [270, true],
            6 => [270, false],
            7 => [90, true],
            8 => [90, false],
            default => [0, false],
        };

        if ($angle !== 0) {
            $image = imagerotate($image, $angle, 0)
                ?: throw new RuntimeException('GD could not rotate a support attachment.');
        }

        if ($mirror) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /**
     * The image scaled down so its long edge is at most `$maxEdge`, transparency kept.
     */
    private function capped(GdImage $image, int $maxEdge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $maxEdge) {
            return $image;
        }

        $ratio = $maxEdge / max($width, $height);
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        $scaled = imagecreatetruecolor($targetWidth, $targetHeight)
            ?: throw new RuntimeException('GD could not allocate a support attachment.');
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $scaled;
    }

    private function encode(GdImage $image, int $type): string
    {
        $stream = fopen('php://memory', 'w+b')
            ?: throw new RuntimeException('Could not open a memory stream for a support attachment.');

        imagealphablending($image, false);
        imagesavealpha($image, true);

        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $stream, self::LOSSY_QUALITY),
            IMAGETYPE_WEBP => imagewebp($image, $stream, self::LOSSY_QUALITY),
            default => imagepng($image, $stream),
        };

        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    /**
     * The sender's file name, reduced to something safe to show and to put in a header.
     */
    private function displayName(UploadedFile $file): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $file->getClientOriginalName()));

        return Str::limit($name === '' ? 'file' : $name, 255, '');
    }

    /**
     * @param  list<array{contents: string}>  $prepared
     *
     * @throws ValidationException
     */
    private function assertWithinAllowance(Authenticatable $sender, array $prepared): void
    {
        if ($prepared === []) {
            return;
        }

        [$filesKey, $bytesKey] = $this->allowanceKeys($sender);

        $files = (int) Cache::get($filesKey, 0) + count($prepared);
        $bytes = (int) Cache::get($bytesKey, 0) + $this->bytes($prepared);

        if ($files > (int) config('magic-starter.support.attachments.daily_files', 30)
            || $bytes > (int) config('magic-starter.support.attachments.daily_kb', 102400) * 1024) {
            throw ValidationException::withMessages([
                'attachments' => __('magic-starter::support.attachments.daily_limit'),
            ]);
        }
    }

    /**
     * @param  list<array{contents: string}>  $prepared
     */
    private function countAgainstAllowance(Authenticatable $sender, array $prepared): void
    {
        [$filesKey, $bytesKey] = $this->allowanceKeys($sender);
        $expires = now()->endOfDay()->addMinute();

        foreach ([
            $filesKey => count($prepared),
            $bytesKey => $this->bytes($prepared),
        ] as $key => $by) {
            Cache::add($key, 0, $expires);
            Cache::increment($key, $by);
        }
    }

    /**
     * @return array{0: string, 1: string} the file and the byte counter of the sender's current day
     */
    private function allowanceKeys(Authenticatable $sender): array
    {
        $prefix = 'magic-starter:support-attachments:' . $sender->getAuthIdentifier() . ':' . now()->toDateString();

        return [
            "{$prefix}:files",
            "{$prefix}:bytes",
        ];
    }

    /**
     * @param  list<array{contents: string}>  $prepared
     */
    private function bytes(array $prepared): int
    {
        return array_sum(array_map(static fn (array $file): int => strlen($file['contents']), $prepared));
    }

    private function unreadable(string $attribute): ValidationException
    {
        return ValidationException::withMessages([
            $attribute => __('validation.image', [
                'attribute' => $attribute,
            ]),
        ]);
    }
}
