<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one source of the support file types and of the response that serves a file.
 *
 * Every route that hands a file out (the customer's, the staff panel's and the
 * signed PDF download) answers through {@see self::make()}, and each decides on
 * its own who may read. The type sent is the row's, decided from the content
 * when the file was stored and never re-guessed from the bytes on the way out:
 * re-guessing would let a file changed on the disk choose its own type. A row
 * of a type outside {@see self::MIMES} is not served.
 */
final class SupportAttachmentResponse
{
    /**
     * The mime type a PDF is stored and served as.
     */
    public const PDF = 'application/pdf';

    /**
     * The mime types an image is stored as after its re-encode.
     *
     * @var list<string>
     */
    public const IMAGE_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    /**
     * Every mime type a support file is stored and served as.
     *
     * @var list<string>
     */
    public const MIMES = [
        ...self::IMAGE_MIMES,
        self::PDF,
    ];

    /**
     * Stream the file: an image inline, a PDF only ever as a download, never sniffed and never cached.
     */
    public static function make(SupportMessageAttachment $file): StreamedResponse
    {
        return Storage::disk($file->disk)->response(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            $file->mime === self::PDF ? 'attachment' : 'inline',
        );
    }
}
