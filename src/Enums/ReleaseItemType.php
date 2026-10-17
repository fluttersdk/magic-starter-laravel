<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * What kind of change a release note entry describes, as `app_releases.items[].type` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum ReleaseItemType: string
{
    /** A capability the app did not have before. */
    case NEW = 'new';

    /** An existing capability that works better now. */
    case IMPROVED = 'improved';

    /** A defect that is gone. */
    case FIXED = 'fixed';

    /**
     * The translated, human-readable name of this item type.
     */
    public function label(): string
    {
        return (string) __('magic-starter::admin_misc.releases.item_types.' . $this->value);
    }
}
