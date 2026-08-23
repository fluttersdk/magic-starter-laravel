<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\RevenueCatWebhookController;
use FlutterSdk\MagicStarter\Jobs\SyncRevenueCatEntitlement;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Support\StoreRailConfiguration;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\RawWebhookRequest;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Http\Request;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The store rail's front door: what a RevenueCat delivery is allowed to make
 * happen, and what it must never be able to.
 *
 * The endpoint does four things and no more (verify, claim, decide, queue), so
 * these tests are written against those four and against the ways each one has
 * a plausible implementation that passes a naive test:
 *
 *  1. VERIFY over the RAW BYTES. The awkward-payload test is the one that bites
 *     a handler verifying over `$request->all()` re-encoded: a body with an
 *     unescaped slash, literal Turkish letters and `9.90` survives a signature
 *     check only if the bytes were never reparsed. Its negative control is the
 *     tampered-body test, without which a verifier that answered "valid"
 *     unconditionally would pass, and its sharpest form is
 *     {@see self::test_one_payload_is_accepted_raw_and_refused_re_encoded()},
 *     which delivers ONE event both ways: both shapes passing means the
 *     signature was never over the raw bytes at all.
 *  2. TOLERANCE sized to the SIGNING time, not the retry window. RevenueCat
 *     re-signs every attempt with the time of that attempt and abandons a
 *     delivery after roughly 80 minutes, so 80 minutes is the tempting wrong
 *     tolerance. One test rejects a signature that old; its companion accepts a
 *     four-minute-old one, which is what stops a zero tolerance from passing
 *     both.
 *  3. ALWAYS 200. A non-200 burns one of five deliveries and then the event is
 *     gone forever, so an app user id nobody owns, a sandbox event and an event
 *     type this rail ignores are each asserted to answer 200 while writing
 *     nothing.
 *  4. NO ENTITLEMENT WRITE HERE. The controller never decides grant-or-revoke,
 *     asserted by delivering an `INITIAL_PURCHASE` with the queue faked and
 *     finding every entitlement column exactly as it was.
 *
 * ## The route, and the configuration that withholds it
 *
 * The served path under the shipped default is literally `webhooks/revenuecat`,
 * because that is the string an adopter registers in the RevenueCat dashboard
 * and a dashboard cannot be edited by a deploy. That is asserted directly, and
 * so is the half-configured rail that withholds the registration entirely: a
 * store rail with no signing secret cannot authenticate anybody, the provider
 * says so ONCE at boot rather than once per delivery, and the installer refuses
 * to complete.
 *
 * ## What the SQLite run proves, and what only PostgreSQL can
 *
 * The replay test means strictly less on the default engine. On PostgreSQL a
 * failed statement aborts the whole transaction, so the unique violation the
 * second delivery raises inside the handler's transaction would poison it
 * without the SAVEPOINT in {@see ProcessedWebhookEvent::recordIfNew()}; SQLite
 * carries on regardless.
 */
class RevenueCatWebhookTest extends TestCase
{
    /**
     * The signing secret both halves of every signature test share. A fake
     * value: no real secret belongs in a public repository.
     */
    protected const WEBHOOK_SECRET = 'rcwhsec_test_secret';

    /**
     * A fake outbound API key, present only to make the store rail COUNT as
     * configured. It is one of the two keys
     * {@see StoreRailConfiguration::isMisconfigured()} reads, and nothing
     * in this file calls RevenueCat.
     */
    protected const API_KEY = 'sk_test_revenuecat_secret';

    /**
     * The path the route is served at under the shipped default, spelled out
     * because it is also the string in the RevenueCat dashboard.
     */
    protected const ROUTE = 'webhooks/revenuecat';

    /**
     * Every event type RevenueCat documents that can change what a subscriber is
     * entitled to, and is therefore worth an authoritative re-read.
     *
     * @var array<int, string>
     */
    protected const ENTITLEMENT_TYPES = [
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
     * Every other type RevenueCat documents. None of them says anything about
     * entitlement, and a re-read for one is a store API call bought for nothing
     * (`PAYWALL_IMPRESSION` fires on app opens).
     *
     * @var array<int, string>
     */
    protected const IGNORED_TYPES = [
        'TEST',
        'SUBSCRIBER_ALIAS',
        // Moved here from the dispatched list, and not because it says nothing
        // about entitlement: it says RevenueCat granted one for up to 24 hours
        // because it could NOT validate with the store. The event therefore
        // carries no subscription fields, the re-read job reads
        // `subscriber.subscriptions` and nothing else, and an authoritative read
        // showing nothing live is how an expiry is honoured. Dispatching it had
        // one possible outcome and it was revoking the tier the grant existed to
        // protect. The follow-up that IS actionable arrives either way, as an
        // INITIAL_PURCHASE or an EXPIRATION.
        'TEMPORARY_ENTITLEMENT_GRANT',
        'EXPERIMENT_ENROLLMENT',
        'INVOICE_ISSUANCE',
        'PURCHASE_REDEEMED',
        'VIRTUAL_CURRENCY_TRANSACTION',
        'PRICE_INCREASE_CONSENT_REQUIRED',
        'PRICE_INCREASE_CONSENT_APPROVED',
        'PAYWALL_IMPRESSION',
        'PAYWALL_CLOSE',
        'PAYWALL_CANCEL',
        'PAYWALL_EXIT_OFFER',
        'PAYWALL_COMPONENT_INTERACTED',
    ];

    /**
     * The real queue manager, captured before {@see Queue::fake()} replaces it.
     *
     * Only the rollback test needs it back: a fake queue accepts everything, so
     * a claim that must roll back with a REFUSED dispatch cannot be exercised
     * against one.
     */
    private QueueManager $realQueue;

    /**
     * Configure the application before its providers BOOT.
     *
     * Testbench runs this hook between `RegisterProviders` and `BootProviders`,
     * so this reaches the package's `boot()`, which is what registers the routes
     * and what runs the half-configured-rail guard. The secret is set here for
     * that reason: with the rail configured and no secret, the route would not be
     * registered at all and every delivery test would 404.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
            'magic-starter.models.user' => ConcreteUser::class,
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.billing.revenuecat.webhook_secret' => static::WEBHOOK_SECRET,
            'magic-starter.billing.revenuecat.secret_api_key' => static::API_KEY,
            'magic-starter.billing.revenuecat.accept_sandbox' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->realQueue = $this->app['queue'];

        // The re-read is asserted as a DISPATCH, never as its outcome: what the
        // job then does with the event is its own test's subject.
        Queue::fake();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->cleanupPublishedArtifacts();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The route: where it is, and where it is not
    // -------------------------------------------------------------------------

    /**
     * The served path under the shipped default is literally
     * `webhooks/revenuecat`, and it is THIS controller answering.
     *
     * Both halves matter. RevenueCat has no vendor default to inherit, so the key
     * is the package's to own, but the DEFAULT is not free to choose: it is
     * registered in a dashboard no deploy can edit, so any other value makes
     * adoption a manual dashboard change with a window in which every delivery
     * 404s and entitlements silently stop moving.
     *
     * The shipped config is asserted alongside the route, because the route file
     * carries the same string as a fallback: a config default that drifted from
     * that fallback would leave this passing while a published config served
     * somewhere else.
     */
    public function test_the_served_path_is_webhooks_revenuecat_and_answers_from_this_controller(): void
    {
        $route = $this->matchRoute('POST', static::ROUTE);

        $this->assertNotNull($route, 'The store webhook must be registered under the package-owned path key.');
        $this->assertSame('webhooks/revenuecat', $route->uri());
        $this->assertSame(RevenueCatWebhookController::class, $route->getActionName());

        $shipped = require __DIR__ . '/../../../config/magic-starter.php';

        $this->assertSame('webhooks/revenuecat', $shipped['billing']['revenuecat']['path']);
    }

    /**
     * An adopter who moves the path key moves this route with it.
     *
     * The mutant is a route file that hardcodes the path. It passes the test
     * above and every delivery test, and it breaks exactly the adopter who set
     * the key, whose dashboard points somewhere this package then refuses to
     * answer.
     */
    public function test_the_route_follows_the_package_owned_path_key(): void
    {
        $this->bootRoutes([Features::billing()], billing: [
            'magic-starter.billing.revenuecat.path' => 'hooks/store',
        ]);

        $this->assertNotNull($this->matchRoute('POST', 'hooks/store'));
        $this->assertNull($this->matchRoute('POST', static::ROUTE));
    }

    /**
     * The webhook does NOT move when the API prefix does.
     *
     * The controls are the whole test. Asserting only that a webhook route still
     * exists passes even when it inherited the prefix, because a prefixed route
     * exists too; what has to be shown is that the API routes MOVED in the same
     * boot while this one did not.
     */
    public function test_the_webhook_path_survives_a_changed_route_prefix(): void
    {
        $this->bootRoutes([Features::billing()], 'api/v9');

        $route = $this->matchRoute('POST', static::ROUTE);

        $this->assertNotNull($route, 'The webhook must not inherit magic-starter.route_prefix.');
        $this->assertSame(RevenueCatWebhookController::class, $route->getActionName());
        $this->assertNull($this->matchRoute('POST', 'api/v9/' . static::ROUTE));

        // The control: the API surface really did move in this same boot, so the
        // assertion above is the webhook standing still rather than a prefix that
        // was never applied to anything.
        $this->assertNotNull($this->matchRoute('POST', 'api/v9/auth/login'));
        $this->assertNull($this->matchRoute('POST', 'auth/login'));
    }

    public function test_no_store_webhook_route_exists_while_billing_is_disabled(): void
    {
        $this->bootRoutes([]);

        $this->assertNull($this->matchRoute('POST', static::ROUTE));

        // The disarming limb: the provider booted and registered its other
        // routes, so the absence above is the billing gate and not an empty
        // router.
        $this->assertNotNull($this->matchRoute('POST', 'auth/login'));
    }

    // -------------------------------------------------------------------------
    // The configuration-time guard
    // -------------------------------------------------------------------------

    /**
     * A store rail configured without a signing secret is refused at
     * CONFIGURATION time: the route is withheld, the reason is logged once, and
     * the rest of the application keeps serving.
     *
     * Once is the assertion with teeth. A guard written into the controller would
     * say the same thing per delivery, which on a rail RevenueCat retries five
     * times is five identical lines per event and nothing at all until the first
     * delivery arrives; the point of a configuration-time guard is that an
     * operator learns at deploy rather than at the first purchase.
     */
    public function test_a_store_rail_without_a_secret_withholds_the_route_and_logs_once_at_boot(): void
    {
        Log::spy();

        $this->bootRoutes([Features::billing()], billing: [
            'magic-starter.billing.revenuecat.webhook_secret' => null,
            'magic-starter.billing.revenuecat.secret_api_key' => static::API_KEY,
        ]);

        $this->assertNull(
            $this->matchRoute('POST', static::ROUTE),
            'A rail that cannot authenticate a caller must not serve an endpoint that queues a tier change.',
        );

        // The application keeps serving: only the store rail is held back.
        $this->assertNotNull($this->matchRoute('POST', 'auth/login'));

        $this->assertBootGuardFired(1);

        // ONCE AT BOOT, not once per request. Two deliveries later the count is
        // unchanged, which is what tells a boot-time guard from a per-request one.
        $this->deliver($this->event('RENEWAL', Str::uuid()->toString()));
        $this->deliver($this->event('RENEWAL', Str::uuid()->toString()));

        $this->assertBootGuardFired(1);
    }

    /**
     * A rail nobody configured is not a misconfiguration.
     *
     * The disarming control for the test above, and the reason the predicate
     * is not simply "the webhook secret is empty": on a fresh install with
     * billing on, no store key is set anywhere, and an error at every boot
     * telling an adopter to configure a rail they never asked for is noise that
     * teaches them to ignore the next one.
     */
    public function test_an_unconfigured_store_rail_is_silent_and_still_serves_the_route(): void
    {
        Log::spy();

        $this->bootRoutes([Features::billing()], billing: [
            'magic-starter.billing.revenuecat.webhook_secret' => null,
            'magic-starter.billing.revenuecat.secret_api_key' => null,
            'magic-starter.billing.store_products' => [],
        ]);

        $this->assertNotNull($this->matchRoute('POST', static::ROUTE));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * A store PRODUCT MAP alone configures the rail too.
     *
     * The API key is the obvious half; the product map is the half a narrower
     * predicate would miss. An adopter who mapped their App Store products has
     * declared that they sell through a store, and a webhook they cannot
     * authenticate is exactly as broken with or without the outbound key.
     */
    public function test_a_store_product_map_alone_is_enough_to_configure_the_rail(): void
    {
        $this->bootRoutes([Features::billing()], billing: [
            'magic-starter.billing.revenuecat.webhook_secret' => null,
            'magic-starter.billing.revenuecat.secret_api_key' => null,
            'magic-starter.billing.store_products' => ['com.example.app.pro.monthly' => 'pro'],
        ]);

        $this->assertTrue(StoreRailConfiguration::isMisconfigured());
        $this->assertNull($this->matchRoute('POST', static::ROUTE));
    }

    /**
     * The installer refuses the same configuration, and publishes nothing.
     *
     * A non-zero exit is the only shape a CI pipeline can see, and stopping
     * before the first write is what makes the re-run after setting the secret a
     * clean install rather than a repair.
     */
    public function test_the_installer_refuses_a_store_rail_with_no_secret(): void
    {
        File::delete(config_path('magic-starter.php'));

        config([
            'magic-starter.billing.revenuecat.webhook_secret' => null,
            'magic-starter.billing.revenuecat.secret_api_key' => static::API_KEY,
        ]);

        $this->artisan('magic-starter:install', ['--features' => ['billing']])
            ->assertExitCode(1);

        $this->assertFileDoesNotExist(config_path('magic-starter.php'));
    }

    /**
     * The control the refusal test cannot do without: with the secret set, the
     * same invocation installs.
     *
     * Without it an installer that returned 1 unconditionally, or one that
     * refused every billing install, would pass the test above.
     */
    public function test_the_installer_completes_once_the_secret_is_set(): void
    {
        File::delete(config_path('magic-starter.php'));

        $this->artisan('magic-starter:install', ['--features' => ['billing']])
            ->assertExitCode(0);

        $this->assertFileExists(config_path('magic-starter.php'));
    }

    // -------------------------------------------------------------------------
    // Signature verification, over the bytes that actually arrived
    // -------------------------------------------------------------------------

    /**
     * The signature is verified over the RAW bytes the sender sent.
     *
     * Every property of this body is one a decode-and-re-encode round trip
     * changes: the unescaped `/`, the literal Turkish letters, `9.90`, and the
     * indentation. A handler verifying `$request->all()` re-encoded rejects a
     * genuinely valid signature here, which in production is five 403s and an
     * abandoned event.
     */
    public function test_the_signature_is_verified_over_the_raw_bytes_the_sender_sent(): void
    {
        $raw = $this->awkwardBody();

        RawWebhookRequest::withBody($raw)
            ->signedWith(static::WEBHOOK_SECRET, $this->signedAt())
            ->deliverTo($this, static::ROUTE)
            ->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    /**
     * ONE payload, ONE signature, delivered both ways: accepted raw, refused
     * re-encoded.
     *
     * This is the sharpest form of the test above, because it removes the last
     * way a byte-fidelity failure can hide. Two separate tests can each pass for
     * their own reason; here the only difference between the two deliveries is
     * who encoded the body, so BOTH passing would mean the handler is not
     * verifying the bytes at all, and both failing would mean the harness is.
     */
    public function test_one_payload_is_accepted_raw_and_refused_re_encoded(): void
    {
        $raw = $this->awkwardBody();
        $signedAt = $this->signedAt();
        $signature = 't=' . $signedAt . ',v1='
            . RawWebhookRequest::signatureFor($raw, static::WEBHOOK_SECRET, $signedAt);

        RawWebhookRequest::withBody($raw)
            ->withHeader(RawWebhookRequest::SIGNATURE_HEADER, $signature)
            ->deliverTo($this, static::ROUTE)
            ->assertOk();

        // The SAME event and the SAME signature, handed to `postJson`, which
        // takes an array and encodes it itself. The escaping, the number
        // formatting and the whitespace all change on the way in.
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded, 'The awkward fixture is not valid JSON.');

        $this->withHeaders([RawWebhookRequest::SIGNATURE_HEADER => $signature])
            ->postJson(static::ROUTE, $decoded)
            ->assertForbidden();

        // One delivery in, one re-read queued: the refusal happened before any
        // side effect, and the acceptance is not merely a 200 from a route that
        // did nothing.
        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    public function test_a_body_altered_after_signing_is_rejected(): void
    {
        // The negative control for the two tests above: same helper, same secret,
        // one field changed after signing.
        $raw = $this->awkwardBody();
        $tampered = str_replace('"RENEWAL"', '"EXPIRATION"', $raw);
        $signedAt = $this->signedAt();

        $this->assertNotSame($raw, $tampered, 'The fixture was not actually tampered with.');

        RawWebhookRequest::withBody($tampered)
            ->withHeader(
                RawWebhookRequest::SIGNATURE_HEADER,
                't=' . $signedAt . ',v1=' . RawWebhookRequest::signatureFor($raw, static::WEBHOOK_SECRET, $signedAt),
            )
            ->deliverTo($this, static::ROUTE)
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
    }

    /**
     * A delivery carrying no signature at all is refused.
     *
     * This is also where the static `Authorization` baseline dies: RevenueCat's
     * default webhook authentication is a fixed header value, and an integration
     * left on it arrives here carrying no `t=`/`v1=` pair. It is refused exactly
     * like an unsigned delivery, which is the whole reason HMAC signing is not
     * optional on this endpoint.
     */
    public function test_a_delivery_carrying_no_signature_at_all_is_rejected(): void
    {
        RawWebhookRequest::withPayload($this->payload($this->event('RENEWAL', Str::uuid()->toString())))
            ->withHeader('Authorization', 'a-static-dashboard-value')
            ->deliverTo($this, static::ROUTE)
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_signature_signed_eighty_minutes_ago_is_outside_the_tolerance(): void
    {
        // 80 minutes is roughly RevenueCat's whole retry window, and it is the
        // tempting wrong tolerance. `t` is the signing time of THAT attempt, so a
        // header this old is a replayed capture rather than a late retry.
        $this->deliver(
            $this->event('RENEWAL', Str::uuid()->toString()),
            $this->signedAt() - 80 * 60,
        )->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_signature_signed_four_minutes_ago_is_still_inside_the_tolerance(): void
    {
        // The companion that stops a zero tolerance from passing the test above:
        // a real delivery crossing a slow network still has to be accepted.
        $this->deliver(
            $this->event('RENEWAL', Str::uuid()->toString()),
            $this->signedAt() - 4 * 60,
        )->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    /**
     * A registered endpoint with no secret behind it refuses every delivery, and
     * says which failure it was.
     *
     * Reachable exactly when the rail is otherwise unconfigured: the boot guard
     * withholds the route only for a rail somebody half configured, so an adopter
     * who enabled billing and set nothing still serves this endpoint. It fails
     * CLOSED, and its reason is DISTINCT from the boot guard's so an operator can
     * tell a rail that was never registered from one refusing a caller it cannot
     * identify.
     */
    public function test_an_unconfigured_signing_secret_refuses_every_delivery_with_its_own_reason(): void
    {
        Log::spy();

        config(['magic-starter.billing.revenuecat.webhook_secret' => null]);

        $this->deliver($this->event('RENEWAL', Str::uuid()->toString()))->assertForbidden();

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'unconfigured_secret');

        Log::shouldNotHaveReceived('error');
    }

    // -------------------------------------------------------------------------
    // The four decisions the endpoint is allowed to make
    // -------------------------------------------------------------------------

    public function test_a_signed_expiration_queues_the_authoritative_re_read(): void
    {
        $event = $this->event('EXPIRATION', Str::uuid()->toString());

        $this->deliver($event)->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);

        // The job is handed the EVENT, not a billable or a tier: the controller
        // has decided only that a re-read is worth making.
        $this->assertSame([$event], $this->queuedEvents());

        $this->assertTrue(
            ProcessedWebhookEvent::query()->where('event_id', $this->claimKey($event))->exists(),
            'The event id was not claimed, so a re-delivery would queue a second re-read.',
        );
    }

    /**
     * The namespace prefix keeps the two id spaces apart, and it is READ FROM THE
     * JOB rather than spelled here.
     *
     * A Stripe event id and a RevenueCat event id are issued by different systems
     * into one unique column and can collide as strings. Without the prefix a
     * Stripe delivery could turn a real store delivery into a permanent no-op,
     * and the failure would be a tier that silently never moved.
     *
     * {@see SyncRevenueCatEntitlement::CLAIM_PREFIX} is the constant both sides
     * name: the job's `failed()` releases `CLAIM_PREFIX . $eventId`, so a prefix
     * spelled twice could drift and the release would look for a key nothing ever
     * claimed.
     */
    public function test_the_namespace_prefix_keeps_the_two_id_spaces_apart(): void
    {
        $event = $this->event('RENEWAL', Str::uuid()->toString());

        ProcessedWebhookEvent::query()->create([
            'event_id' => $event['id'],
            'type' => 'customer.subscription.updated',
            'processed_at' => now(),
        ]);

        $this->deliver($event)->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
        $this->assertTrue(
            ProcessedWebhookEvent::query()->where('event_id', $this->claimKey($event))->exists(),
        );
        $this->assertSame(2, ProcessedWebhookEvent::query()->count());
    }

    public function test_a_replayed_event_id_queues_the_re_read_only_once(): void
    {
        // Each delivery is signed afresh, exactly as RevenueCat re-signs a retry
        // while reusing the payload `id`.
        $event = $this->event('EXPIRATION', Str::uuid()->toString());

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
        $this->assertSame(1, ProcessedWebhookEvent::query()->count());
    }

    /**
     * A queue that REFUSES the job rolls the claim back with it.
     *
     * The mutant is a claim committed before the dispatch, or moved out of the
     * transaction entirely, and it is invisible on every happy path: the event id
     * stays claimed for a re-read that never happened, so all five of RevenueCat's
     * redeliveries answer 200 having done nothing and the entitlement never moves.
     * The job's own `failed()` cannot cover this one, because the job never
     * existed.
     *
     * Driven against a REAL queue that throws rather than a fake, since a fake
     * accepts everything; the retry is then driven for real, because a row count
     * of zero is the mechanism and a redelivery that queues the re-read is the
     * property.
     */
    public function test_a_queue_that_refuses_the_job_rolls_the_claim_back(): void
    {
        $event = $this->event('INITIAL_PURCHASE', Str::uuid()->toString());

        $this->useExplodingQueue();

        $this->assertSame(500, $this->deliver($event)->getStatusCode());

        $this->assertSame(
            0,
            ProcessedWebhookEvent::query()->count(),
            'The claim outlived the dispatch that failed, so every redelivery is now a permanent no-op.',
        );

        // RevenueCat retries the same event id. With the claim rolled back it is
        // processed rather than acknowledged as already seen.
        Queue::fake();

        $this->deliver($event)->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', $this->claimKey($event))->count());
    }

    // -------------------------------------------------------------------------
    // The sandbox gate, read off the event
    // -------------------------------------------------------------------------

    public function test_a_sandbox_event_writes_nothing_and_still_returns_200(): void
    {
        // A sandbox purchase granting a production paid tier is money out of the
        // door, and it is rejected HERE in code rather than trusted to a dashboard
        // filter. 200 because a non-200 costs a delivery, and there is nothing to
        // retry.
        $billable = $this->createBillable();

        $this->deliver($this->event('INITIAL_PURCHASE', $billable->getKey(), ['environment' => 'SANDBOX']))
            ->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
        $this->assertNull($billable->refresh()->getAttribute('plan'));
    }

    public function test_a_sandbox_event_is_accepted_only_where_the_deployment_opted_in(): void
    {
        // The other half of the gate: the flag WIDENS what the event field is
        // allowed to say, it never replaces reading it.
        config(['magic-starter.billing.revenuecat.accept_sandbox' => true]);

        $this->deliver($this->event('RENEWAL', Str::uuid()->toString(), ['environment' => 'SANDBOX']))
            ->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    public function test_an_event_naming_no_environment_is_treated_as_not_production(): void
    {
        // An absent or unrecognised `environment` is not evidence of a production
        // purchase, and the direction that cannot cost money is to ignore it.
        $this->deliver($this->event('RENEWAL', Str::uuid()->toString(), ['environment' => null]))->assertOk();

        // And a value that is not a string at all. `(string) $event['environment']`
        // on an array is "Array to string conversion", which `HandleExceptions`
        // promotes to an ErrorException: a 500 that burns the delivery.
        $this->deliver($this->event('RENEWAL', Str::uuid()->toString(), ['environment' => ['PRODUCTION']]))
            ->assertOk();

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // Always 200, and never an entitlement write
    // -------------------------------------------------------------------------

    public function test_an_app_user_id_nobody_owns_returns_200(): void
    {
        // The controller does not resolve billables at all: whether an App User ID
        // has a subject behind it is the job's decision, and it answers it with a
        // warning. What matters here is that the delivery is not burned.
        $this->deliver($this->event('RENEWAL', Str::uuid()->toString()))->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    public function test_an_unreadable_body_returns_200_and_queues_nothing(): void
    {
        // Correctly signed and still unusable: nothing about a retry can improve
        // it, so it must not burn one of five deliveries.
        RawWebhookRequest::withBody('{"api_version":"1.0"}')
            ->signedWith(static::WEBHOOK_SECRET, $this->signedAt())
            ->deliverTo($this, static::ROUTE)
            ->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
    }

    public function test_a_paywall_impression_is_ignored_outright(): void
    {
        // Named on its own rather than left to the exhaustive test below, because
        // it is the type whose cost is measurable: it fires on app opens, so
        // queueing one would buy a store API call and a dedup row per impression.
        $this->deliver($this->event('PAYWALL_IMPRESSION', Str::uuid()->toString()))->assertOk();

        Queue::assertNotPushed(SyncRevenueCatEntitlement::class);
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
    }

    public function test_every_type_that_can_change_entitlement_is_queued_and_every_other_type_is_ignored(): void
    {
        // An ALLOWLIST, asserted against RevenueCat's whole documented list, so a
        // type added next year defaults to ignored: an unknown type dispatched is
        // a re-read that could revoke on a payload nobody has read the docs for.
        $appUserId = Str::uuid()->toString();

        foreach ([...static::ENTITLEMENT_TYPES, ...static::IGNORED_TYPES] as $type) {
            $this->deliver($this->event($type, $appUserId))->assertOk();
        }

        $this->assertSame(
            static::ENTITLEMENT_TYPES,
            array_values(array_map(
                static fn (array $event): string => (string) $event['type'],
                $this->queuedEvents(),
            )),
            'The queued set is not exactly the entitlement-changing set.',
        );

        $this->assertSame(
            count(static::ENTITLEMENT_TYPES),
            ProcessedWebhookEvent::query()->count(),
            'An ignored type claimed an event id, which fills the dedup table with events that have no side effect.',
        );
    }

    /**
     * The load-bearing one: the controller writes no entitlement of its own.
     *
     * With the queue faked nothing downstream runs, so any provenance column that
     * moved was moved here. Deciding grant-or-revoke belongs to the job, which
     * re-reads RevenueCat rather than trusting the payload, and four of the event
     * types that reach it mean the opposite of what their name suggests.
     */
    public function test_the_controller_writes_no_entitlement_of_its_own(): void
    {
        $billable = $this->createBillable()->refresh();
        $before = $billable->only($this->entitlementColumns());

        $this->deliver($this->event('INITIAL_PURCHASE', $billable->getKey()))->assertOk();

        $this->assertSame(
            $before,
            $billable->refresh()->only($this->entitlementColumns()),
            "The controller decided grant-or-revoke, which is the job's decision and only its.",
        );
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    /**
     * Assert the boot-time guard fired exactly [$times].
     *
     * On the guard's own `reason` rather than merely on "an error", so the
     * per-request refusal reason cannot satisfy it.
     */
    private function assertBootGuardFired(int $times): void
    {
        Log::shouldHaveReceived('error')
            ->times($times)
            ->withArgs(
                fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'store_rail_without_webhook_secret',
            );
    }

    /**
     * Re-register the package's routes against a feature set, prefix and billing
     * configuration.
     *
     * @param  array<int, string>  $features
     * @param  array<string, mixed>  $billing
     */
    private function bootRoutes(array $features, string $prefix = '', array $billing = []): void
    {
        config([
            'magic-starter.features' => $features,
            'magic-starter.route_prefix' => $prefix,
            ...$billing,
        ]);

        $this->app['router']->setRoutes(new RouteCollection);

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    private function matchRoute(string $method, string $uri): ?\Illuminate\Routing\Route
    {
        try {
            return $this->app['router']->getRoutes()->match(Request::create($uri, $method));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }
    }

    /**
     * Point the container's queue at a connection that REFUSES every push.
     *
     * A real connection rather than a fake, because the property under test is
     * what happens when the dispatch itself fails; a fake queue accepts
     * everything and can never exercise it.
     */
    private function useExplodingQueue(): void
    {
        Queue::swap($this->realQueue);

        $this->realQueue->extend('exploding', fn (): ExplodingQueueConnector => new ExplodingQueueConnector);

        config([
            'queue.default' => 'exploding',
            'queue.connections.exploding' => ['driver' => 'exploding'],
        ]);
    }

    /**
     * Build the schema the way a consumer's `migrate` does.
     *
     * The package's own migrations rather than a hand-written Blueprint, so the
     * provenance columns this file asserts nothing wrote are the real ones.
     */
    private function createSchema(): void
    {
        $this->runMigration('create_users_table.php');
        $this->runMigration('add_entitlement_provenance_to_billable_table.php');
        $this->runMigration('create_processed_webhook_events_table.php');

        $this->assertTrue(Schema::hasColumn('users', 'plan_provider'));
    }

    private function runMigration(string $filename): void
    {
        $migration = require __DIR__ . '/../../../database/migrations/' . $filename;

        $migration->up();
    }

    /**
     * Remove anything an installer run in this file published into the skeleton.
     */
    private function cleanupPublishedArtifacts(): void
    {
        File::delete(config_path('magic-starter.php'));

        foreach (glob(__DIR__ . '/../../../database/migrations/*.php') ?: [] as $source) {
            foreach (glob(database_path('migrations/*_' . basename($source))) ?: [] as $published) {
                File::delete($published);
            }
        }

        foreach ([app_path('Models/User.php'), database_path('factories/UserFactory.php')] as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }
    }

    /**
     * A billable holding nothing yet.
     */
    private function createBillable(): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Payer',
            'email' => 'payer@example.test',
            'password' => 'secret',
        ]);
    }

    /**
     * The dedup key one event is claimed under, read through the job's own
     * constant so this file cannot certify a prefix that drifted.
     *
     * @param  array<string, mixed>  $event
     */
    private function claimKey(array $event): string
    {
        return SyncRevenueCatEntitlement::CLAIM_PREFIX . $event['id'];
    }

    /**
     * Deliver one event, signed, as RevenueCat would.
     *
     * @param  array<string, mixed>  $event
     * @param  int|null  $signedAt  the signing time carried in the header, for
     *                              the tolerance tests
     */
    private function deliver(array $event, ?int $signedAt = null): TestResponse
    {
        return RawWebhookRequest::withPayload($this->payload($event))
            ->signedWith(static::WEBHOOK_SECRET, $signedAt ?? $this->signedAt())
            ->deliverTo($this, static::ROUTE);
    }

    /**
     * The envelope RevenueCat posts: an `api_version` and the event beside it.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function payload(array $event): array
    {
        return [
            'api_version' => '1.0',
            'event' => $event,
        ];
    }

    /**
     * One event, carrying the fields this endpoint reads and a couple it must
     * ignore.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $type, mixed $appUserId, array $overrides = []): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'type' => $type,
            'app_user_id' => (string) $appUserId,
            'event_timestamp_ms' => CarbonImmutable::now()->getTimestampMs(),
            'environment' => 'PRODUCTION',
            'store' => 'APP_STORE',
            // Deliberately present and deliberately never read here: the tier is
            // the authoritative read's answer, not the payload's.
            'product_id' => 'starter_business_monthly',
            ...$overrides,
        ];
    }

    /**
     * The events the controller queued a re-read for, in dispatch order.
     *
     * The job keeps its event array protected, so it is read through a closure
     * bound to the job rather than by widening the job's surface for a test.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queuedEvents(): array
    {
        return Queue::pushed(SyncRevenueCatEntitlement::class)
            ->map(static fn (SyncRevenueCatEntitlement $job): array => (array) (fn (): array => $this->event)->call($job))
            ->values()
            ->all();
    }

    /**
     * The signing time a delivery carries by default: now, as the handler reads
     * it.
     *
     * Read through Carbon rather than `time()` so the handler's tolerance and the
     * harness agree about what "now" is.
     */
    private function signedAt(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }

    /**
     * Every provenance column the write action owns, which is exactly the set
     * this controller must never touch.
     *
     * @return array<int, string>
     */
    private function entitlementColumns(): array
    {
        return [
            'plan',
            'plan_status',
            'plan_provider',
            'plan_provider_status',
            'plan_product_id',
            'plan_source_event_at',
            'plan_current_period_end',
            'plan_renews',
            'plan_grace_period_ends_at',
            'plan_manage_url',
        ];
    }

    /**
     * A body written the way a sender writes one, not the way `json_encode`
     * would: the unescaped `/`, literal Turkish letters, `9.90` rather than
     * `9.9`, and the sender's own indentation. Each one alone breaks a signature
     * that was recomputed over a reparsed body.
     */
    private function awkwardBody(): string
    {
        return <<<'JSON'
            {
              "api_version": "1.0",
              "event": {
                "id": "9d1e1a5c-4f2a-4c1b-9f0e-2b7d3c5a8e11",
                "type": "RENEWAL",
                "app_user_id": "3f1b8f7e-2c4a-4f1e-9b0d-7a5c2e8d1b40",
                "store": "APP_STORE",
                "environment": "PRODUCTION",
                "price": 9.90,
                "currency": "USD",
                "note": "Ödeme alındı, yenilendi",
                "management_url": "https://apps.apple.com/account/subscriptions"
              }
            }
            JSON;
    }
}

/**
 * A queue connection that refuses every push.
 *
 * {@see SyncQueue} rather than a bare implementation of the queue contract,
 * because everything except `push()` is irrelevant here and a hand-written
 * connection would be ten methods of noise around the one line that matters.
 */
class ExplodingQueue extends SyncQueue
{
    /**
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        throw new RuntimeException('The queue refused the job.');
    }
}

/**
 * The connector that hands {@see ExplodingQueue} to the queue manager.
 */
class ExplodingQueueConnector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): QueueContract
    {
        return new ExplodingQueue;
    }
}
