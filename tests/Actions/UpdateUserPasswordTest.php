<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\UpdateUserPassword;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Tests for the default UpdateUserPassword action.
 *
 * The action is driven directly rather than through PUT /user/password,
 * because UpdatePasswordRequest::withValidator already runs the identical
 * current-password check and answers 422 before the action is resolved. Over
 * HTTP the action's own guard is therefore unreachable; it exists for the
 * consumer who calls `app(UpdatesUserPasswords::class)->update(...)` from their
 * own code, which is the contract-action pattern this package is built on, and
 * that is the caller these tests stand in for.
 */
final class UpdateUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('phone')->unique()->nullable();
            $table->timestamps();
        });
    }

    /**
     * The happy path, which is what makes the refusal below mean something: an
     * action that threw on every call would satisfy it too.
     */
    public function test_update_replaces_the_password_when_the_current_one_matches(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::query()->create([
            'name' => 'Holder',
            'email' => 'holder@test.dev',
            'password' => Hash::make('OldPassword123'),
            'is_guest' => false,
        ]);

        (new UpdateUserPassword)->update($user, [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

        $this->assertTrue(Hash::check('NewPassword123', (string) $user->fresh()->password));
    }

    /**
     * A wrong current password is refused on `current_password`, and the old
     * password survives.
     *
     * Asserting the field as well as the sentence matters: the same call can
     * fail on `password` (too short, unconfirmed), and a client that renders
     * the error under the wrong input tells the person to fix the field they
     * got right.
     */
    public function test_update_refuses_a_wrong_current_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::query()->create([
            'name' => 'Holder',
            'email' => 'holder@test.dev',
            'password' => Hash::make('OldPassword123'),
            'is_guest' => false,
        ]);

        try {
            (new UpdateUserPassword)->update($user, [
                'current_password' => 'NotTheCurrentOne',
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ]);

            $this->fail('A wrong current password must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['The current password is incorrect.'],
                $exception->errors()['current_password'],
            );
        }

        $this->assertTrue(
            Hash::check('OldPassword123', (string) $user->fresh()->password),
            'The stored password must be untouched after a refused change.',
        );
    }

    /**
     * The refusal reaches the caller in the caller's language.
     *
     * Under `en` a key that resolved to nothing is indistinguishable from one
     * that resolved: `__()` returns its argument on a miss and the English line
     * happens to be the sentence the test above expects. Asking for `tr` is
     * what proves the package's translation namespace is registered.
     */
    public function test_update_answers_its_refusal_in_the_callers_locale(): void
    {
        $this->app->setLocale('tr');

        /** @var ConcreteUser $user */
        $user = ConcreteUser::query()->create([
            'name' => 'Holder',
            'email' => 'holder@test.dev',
            'password' => Hash::make('OldPassword123'),
            'is_guest' => false,
        ]);

        try {
            (new UpdateUserPassword)->update($user, [
                'current_password' => 'NotTheCurrentOne',
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ]);

            $this->fail('A wrong current password must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Mevcut şifreniz hatalı.'],
                $exception->errors()['current_password'],
            );
        }
    }

    /**
     * A guest who has no password yet sets one without supplying a current
     * password, and the guard stays out of the way.
     *
     * This is the branch the refusal above must not swallow: the same closure
     * decides both, and a guard that ran unconditionally would lock every guest
     * out of ever setting a first password.
     */
    public function test_update_lets_a_passwordless_guest_set_a_first_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::query()->create([
            'name' => 'Guest',
            'email' => null,
            'password' => null,
            'is_guest' => true,
        ]);

        (new UpdateUserPassword)->update($user, [
            'password' => 'FirstPassword123',
            'password_confirmation' => 'FirstPassword123',
        ]);

        $fresh = $user->fresh();

        $this->assertTrue(Hash::check('FirstPassword123', (string) $fresh->password));
        $this->assertTrue((bool) $fresh->is_guest, 'A guest with no email and no phone stays a guest.');
    }
}
