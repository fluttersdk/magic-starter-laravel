<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CreateUser;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Notifications\NewsletterSubscribedNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Registering with the newsletter box ticked records the consent it stands for:
 * an owned row, the consent version and source, and the confirmation mail.
 */
final class CreateUserNewsletterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();
        MagicStarter::useUserModel(CreateUserNewsletterTestUser::class);

        config(['magic-starter.features' => [Features::newsletterSubscription()]]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password');
            $table->timestamps();
        });

        foreach ([
            'create_newsletter_subscribers_table',
            'add_consent_to_newsletter_subscribers_table',
        ] as $migration) {
            (require __DIR__ . "/../../database/migrations/{$migration}.php")->up();
        }
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_registering_with_the_box_ticked_creates_a_consented_row_and_sends_the_confirmation(): void
    {
        Notification::fake();

        $user = (new CreateUser)->create($this->input(['subscribe_newsletter' => true]));

        $row = NewsletterSubscriber::query()->sole();
        $this->assertSame($user->getAuthIdentifier(), $row->user_id);
        $this->assertSame('reader@example.com', $row->email);
        $this->assertTrue($row->is_active);
        $this->assertNotNull($row->consented_at);
        $this->assertSame('1', $row->consent_version);
        $this->assertSame('registration', $row->consent_source);
        $this->assertSame('registration', $row->source);
        $this->assertNotEmpty($row->unsubscribe_token);
        Notification::assertSentToTimes($user, NewsletterSubscribedNotification::class, 1);
    }

    public function test_registering_without_the_box_creates_no_row_and_sends_nothing(): void
    {
        Notification::fake();

        (new CreateUser)->create($this->input());
        (new CreateUser)->create($this->input([
            'email' => 'other@example.com',
            'subscribe_newsletter' => false,
        ]));

        $this->assertSame(0, NewsletterSubscriber::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_feature_off_ignores_the_box(): void
    {
        Notification::fake();
        config(['magic-starter.features' => []]);

        (new CreateUser)->create($this->input(['subscribe_newsletter' => true]));

        $this->assertSame(0, NewsletterSubscriber::query()->count());
        Notification::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Reader',
            'email' => 'reader@example.com',
            'password' => 'Secret123!',
        ], $overrides);
    }
}

final class CreateUserNewsletterTestUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
