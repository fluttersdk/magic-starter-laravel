<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for staff changing a conversation's state from the panel: status,
 * priority or assignee.
 *
 * The actor is a staff member, never the customer. The opener and the team are
 * not changeable: an implementation never reads `user_id` or `team_id` from
 * `$changes`, so a change cannot move a thread onto another account.
 */
interface ChangesSupportConversations
{
    /**
     * Apply the changes and return the updated conversation.
     *
     * @param  array<string, mixed>  $changes  any of `status`, `priority` and `assignee_id`
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function change(Authenticatable $staff, SupportConversation $c, array $changes): SupportConversation;
}
