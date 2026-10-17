<?php

namespace FlutterSdk\MagicStarter\Observers;

use FlutterSdk\MagicStarter\Support\ReleaseFeed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Keeps the cached releases feed honest.
 *
 * Runs after the surrounding transaction commits, so a rebuild can never read
 * the table before the write that caused it is visible.
 */
class AppReleaseObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * A release was created or updated: the cached feed is stale.
     */
    public function saved(): void
    {
        ReleaseFeed::forget();
    }

    /**
     * A release was removed: the cached feed is stale.
     */
    public function deleted(): void
    {
        ReleaseFeed::forget();
    }
}
