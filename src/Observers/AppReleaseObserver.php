<?php

namespace FlutterSdk\MagicStarter\Observers;

use FlutterSdk\MagicStarter\Support\ReleaseFeed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Keeps the cached releases feed honest.
 *
 * Runs after the surrounding transaction commits, so the next rebuild sees the
 * write. A rebuild already in flight may still finish with the old rows; it
 * stores them under the version this observer just retired (see ReleaseFeed),
 * so nobody is served them.
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
