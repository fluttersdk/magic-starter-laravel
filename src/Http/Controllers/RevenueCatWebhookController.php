<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Jobs\SyncRevenueCatEntitlement;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Support\StoreRailConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The store rail's front door, and a sibling to {@see StripeWebhookController}
 * in DISCIPLINE rather than in mechanism.
 *
 * It does four things and no more: verify the signature, claim the event id,
 * decide whether the event is worth an authoritative re-read, and queue that
 * re-read. It never decides grant-or-revoke, never reads a tier, a product or a
 * period out of the payload, and never writes an entitlement column. Deciding
 * what a subscriber is owed belongs to {@see SyncRevenueCatEntitlement}, which
 * re-reads RevenueCat and claims through the single write action; four of the
 * event types that reach it mean the opposite of what their name suggests, and
 * that is exactly the reasoning this controller must not duplicate.
 *
 * ## Why not extend Cashier's webhook controller
 *
 * The discipline is copied, the code is not. {@see StripeWebhookController} is a
 * Cashier subclass: its constructor attaches Stripe's `VerifyWebhookSignature`
 * middleware and its handler methods are named for Stripe's event vocabulary, so
 * inheriting it would drag the Stripe signature scheme onto a route RevenueCat
 * signs differently. What IS copied is the insert-then-handle transaction:
 * {@see ProcessedWebhookEvent::recordIfNew()} and the side effect it gates share
 * ONE transaction, so a mid-handler failure rolls the dedup row back with the
 * side effect and the sender's retry reprocesses the event instead of hitting a
 * permanent no-op. Here the side effect is the DISPATCH, so a queue that cannot
 * accept the job rolls the claim back too.
 *
 * The id is claimed under {@see SyncRevenueCatEntitlement::CLAIM_PREFIX}, and it
 * is named rather than spelled out here. RevenueCat and Stripe issue ids from
 * different systems into ONE unique column, the prefix is what keeps a collision
 * between them from turning a real store delivery into a permanent no-op, and
 * the job's own `failed()` releases `CLAIM_PREFIX . $eventId`: a second copy of
 * the string here could drift, and a drifted prefix releases a key nothing ever
 * claimed.
 *
 * ## The route, and the one configuration that withholds it
 *
 * `src/routes/webhooks.php` registers this controller under
 * `magic-starter.billing.revenuecat.path`, whose default is the served path
 * `webhooks/revenuecat`. That default is not free to choose: it is the string an
 * adopter registers in the RevenueCat dashboard, so moving it would 404 every
 * delivery until somebody edited the dashboard by hand.
 *
 * {@see StoreRailConfiguration::isMisconfigured()} withholds the registration, and the
 * provider logs the same condition once at boot. A store rail configured without
 * a signing secret cannot authenticate anybody, so the endpoint would refuse
 * every delivery it received; serving it would only spend RevenueCat's five
 * retries on a configuration no retry can fix.
 *
 * ## The four traps, each from RevenueCat's own documentation
 *
 *  1. RAW BYTES. The HMAC is over `"{t}.{raw body}"`, so the body is read with
 *     `$request->getContent()` and never through the parsed input: an unescaped
 *     `/`, a literal non-ASCII character or `9.90` all change under a re-encode,
 *     and a signature computed over the reparsed body fails while looking like a
 *     genuine forgery. The tolerance is sized to the SIGNING time of that
 *     attempt ({@see self::SIGNATURE_TOLERANCE_SECONDS}), not to the retry
 *     window.
 *  2. `environment`, READ OFF THE EVENT. A `SANDBOX` delivery is refused in code
 *     rather than by a dashboard filter, because a sandbox purchase granting a
 *     production tier is money out of the door and a dashboard filter is one
 *     unchecked checkbox.
 *  3. ALWAYS 200, except for a signature that did not verify. RevenueCat retries
 *     a non-200 five times across roughly three hours and then abandons the
 *     event permanently, so an unknown billable, an ignored type and an
 *     unreadable body all answer 200 having done nothing: a retry cannot improve
 *     any of them, and burning the delivery loses the ones that follow it too.
 *  4. QUEUE THE RE-READ. There is a 60-second response wall on the delivery, so
 *     the authoritative call happens in the job, whose whole operation is
 *     budgeted (`magic-starter.billing.revenuecat.operation_budget_seconds`
 *     inside the job's timeout inside the worker's).
 */
class RevenueCatWebhookController
{
    /**
     * The header RevenueCat carries its signature in.
     *
     * `t=<unix>,v1=<hmac_sha256_hex>`, the HMAC computed over
     * `"{t}.{raw body}"`. HMAC signing is opt-in per webhook integration on
     * RevenueCat's side and this endpoint accepts nothing else, so an integration
     * left on the baseline static `Authorization` header is refused rather than
     * silently trusted.
     */
    public const SIGNATURE_HEADER = 'X-RevenueCat-Webhook-Signature';

    /**
     * How far the signing time may be from now, in seconds.
     *
     * FIVE MINUTES, sized to how long a single delivery attempt plausibly takes
     * to cross the network and be read. It is deliberately NOT the ~80-minute
     * retry window, which is the tempting wrong value: RevenueCat re-signs every
     * attempt with the time of THAT attempt while reusing the payload `id` and
     * `event_timestamp_ms`, so a late retry arrives with a FRESH `t` and a
     * genuinely old one is a replayed capture. Widening this to the retry window
     * would buy nothing and would hand an attacker an 80-minute replay window
     * for a captured delivery.
     */
    protected const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * The event types worth an authoritative re-read, and the reason this is an
     * ALLOWLIST rather than a list of the ignorable ones.
     *
     * A type RevenueCat adds next year must default to IGNORED. An unknown type
     * dispatched is a re-read that could revoke on a payload nobody has read the
     * documentation for; an unknown type ignored is one entitlement that moves a
     * few minutes late, when the next real event catches it.
     *
     * Every type here can change what a subscriber is entitled to, including the
     * two that are easy to read as noise: `SUBSCRIPTION_EXTENDED` pushes the
     * period end back and `REFUND_REVERSED` restores an entitlement a refund took
     * away. `TRANSFER` moves a subscription between App User IDs and therefore
     * between BILLABLE SUBJECTS, which is why the job re-reads both sides.
     *
     * `TEMPORARY_ENTITLEMENT_GRANT` was here and was REMOVED, and the reason is
     * the shape of this whole design rather than a judgement about the event. It
     * is RevenueCat entitling a customer for up to 24 hours when it could not
     * validate with the store, so by definition there is no validated
     * transaction, and RevenueCat documents that the event carries no
     * subscription or identity fields beyond `app_user_id`. The job it dispatches
     * reads `subscriber.subscriptions` and nothing else, so this event had no
     * path that could GRANT from it and one that could REVOKE: an authoritative
     * read showing nothing live is exactly how an expiry is honoured. Dispatching
     * it could only ever take the tier away from the customer it was invented to
     * protect. RevenueCat promises the follow-up worth acting on either way: a
     * regular `INITIAL_PURCHASE` when validation succeeds, an `EXPIRATION` when
     * it fails, and both are already here.
     *
     * Everything else RevenueCat documents is absent on purpose and says nothing
     * about entitlement: `TEST`, `SUBSCRIBER_ALIAS`, `EXPERIMENT_ENROLLMENT`,
     * `INVOICE_ISSUANCE`, `PURCHASE_REDEEMED`, `VIRTUAL_CURRENCY_TRANSACTION`,
     * both `PRICE_INCREASE_CONSENT_*` and every `PAYWALL_*`. The paywall family
     * is the one that would hurt: it fires on app opens, so dispatching it would
     * buy a store API call and a dedup row per impression.
     *
     * @var array<int, string>
     */
    protected const ENTITLEMENT_EVENT_TYPES = [
        'INITIAL_PURCHASE',
        'RENEWAL',
        'CANCELLATION',
        'UNCANCELLATION',
        'NON_RENEWING_PURCHASE',
        'SUBSCRIPTION_PAUSED',
        'EXPIRATION',
        'BILLING_ISSUE',
        'PRODUCT_CHANGE',
        'SUBSCRIPTION_EXTENDED',
        'REFUND_REVERSED',
        'TRANSFER',
    ];

    /**
     * The only `environment` value a production deployment acts on.
     */
    protected const PRODUCTION_ENVIRONMENT = 'PRODUCTION';

    /**
     * The environment a sandbox purchase arrives under, accepted only where the
     * deployment has opted in.
     */
    protected const SANDBOX_ENVIRONMENT = 'SANDBOX';

    /**
     * Handle one RevenueCat delivery.
     *
     * @throws AccessDeniedHttpException When the signature does not verify, which
     *                                   is the ONLY non-200 this endpoint answers.
     */
    public function __invoke(Request $request): Response
    {
        // 1. Authenticity first, over the bytes as they arrived. Everything below
        //    this line is trusted to have come from RevenueCat.
        $raw = $request->getContent();

        $this->verifySignature($raw, (string) $request->header(self::SIGNATURE_HEADER, ''));

        // 2. A body that cannot be read is not a retryable condition: it was
        //    signed with our own secret, so asking for it again returns the same
        //    bytes. Acknowledged so the delivery is not burned.
        $event = $this->event($raw);

        if ($event === null) {
            return $this->acknowledged();
        }

        // 3. The sandbox gate, read off the EVENT. Refused before the claim: the
        //    event is not merely uninteresting, it is one this deployment must
        //    never act on, and leaving its id unclaimed keeps the decision
        //    reversible if the deployment later opts in.
        if (! $this->isActionableEnvironment($event)) {
            $this->warn('non_production_environment', $event);

            return $this->acknowledged();
        }

        // 4. Worth a re-read? Decided before the claim as well, and deliberately:
        //    a dedup row exists to gate a SIDE EFFECT, an ignored type has none,
        //    and `PAYWALL_IMPRESSION` fires often enough that claiming one would
        //    grow the dedup table without bound for no benefit.
        if (! in_array((string) $event['type'], self::ENTITLEMENT_EVENT_TYPES, true)) {
            return $this->acknowledged();
        }

        // 5. Insert-then-handle, in ONE transaction. A re-delivery is a total
        //    no-op; a dispatch that fails rolls the claim back so the sender's
        //    retry reprocesses the event rather than meeting a permanent no-op
        //    for a re-read that never happened.
        //
        //    The other order of failure (the push landed, the commit did not)
        //    leaves the retry able to queue a SECOND re-read, and that is the
        //    direction to fail in: the job is at-least-once by construction, it
        //    re-reads authoritatively rather than trusting the payload, and the
        //    write action drops any claim that is not strictly newer than what is
        //    already on record for the same rail.
        DB::transaction(function () use ($event): void {
            $claimed = ProcessedWebhookEvent::recordIfNew(
                SyncRevenueCatEntitlement::CLAIM_PREFIX . (string) $event['id'],
                (string) $event['type'],
            );

            if (! $claimed) {
                return;
            }

            SyncRevenueCatEntitlement::dispatch($event);
        });

        return $this->acknowledged();
    }

    /**
     * Verify the HMAC over `"{t}.{raw body}"`, or refuse the delivery.
     *
     * The only path in this class that answers a non-200, and every branch of it
     * is a failure to authenticate: an integration with no signing secret
     * configured on our side is refused exactly like a bad signature, because an
     * endpoint that queues a tier change must not accept a caller it cannot
     * identify. A verified-but-unusable delivery is a different thing and is
     * acknowledged upstream.
     *
     * @throws AccessDeniedHttpException
     */
    protected function verifySignature(string $raw, string $header): void
    {
        $secret = StoreRailConfiguration::webhookSecret();

        if ($secret === null) {
            $this->refuse('unconfigured_secret');
        }

        [$signedAt, $signature] = $this->parseSignature($header);

        if ($signedAt === null || $signature === '') {
            $this->refuse('malformed_signature_header');
        }

        // Read through Carbon rather than `time()` so the window is the same one
        // the rest of the application and its tests measure against.
        if (abs(CarbonImmutable::now()->getTimestamp() - $signedAt) > self::SIGNATURE_TOLERANCE_SECONDS) {
            $this->refuse('stale_signature');
        }

        $expected = hash_hmac('sha256', "{$signedAt}.{$raw}", $secret);

        if (! hash_equals($expected, $signature)) {
            $this->refuse('signature_mismatch');
        }
    }

    /**
     * Split a `t=<unix>,v1=<hex>` header into its signing time and its digest.
     *
     * @return array{0: int|null, 1: string}
     */
    protected function parseSignature(string $header): array
    {
        $fields = [];

        foreach (explode(',', $header) as $piece) {
            if (! str_contains($piece, '=')) {
                continue;
            }

            [$field, $value] = explode('=', trim($piece), 2);
            $fields[$field] = $value;
        }

        $signedAt = $fields['t'] ?? null;

        return [
            is_string($signedAt) && ctype_digit($signedAt) ? (int) $signedAt : null,
            (string) ($fields['v1'] ?? ''),
        ];
    }

    /**
     * The `event` object out of a verified body, or null when there is nothing
     * usable there.
     *
     * The envelope RevenueCat posts is `{"api_version": ..., "event": {...}}`,
     * and the two fields required here are the two this endpoint's own work needs:
     * the `id` it claims and the `type` it decides on. A body missing either
     * cannot be acted on, and the job downstream reads the rest for itself.
     *
     * @return array<string, mixed>|null
     */
    protected function event(string $raw): ?array
    {
        $payload = json_decode($raw, true);
        $event = is_array($payload) ? ($payload['event'] ?? null) : null;

        $readable = is_array($event)
            && $this->isUsableString($event['id'] ?? null)
            && $this->isUsableString($event['type'] ?? null);

        if (! $readable) {
            Log::warning('A verified RevenueCat delivery carried no readable event; acknowledged and ignored.', [
                'reason' => 'unreadable_event',
                'bytes' => strlen($raw),
            ]);

            return null;
        }

        /** @var array<string, mixed> $event */
        return $event;
    }

    /**
     * Whether this deployment may act on the environment the EVENT names.
     *
     * The event field decides; `magic-starter.billing.revenuecat.accept_sandbox`
     * only widens what it is allowed to say. An absent or unrecognised value is
     * treated as not production, which is the direction that cannot cost money:
     * it is no evidence of a real purchase.
     *
     * The flag is read HERE and again in {@see SyncRevenueCatEntitlement}, and the
     * duplication is deliberate rather than an oversight to consolidate: this one
     * gates the event's own `environment` field and that one gates the
     * per-subscription `is_sandbox` flag on the authoritative read, because the
     * API answers the two questions separately.
     *
     * `is_string` and never a cast: `(string)` on an array is "Array to string
     * conversion", which `HandleExceptions` promotes to an ErrorException, and a
     * 500 here BURNS one of five deliveries. A signed sender is not a well-formed
     * one.
     *
     * @param  array<string, mixed>  $event
     */
    protected function isActionableEnvironment(array $event): bool
    {
        $named = $event['environment'] ?? null;
        $environment = is_string($named) ? strtoupper(trim($named)) : '';

        if ($environment === self::PRODUCTION_ENVIRONMENT) {
            return true;
        }

        return $environment === self::SANDBOX_ENVIRONMENT
            && (bool) config('magic-starter.billing.revenuecat.accept_sandbox', false);
    }

    /**
     * Whether a payload field is a string with something in it.
     */
    protected function isUsableString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Report a delivery that was authentic and still did not lead to a re-read.
     *
     * Warning level: each one means a store said something about a subscriber and
     * this application deliberately did nothing with it. Ignored event TYPES are
     * not logged here on purpose, because `PAYWALL_*` alone would fill the log
     * with one line per app open.
     *
     * @param  array<string, mixed>  $event
     */
    protected function warn(string $reason, array $event): void
    {
        Log::warning('A RevenueCat delivery was acknowledged without queueing a re-read.', [
            'reason' => $reason,
            'event_id' => $event['id'] ?? null,
            'event_type' => $event['type'] ?? null,
            'environment' => $event['environment'] ?? null,
        ]);
    }

    /**
     * Refuse the delivery, which is the one thing worth a non-200.
     *
     * 403 rather than 401: there is no credential to re-present, and it matches
     * what Cashier's `VerifyWebhookSignature` answers on the Stripe route, so an
     * operator reading the access log sees one status for one meaning.
     *
     * @throws AccessDeniedHttpException
     */
    protected function refuse(string $reason): never
    {
        Log::warning('A RevenueCat webhook delivery failed signature verification.', [
            'reason' => $reason,
        ]);

        throw new AccessDeniedHttpException('Invalid RevenueCat webhook signature.');
    }

    /**
     * The 200 every authentic delivery gets, whatever this endpoint decided to do
     * with it.
     *
     * Empty-bodied: RevenueCat reads the status and nothing else, and naming the
     * decision in the body would publish which App User IDs this deployment
     * knows about to anybody who can forge nothing but can still read a reply.
     */
    protected function acknowledged(): Response
    {
        return response('', Response::HTTP_OK);
    }
}
