<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;

final class VerifyEmailNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.test',
            'magic-starter.frontend_url' => 'myapp://verify',
        ]);

        $this->app->make(Router::class)->get('/email/verify/{id}/{hash}', static function (): string {
            return 'ok';
        })->name('verification.verify');

        URL::forceRootUrl('https://api.example.test');
    }

    /**
     * The worst landing site for a slash-only value, because this call site's guard
     * is an explicit `=== null` rather than a `??`.
     *
     * `rtrim(trim('/'), '/')` is the EMPTY STRING, so the guard missed it and
     * `str_replace(url('/'), '', $signedUrl)` stripped the host off a URL that was
     * ALREADY absolute, handing the customer `/email/verify/...`. The other two
     * builders only failed to add a host; this one removed one.
     */
    public function test_a_slash_only_frontend_url_leaves_the_signed_url_absolute(): void
    {
        config(['magic-starter.frontend_url' => '/', 'app.frontend_url' => null]);
        URL::forceScheme('https');

        $mail = (new VerifyEmailNotification)->toMail(
            new VerifyEmailNotificationTestUser(id: 'user-1', email: 'test@example.com'),
        );

        $this->assertStringStartsWith('https://api.example.test/', $mail->actionUrl);
    }

    public function test_notification_is_sent_via_mail(): void
    {
        $notification = new VerifyEmailNotification;
        $notifiable = new VerifyEmailNotificationTestUser;

        $this->assertSame([
            'mail',
        ], $notification->via($notifiable));
    }

    public function test_notification_mail_contains_verify_action(): void
    {
        $notification = new VerifyEmailNotification;

        $notifiable = new VerifyEmailNotificationTestUser(
            id: 'user-1',
            email: 'test@example.com',
        );

        $mailMessage = $notification->toMail($notifiable);

        $this->assertInstanceOf(MailMessage::class, $mailMessage);
        $this->assertSame('Verify Email', $mailMessage->actionText);
    }

    public function test_verification_url_uses_frontend_url(): void
    {
        $notification = new VerifyEmailNotification;

        $notifiable = new VerifyEmailNotificationTestUser(
            id: 'user-1',
            email: 'test@example.com',
        );

        $mailMessage = $notification->toMail($notifiable);

        $this->assertStringStartsWith('myapp://verify/', $mailMessage->actionUrl);
    }
}

final class VerifyEmailNotificationTestUser
{
    public function __construct(
        private readonly string $id = 'user-1',
        private readonly string $email = 'test@example.com',
    ) {}

    public function getKey(): string
    {
        return $this->id;
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }
}
