<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Where a catalogue product is priced and sold: the keys of a product's
 * `prices` and of its store `refs`.
 *
 * Not {@see BillingProvider}. A provider answers who bills a subscriber today
 * and includes `none` and `manual`, which sell nothing; a channel is a shop
 * window a price is written for. The two also spell Play differently on
 * purpose: `play` is the catalogue's config word, `play_store` the wire word.
 */
enum BillingChannel: string
{
    /**
     * The card rail. Its price is the source every store price derives from
     * and is never itself derived.
     */
    case WEB = 'web';

    case APP_STORE = 'app_store';

    /** Google Play, whose ref is `<subscription_id>:<base_plan_id>`. */
    case PLAY = 'play';
}
