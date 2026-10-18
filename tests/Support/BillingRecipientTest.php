<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\BillingRecipient;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;

/**
 * Locks who a billing lifecycle mail goes to: the person BillingPolicy::manage lets spend the billable's money. A
 * team's mail reaching a member who cannot act on it, or an owner-less team throwing inside the webhook
 * transaction, is what these tests keep out.
 */
final class BillingRecipientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $schema->create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });
    }

    public function test_a_team_billable_resolves_to_its_owner(): void
    {
        config(['magic-starter.billing.billable' => 'team']);

        $owner = $this->createUser('owner@example.test');
        $team = $this->createTeam($owner->getKey());

        $recipient = BillingRecipient::for($team);

        $this->assertNotNull($recipient);
        $this->assertTrue($recipient->is($owner));
    }

    public function test_a_user_billable_resolves_to_itself(): void
    {
        config(['magic-starter.billing.billable' => 'user']);

        $user = $this->createUser('solo@example.test');

        $this->assertSame($user, BillingRecipient::for($user));
    }

    /**
     * A config published before `billable` existed carries no key, and the policy treats that as a user billable.
     */
    public function test_a_config_without_a_billable_key_resolves_to_the_billable_itself(): void
    {
        config(['magic-starter.billing' => []]);

        $user = $this->createUser('solo@example.test');

        $this->assertSame($user, BillingRecipient::for($user));
    }

    public function test_a_team_whose_owner_row_is_gone_has_no_recipient(): void
    {
        config(['magic-starter.billing.billable' => 'team']);

        $team = $this->createTeam('00000000-0000-0000-0000-000000000000');

        $this->assertNull(BillingRecipient::for($team));
    }

    /**
     * A team-mode config over a billable that has no `owner()` relation must answer null, not call a missing method.
     */
    public function test_a_team_mode_billable_without_an_owner_relation_has_no_recipient(): void
    {
        config(['magic-starter.billing.billable' => 'team']);

        $this->assertNull(BillingRecipient::for($this->createUser('stray@example.test')));
    }

    private function createUser(string $email): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Test User',
            'email' => $email,
        ]);
    }

    private function createTeam(string $ownerId): ConcreteTeam
    {
        return ConcreteTeam::query()->create([
            'user_id' => $ownerId,
            'name' => 'Billed Team',
            'personal_team' => false,
        ]);
    }
}
