<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Contracts\CancelsTeamInvitations;
use FlutterSdk\MagicStarter\Contracts\ResendsTeamInvitations;
use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamInvitation;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelling and resending a pending invitation through the contracts an admin
 * surface calls.
 */
final class TeamInvitationAdminTest extends TestCase
{
    use RefreshDatabase;

    private ConcreteUser $owner;

    private ConcreteTeamInvitation $invitation;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });

        // `Team::invitations()` infers its foreign key from the runtime class.
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('concrete_team_id');
            $table->string('email');
            $table->string('role')->nullable();
            $table->string('token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Notification::fake();

        $this->owner = ConcreteUser::query()->create(['name' => 'Owner']);
        $team = ConcreteTeam::query()->create([
            'user_id' => $this->owner->getKey(),
            'name' => 'Shared',
            'personal_team' => false,
        ]);
        $this->invitation = $team->invitations()->create([
            'email' => 'invitee@test.dev',
            'role' => 'member',
            'token' => 'a-token',
            'expires_at' => now()->subDay(),
        ]);
    }

    public function test_cancel_deletes_the_invitation(): void
    {
        app(CancelsTeamInvitations::class)->cancel($this->owner, $this->invitation);

        $this->assertNull(ConcreteTeamInvitation::query()->find($this->invitation->getKey()));
    }

    public function test_resend_mails_the_same_invitation_again_with_a_fresh_expiry(): void
    {
        config(['magic-starter.invitation_expiry_days' => 7]);
        $this->freezeSecond();

        app(ResendsTeamInvitations::class)->resend($this->owner, $this->invitation);

        $invitation = ConcreteTeamInvitation::query()->findOrFail($this->invitation->getKey());
        $this->assertSame('a-token', $invitation->token, 'A resend keeps the link the invitee may already hold.');
        $this->assertTrue($invitation->expires_at?->equalTo(now()->addDays(7)));
        Notification::assertSentTo(
            new AnonymousNotifiable,
            TeamInvitationNotification::class,
            fn (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'invitee@test.dev'
                && $notification->invitation->getKey() === $this->invitation->getKey(),
        );
    }
}
