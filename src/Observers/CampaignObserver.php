<?php

namespace FlutterSdk\MagicStarter\Observers;

use FlutterSdk\MagicStarter\Support\CampaignFeed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Keeps the cached campaign rows honest.
 *
 * Runs after the surrounding transaction commits, so the next rebuild sees the
 * write; a rebuild already in flight stores its rows under the version this
 * observer just retired (see CampaignFeed), so nobody is served them.
 */
class CampaignObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * A campaign was created or updated: the cached rows are stale.
     */
    public function saved(): void
    {
        CampaignFeed::forget();
    }

    /**
     * A campaign was removed: the cached rows are stale.
     */
    public function deleted(): void
    {
        CampaignFeed::forget();
    }
}
