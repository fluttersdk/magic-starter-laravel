<?php

namespace FlutterSdk\MagicStarter\Tests\Listeners;

use FlutterSdk\MagicStarter\Actions\UpdateUserProfile;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The newsletter row keeps a copy of the owner's address, so an address change
 * from any path (the profile action, a plain model update, the admin panel)
 * moves the copy with it, and an owner-less row already holding the new address
 * gives way to the owned one because the column is unique.
 */
#[DefineEnvironment('withTheFeature')]
final class SyncNewsletterEmailTest extends TestCase
{
    /**
     * The listener is registered at boot, so the feature has to be on before it.
     *
     * @param  Application  $app
     */
    protected function withTheFeature($app): void
    {
        $app['config']->set('magic-starter.features', [
            Features::newsletterSubscription(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(SyncNewsletterEmailTestUser::class);

        foreach ([
            'create_users_table',
            'create_newsletter_subscribers_table',
            'add_consent_to_newsletter_subscribers_table',
        ] as $migration) {
            (require __DIR__ . "/../../database/migrations/{$migration}.php")->up();
        }
    }

    public function test_the_profile_action_moves_the_row_to_the_new_address(): void
    {
        $user = $this->subscribedUser('old@example.com');

        (new UpdateUserProfile)->update($user, [
            'name' => 'Reader',
            'email' => 'New@Example.com',
        ]);

        $this->assertSame('new@example.com', NewsletterSubscriber::query()->sole()->email);
    }

    public function test_a_plain_model_update_moves_the_row_to_the_new_address(): void
    {
        $user = $this->subscribedUser('old@example.com');
        $token = NewsletterSubscriber::query()->sole()->unsubscribe_token;

        $user->update(['email' => 'new@example.com']);

        $row = NewsletterSubscriber::query()->sole();
        $this->assertSame('new@example.com', $row->email);
        $this->assertSame($user->getKey(), $row->user_id);
        $this->assertSame($token, $row->unsubscribe_token);
        $this->assertTrue($row->is_active);
    }

    public function test_an_address_held_by_an_unclaimed_row_is_taken_over(): void
    {
        $user = $this->subscribedUser('old@example.com');
        NewsletterSubscriber::query()->create([
            'email' => 'Taken@Example.com',
            'is_active' => true,
            'source' => 'registration',
        ]);

        $user->update(['email' => 'taken@example.com']);

        $row = NewsletterSubscriber::query()->sole();
        $this->assertSame('taken@example.com', $row->email);
        $this->assertSame($user->getKey(), $row->user_id);
    }

    public function test_the_taken_over_rows_audit_trail_goes_with_it(): void
    {
        (require __DIR__ . '/../../database/migrations/create_magic_starter_audits_table.php')->up();

        $user = $this->subscribedUser('old@example.com');
        $unclaimed = NewsletterSubscriber::query()->create([
            'email' => 'taken@example.com',
            'is_active' => true,
            'source' => 'registration',
        ]);

        Audit::query()->forceCreate([
            'event' => 'created',
            'auditable_type' => NewsletterSubscriber::class,
            'auditable_id' => (string) $unclaimed->getKey(),
            'new_values' => ['email' => 'taken@example.com'],
            'created_at' => now(),
        ]);

        $user->update(['email' => 'taken@example.com']);

        $this->assertSame(0, DB::table('magic_starter_audits')
            ->where('auditable_id', (string) $unclaimed->getKey())
            ->count());
    }

    public function test_a_change_that_leaves_the_address_alone_touches_nothing(): void
    {
        $user = $this->subscribedUser('same@example.com');
        $before = NewsletterSubscriber::query()->sole()->getAttributes();

        $user->update(['name' => 'Renamed']);

        $this->assertSame($before, NewsletterSubscriber::query()->sole()->getAttributes());
    }

    public function test_another_users_row_is_never_touched(): void
    {
        $user = $this->subscribedUser('mine@example.com');
        $other = $this->subscribedUser('theirs@example.com');

        $user->update(['email' => 'moved@example.com']);

        $this->assertSame('theirs@example.com', NewsletterSubscriber::query()->where('user_id', $other->getKey())->sole()->email);
        $this->assertSame('moved@example.com', NewsletterSubscriber::query()->where('user_id', $user->getKey())->sole()->email);
    }

    public function test_a_user_without_a_row_changes_address_quietly(): void
    {
        $user = SyncNewsletterEmailTestUser::query()->create([
            'name' => 'Reader',
            'email' => 'no-row@example.com',
            'password' => 'secret',
        ]);

        $user->update(['email' => 'still-none@example.com']);

        $this->assertSame(0, NewsletterSubscriber::query()->count());
    }

    #[DefineEnvironment('withoutTheFeature')]
    public function test_the_listener_is_not_registered_without_the_feature(): void
    {
        $user = SyncNewsletterEmailTestUser::query()->create([
            'name' => 'Reader',
            'email' => 'old@example.com',
            'password' => 'secret',
        ]);
        NewsletterSubscriber::query()->create([
            'user_id' => $user->getKey(),
            'email' => 'old@example.com',
        ]);

        $user->update(['email' => 'new@example.com']);

        $this->assertSame('old@example.com', NewsletterSubscriber::query()->sole()->email);
    }

    /**
     * @param  Application  $app
     */
    protected function withoutTheFeature($app): void
    {
        $app['config']->set('magic-starter.features', []);
    }

    private function subscribedUser(string $email): SyncNewsletterEmailTestUser
    {
        Notification::fake();

        $user = SyncNewsletterEmailTestUser::query()->create([
            'name' => 'Reader',
            'email' => $email,
            'password' => 'secret',
        ]);

        NewsletterSubscriber::subscribeUser($user, 'profile');

        return $user;
    }
}

final class SyncNewsletterEmailTestUser extends ConcreteUser
{
    use Notifiable;
}
