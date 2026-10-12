<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for opening a support conversation with its first message.
 *
 * The actor is the customer. Who owns the conversation comes from the auth
 * context only: `open()` takes the opener and their current team from `$user`,
 * and `openPublic()` stores neither. An implementation never reads `user_id` or
 * `team_id` from `$input`, so a customer cannot open a thread on someone else's
 * account. A new conversation dispatches {@see SupportConversationNeedsStaff}
 * with reason `opened`.
 */
interface OpensSupportConversations
{
    /**
     * Open a conversation for a signed-in user.
     *
     * @param  array<string, mixed>  $input  the customer's request: subject, body and source
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function open(Authenticatable $user, array $input): SupportConversation;

    /**
     * Open a conversation for a visitor with no account, who follows it through
     * the public thread link mailed to the address they gave.
     *
     * @param  array<string, mixed>  $input  the visitor's request: email, name, subject, body and source
     *
     * @throws ValidationException
     */
    public function openPublic(array $input): SupportConversation;
}
