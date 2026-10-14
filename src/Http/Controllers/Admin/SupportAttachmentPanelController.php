<?php

namespace FlutterSdk\MagicStarter\Http\Controllers\Admin;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Support\SupportAttachmentResponse;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files of a support conversation, read by staff from the admin panel.
 *
 * Mounted by the plugin as an authenticated panel route, so the panel session
 * is the read credential and the panel's auth middleware runs first; the gate
 * is asked again here so a panel configured without Filament's own
 * `Authenticate` middleware still refuses. There is no signed url.
 *
 * Staff read every file of the thread, a staff note's included. The response
 * is the customer route's, through {@see SupportAttachmentResponse}: a PDF is
 * only ever a download, since its contents are the customer's and untrusted.
 */
class SupportAttachmentPanelController
{
    /**
     * Stream a file of the conversation to a staff user of the current panel; anyone else is refused with 403.
     */
    public function show(string $conversation, string $attachment): StreamedResponse
    {
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        abort_unless($user instanceof FilamentUser && $panel !== null && $user->canAccessPanel($panel), 403);

        return SupportAttachmentResponse::make($this->find($conversation, $attachment));
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
            ->whereIn('mime', SupportAttachmentResponse::MIMES)
            ->whereKey($attachment)
            ->firstOrFail();
    }
}
