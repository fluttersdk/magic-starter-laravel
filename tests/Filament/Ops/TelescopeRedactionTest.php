<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\Ops;

use FlutterSdk\MagicStarter\Filament\Ops\TelescopeRedaction;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use RuntimeException;

/**
 * What Telescope keeps of a request once the plugin has configured it.
 *
 * The probe request carries every secret the starter's API receives, so the
 * stored entry is the evidence: each one must read `********`. The control is
 * the unlisted `name` parameter, which must survive in clear text, or a
 * redaction that blanked the whole payload would pass.
 */
class TelescopeRedactionTest extends TestCase
{
    /**
     * Telescope's rules are statics that outlive this application.
     *
     * @var array<string, mixed>
     */
    private array $statics = [];

    protected function setUp(): void
    {
        if (! class_exists(Telescope::class)) {
            $this->markTestSkipped('laravel/telescope is not installed.');
        }

        $this->statics = [
            'filterBatch' => Telescope::$filterBatchUsing,
            'requestParameters' => Telescope::$hiddenRequestParameters,
            'responseParameters' => Telescope::$hiddenResponseParameters,
            'requestHeaders' => Telescope::$hiddenRequestHeaders,
        ];

        parent::setUp();

        foreach (glob(__DIR__ . '/../../../vendor/laravel/telescope/database/migrations/*.php') as $file) {
            (require $file)->up();
        }

        Route::post('/probe', static fn () => response()->json([
            'token' => 'issued-sanctum-token',
            'user' => 'ops',
        ]));

        Route::get('/explode', static function (): never {
            throw new RuntimeException('Probe failure.');
        });
    }

    protected function tearDown(): void
    {
        Telescope::stopRecording();
        Telescope::flushEntries();

        parent::tearDown();

        Telescope::$filterBatchUsing = $this->statics['filterBatch'];
        Telescope::$hiddenRequestParameters = $this->statics['requestParameters'];
        Telescope::$hiddenResponseParameters = $this->statics['responseParameters'];
        Telescope::$hiddenRequestHeaders = $this->statics['requestHeaders'];
    }

    public function test_a_recorded_request_shows_its_secrets_masked(): void
    {
        TelescopeRedaction::hideSecrets();

        $this->withHeaders([
            'Authorization' => 'Bearer live-token',
            'X-XSRF-TOKEN' => 'xsrf-value',
        ])->postJson('/probe', [
            'name' => 'Ops',
            'token' => 'push-or-reset-token',
            'id_token' => 'google-id-token',
            'code' => '123456',
            'code_verifier' => 'pkce-verifier',
            'recovery_code' => 'abcd-efgh',
            'current_password' => 'old-secret',
        ])->assertOk();

        $content = $this->recordedRequest();

        $this->assertSame('Ops', $content['payload']['name']);
        $this->assertSame('********', $content['payload']['id_token']);
        $this->assertSame('********', $content['payload']['token']);
        $this->assertSame('********', $content['payload']['code']);
        $this->assertSame('********', $content['payload']['code_verifier']);
        $this->assertSame('********', $content['payload']['recovery_code']);
        $this->assertSame('********', $content['payload']['current_password']);
        $this->assertSame('********', $content['response']['token']);
        $this->assertSame('ops', $content['response']['user']);
        $this->assertSame('********', $content['headers']['authorization']);
        $this->assertSame('********', $content['headers']['x-xsrf-token']);
    }

    public function test_outside_local_an_unremarkable_batch_is_dropped(): void
    {
        TelescopeRedaction::keepNoteworthyBatches();

        $this->postJson('/probe', [
            'name' => 'Ops',
        ])->assertOk();

        $this->assertSame(0, DB::table('telescope_entries')->count());
    }

    public function test_outside_local_a_batch_with_a_reportable_exception_is_kept(): void
    {
        TelescopeRedaction::keepNoteworthyBatches();

        $this->get('/explode')->assertServerError();

        $this->assertSame(1, DB::table('telescope_entries')->where('type', 'exception')->count());
        $this->assertSame(1, DB::table('telescope_entries')->where('type', 'request')->count());
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            TelescopeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telescope.storage.database.connection', 'testing');
    }

    /**
     * @return array<string, mixed>
     */
    private function recordedRequest(): array
    {
        $entry = DB::table('telescope_entries')->where('type', 'request')->sole();

        return json_decode($entry->content, true);
    }
}
