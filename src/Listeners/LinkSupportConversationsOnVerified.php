<?php

namespace FlutterSdk\MagicStarter\Listeners;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Hands the public conversations of a newly proven address to its account.
 *
 * Bound to `Verified` and never to {@see Registered}: anyone can register with
 * somebody else's address, so only a clicked verification link, or a provider
 * that vouched for the address at a social sign-up, proves the mailbox. Guests
 * read as verified without proving anything, so the account's flag is never
 * consulted; only the event counts.
 *
 * Only unowned rows move, onto the user and nobody else; the team stays null
 * because a visitor's question was never a team's. The public link of each
 * moved conversation is revoked, since the account now reads it.
 */
class LinkSupportConversationsOnVerified
{
    /**
     * Link every unowned conversation whose address matches, case-insensitively.
     */
    public function handle(Verified $event): void
    {
        $user = $event->user;

        // The package's users table always carries `email`; the event's
        // documented contract does not expose the key, the model does.
        if (! $user instanceof Model || ! $user instanceof Authenticatable) {
            return;
        }

        $email = mb_strtolower(trim((string) $user->getAttribute('email')));

        if ($email === '') {
            return;
        }

        DB::transaction(function () use ($user, $email): void {
            $conversations = SupportConversation::query()
                ->whereNull('user_id')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->get();

            foreach ($conversations as $conversation) {
                $conversation->forceFill([
                    'user_id' => $user->getAuthIdentifier(),
                ]);

                // Saves the owner together with the revoked link.
                SupportThreadToken::revoke($conversation);

                Auditor::record('support.conversation_linked', $conversation, [], $user);
            }
        });
    }
}
