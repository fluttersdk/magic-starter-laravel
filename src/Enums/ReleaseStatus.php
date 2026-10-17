<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Whether an app release is visible to clients, as `app_releases.status` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum ReleaseStatus: string
{
    /** Being written by staff; no client reads it. */
    case DRAFT = 'draft';

    /** Live: clients read it once its `published_at` is set. */
    case PUBLISHED = 'published';

    /**
     * The translated, human-readable name of this status.
     */
    public function label(): string
    {
        return (string) __('magic-starter::admin_misc.releases.statuses.' . $this->value);
    }
}
