<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Locks the reset URL the package installs through `ResetPassword::createUrlUsing()`.
 *
 * This is the worst of the three relative-link sites: it is the path a customer
 * takes when they are ALREADY locked out, and a mail client cannot resolve
 * `/auth/reset-password?token=...`. The closure was registered in `boot()` and
 * called by nothing in this suite, so the fix shipped with the branch it fixes
 * uncovered; the sibling `TeamInvitationNotificationTest` covered its own link
 * and left this one to a manual tinker session.
 *
 * Every case here drives the real closure through `ResetPassword::toMail()`,
 * which is the same path the delivered mail takes.
 */
final class PasswordResetUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://api.example.test']);
        // Both, deliberately: forceRootUrl sets the host and forceScheme the scheme,
        // and without the second one url('/') answers http on a test app, which would
        // make the fallback assertions below about the harness instead of the code.
        URL::forceRootUrl('https://api.example.test');
        URL::forceScheme('https');
    }

    public function test_the_reset_url_uses_the_frontend_url_when_configured(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test']);

        $this->assertSame(
            'https://app.example.test/auth/reset-password?token=tok-123&email=ada@example.test',
            $this->resetUrlFor('tok-123'),
        );
    }

    public function test_a_trailing_slash_on_the_frontend_url_does_not_double_up(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test/']);

        $this->assertSame(
            'https://app.example.test/auth/reset-password?token=tok-123&email=ada@example.test',
            $this->resetUrlFor('tok-123'),
        );
    }

    /**
     * The chain the old `config('magic-starter.frontend_url', config('app.frontend_url'))`
     * INTENDED and never reached: its default argument only fires for a MISSING key,
     * and `MAGIC_STARTER_FRONTEND_URL=` makes the key present-and-empty. So
     * `app.frontend_url` was dead configuration and an operator who set it got nothing.
     */
    public function test_an_empty_package_key_falls_back_to_the_app_key(): void
    {
        config([
            'magic-starter.frontend_url' => '',
            'app.frontend_url' => 'https://fallback.example.test',
        ]);

        $this->assertSame(
            'https://fallback.example.test/auth/reset-password?token=tok-123&email=ada@example.test',
            $this->resetUrlFor('tok-123'),
        );
    }

    /**
     * THE REGRESSION. With no frontend URL anywhere the link must still be absolute,
     * because a relative one is what locked the customer out of their own recovery.
     */
    public function test_no_frontend_url_anywhere_still_yields_an_absolute_url(): void
    {
        config(['magic-starter.frontend_url' => '', 'app.frontend_url' => null]);

        $this->assertSame(
            'https://api.example.test/auth/reset-password?token=tok-123&email=ada@example.test',
            $this->resetUrlFor('tok-123'),
        );
    }

    public function test_a_missing_frontend_url_still_yields_an_absolute_url(): void
    {
        config(['magic-starter.frontend_url' => null, 'app.frontend_url' => null]);

        $this->assertStringStartsWith('https://api.example.test/', $this->resetUrlFor('tok-123'));
    }

    /**
     * A whitespace-only value is the same operator mistake as an empty one, and it
     * used to produce a URL with leading spaces rather than a usable host.
     */
    public function test_a_whitespace_only_frontend_url_is_treated_as_absent(): void
    {
        config(['magic-starter.frontend_url' => '   ', 'app.frontend_url' => '  ']);

        $this->assertStringStartsWith('https://api.example.test/', $this->resetUrlFor('tok-123'));
    }

    /**
     * The third shape of "no base", and the sharpest of the three.
     *
     * `rtrim(trim('/'), '/')` is the EMPTY STRING rather than null, so a
     * slash-only value used to pass straight through the `?? rtrim(url('/'), '/')`
     * guard (`??` fires on null, not on `''`) and rebuild the exact relative URL
     * this branch exists to remove. Empty and whitespace-only never reached that
     * far, because they were rejected before the rtrim ran.
     */
    public function test_a_slash_only_frontend_url_is_treated_as_absent(): void
    {
        config(['magic-starter.frontend_url' => '/', 'app.frontend_url' => null]);

        $this->assertSame(
            'https://api.example.test/auth/reset-password?token=tok-123&email=ada@example.test',
            $this->resetUrlFor('tok-123'),
        );
    }

    public function test_a_slash_only_package_key_still_falls_back_to_the_app_key(): void
    {
        config([
            'magic-starter.frontend_url' => '///',
            'app.frontend_url' => 'https://fallback.example.test',
        ]);

        $this->assertSame(
            'https://fallback.example.test/auth/reset-password?token=tok-123&email=ada@example.test',
            $this->resetUrlFor('tok-123'),
        );
    }

    /**
     * Build the URL the delivered mail would carry, through the notification itself
     * rather than by calling the closure directly, so the registration is covered too.
     */
    private function resetUrlFor(string $token): string
    {
        $notifiable = new PasswordResetUrlTestUser(['email' => 'ada@example.test']);

        return (string) (new ResetPassword($token))->toMail($notifiable)->actionUrl;
    }
}

/**
 * The smallest thing the closure reads: an email for password reset.
 *
 * An Eloquent model because the notifiable is one in every real deployment, but
 * never persisted, so this suite stays off the database.
 */
final class PasswordResetUrlTestUser extends Model
{
    protected $guarded = [];

    public function getEmailForPasswordReset(): string
    {
        return $this->getAttribute('email');
    }
}
