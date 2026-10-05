<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\Users;

use Filament\Notifications\Notification;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\EditUser;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\PushDevicesRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\SocialAccountsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\TeamsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\TokensRelationManager;
use FlutterSdk\MagicStarter\Jobs\PurgeUserNow;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\PushDevice;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The account actions on the edit page and the relation managers' row actions:
 * each runs a package contract, a refusal reads as a notification, and the
 * admin event follows only a success.
 */
class UserActionsTest extends FilamentTestCase
{
    public function test_reset_two_factor_clears_the_second_factor_and_is_hidden_without_the_feature(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $member = $this->member();
        $member->forceFill([
            'two_factor_secret' => 'secret',
            'two_factor_recovery_codes' => 'codes',
            'two_factor_confirmed_at' => Carbon::now(),
        ])->save();

        config()->set('magic-starter.features', []);
        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->assertActionHidden('reset_two_factor');

        config()->set('magic-starter.features', [Features::twoFactorAuthentication()]);
        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->assertActionVisible('reset_two_factor')
            ->callAction('reset_two_factor');

        $member->refresh();

        $this->assertNull($member->two_factor_secret);
        $this->assertNull($member->two_factor_recovery_codes);
        $this->assertNull($member->two_factor_confirmed_at);
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'user.two_factor_reset'
                && $event->subject?->is($member) === true,
        );
    }

    public function test_revoke_all_tokens_deletes_every_token_of_the_user(): void
    {
        $member = $this->member();
        $member->createToken('phone');
        $member->createToken('laptop');
        $other = $this->member('other@example.com');
        $other->createToken('kept');

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->callAction('revoke_tokens');

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_schedule_deletion_stamps_the_account(): void
    {
        Queue::fake();
        $member = $this->member();

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->callAction('schedule_deletion', ['immediately' => false]);

        $this->assertNotNull($member->refresh()->deletion_scheduled_at);
        Queue::assertNotPushed(PurgeUserNow::class);
    }

    public function test_schedule_deletion_can_queue_the_purge_at_once(): void
    {
        Queue::fake();
        $member = $this->member();

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->callAction('schedule_deletion', ['immediately' => true]);

        Queue::assertPushed(PurgeUserNow::class);
    }

    public function test_scheduling_the_deletion_of_a_user_who_owns_a_shared_team_is_refused(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $member = $this->member();
        $team = ConcreteTeam::query()->create([
            'name' => 'Shared',
            'personal_team' => false,
            'user_id' => $member->getKey(),
        ]);
        $team->users()->attach($this->member('colleague@example.com')->getKey(), ['role' => 'member']);

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->callAction('schedule_deletion', ['immediately' => false]);

        $this->assertNull($member->refresh()->deletion_scheduled_at);
        Notification::assertNotified(
            Notification::make()->danger()->title((string) __('magic-starter::social.owns_shared_teams')),
        );
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_cancel_deletion_is_visible_only_when_scheduled_and_clears_the_schedule(): void
    {
        $member = $this->member();

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->assertActionHidden('cancel_deletion');

        $member->forceFill(['deletion_scheduled_at' => Carbon::now()])->save();

        Livewire::actingAs($this->staff())->test(EditUser::class, ['record' => $member->getKey()])
            ->assertActionVisible('cancel_deletion')
            ->callAction('cancel_deletion');

        $this->assertNull($member->refresh()->deletion_scheduled_at);
    }

    public function test_resend_verification_is_visible_only_for_an_unverified_user(): void
    {
        config()->set('magic-starter.features', [Features::emailVerification()]);
        $model = (new class extends ConcreteAdminUser
        {
            public static int $resent = 0;

            public function sendEmailVerificationNotification(): void
            {
                self::$resent++;
            }
        })::class;
        MagicStarter::useUserModel($model);
        $model::$resent = 0;
        $verified = $model::query()->create([
            'name' => 'Verified',
            'email' => 'verified@example.com',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
        $unverified = $model::query()->create([
            'name' => 'Unverified',
            'email' => 'unverified@example.com',
            'password' => 'secret',
        ]);
        $staff = $this->staff();

        Livewire::actingAs($staff)->test(EditUser::class, ['record' => $verified->getKey()])
            ->assertActionHidden('resend_verification');

        Livewire::actingAs($staff)->test(EditUser::class, ['record' => $unverified->getKey()])
            ->assertActionVisible('resend_verification')
            ->callAction('resend_verification');

        $this->assertSame(1, $model::$resent);
    }

    public function test_a_token_can_be_revoked_one_at_a_time(): void
    {
        $member = $this->member();
        $kept = $member->createToken('kept')->accessToken;
        $revoked = $member->createToken('revoked')->accessToken;

        Livewire::actingAs($this->staff())
            ->test(TokensRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditUser::class])
            ->assertCanSeeTableRecords([$kept, $revoked])
            ->callTableAction('revoke', $revoked);

        $this->assertSame([$kept->getKey()], $member->tokens()->pluck('id')->all());
    }

    public function test_a_social_account_can_be_disconnected(): void
    {
        $member = $this->member();
        $github = $this->socialAccount($member, 'github');
        $this->socialAccount($member, 'google');

        Livewire::actingAs($this->staff())
            ->test(SocialAccountsRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditUser::class])
            ->callTableAction('disconnect', $github);

        $this->assertSame(
            ['google'],
            SocialAccount::query()->where('user_id', $member->getKey())->pluck('provider')->all(),
        );
    }

    public function test_disconnecting_the_last_login_method_is_refused_with_a_notification(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $member = $this->member();
        $member->forceFill(['password' => null])->save();
        $only = $this->socialAccount($member, 'github');

        Livewire::actingAs($this->staff())
            ->test(SocialAccountsRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditUser::class])
            ->callTableAction('disconnect', $only);

        $this->assertTrue($only->newQuery()->whereKey($only->getKey())->exists());
        Notification::assertNotified();
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    public function test_a_push_device_can_be_released(): void
    {
        $member = $this->member();
        $released = $this->pushDevice($member, 'sub-1');
        $kept = $this->pushDevice($member, 'sub-2');

        Livewire::actingAs($this->staff())
            ->test(PushDevicesRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditUser::class])
            ->assertCanSeeTableRecords([$released, $kept])
            ->callTableAction('release', $released);

        $this->assertSame(
            ['sub-2'],
            PushDevice::query()->where('user_id', $member->getKey())->pluck('subscription_id')->all(),
        );
    }

    public function test_the_teams_relation_manager_lists_teams_and_offers_no_action(): void
    {
        $member = $this->member();
        $team = ConcreteTeam::query()->create([
            'name' => 'Acme',
            'personal_team' => false,
            'user_id' => $member->getKey(),
        ]);
        $team->users()->attach($member->getKey(), ['role' => 'admin']);

        $page = Livewire::actingAs($this->staff())
            ->test(TeamsRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditUser::class])
            ->assertCanSeeTableRecords([$team]);

        $this->assertSame([], $page->instance()->getTable()->getActions());
    }

    private function staff(): ConcreteAdminUser
    {
        config()->set('magic-starter.admin.emails', ['staff@example.com']);

        return ConcreteAdminUser::query()->firstOrCreate(['email' => 'staff@example.com'], [
            'name' => 'Staff',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }

    private function member(string $email = 'member@example.com'): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => 'Member',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }

    private function socialAccount(ConcreteAdminUser $user, string $provider): SocialAccount
    {
        return SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $provider . '-' . $user->getKey(),
        ]);
    }

    private function pushDevice(ConcreteAdminUser $user, string $subscriptionId): PushDevice
    {
        return PushDevice::query()->create([
            'user_id' => $user->getKey(),
            'subscription_id' => $subscriptionId,
            'reachability' => PushDevice::REACHABLE,
            'captured_at' => Carbon::now(),
            'reported_at' => Carbon::now(),
        ]);
    }
}
