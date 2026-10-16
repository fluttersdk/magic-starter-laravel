<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\InviteTeamMember;
use FlutterSdk\MagicStarter\Actions\ResendTeamInvitation;
use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation mail is rendered in the INVITER's language.
 *
 * The recipient is an `AnonymousNotifiable`, which has no locale of its own, and a
 * queued notification renders in the worker's default locale unless `->locale()`
 * pinned one at dispatch. So the mail an inviter read as Turkish arrived English.
 */
final class InviteTeamMemberLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('locale')->nullable();
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
        app()->setLocale('en');
    }

    public function test_the_notification_carries_the_inviters_preferred_locale(): void
    {
        $inviter = InviteTeamMemberLocaleTestUser::query()->create(['name' => 'Ada', 'locale' => 'tr']);

        (new InviteTeamMember)->invite($inviter, $this->teamOwnedBy($inviter), 'Invitee@Test.dev', 'member');

        $this->assertInvitationLocale('tr');
    }

    public function test_an_inviter_without_a_preference_falls_back_to_the_app_locale(): void
    {
        $inviter = InviteTeamMemberLocaleTestUser::query()->create(['name' => 'Ada']);
        app()->setLocale('tr');

        (new InviteTeamMember)->invite($inviter, $this->teamOwnedBy($inviter), 'invitee@test.dev', 'member');

        $this->assertInvitationLocale('tr');
    }

    public function test_an_inviter_that_declares_no_locale_contract_falls_back_to_the_app_locale(): void
    {
        $inviter = ConcreteUser::query()->create(['name' => 'Ada']);
        app()->setLocale('tr');

        (new InviteTeamMember)->invite($inviter, $this->teamOwnedBy($inviter), 'invitee@test.dev', 'member');

        $this->assertInvitationLocale('tr');
    }

    public function test_a_resend_carries_the_actors_preferred_locale(): void
    {
        $actor = InviteTeamMemberLocaleTestUser::query()->create(['name' => 'Ada', 'locale' => 'tr']);
        $invitation = $this->teamOwnedBy($actor)->invitations()->create([
            'email' => 'invitee@test.dev',
            'role' => 'member',
            'token' => 'a-token',
        ]);

        (new ResendTeamInvitation)->resend($actor, $invitation);

        $this->assertInvitationLocale('tr');
    }

    private function teamOwnedBy(ConcreteUser $owner): ConcreteTeam
    {
        return ConcreteTeam::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Shared',
            'personal_team' => false,
        ]);
    }

    private function assertInvitationLocale(string $locale): void
    {
        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            fn (TeamInvitationNotification $notification): bool => $notification->locale === $locale,
        );
    }
}

/**
 * A user whose `locale` column is its preference, as an adopter's model declares it.
 */
final class InviteTeamMemberLocaleTestUser extends ConcreteUser implements HasLocalePreference
{
    public function preferredLocale(): ?string
    {
        $locale = $this->getAttribute('locale');

        return is_string($locale) ? $locale : null;
    }
}
