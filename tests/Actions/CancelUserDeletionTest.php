<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Contracts\CancelsUserDeletion;
use FlutterSdk\MagicStarter\Events\UserDeletionCancelled;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelling a scheduled deletion clears the schedule and tells the host, and
 * leaves an orphan's schedule alone: the identity provider deleted that
 * account, so nobody on this side can take the request back.
 */
final class CancelUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();
        });

        Event::fake([UserDeletionCancelled::class]);
    }

    public function test_a_scheduled_deletion_is_cleared_and_announced(): void
    {
        $user = $this->createUser(scheduled: true);

        $cancelled = app(CancelsUserDeletion::class)->cancel($user);

        $this->assertTrue($cancelled);
        $this->assertNull($user->refresh()->deletion_scheduled_at);
        Event::assertDispatched(
            UserDeletionCancelled::class,
            fn (UserDeletionCancelled $event): bool => $event->user->getAuthIdentifier() === $user->getKey(),
        );
    }

    public function test_nothing_happens_when_nothing_is_scheduled(): void
    {
        $user = $this->createUser(scheduled: false);

        $this->assertFalse(app(CancelsUserDeletion::class)->cancel($user));

        Event::assertNotDispatched(UserDeletionCancelled::class);
    }

    public function test_an_orphans_schedule_stays(): void
    {
        $user = $this->createUser(scheduled: true, orphan: true);

        $this->assertFalse(app(CancelsUserDeletion::class)->cancel($user));

        $this->assertNotNull($user->refresh()->deletion_scheduled_at);
        Event::assertNotDispatched(UserDeletionCancelled::class);
    }

    private function createUser(bool $scheduled, bool $orphan = false): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Someone',
            'deletion_scheduled_at' => $scheduled ? now()->addDays(30) : null,
            'orphaned_at' => $orphan ? now() : null,
        ]);
    }
}
