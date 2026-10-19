<?php

namespace FlutterSdk\MagicStarter\Observers;

use FlutterSdk\MagicStarter\Support\SupportArticleFeed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Keeps the cached knowledge-base corpus honest.
 *
 * Runs after the surrounding transaction commits, so the next rebuild sees the
 * write. A rebuild already in flight may still finish with the old rows; it
 * stores them under the version this observer just retired (see
 * SupportArticleFeed), so nobody is served them.
 */
class SupportArticleObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * An article was created or updated: every cached corpus is stale.
     */
    public function saved(): void
    {
        SupportArticleFeed::forget();
    }

    /**
     * An article was removed: every cached corpus is stale.
     */
    public function deleted(): void
    {
        SupportArticleFeed::forget();
    }
}
