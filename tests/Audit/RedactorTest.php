<?php

namespace FlutterSdk\MagicStarter\Tests\Audit;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Audit\Redactor;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Redaction is deny-by-default: anything a model hides, encrypts or hashes,
 * every credential-shaped column, the configured list and the model's own
 * exclusions are stored as a placeholder in both old and new values.
 */
final class RedactorTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('magic-starter.features', [
            Features::audit(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__ . '/../../database/migrations/create_magic_starter_audits_table.php')->up();

        Schema::create('redactor_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('label')->nullable();
            $table->text('vault')->nullable();
            $table->text('ledger')->nullable();
            $table->string('hidden_note')->nullable();
            $table->string('pin')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('two_factor_secret')->nullable();
            $table->string('two_factor_recovery_codes')->nullable();
            $table->string('token')->nullable();
            $table->string('nickname')->nullable();
            $table->string('internal')->nullable();
            $table->timestamps();
        });
    }

    public function test_an_encrypted_array_and_a_hidden_attribute_store_the_placeholder_in_old_and_new_values(): void
    {
        $secret = RedactorTestSecret::query()->create([
            'label' => 'first',
            'vault' => ['api_key' => 'sk-live-old'],
            'hidden_note' => 'old note',
        ]);

        $secret->update([
            'label' => 'second',
            'vault' => ['api_key' => 'sk-live-new'],
            'hidden_note' => 'new note',
        ]);

        $audit = Audit::query()->where('event', 'updated')->sole();

        $this->assertSame(Redactor::PLACEHOLDER, $audit->old_values['vault']);
        $this->assertSame(Redactor::PLACEHOLDER, $audit->new_values['vault']);
        $this->assertSame(Redactor::PLACEHOLDER, $audit->old_values['hidden_note']);
        $this->assertSame(Redactor::PLACEHOLDER, $audit->new_values['hidden_note']);
        $this->assertSame('first', $audit->old_values['label']);
        $this->assertSame('second', $audit->new_values['label']);

        $this->assertDatabaseMissingSecrets();
    }

    public function test_a_created_row_redacts_every_sensitive_attribute(): void
    {
        config()->set('magic-starter.audit.redact', [
            'nickname',
        ]);

        RedactorTestSecret::query()->create([
            'label' => 'visible',
            'vault' => ['api_key' => 'sk-live-old'],
            'ledger' => ['iban' => 'TR00-old-note'],
            'hidden_note' => 'old note',
            'pin' => 'old-pin',
            'password' => 'old-password',
            'remember_token' => 'old-remember',
            'two_factor_secret' => 'old-totp',
            'two_factor_recovery_codes' => 'old-codes',
            'token' => 'old-token',
            'nickname' => 'old-nick',
            'internal' => 'old-internal',
        ]);

        $values = Audit::query()->sole()->new_values;

        foreach ([
            'vault',
            'ledger',
            'hidden_note',
            'pin',
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'token',
            'nickname',
            'internal',
        ] as $key) {
            $this->assertSame(Redactor::PLACEHOLDER, $values[$key], "[{$key}] must be redacted.");
        }

        $this->assertSame('visible', $values['label']);
        $this->assertDatabaseMissingSecrets();
    }

    public function test_the_configured_list_extends_the_defaults_and_never_replaces_them(): void
    {
        config()->set('magic-starter.audit.redact', [
            'label',
        ]);

        $redacted = Redactor::redact(new RedactorTestSecret, [
            'label' => 'x',
            'password' => 'y',
            'created_at' => '2026-10-05 00:00:00',
        ]);

        $this->assertSame([
            'label' => Redactor::PLACEHOLDER,
            'password' => Redactor::PLACEHOLDER,
            'created_at' => '2026-10-05 00:00:00',
        ], $redacted);
    }

    public function test_redaction_keeps_the_keys_it_was_given_and_passes_null_through(): void
    {
        $this->assertNull(Redactor::redact(new RedactorTestSecret, null));
        $this->assertSame([
            'label' => 'x',
        ], Redactor::redact(new RedactorTestSecret, [
            'label' => 'x',
        ]));
    }

    private function assertDatabaseMissingSecrets(): void
    {
        $stored = (string) json_encode(Audit::query()->get()->toArray());

        foreach (['sk-live', 'old note', 'new note', 'old-'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $stored);
        }
    }
}

class RedactorTestSecret extends Model
{
    protected $table = 'redactor_secrets';

    protected $guarded = [];

    protected $hidden = [
        'hidden_note',
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = [
        'internal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vault' => 'encrypted:array',
            'ledger' => AsEncryptedCollection::class,
            'pin' => 'hashed',
        ];
    }
}
