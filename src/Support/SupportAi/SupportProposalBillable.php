<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolContext;
use Illuminate\Database\Eloquent\Model;

/**
 * The account a support proposal would change, decided from the conversation
 * the customer wrote in, the same way when it is recorded and when staff decide it.
 *
 * Under user billing it is the opener; under team billing it is the
 * conversation's team, honoured only while the opener still belongs to it.
 * Both are re-read on every call and never taken from the conversation's
 * loaded relations, so a member removed or an account deleted since the
 * conversation was loaded yields null.
 */
final class SupportProposalBillable
{
    /**
     * The billable the conversation speaks for, or null when there is none.
     *
     * The token is read with the same `'user'` default as
     * {@see MagicStarter::billableModel()}, since a published config that
     * predates the key carries no key at all.
     */
    public static function for(SupportConversation $conversation): ?Model
    {
        if (config('magic-starter.billing.billable', 'user') === 'team') {
            return SupportToolContext::forConversation($conversation)->team();
        }

        return self::opener($conversation);
    }

    /**
     * The user who opened the conversation, read fresh, or null for a visitor
     * or a deleted account.
     */
    public static function opener(SupportConversation $conversation): ?Model
    {
        if ($conversation->user_id === null) {
            return null;
        }

        return MagicStarter::userModel()::query()->find($conversation->user_id);
    }
}
