<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Notifications\TrialRefusedNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\URL;

/**
 * Locks the call to action the trial-refused mail carries.
 *
 * The mail used to end on "subscribe from the billing screen" with nothing to
 * press, sent to a person who had already left the app. The button has to land
 * on that screen, and an absolute URL is the only kind a mail client can open.
 */
final class TrialRefusedNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://api.example.test']);
        // Both, deliberately: forceRootUrl sets the host and forceScheme the scheme,
        // and without the second one url('/') answers http on a test app.
        URL::forceRootUrl('https://api.example.test');
        URL::forceScheme('https');
    }

    public function test_the_action_points_at_the_billing_screen_on_the_frontend(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test']);

        $mail = (new TrialRefusedNotification)->toMail(null);

        $this->assertSame('https://app.example.test/teams/billing', $mail->actionUrl);
    }

    /**
     * Consumer configs replace whole top-level arrays, so a published `billing`
     * array written before `client_path` existed has no such key.
     */
    public function test_a_billing_array_without_client_path_still_yields_the_default_path(): void
    {
        config([
            'magic-starter.frontend_url' => 'https://app.example.test',
            'magic-starter.billing' => ['billable' => 'user'],
        ]);

        $mail = (new TrialRefusedNotification)->toMail(null);

        $this->assertSame('https://app.example.test/teams/billing', $mail->actionUrl);
    }

    public function test_a_configured_client_path_wins(): void
    {
        config([
            'magic-starter.frontend_url' => 'https://app.example.test',
            'magic-starter.billing.client_path' => '/account/plans',
        ]);

        $mail = (new TrialRefusedNotification)->toMail(null);

        $this->assertSame('https://app.example.test/account/plans', $mail->actionUrl);
    }

    public function test_no_frontend_url_still_yields_an_absolute_url(): void
    {
        config(['magic-starter.frontend_url' => null, 'app.frontend_url' => null]);

        $mail = (new TrialRefusedNotification)->toMail(null);

        $this->assertSame('https://api.example.test/teams/billing', $mail->actionUrl);
    }

    public function test_the_action_text_is_translated_for_a_turkish_recipient(): void
    {
        app()->setLocale('tr');

        $mail = (new TrialRefusedNotification)->toMail(null);

        $this->assertSame('Planları görüntüleyin', $mail->actionText);
    }

    public function test_the_action_text_is_english_by_default(): void
    {
        $mail = (new TrialRefusedNotification)->toMail(null);

        $this->assertSame('View plans', $mail->actionText);
    }
}
