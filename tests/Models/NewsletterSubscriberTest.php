<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Locks the consent shape of a subscription: a row belongs to a user, carries a
 * one-time unsubscribe token, and only a consenting, still subscribed, owned row
 * is a marketing recipient. The schema is the one the shipped migrations build.
 */
class NewsletterSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
        ]);

        foreach ([
            'create_users_table.php',
            'create_newsletter_subscribers_table.php',
            'add_consent_to_newsletter_subscribers_table.php',
        ] as $file) {
            $migration = require __DIR__ . '/../../database/migrations/' . $file;
            $migration->up();
        }
    }

    public function test_creating_a_row_mints_a_url_safe_token(): void
    {
        $first = NewsletterSubscriber::query()->create([
            'email' => 'a@example.com',
        ]);
        $second = NewsletterSubscriber::query()->create([
            'email' => 'b@example.com',
        ]);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{54}$/', $first->unsubscribe_token);
        $this->assertNotSame($first->unsubscribe_token, $second->unsubscribe_token);
    }

    public function test_creating_a_row_keeps_a_token_it_was_given(): void
    {
        $subscriber = new NewsletterSubscriber([
            'email' => 'a@example.com',
        ]);
        $subscriber->unsubscribe_token = 'given-token';
        $subscriber->save();

        $this->assertSame('given-token', $subscriber->fresh()?->unsubscribe_token);
    }

    public function test_the_token_never_serializes(): void
    {
        $subscriber = NewsletterSubscriber::query()->create([
            'email' => 'a@example.com',
        ]);

        $this->assertArrayNotHasKey('unsubscribe_token', $subscriber->toArray());
    }

    public function test_it_belongs_to_the_configured_user_model(): void
    {
        $user = $this->makeUser('a@example.com');
        $subscriber = NewsletterSubscriber::query()->create([
            'email' => 'a@example.com',
            'user_id' => $user->getKey(),
        ]);

        $this->assertInstanceOf(ConcreteUser::class, $subscriber->user);
        $this->assertTrue($subscriber->user->is($user));
    }

    public function test_marketable_keeps_only_active_consenting_subscribed_owned_rows(): void
    {
        $consent = [
            'is_active' => true,
            'consented_at' => now(),
            'consent_version' => 'v1',
            'consent_source' => 'settings',
        ];

        $kept = $this->subscribe('kept@example.com', $consent);
        $this->subscribe('inactive@example.com', array_merge($consent, [
            'is_active' => false,
        ]));
        $this->subscribe('unconsented@example.com', array_merge($consent, [
            'consented_at' => null,
        ]));
        $this->subscribe('unsubscribed@example.com', array_merge($consent, [
            'unsubscribed_at' => now(),
        ]));
        NewsletterSubscriber::query()->create(array_merge($consent, [
            'email' => 'ownerless@example.com',
        ]));

        $this->assertSame([
            $kept->getKey(),
        ], NewsletterSubscriber::query()->marketable()->pluck('id')->all());
    }

    public function test_for_user_finds_the_row_the_user_owns(): void
    {
        $subscriber = $this->subscribe('a@example.com');
        $user = $subscriber->user;
        $user->forceFill([
            'email' => 'renamed@example.com',
        ])->save();

        $this->assertTrue(NewsletterSubscriber::forUser($user)?->is($subscriber));
    }

    public function test_for_user_claims_a_row_written_by_address_only(): void
    {
        $user = $this->makeUser('reader@example.com');
        $id = (string) Str::uuid();
        DB::table('newsletter_subscribers')->insert([
            'id' => $id,
            'email' => 'Reader@Example.com',
            'is_active' => true,
            'source' => 'settings',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subscriber = NewsletterSubscriber::forUser($user);

        $this->assertNotNull($subscriber);
        $this->assertSame($id, $subscriber->getKey());
        $row = DB::table('newsletter_subscribers')->where('id', $id)->first();
        $this->assertEquals($user->getKey(), $row->user_id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{54}$/', $row->unsubscribe_token);
    }

    public function test_for_user_never_claims_a_row_another_user_owns(): void
    {
        $owned = $this->subscribe('shared@example.com');
        $owned->user->forceFill([
            'email' => 'moved@example.com',
        ])->save();
        $newcomer = $this->makeUser('shared@example.com');

        $this->assertNull(NewsletterSubscriber::forUser($newcomer));
        $this->assertEquals($owned->user_id, $owned->fresh()?->user_id);
    }

    public function test_for_user_answers_null_without_a_row(): void
    {
        $this->assertNull(NewsletterSubscriber::forUser($this->makeUser('a@example.com')));
    }

    private function makeUser(string $email): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Reader',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    /**
     * A row owned by a new user of the same address.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function subscribe(string $email, array $attributes = []): NewsletterSubscriber
    {
        return NewsletterSubscriber::query()->create(array_merge([
            'email' => $email,
            'user_id' => $this->makeUser($email)->getKey(),
        ], $attributes));
    }
}
