<?php

namespace FlutterSdk\MagicStarter\Audit;

use FlutterSdk\MagicStarter\Events\AdminActionPerformed;

/**
 * Turns an admin panel write into one `admin.*` audit row.
 *
 * The actor is passed explicitly: the panel user is the one the event names,
 * and the request's own authenticated user is not guaranteed to be them (a
 * queued listener runs with none at all).
 */
class RecordAdminAction
{
    public function handle(AdminActionPerformed $event): void
    {
        Auditor::record("admin.{$event->action}", $event->subject, $event->context, $event->actor);
    }
}
