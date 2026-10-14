<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Support\SupportAttachmentResponse;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PDF behind a link minted by {@see SupportAttachmentController::link()}.
 *
 * The route sits behind Laravel's `signed` middleware and nothing else: the
 * short-lived signature over the attachment key is the whole credential, so a
 * system viewer that holds no bearer token can follow it, and a tampered or
 * expired url is refused with 403 before this runs. Only a customer-visible
 * PDF is served, always as a download; an image never has a signed url.
 */
class SupportAttachmentDownloadController
{
    /**
     * The route name the link is signed for.
     */
    public const ROUTE = 'magic-starter.support.attachment-download';

    /**
     * Stream the signed-for PDF as a download.
     *
     * @throws ModelNotFoundException<SupportMessageAttachment> for an image, a staff note's file or a missing row
     */
    public function __invoke(string $attachment): StreamedResponse
    {
        if (! TeamKey::looksLikeOne($attachment)) {
            throw (new ModelNotFoundException)->setModel(SupportMessageAttachment::class, [
                $attachment,
            ]);
        }

        $file = SupportMessageAttachment::query()
            ->where('mime', SupportAttachmentResponse::PDF)
            ->whereHas('message', function (Builder $query): void {
                /** @var Builder<SupportMessage> $query */
                $query->customerVisible();
            })
            ->whereKey($attachment)
            ->firstOrFail();

        return SupportAttachmentResponse::make($file);
    }
}
