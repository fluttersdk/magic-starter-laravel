<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Locks the accept URL this notification puts in front of an invited person.
 *
 * The mail is the ONLY way an invitation is accepted, so the link has to be
 * absolute in every configuration. It was not: with `frontend_url` unset the
 * concatenation produced a host-less `/invitations/<token>/accept`, which a mail
 * client cannot resolve and a human cannot copy anywhere useful. Measured in a
 * delivered message on a consumer app, whose own fallback line read "copy and
 * paste the URL below into your web browser: /invitations/.../accept".
 *
 * `VerifyEmailNotification` already guards the same config value; this brings the
 * two into line.
 */
final class TeamInvitationNotificationTest extends TestCase
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

    public function test_accept_url_uses_the_frontend_url_when_configured(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test']);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('tok-123'),
        ))->toMail(null);

        $this->assertSame(
            'https://app.example.test/invitations/tok-123/accept',
            $mail->actionUrl,
        );
    }

    public function test_a_trailing_slash_on_the_frontend_url_does_not_double_up(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test/']);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('tok-123'),
        ))->toMail(null);

        $this->assertSame(
            'https://app.example.test/invitations/tok-123/accept',
            $mail->actionUrl,
        );
    }

    /**
     * The chain the old `config('magic-starter.frontend_url', config('app.frontend_url'))`
     * INTENDED and never reached: its default argument only fires for a MISSING key,
     * and the package key is present-and-empty. So `app.frontend_url` was dead
     * configuration until now.
     */
    public function test_an_empty_package_key_falls_back_to_the_app_key(): void
    {
        config([
            'magic-starter.frontend_url' => '',
            'app.frontend_url' => 'https://fallback.example.test',
        ]);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('tok-123'),
        ))->toMail(null);

        $this->assertSame(
            'https://fallback.example.test/invitations/tok-123/accept',
            $mail->actionUrl,
        );
    }

    /**
     * THE REGRESSION. With NO frontend URL anywhere, the link must still be absolute.
     */
    public function test_no_frontend_url_anywhere_still_yields_an_absolute_url(): void
    {
        config(['magic-starter.frontend_url' => '', 'app.frontend_url' => null]);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('tok-123'),
        ))->toMail(null);

        $this->assertSame(
            'https://api.example.test/invitations/tok-123/accept',
            $mail->actionUrl,
        );
    }

    public function test_a_missing_frontend_url_still_yields_an_absolute_url(): void
    {
        config(['magic-starter.frontend_url' => null, 'app.frontend_url' => null]);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('tok-123'),
        ))->toMail(null);

        $this->assertStringStartsWith('https://api.example.test/', $mail->actionUrl);
    }

    /**
     * A whitespace-only value is the same mistake as an empty one, and an operator
     * who leaves `MAGIC_STARTER_FRONTEND_URL= ` in an env file has made it.
     */
    public function test_a_whitespace_only_frontend_url_is_treated_as_absent(): void
    {
        config(['magic-starter.frontend_url' => '   ', 'app.frontend_url' => '  ']);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('tok-123'),
        ))->toMail(null);

        $this->assertStringStartsWith('https://api.example.test/', $mail->actionUrl);
    }

    /**
     * The third shape of "no base", and the sharpest of the three: `rtrim(trim('/'),
     * '/')` is the EMPTY STRING rather than null, so a slash-only value used to pass
     * straight through the `??` guard (which fires on null, not on `''`) and rebuild
     * the relative accept link this test class exists to prevent.
     */
    public function test_a_slash_only_frontend_url_is_treated_as_absent(): void
    {
        config(['magic-starter.frontend_url' => '/', 'app.frontend_url' => null]);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('tok-123'),
        ))->toMail(null);

        $this->assertSame(
            'https://api.example.test/invitations/tok-123/accept',
            $mail->actionUrl,
        );
    }

    public function test_the_token_is_url_encoded(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test']);

        $mail = (new TeamInvitationNotification(
            new TeamInvitationNotificationTestInvitation('a b/c'),
        ))->toMail(null);

        $this->assertSame(
            'https://app.example.test/invitations/a+b%2Fc/accept',
            $mail->actionUrl,
        );
    }
}

/**
 * The smallest thing the notification reads: a `token` attribute and a `team`
 * relation carrying a name.
 *
 * An Eloquent model because the constructor types the parameter as one, but never
 * persisted: attributes are filled in memory and the `team` relation is set
 * directly, so the whole suite stays off the database.
 */
final class TeamInvitationNotificationTestInvitation extends Model
{
    protected $guarded = [];

    public function __construct(string $token = 'tok-123')
    {
        parent::__construct(['token' => $token]);

        $team = new TeamInvitationNotificationTestTeam(['name' => 'Acme Ops']);
        $this->setRelation('team', $team);
    }
}

final class TeamInvitationNotificationTestTeam extends Model
{
    protected $guarded = [];
}
