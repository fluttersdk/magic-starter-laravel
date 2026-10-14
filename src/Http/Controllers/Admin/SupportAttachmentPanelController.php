<?php

namespace FlutterSdk\MagicStarter\Http\Controllers\Admin;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files of a support conversation, read by staff from the admin panel.
 *
 * Mounted by the plugin as an authenticated panel route, so the panel session
 * is the read credential and the panel's auth middleware runs first; the gate
 * is asked again here so a panel configured without Filament's own
 * `Authenticate` middleware still refuses. There is no signed url.
 *
 * Staff read every file of the thread, a staff note's included. The headers
 * are the customer route's: the type comes from the row, never re-guessed from
 * the bytes, an image is shown inline and never sniffed, and a PDF is only ever
 * a download, since its contents are the customer's and untrusted.
 */
class SupportAttachmentPanelController
{
    /**
     * The types served, each sent as its own `Content-Type`; a row of any other type is not found.
     *
     * @var list<string>
     */
    private const SERVED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/pdf',
    ];

    public function show(string $conversation, string $attachment): StreamedResponse
    {
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        abort_unless($user instanceof FilamentUser && $panel !== null && $user->canAccessPanel($panel), 403);

        $file = $this->find($conversation, $attachment);

        return Storage::disk($file->disk)->response(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            $file->mime === 'application/pdf' ? 'attachment' : 'inline',
        );
    }

    /**
     * A served file of the named conversation, or a 404.
     *
     * @throws ModelNotFoundException<SupportMessageAttachment>
     */
    private function find(string $conversation, string $attachment): SupportMessageAttachment
    {
        // A key of the wrong shape never reaches the query, where PostgreSQL would refuse to compare it to a uuid.
        if (! TeamKey::looksLikeOne($conversation) || ! TeamKey::looksLikeOne($attachment)) {
            throw (new ModelNotFoundException)->setModel(SupportMessageAttachment::class, [
                $attachment,
            ]);
        }

        return SupportMessageAttachment::query()
            ->where('conversation_id', $conversation)
            ->whereIn('mime', self::SERVED_TYPES)
            ->whereKey($attachment)
            ->firstOrFail();
    }
}
