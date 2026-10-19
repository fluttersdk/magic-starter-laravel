<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Notifications\VerifyEmailNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Http\Request;
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

    public function test_the_mail_reads_in_english_under_en(): void
    {
        app()->setLocale('en');

        $mail = (new VerifyEmailNotification)->toMail(new VerifyEmailNotificationTestUser);

        $this->assertSame('Verify Email Address', $mail->subject);
        $this->assertSame(['Please click the button below to verify your email address.'], $mail->introLines);
        $this->assertSame('Verify Email', $mail->actionText);
    }

    public function test_the_mail_reads_in_turkish_under_tr(): void
    {
        app()->setLocale('tr');

        $mail = (new VerifyEmailNotification)->toMail(new VerifyEmailNotificationTestUser);

        $this->assertSame('E-posta Adresinizi Doğrulayın', $mail->subject);
        $this->assertSame(
            ['E-posta adresinizi doğrulamak için lütfen aşağıdaki düğmeye tıklayın.'],
            $mail->introLines,
        );
        $this->assertSame('E-postanızı doğrulayın', $mail->actionText);
    }

    /**
     * The link used to swap the API host for the client's and keep the API path,
     * so a customer landed on `<client>/api/v1/email/verify/...`, a path no client
     * route serves, and was never verified. The client page owns `/auth/verify-email`.
     */
    public function test_verification_url_opens_the_client_verify_page(): void
    {
        $mail = (new VerifyEmailNotification)->toMail(
            new VerifyEmailNotificationTestUser(id: 'user-1', email: 'test@example.com'),
        );

        $this->assertStringStartsWith('myapp://verify/auth/verify-email?', $mail->actionUrl);

        parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);

        $this->assertSame('user-1', $query['id']);
        $this->assertSame(sha1('test@example.com'), $query['hash']);
    }

    /**
     * The client replays the link's parameters against the API route, so they
     * have to rebuild a request the `signed` middleware accepts.
     */
    public function test_the_client_link_carries_a_signature_the_api_accepts(): void
    {
        $mail = (new VerifyEmailNotification)->toMail(
            new VerifyEmailNotificationTestUser(id: 'user-1', email: 'test@example.com'),
        );

        parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);

        // Replayed on the API host, whatever host sent the mail.
        $replayed = Request::create(
            "https://api.other-host.test/email/verify/{$query['id']}/{$query['hash']}?"
            . http_build_query([
                'expires' => $query['expires'],
                'signature' => $query['signature'],
            ]),
        );

        $this->assertTrue(URL::hasValidSignature($replayed, absolute: false));
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
