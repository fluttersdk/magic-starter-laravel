<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for staff answering a conversation from the panel.
 *
 * The actor is a staff member, never the customer. A reply is customer-visible;
 * a note (`$note` true) is staff-only and never reaches the customer by any
 * read or mail.
 */
interface RepliesToSupport
{
    /**
     * Add a staff reply or an internal note to the thread.
     *
     * @param  bool  $note  true for an internal note the customer never sees
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function reply(Authenticatable $staff, SupportConversation $c, string $body, bool $note): SupportMessage;
}
