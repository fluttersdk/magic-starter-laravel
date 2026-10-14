<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files on a signed-in customer's own support conversation.
 *
 * The conversation is found through {@see SupportConversationController::findOwned()}
 * and the file within it, so a team mate, a stranger, or a file of another
 * conversation all answer 404. A file on a staff note is not the customer's to
 * read either. The bearer token is the read credential: a signed url is minted
 * only for a PDF, through {@see self::link()}, for the clients that must hand
 * it to a system viewer.
 */
class SupportAttachmentController
{
    /**
     * The types served, each sent as its own `Content-Type`.
     *
     * The type comes from the row, decided from the content when the file was
     * stored, never guessed from the bytes on the way out: re-guessing would
     * let a file changed on the disk choose its own type. A row of any other
     * type is not served.
     *
     * @var list<string>
     */
    private const SERVED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/pdf',
    ];

    /**
     * Stream the file: an image inline, a PDF as a download, never sniffed and never cached.
     */
    public function show(Request $request, string $conversation, string $attachment): StreamedResponse
    {
        $file = $this->findOwned($request, $conversation, $attachment);
        $isPdf = $file->mime === 'application/pdf';

        return Storage::disk($file->disk)->response(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            $isPdf ? 'attachment' : 'inline',
        );
    }

    /**
     * A url to a PDF that expires after `support.attachments.link_ttl_seconds`.
     *
     * An image has no link: it is read through {@see self::show()} with the bearer token.
     */
    public function link(Request $request, string $conversation, string $attachment): JsonResponse
    {
        $file = $this->findOwned($request, $conversation, $attachment);

        if ($file->mime !== 'application/pdf') {
            throw (new ModelNotFoundException)->setModel(SupportMessageAttachment::class, [
                $attachment,
            ]);
        }

        $url = Storage::disk($file->disk)->temporaryUrl(
            $file->path,
            now()->addSeconds((int) config('magic-starter.support.attachments.link_ttl_seconds', 60)),
            [
                'ResponseContentType' => 'application/pdf',
                'ResponseContentDisposition' => 'attachment',
            ],
        );

        return response()->json([
            'url' => $url,
        ]);
    }

    /**
     * A customer-visible file of the caller's own conversation, or a 404.
     *
     * @throws ModelNotFoundException<SupportMessageAttachment>
     */
    private function findOwned(Request $request, string $conversation, string $attachment): SupportMessageAttachment
    {
        $owned = SupportConversationController::findOwned($request, $conversation);

        if (! TeamKey::looksLikeOne($attachment)) {
            throw (new ModelNotFoundException)->setModel(SupportMessageAttachment::class, [
                $attachment,
            ]);
        }

        return SupportMessageAttachment::query()
            ->where('conversation_id', $owned->getKey())
            ->whereIn('mime', self::SERVED_TYPES)
            ->whereHas('message', function (Builder $query): void {
                /** @var Builder<SupportMessage> $query */
                $query->customerVisible();
            })
            ->whereKey($attachment)
            ->firstOrFail();
    }
}
