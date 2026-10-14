<?php

namespace FlutterSdk\MagicStarter\Support;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

/**
 * The one source of the validation rules for a file sent to support.
 *
 * The type is sniffed from the bytes (`File::types`) and the client's extension
 * must agree (`extensions`), so neither an SVG nor an HTML page passes under a
 * `.png` name. An image's size is read from its header with `getimagesize()`,
 * which decodes no pixel, and refused past `support.attachments.max_edge` on a
 * side or `max_pixels` in total; `bail` keeps a file of the wrong type from
 * reaching that check at all.
 */
final class SupportAttachmentRules
{
    /**
     * The image types `getimagesize()` may report for a stored image.
     *
     * @var list<int>
     */
    public const IMAGE_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_WEBP,
    ];

    /**
     * Rules for the `attachments` array of a customer's message: at most
     * `max_files` files, each of the configured `types` and `max_kb`.
     *
     * @return array<string, list<mixed>>
     */
    public static function attachments(): array
    {
        $types = self::types();

        return [
            'attachments' => [
                'sometimes',
                'array',
                'max:' . (int) config('magic-starter.support.attachments.max_files', 3),
            ],
            'attachments.*' => self::file($types),
        ];
    }

    /**
     * Rules for the screenshot the app captures when a conversation is opened: one PNG.
     *
     * @return array<string, list<mixed>>
     */
    public static function screenshot(): array
    {
        return [
            'screenshot' => [
                'sometimes',
                ...self::file([
                    'png',
                ]),
            ],
        ];
    }

    /**
     * The extensions a file may have, from `support.attachments.types`.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_map(
            strval(...),
            (array) config('magic-starter.support.attachments.types', [
                'jpg',
                'jpeg',
                'png',
                'webp',
                'pdf',
            ]),
        ));
    }

    /**
     * @param  list<string>  $types
     * @return list<mixed>
     */
    private static function file(array $types): array
    {
        return [
            'bail',
            File::types($types)
                ->extensions($types)
                ->max((int) config('magic-starter.support.attachments.max_kb', 5120)),
            self::withinPixelBounds(),
        ];
    }

    /**
     * Refuse an image whose header claims more than the configured edge or pixel count.
     *
     * A PDF has no image header and passes; a file sniffed as an image whose
     * header cannot be read, or names a type not stored, is refused.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private static function withinPixelBounds(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile || ! str_starts_with((string) $value->getMimeType(), 'image/')) {
                return;
            }

            $size = getimagesize($value->getRealPath());

            if ($size === false || ! in_array($size[2], self::IMAGE_TYPES, true)) {
                $fail('validation.image')->translate();

                return;
            }

            $maxEdge = (int) config('magic-starter.support.attachments.max_edge', 8000);
            $maxPixels = (int) config('magic-starter.support.attachments.max_pixels', 40000000);

            if (max($size[0], $size[1]) > $maxEdge || $maxPixels < $size[0] * $size[1]) {
                $fail('validation.dimensions')->translate();
            }
        };
    }
}
