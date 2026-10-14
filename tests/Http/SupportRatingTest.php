<?php

namespace FlutterSdk\MagicStarter\Tests\Http;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Notifications\SupportConversationNeedsStaffNotification;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * The thumbs rating of a resolved conversation, driven through the route the package registers.
 *
 * Every refusal asserts the row is untouched and every isolation case asserts the opener's own success beside the
 * intruder's 404, so a route that is simply missing cannot pass. Staff alerts are counted from the event the
 * rating fires, per reason.
 */
class SupportRatingTest extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
            Features::supportCsat(),
        ]);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 12:00:00');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'add_feedback_columns_to_support_conversations_table.php',
        ] as $file) {
            (require __DIR__ . '/../../database/migrations/' . $file)->up();
        }

        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_rating_an_open_conversation_is_refused(): void
    {
        $opener = $this->customer('opener@example.com');
        $conversation = $this->conversation($opener, SupportConversationStatus::OPEN);

        $this->actingAs($opener, 'sanctum');
        $this->postJson("/support/conversations/{$conversation->getKey()}/rating", [
            'rating' => 'down',
        ])->assertUnprocessable()->assertJsonValidationErrors('rating');

        $this->assertNull($conversation->refresh()->csat_rating);
        $this->assertNull($conversation->csat_rated_at);
        $this->assertCount(0, $this->alerts());
    }

    public function test_the_owner_rates_a_resolved_conversation_and_can_rate_again_after_a_new_resolution(): void
    {
        $opener = $this->customer('opener@example.com');
        $assignee = $this->customer('staff@example.com');
        $conversation = $this->conversation($opener, SupportConversationStatus::RESOLVED, $assignee);

        $this->actingAs($opener, 'sanctum');
        $this->getJson("/support/conversations/{$conversation->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.can_rate', true)
            ->assertJsonPath('data.rating', null);

        $this->postJson("/support/conversations/{$conversation->getKey()}/rating", [
            'rating' => 'up',
            'comment' => 'Quick and clear.',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->getKey())
            ->assertJsonPath('data.can_rate', false)
            ->assertJsonPath('data.rating.value', 'up')
            ->assertJsonPath('data.rating.comment', 'Quick and clear.')
            ->assertJsonPath('data.rating.rated_at', now()->toIso8601String());

        $conversation->refresh();
        $this->assertSame(SupportCsatRating::UP, $conversation->csat_rating);
        $this->assertSame((string) $assignee->getKey(), (string) $conversation->csat_assignee_id);

        $this->getJson("/support/conversations/{$conversation->getKey()}")->assertJsonPath('data.can_rate', false);

        // The customer writes again, staff resolve it a second time.
        $this->travel(1)->hours();
        $conversation->forceFill([
            'status' => SupportConversationStatus::RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $this->getJson("/support/conversations/{$conversation->getKey()}")
            ->assertJsonPath('data.can_rate', true)
            ->assertJsonPath('data.rating.value', 'up');
    }

    public function test_a_teammate_cannot_rate_the_conversation(): void
    {
        $opener = $this->customer('opener@example.com', team: 'team-1');
        $teammate = $this->customer('teammate@example.com', team: 'team-1');
        $conversation = $this->conversation($opener, SupportConversationStatus::RESOLVED);

        $this->actingAs($teammate, 'sanctum');
        $this->postJson("/support/conversations/{$conversation->getKey()}/rating", [
            'rating' => 'down',
        ])->assertNotFound();

        $this->assertNull($conversation->refresh()->csat_rating);
        $this->assertCount(0, $this->alerts());

        $this->actingAs($opener, 'sanctum');
        $this->postJson("/support/conversations/{$conversation->getKey()}/rating", [
            'rating' => 'down',
        ])->assertOk();
    }

    public function test_a_thumbs_down_alerts_staff_once_per_resolution(): void
    {
        $opener = $this->customer('opener@example.com');
        $conversation = $this->conversation($opener, SupportConversationStatus::RESOLVED);
        $url = "/support/conversations/{$conversation->getKey()}/rating";

        $this->actingAs($opener, 'sanctum');

        $this->postJson($url, ['rating' => 'down'])->assertOk();
        $this->assertCount(1, $this->alerts());
        $this->assertTrue($this->alerts()->sole()->conversation->is($conversation));

        $this->postJson($url, ['rating' => 'up'])->assertOk();
        $this->assertCount(1, $this->alerts());
        $this->assertSame(SupportCsatRating::UP, $conversation->refresh()->csat_rating);

        $this->postJson($url, ['rating' => 'down', 'comment' => 'Changed my mind.'])->assertOk();
        $this->assertCount(1, $this->alerts());
        $this->assertSame(SupportCsatRating::DOWN, $conversation->refresh()->csat_rating);
        $this->assertSame('Changed my mind.', $conversation->csat_comment);

        // A second resolution is a new cycle with its own alert.
        $this->travel(1)->hours();
        $conversation->forceFill([
            'status' => SupportConversationStatus::RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $this->postJson($url, ['rating' => 'down'])->assertOk();
        $this->assertCount(2, $this->alerts());
    }

    public function test_a_thumbs_up_never_alerts_staff(): void
    {
        $opener = $this->customer('opener@example.com');
        $conversation = $this->conversation($opener, SupportConversationStatus::RESOLVED);

        $this->actingAs($opener, 'sanctum');
        $this->postJson("/support/conversations/{$conversation->getKey()}/rating", [
            'rating' => 'up',
        ])->assertOk();

        $this->assertCount(0, $this->alerts());
        $this->assertNull($conversation->refresh()->csat_alerted_at);
    }

    public function test_an_unknown_rating_and_an_overlong_comment_are_refused(): void
    {
        $opener = $this->customer('opener@example.com');
        $conversation = $this->conversation($opener, SupportConversationStatus::RESOLVED);
        $url = "/support/conversations/{$conversation->getKey()}/rating";

        $this->actingAs($opener, 'sanctum');

        $this->postJson($url, ['rating' => 'sideways'])->assertJsonValidationErrors('rating');
        $this->postJson($url, ['rating' => 'up', 'comment' => str_repeat('a', 1001)])
            ->assertJsonValidationErrors('comment');
        $this->postJson($url, ['rating' => 'up', 'comment' => str_repeat('a', 1000)])->assertOk();

        $this->assertSame(SupportCsatRating::UP, $conversation->refresh()->csat_rating);
    }

    public function test_the_staff_lines_for_a_rating_and_a_feedback_carry_no_customer_text(): void
    {
        $opener = $this->customer('opener@example.com');
        $conversation = $this->conversation($opener, SupportConversationStatus::RESOLVED);

        foreach (['negative_rating', 'feedback'] as $reason) {
            $mail = (new SupportConversationNeedsStaffNotification($conversation, $reason))->toMail($opener);

            $this->assertSame(
                [
                    Lang::get("magic-starter::admin_support.notifications.staff.{$reason}"),
                    Lang::get('magic-starter::admin_support.notifications.staff.open_panel'),
                ],
                $mail->introLines,
            );
            $this->assertStringNotContainsString(
                Lang::get('magic-starter::admin_support.notifications.staff.customer_wrote'),
                $mail->introLines[0],
            );
            $this->assertStringNotContainsString('notifications.staff', $mail->introLines[0]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, SupportConversationNeedsStaff>
     */
    private function alerts(): \Illuminate\Support\Collection
    {
        return Event::dispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'negative_rating',
        )->map(fn (array $arguments): SupportConversationNeedsStaff => $arguments[0]);
    }

    private function customer(string $email, ?string $team = null): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => 'Customer',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => now(),
            'current_team_id' => $team,
        ]);
    }

    private function conversation(
        ConcreteUser $opener,
        SupportConversationStatus $status,
        ?ConcreteUser $assignee = null,
    ): SupportConversation {
        return SupportConversation::query()->create([
            'user_id' => $opener->getKey(),
            'team_id' => $opener->current_team_id,
            'email' => $opener->email,
            'name' => $opener->name,
            'subject' => 'Monitors',
            'source' => SupportConversationSource::IN_APP,
            'status' => $status,
            'assignee_id' => $assignee?->getKey(),
            'resolved_at' => $status === SupportConversationStatus::RESOLVED ? now() : null,
            'last_activity_at' => now(),
        ]);
    }
}
