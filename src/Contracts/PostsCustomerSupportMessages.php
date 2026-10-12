<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for a customer adding a message to their own conversation.
 *
 * The actor is the customer: the opener when `$user` is set, or the visitor
 * holding the public thread link when it is null, in which case the caller has
 * already resolved `$conversation` from that link. A signed-in user who is not
 * the opener is answered as if the conversation did not exist (404, never 403);
 * a team mate of the opener is no exception. Each message dispatches
 * {@see SupportConversationNeedsStaff} with reason `customer_message`.
 */
interface PostsCustomerSupportMessages
{
    /**
     * Append the customer's message to the thread.
     *
     * @param  Authenticatable|null  $user  the opener, or null for a visitor on the public link
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function post(SupportConversation $c, string $body, ?Authenticatable $user): SupportMessage;
}
