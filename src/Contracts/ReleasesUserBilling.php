<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Support\BillingReleaseFailed;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for ending what bills a user before their account is deleted.
 *
 * Called by the account-deletion scheduler, before it locks the account, when
 * `account_deletion.billing` is `release`. It is a remote call, so the scheduler
 * makes it before its own lock transaction and never holds one open across it.
 * A caller that schedules inside a transaction of its own holds that one open
 * across the call: the Apple notification handler does, to apply a notification
 * atomically, so a failure here rolls the whole notification back for the
 * provider's redelivery.
 *
 * What a rail can do differs. The card rail is this application's own merchant
 * account, so its subscriptions are cancelled. A store's subscription lives in
 * the customer's store account, which only they can reach, so it is reported for
 * the client to send them there, and it does not stand in the way.
 *
 * @phpstan-type BillingRelease array{
 *     cancelled: list<array{provider: string, id: string}>,
 *     store: list<array{provider: string, manage_url: string|null}>,
 * }
 */
interface ReleasesUserBilling
{
    /**
     * End what bills the user and report what happened.
     *
     * `cancelled` names each subscription ended now: its `provider` (a
     * `BillingProvider` wire value, `stripe`) and the rail's `id`. `store`
     * names each store subscription still billing, which the user has to cancel
     * in the store: its `provider` (`app_store` or `play_store`) and the
     * `manage_url` the store gave for it, null when it gave none. Both are empty
     * for a user nothing bills, and for a user who is not the configured
     * billable subject.
     *
     * Idempotent: a subscription that has already ended is not cancelled again.
     * A subscription it cancels is ended in the application's own records too,
     * not only at the rail, because the purge and the deleter hold an account
     * while a card subscription is still valid and neither releases anything.
     * Not atomic: when it throws, the subscriptions ended before the failure
     * stay ended.
     *
     * @return BillingRelease
     *
     * @throws BillingReleaseFailed When a rail refuses or cannot be reached; the
     *                              deletion must not go on as if the person had
     *                              stopped paying.
     */
    public function release(Authenticatable $user): array;
}
