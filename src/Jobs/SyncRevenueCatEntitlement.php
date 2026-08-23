<?php

namespace FlutterSdk\MagicStarter\Jobs;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The store rail's feeder for the billable's entitlement columns.
 *
 * A RevenueCat webhook is a dirty signal, not a source. The event says
 * something about a subscriber changed; WHAT they are now entitled to is read
 * back from `GET /v1/subscribers/{app_user_id}` ({@see RevenueCatClient}) and
 * claimed through {@see WritesEntitlement}, which is the only code path allowed
 * to write those columns.
 *
 * ## The event type does not decide the tier. That is the whole design.
 *
 * Six of the event types that reach this job can change an entitlement, and
 * four of them mean the opposite of what their name suggests:
 *
 *  - `CANCELLATION` is auto-renew switched off. The customer has paid through
 *    the end of the period and is still entitled. It does NOT revoke.
 *  - `BILLING_ISSUE` is a charge that failed and a store that is retrying, with
 *    a grace window attached. It does NOT revoke.
 *  - `SUBSCRIPTION_PAUSED` (Google Play only) carries a resume date, and the
 *    pause takes effect at the end of the paid period. It does NOT revoke.
 *  - `PRODUCT_CHANGE` announces a change that is NOT yet in effect; the new
 *    product starts at the next renewal, which arrives later as its own
 *    `RENEWAL`. Writing its tier downgrades somebody who has not been
 *    downgraded.
 *
 * Rather than four exceptions, there is one rule: nothing in this job reads a
 * tier, a product id or a period out of the event. It reads WHICH subscribers to
 * re-read (and when the event happened, for ordering), then maps everything else
 * from the authoritative response. `EXPIRATION` revokes not because of its name
 * but because by the time it is delivered the authoritative read shows nothing
 * live.
 *
 * ## Every ambiguity resolves toward keeping the entitlement
 *
 * A tier held a little too long costs a log line; a tier taken from somebody who
 * is paying costs a support ticket and their trust. So: an unmapped product
 * warns and writes nothing (the same rule the Stripe feeder applies to an
 * unmapped price); a subscriber whose only subscriptions are sandbox purchases
 * writes nothing; a subscription whose dates cannot be read writes nothing; a
 * failed read raises so the queue retries instead of revoking. Only an
 * authoritative read that shows nothing live revokes, and then only on the store
 * rail that granted it.
 *
 * ## Idempotency is not this job's problem
 *
 * It needs none of its own. RevenueCat reuses the `id` and the
 * `event_timestamp_ms` on every retry, the webhook endpoint dedupes on the id,
 * and the write action drops any claim whose event is not strictly newer than
 * the one already on record for the same rail. A second mechanism here would
 * only be a second thing to get wrong.
 *
 * What this job DOES own is releasing that claim when it dies permanently
 * ({@see self::failed()}). That is not a second dedup mechanism; it is the one
 * thing the endpoint cannot do, because it commits the claim minutes before the
 * work is attempted and its own retry budget is spent long before RevenueCat
 * stops redelivering.
 */
class SyncRevenueCatEntitlement implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The provenance column this job reads back is decoded through the shared
     * readers rather than off the model. The package ships the columns and not
     * the model that casts them, so an adopter is free to cast `plan_provider`
     * to a tier enum of their own; read straight off the model that value is an
     * enum instance where `BillingProvider::fromWire()` wants a `?string`, which
     * is a TypeError on a revocation path.
     */
    use ReadsBillableAttributes;

    use SerializesModels;

    /**
     * The queue this job rides.
     *
     * `default`, because it is the one queue an adopter's worker drains without
     * being told to. A package-owned `billing` queue would need a supervisor
     * entry in the same breath, on infrastructure this package does not own, and
     * a queue with no worker behind it is a job that dispatches successfully and
     * never runs.
     */
    public const QUEUE = 'default';

    /**
     * The namespace the store rail's event ids are claimed under.
     *
     * `ProcessedWebhookEvent.event_id` is ONE unique column serving two senders
     * whose id spaces are unrelated, and the prefix is what keeps a Stripe id
     * from turning a real store delivery into a permanent no-op.
     *
     * PUBLIC, unlike the application this was extracted from, where it was
     * `protected` and the webhook controller spelled the same prefix inline: the
     * two could drift, and the only thing keeping them together was an
     * end-to-end test that claimed through the endpoint. Here the controller
     * that claims the id ships in the same package, so it can name this constant
     * instead of a second copy of the string, and the drift stops being possible
     * rather than being watched for. The dependency direction is the safe one: a
     * controller may know its job, a job must not know its controller.
     */
    public const CLAIM_PREFIX = 'rc:';

    /**
     * RevenueCat abandons a delivery after five attempts inside about three
     * hours, so a read that fails transiently must be retried here rather than
     * left to the reconciler: the alternative is a paying customer on the free
     * tier until the next sweep.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Comfortably above the operation budget of the reads it makes
     * ({@see RevenueCatClient}: 10 seconds each), and under the 60 seconds a
     * queue worker is given by default. A `TRANSFER` re-reads both sides, so the
     * arithmetic has to hold for a handful of reads rather than one.
     *
     * @var int
     */
    public $timeout = 55;

    /**
     * @param  array<string, mixed>  $event  the RevenueCat webhook `event`
     *                                       object. Only IDENTITY and ORDERING
     *                                       are read from it: `app_user_id` plus
     *                                       `transferred_to`/`transferred_from`
     *                                       (which subscribers to re-read),
     *                                       `original_app_user_id` and `aliases`
     *                                       (a fallback when the last-seen id is
     *                                       not a billable key),
     *                                       `event_timestamp_ms` (the ordering
     *                                       key) and `type` (recorded verbatim
     *                                       as the rail's own word). Not the
     *                                       tier, not the product, not the
     *                                       period: those come only from the
     *                                       authoritative read.
     */
    public function __construct(protected array $event)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Wait a minute, then five, before giving up. A store's API and our own
     * network are the failures being waited out here.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Re-read every subscriber this event touches and claim what each one is
     * owed.
     */
    public function handle(RevenueCatClient $revenueCat, WritesEntitlement $writeEntitlement): void
    {
        // The alias fallback applies to the event's OWN subscriber and to nothing
        // else. `transferred_from` and `transferred_to` name two DIFFERENT
        // subscribers, and `aliases` describes one, so substituting an alias for
        // a transferred id would read one side's subscriber and claim it against
        // the other side's billable: an anonymous source id would resolve to the
        // destination billable and then claim that billable's entitlement from
        // the emptied source, which is a revocation against the subject that just
        // gained the subscription.
        $primary = is_string($this->event['app_user_id'] ?? null)
            ? trim((string) $this->event['app_user_id'])
            : null;

        foreach ($this->appUserIds() as $appUserId) {
            $billable = $this->resolveBillable($appUserId, allowAliasFallback: $appUserId === $primary);

            if (! $billable instanceof Model) {
                continue;
            }

            $claim = $this->claimFor($billable, $revenueCat->subscriber($appUserId));

            if ($claim instanceof EntitlementWrite) {
                $writeEntitlement->write($claim);
            }
        }
    }

    /**
     * Release the event id this delivery claimed, so a REDELIVERY is processed
     * rather than acknowledged as a no-op.
     *
     * The endpoint claims the id and dispatches inside one transaction, which is
     * the right shape for a queue that refuses the job: the claim rolls back
     * with the dispatch. But the work happens later, and the budget above
     * ({@see self::$tries} with {@see self::backoff()}) is spent inside about six
     * minutes, well inside RevenueCat's 5/10/20/40/80-minute retry schedule. So
     * without this every remaining delivery of the same id answers 200 having
     * done nothing, and a dropped `INITIAL_PURCHASE` is the failure this rail
     * cannot recover from on its own: the store will not resell what the
     * customer already owns.
     *
     * Releasing is safe BY CONSTRUCTION rather than by luck. This job is a
     * re-read-and-claim: nothing is taken from the event but identity and
     * ordering, and {@see WritesEntitlement} drops any claim not strictly newer
     * than what is already on record for the same rail. So a second processing of
     * the same event converges instead of double-applying, which is the property
     * that makes releasing the dedup row cheaper than keeping a burnt one.
     *
     * The exception is not handled here. This releases and returns; the failure
     * stays failed and stays recorded in `failed_jobs`.
     */
    public function failed(?Throwable $exception): void
    {
        $eventId = $this->event['id'] ?? null;

        // Narrowed rather than trusted: the endpoint only claims an id it has
        // already read as a non-empty string, so anything else here means no
        // claim was ever made and there is nothing to release.
        if (! is_string($eventId) || trim($eventId) === '') {
            return;
        }

        // VERBATIM, never trimmed: the endpoint claims `prefix . id` with the id
        // exactly as the payload spelled it, so trimming here would look for a
        // key nothing wrote and leave a padded id burnt. `appUserIds()` trims for
        // the opposite reason, and the two must not be made to match.
        $released = ProcessedWebhookEvent::query()
            ->where('event_id', self::CLAIM_PREFIX . $eventId)
            ->delete();

        $this->warn(
            'released_burnt_event_id',
            'A RevenueCat re-read failed permanently; its claimed event id is released so a '
            . 'redelivery is processed instead of acknowledged as a no-op.',
            [
                'exception' => $exception === null ? null : $exception::class,
                'released' => $released,
            ],
        );
    }

    /**
     * The subscribers this event requires a fresh read of.
     *
     * Normally one. A `TRANSFER` moves a subscription between App User IDs and
     * therefore between BILLABLE SUBJECTS, so both sides have to be re-read: the
     * destination gains an entitlement and the source loses one, and syncing only
     * the destination leaves a subject paying for a subscription that now funds
     * somebody else.
     *
     * Destinations are ordered before sources deliberately. Both writes are
     * independent, so if the job dies between them the customer is left holding
     * a tier rather than missing one.
     *
     * @return array<int, string>
     */
    protected function appUserIds(): array
    {
        $ids = array_merge(
            array_filter([$this->event['app_user_id'] ?? null], 'is_string'),
            array_filter((array) ($this->event['transferred_to'] ?? []), 'is_string'),
            array_filter((array) ($this->event['transferred_from'] ?? []), 'is_string'),
        );

        // TRIMMED, not merely filtered on a trimmed value. The two differ, and
        // the difference WOULD reach a customer: `$primary` in `handle()` is
        // built with `trim()`, so a padded `app_user_id` compared unequal to its
        // own loop entry, the alias fallback was refused for the event's OWN
        // subscriber, and the refusal said "transferred" about an id nothing had
        // transferred. Trimming here also makes the id handed to the billable
        // lookup and the one in the subscriber URL the same string in every case.
        return array_values(array_unique(array_filter(
            array_map('trim', $ids),
            fn (string $id): bool => $id !== '',
        )));
    }

    /**
     * The billable subject behind an App User ID, or null with a reason logged.
     *
     * The id is validated against the shape billable keys actually have BEFORE it
     * reaches a query. That is not belt-and-braces: on a UUID deployment the key
     * column is a PostgreSQL `uuid`, and a malformed value in `where id = ?` is a
     * 500 there and a clean null on SQLite, so without this guard the default
     * test engine could not see the failure production would get. An id nobody
     * here owns is not an error either; the webhook already answered 200 for it.
     */
    protected function resolveBillable(string $appUserId, bool $allowAliasFallback = false): ?Model
    {
        if (! TeamKey::looksLikeOne($appUserId)) {
            if (! $allowAliasFallback) {
                $this->warn(
                    'malformed_app_user_id',
                    'RevenueCat named a transferred app_user_id that cannot be a billable key; '
                    . 'entitlement left untouched. An alias cannot stand in for a transfer side, '
                    . 'because the two sides are different subscribers.',
                    ['app_user_id' => $appUserId],
                );

                return null;
            }

            // `app_user_id` is documented as the LAST SEEN id, and RevenueCat
            // instructs callers to "search both the `original_app_user_id` and
            // the `aliases` array". A subscriber whose last-seen id is one the
            // SDK generated (an anonymous `$RCAnonymousID:...` before `logIn`
            // ran) therefore arrives here malformed while the subject's own id
            // sits in the aliases, and refusing it is the failure this job's own
            // docblock calls the worst one available: a paying customer left on
            // free, with no self-serve recovery, because the store will not
            // resell what they already own.
            $alias = $this->unambiguousAliasKey();

            if ($alias === null) {
                $this->warn(
                    'malformed_app_user_id',
                    'RevenueCat named an app_user_id that cannot be a billable key, and no single '
                    . 'alias could stand in for it; entitlement left untouched.',
                    ['app_user_id' => $appUserId],
                );

                return null;
            }

            $appUserId = $alias;
        }

        /** @var class-string<Model> $model */
        $model = MagicStarter::billableModel();

        $billable = $model::query()->find($appUserId);

        if (! $billable instanceof Model) {
            $this->warn(
                'unknown_billable',
                'RevenueCat named an app_user_id with no billable behind it; entitlement left untouched.',
                ['app_user_id' => $appUserId],
            );

            return null;
        }

        return $billable;
    }

    /**
     * The one alias on this event that could be a billable key, or null when
     * there is not exactly one.
     *
     * EXACTLY one, and the strictness is the whole point rather than caution.
     * `aliases` is "all App User IDs ever used by the subscriber", and an
     * application that calls `identify()` on every subject switch makes BOTH
     * subject ids aliases of a single subscriber when one owner moves between two
     * of their own subjects on one device. Taking "the first alias that parses"
     * would then hand one subject's subscription to the other, silently. Two
     * candidates is not a tie to break, it is a question this job cannot answer,
     * so it declines and says so.
     *
     * `original_app_user_id` is included as a candidate rather than preferred:
     * it is the FIRST id the subscriber ever used, which on such a switch is the
     * subject they left.
     */
    protected function unambiguousAliasKey(): ?string
    {
        $candidates = array_merge(
            array_filter([$this->event['original_app_user_id'] ?? null], 'is_string'),
            array_filter((array) ($this->event['aliases'] ?? []), 'is_string'),
        );

        $keys = array_values(array_unique(array_filter(
            array_map('trim', $candidates),
            fn (string $id): bool => $id !== '' && TeamKey::looksLikeOne($id),
        )));

        if (count($keys) !== 1) {
            if ($keys !== []) {
                $this->warn(
                    'ambiguous_aliases',
                    'RevenueCat named several aliases that could each be a billable; this job cannot '
                    . 'choose between them, so the entitlement is left untouched.',
                    ['alias_billable_keys' => $keys],
                );
            }

            return null;
        }

        return $keys[0];
    }

    /**
     * Turn one authoritative subscriber response into a claim, or into null when
     * the response cannot decide anything (which is never a revocation).
     *
     * `subscriber.subscriptions` is the map this reads, not
     * `subscriber.entitlements`: only the subscriptions carry the `store`, the
     * `is_sandbox` flag and the rail-native product id in its key, and those are
     * three of the fields the entitlement columns need.
     *
     * @param  array<string, mixed>  $subscriber
     */
    protected function claimFor(Model $billable, array $subscriber): ?EntitlementWrite
    {
        $all = is_array($subscriber['subscriptions'] ?? null) ? $subscriber['subscriptions'] : [];

        // 1. The sandbox gate, and it is the SECOND place this rail is gated:
        //    the webhook rejects a `SANDBOX` event, and `is_sandbox` is carried
        //    per subscription in the API response as well. A sandbox purchase
        //    granting a production paid tier is money out of the door, and it is
        //    equally no evidence that a production tier ENDED, so a subscriber
        //    holding nothing else is left exactly as it was.
        $production = $this->acceptsSandbox()
            ? $all
            : array_filter($all, fn (mixed $subscription): bool => ! $this->isSandbox($subscription));

        if ($production === [] && $all !== []) {
            $this->warn(
                'sandbox_only_subscriber',
                'Every RevenueCat subscription for this billable is a sandbox purchase; entitlement left untouched.',
                ['billable_id' => $billable->getKey()],
            );

            return null;
        }

        // 2. A subscription with no readable date can neither grant nor expire,
        //    so it is dropped rather than read as expired. Dropping the last one
        //    means the response cannot decide anything at all.
        $dated = array_filter($production, fn (mixed $subscription): bool => $this->hasReadableDates($subscription));

        if ($dated === [] && $production !== []) {
            $this->warn(
                'undated_subscriptions',
                'RevenueCat returned subscriptions with no readable expiry; entitlement left untouched.',
                ['billable_id' => $billable->getKey(), 'product_ids' => array_keys($production)],
            );

            return null;
        }

        // 3. Only the stores this feeder OWNS. A subscriber can hold a
        //    subscription sold by a rail this job does not feed (`stripe`,
        //    `promotional`, `amazon`), and this check used to sit on the ranked
        //    WINNER instead of here. That let one foreign subscription reaching
        //    furthest into the future hide every store subscription behind it:
        //    the winner was refused as unfed, and nothing ever looked at the
        //    rest, so a live App Store purchase went unclaimed while a
        //    promotional grant with a later date sat in front of it.
        $owned = array_filter(
            $dated,
            fn (mixed $subscription): bool => $this->providerFor(
                is_array($subscription) ? ($subscription['store'] ?? null) : null,
            ) instanceof BillingProvider,
        );

        if (count($owned) !== count($dated)) {
            $this->warn(
                'unfed_store',
                'RevenueCat reports subscriptions from a store this feeder does not own; they are ignored.',
                [
                    'billable_id' => $billable->getKey(),
                    'ignored_product_ids' => array_keys(array_diff_key($dated, $owned)),
                ],
            );
        }

        // Everything the subscriber holds belongs to another rail. There is
        // nothing for this feeder to claim and nothing it may revoke, because a
        // rail may only revoke what it granted.
        //
        // The `$dated !== []` half is load-bearing: when the subscriber holds
        // NOTHING at all, both arrays are empty and the walk must continue to
        // the revocation path below, which is exactly how an EXPIRATION is
        // honoured. Returning early on an empty `$owned` alone would silently
        // turn every expiry into a no-op.
        if ($owned === [] && $dated !== []) {
            return null;
        }

        $ranked = $this->rankedByExpiry($owned);
        $live = array_filter($ranked, fn (array $subscription): bool => $this->isLive($subscription));

        // 4. The subscription that decides is the live one reaching furthest
        //    into the future. Deliberately NOT "the highest tier among the live
        //    ones": ranking tiers is the write action's job, and a second
        //    definition of which tier is higher would be free to disagree with
        //    the one the action already enforces.
        return $live === []
            ? $this->revocation($billable, $subscriber, $ranked)
            : $this->grant($billable, $subscriber, (string) array_key_first($live), (array) reset($live));
    }

    /**
     * The claim a live subscription makes.
     *
     * @param  array<string, mixed>  $subscriber
     * @param  array<string, mixed>  $subscription
     */
    protected function grant(
        Model $billable,
        array $subscriber,
        string $productId,
        array $subscription,
    ): ?EntitlementWrite {
        // The store is known to be one this feeder owns: `claimFor` filters the
        // whole set before ranking it, so a foreign rail's subscription can no
        // longer reach this method at all, let alone mask the ones behind it.
        $provider = $this->providerFor($subscription['store'] ?? null);

        if (! $provider instanceof BillingProvider) {
            throw new RuntimeException(
                'A subscription from an unowned store reached grant(); claimFor is '
                . 'supposed to have filtered it out before ranking.',
            );
        }

        // An unmapped product is a CONFIG gap, exactly as an unmapped Stripe
        // price is: the absence of a reason to grant is not a reason to revoke.
        // It matters more here, because an adopter creates their store products
        // by hand in App Store Connect and Play Console, so until a human fills
        // `billing.store_products` in, every event lands on this branch. If it
        // downgraded anybody, going live would be a mass revocation.
        $plan = $this->planFor($productId);

        if ($plan === null) {
            $this->warn(
                'unmapped_product',
                'A RevenueCat product id is not mapped to a plan; entitlement left untouched.',
                ['billable_id' => $billable->getKey(), 'product_id' => $productId],
            );

            return null;
        }

        // Apple Family Sharing: the access is real and the store granted it, so
        // refusing it would deny a tier the customer genuinely has. But the
        // subject holding it is not the one whose owner paid, which is worth an
        // operator's attention under an owner-only purchase rule.
        if ($this->isFamilyShared($subscription)) {
            $this->warn(
                'family_shared_entitlement',
                'A RevenueCat entitlement arrived through family sharing; granted, and worth an eye.',
                ['billable_id' => $billable->getKey(), 'product_id' => $productId],
            );
        }

        return new EntitlementWrite(
            billable: $billable,
            plan: $plan,
            status: $this->grantingStatus($subscription),
            provider: $provider,
            eventAt: $this->eventAt(),
            // AUTHORITATIVE, and it is the whole design of this job: the event
            // is a dirty signal and every field here comes from re-reading
            // `GET /v1/subscribers`. A store purchase is therefore allowed to
            // move the record, which is what makes a web-to-store migration
            // land rather than being refused as a same-tier duplicate.
            authoritative: true,
            providerStatus: $this->eventType(),
            productId: $productId,
            currentPeriodEnd: $this->instant($subscription['expires_date'] ?? null),
            renews: $this->renews($subscription),
            gracePeriodEndsAt: $this->instant($subscription['grace_period_expires_date'] ?? null),
            manageUrl: $this->manageUrl($subscriber),
        );
    }

    /**
     * The claim an authoritative read with nothing live makes.
     *
     * @param  array<string, mixed>  $subscriber
     * @param  array<string, array<string, mixed>>  $ranked  every admissible
     *                                                       subscription, expiry-desc
     */
    protected function revocation(Model $billable, array $subscriber, array $ranked): ?EntitlementWrite
    {
        $latest = $ranked === [] ? null : (array) reset($ranked);
        $provider = $this->revokingProvider($billable, $latest);

        if (! $provider instanceof BillingProvider) {
            $this->warn(
                'nothing_to_revoke',
                'RevenueCat has nothing live for this billable and no store rail granted its plan; left untouched.',
                [
                    'billable_id' => $billable->getKey(),
                    'stored_provider' => $this->stringAttribute($billable, 'plan_provider'),
                ],
            );

            return null;
        }

        return new EntitlementWrite(
            billable: $billable,
            // No tier: the authoritative read found nothing live, so the store
            // owes this subject nothing. Naming a free tier here would claim the
            // store sold them the cheapest one, which no store event ever says.
            plan: null,
            status: $this->revokedStatus($latest),
            provider: $provider,
            eventAt: $this->eventAt(),
            // Authoritative for the same reason: this revocation is what the
            // fresh read SHOWED, not what the event was called.
            authoritative: true,
            providerStatus: $this->eventType(),
            productId: $ranked === [] ? null : (string) array_key_first($ranked),
            // No period is carried forward: a subscription that has run out has
            // no period left to run and no dunning window open. `renews` is the
            // one thing this path does know, and it is false.
            renews: false,
            manageUrl: $this->manageUrl($subscriber),
        );
    }

    /**
     * Which rail a revocation is made on behalf of.
     *
     * Read from the expired subscription itself when there is one. When the read
     * is empty there is no `store` field to read, and the rail whose absence is
     * being confirmed is the one on record: this is safe here precisely because
     * the App User ID IS the billable key, so a resolvable subject plus an empty
     * response cannot mean "we read somebody else's subscriber".
     *
     * Null when the plan on record was not sold by a store. This feeder must not
     * revoke a Stripe or an operator-granted plan; the write action would drop
     * the attempt anyway, and a claim nobody is entitled to make is better not
     * made.
     *
     * @param  array<string, mixed>|null  $latest
     */
    protected function revokingProvider(Model $billable, ?array $latest): ?BillingProvider
    {
        $fromRead = $latest === null ? null : $this->providerFor($latest['store'] ?? null);

        if ($fromRead instanceof BillingProvider) {
            return $fromRead;
        }

        $stored = BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'));

        return $stored->isStore() ? $stored : null;
    }

    /**
     * Where a still-entitled subscription stands, in the neutral vocabulary.
     *
     * An open grace window outranks everything else: it is what says the store
     * is still trying to charge, and it is why a billing issue keeps its tier.
     *
     * @param  array<string, mixed>  $subscription
     */
    protected function grantingStatus(array $subscription): PlanStatus
    {
        $grace = $this->instant($subscription['grace_period_expires_date'] ?? null);

        return match (true) {
            $grace !== null && $grace->greaterThan(CarbonImmutable::now()) => PlanStatus::GRACE,
            ($subscription['billing_issues_detected_at'] ?? null) !== null => PlanStatus::PAST_DUE,
            ($subscription['period_type'] ?? null) === 'trial' => PlanStatus::TRIALING,
            default => PlanStatus::ACTIVE,
        };
    }

    /**
     * How a finished subscription finished.
     *
     * `PAUSED` is reachable only here, and only once the pause is IN EFFECT: a
     * pending Play pause still has a future `expires_date`, so it is live and
     * keeps its tier. What lands here is the `EXPIRATION` that follows.
     *
     * @param  array<string, mixed>|null  $subscription
     */
    protected function revokedStatus(?array $subscription): PlanStatus
    {
        if ($subscription === null) {
            return PlanStatus::EXPIRED;
        }

        $resume = $this->instant($subscription['auto_resume_date'] ?? null);

        return match (true) {
            $resume !== null && $resume->greaterThan(CarbonImmutable::now()) => PlanStatus::PAUSED,
            ($subscription['refunded_at'] ?? null) !== null,
            ($subscription['unsubscribe_detected_at'] ?? null) !== null => PlanStatus::CANCELED,
            default => PlanStatus::EXPIRED,
        };
    }

    /**
     * Whether the subscription still rolls over.
     *
     * An ABSENT key leaves the column null rather than assuming it renews, the
     * same discipline the Stripe feeder applies to `cancel_at_period_end`: null
     * means the rail has not said, which is a different claim from false.
     *
     * @param  array<string, mixed>  $subscription
     */
    protected function renews(array $subscription): ?bool
    {
        if (! array_key_exists('unsubscribe_detected_at', $subscription)) {
            return null;
        }

        return $subscription['unsubscribe_detected_at'] === null;
    }

    /**
     * The store's own management surface for this subscriber.
     *
     * Passed through rather than hardcoded per platform. RevenueCat returns it
     * (`https://apps.apple.com/account/subscriptions` and the Play equivalent),
     * it is the only source `plan_manage_url` has on a store rail, and a store
     * that moves its management page then needs no app release.
     *
     * @param  array<string, mixed>  $subscriber
     */
    protected function manageUrl(array $subscriber): ?string
    {
        $url = $subscriber['management_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    /**
     * The rail behind a RevenueCat `store` value, or null when this feeder does
     * not own it.
     *
     * Compared case-insensitively on purpose: the API answers `app_store` and a
     * webhook says `APP_STORE`, for the same fact.
     */
    protected function providerFor(mixed $store): ?BillingProvider
    {
        return match (strtolower((string) (is_scalar($store) ? $store : ''))) {
            'app_store', 'mac_app_store' => BillingProvider::APP_STORE,
            'play_store' => BillingProvider::PLAY_STORE,
            default => null,
        };
    }

    /**
     * The tier a rail-native product id grants, or null when the map does not
     * name it.
     *
     * The tier arrives as a plain string because the tier vocabulary belongs to
     * the consuming application; this package has no opinion about what any of
     * them means. `billing.store_products` is the store rail's half of the same
     * question `billing.prices` answers for the card rail, and an entry with no
     * usable tier behind it reads as unmapped rather than being stringified into
     * a tier nobody published.
     *
     * Google Play sends `<subscription_id>:<base_plan_id>`, so the map keys on
     * that whole string; keyed on the bare subscription id it would be an
     * unmapped-product warning on every Android renewal.
     */
    protected function planFor(string $productId): ?string
    {
        $map = config('magic-starter.billing.store_products', []);

        if (! is_array($map)) {
            return null;
        }

        $plan = $map[$productId] ?? null;

        return is_string($plan) && $plan !== '' ? $plan : null;
    }

    /**
     * Whether this deployment is allowed to act on sandbox purchases at all.
     * Absent config means no, which is the direction that cannot cost money.
     */
    protected function acceptsSandbox(): bool
    {
        return (bool) config('magic-starter.billing.revenuecat.accept_sandbox', false);
    }

    /**
     * @param  mixed  $subscription  one `subscriber.subscriptions` entry
     */
    protected function isSandbox(mixed $subscription): bool
    {
        return is_array($subscription) && ($subscription['is_sandbox'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    protected function isFamilyShared(array $subscription): bool
    {
        return strtoupper((string) ($subscription['ownership_type'] ?? '')) === 'FAMILY_SHARED';
    }

    /**
     * Whether a subscription carries at least one date this build can read, and
     * is therefore able to say something about entitlement.
     */
    protected function hasReadableDates(mixed $subscription): bool
    {
        if (! is_array($subscription)) {
            return false;
        }

        return $this->instant($subscription['expires_date'] ?? null) !== null
            || $this->instant($subscription['grace_period_expires_date'] ?? null) !== null;
    }

    /**
     * Whether a subscription still entitles: the paid period, or the dunning
     * window the store is retrying inside, reaches into the future.
     *
     * @param  array<string, mixed>  $subscription
     */
    protected function isLive(array $subscription): bool
    {
        $now = CarbonImmutable::now();

        // `refunded_at` is deliberately NOT read here, and this comment exists so
        // it is not added back. It was, briefly, on the reasoning that an Apple
        // refund might leave `expires_date` in the future and hand out months of
        // free service. RevenueCat's own documentation refutes the premise:
        // "this doesn't mean that a subscription's autorenewal preference has
        // been deactivated since refunds can be given without canceling a
        // subscription. Check the current subscription status to see whether the
        // subscription is still active."
        //
        // So a refunded subscription with a future `expires_date` is a state the
        // vendor documents as LIVE, and refusing it would have made this the one
        // place in this class where an ambiguity resolves toward taking a tier
        // away from somebody who may still be paying, against the doctrine at the
        // top of the file. The dates are the status RevenueCat tells us to check,
        // and a refund that really did end the subscription moves them.
        //
        // {@see revokedStatus()} still reads the field, which is correct: there
        // it only NAMES a revocation the dates already decided.
        foreach (['expires_date', 'grace_period_expires_date'] as $field) {
            $instant = $this->instant($subscription[$field] ?? null);

            if ($instant !== null && $instant->greaterThan($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The admissible subscriptions, furthest-reaching first, product ids kept as
     * keys because the key IS the rail-native product id.
     *
     * @param  array<string, mixed>  $subscriptions
     * @return array<string, array<string, mixed>>
     */
    protected function rankedByExpiry(array $subscriptions): array
    {
        uasort(
            $subscriptions,
            fn (mixed $a, mixed $b): int => $this->reachOf($b) <=> $this->reachOf($a),
        );

        /** @var array<string, array<string, mixed>> $subscriptions */
        return $subscriptions;
    }

    /**
     * How far into the future a subscription reaches, as a timestamp, taking
     * whichever of its two windows lasts longer.
     */
    protected function reachOf(mixed $subscription): int
    {
        if (! is_array($subscription)) {
            return 0;
        }

        $reach = 0;

        foreach (['expires_date', 'grace_period_expires_date'] as $field) {
            $instant = $this->instant($subscription[$field] ?? null);

            if ($instant !== null) {
                $reach = max($reach, $instant->getTimestamp());
            }
        }

        return $reach;
    }

    /**
     * The event's OWN timestamp, which is what orders one delivery against
     * another inside the write action.
     *
     * Never `now()`: receipt time only ever increases, so a feeder stamping it
     * would read every write as the freshest truth on record and disarm the
     * monotonic rule while satisfying every type check. RevenueCat puts
     * `event_timestamp_ms` on every event and reuses it across retries, so the
     * fallback is unreachable in practice; it is the epoch rather than `now()`
     * because a degenerate timestamp has to LOSE to a real one.
     */
    protected function eventAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs((int) ($this->event['event_timestamp_ms'] ?? 0));
    }

    /**
     * The rail's own word for what happened, kept verbatim in
     * `plan_provider_status`, which gates nothing.
     *
     * The event type is the nearest thing the store rail has to a status string:
     * the API response carries dates and flags but no status word at all. Stored
     * so an operator can see WHICH delivery last wrote a billable's entitlement.
     */
    protected function eventType(): ?string
    {
        $type = $this->event['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Parse one RevenueCat date, or null when there is nothing readable there.
     *
     * The caller decides what null MEANS, and every caller here treats it as
     * "this subscription cannot decide anything" rather than as "expired", which
     * is why an unreadable date is reported by the caller rather than swallowed:
     * a subscription with no readable window is dropped and logged in
     * {@see self::claimFor()}.
     */
    protected function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Report a decision not to write, or the release of a burnt claim
     * ({@see self::failed()}), with the reason and the event behind it.
     *
     * Warning level for every case, because each one means a paying customer's
     * entitlement did not move when a store said something about it. The
     * `reason` field is what a test asserts on: it distinguishes the guard that
     * fired from every other guard that would also have left the row alone.
     *
     * @param  array<string, mixed>  $context
     */
    protected function warn(string $reason, string $message, array $context = []): void
    {
        Log::warning($message, [
            'reason' => $reason,
            'event_id' => $this->event['id'] ?? null,
            'event_type' => $this->eventType(),
            ...$context,
        ]);
    }
}
