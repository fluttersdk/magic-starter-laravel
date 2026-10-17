<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Notifications\NewsletterSubscribedNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * The confirmation mail a person gets after subscribing to the newsletter.
 *
 * The unsubscribe link has to work from the mail client's own button too, so the
 * headers are asserted on a message that went through the array mailer, not only
 * on the MailMessage object that describes it.
 */
final class NewsletterSubscribedNotificationTest extends TestCase
{
    private const TOKEN = 'tok-0123456789abcdef';

    private const UNSUBSCRIBE_URL = 'https://api.example.test/newsletter/unsubscribe/' . self::TOKEN;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);
        URL::forceRootUrl('https://api.example.test');
        URL::forceScheme('https');

        // The real route is registered by the newsletter routes file; the notification only needs the name to resolve.
        Route::get('newsletter/unsubscribe/{token}', fn (): string => 'ok')
            ->name('magic-starter.newsletter.unsubscribe');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_it_is_delivered_by_mail_only(): void
    {
        $notification = new NewsletterSubscribedNotification($this->subscriber());

        $this->assertSame(['mail'], $notification->via(null));
    }

    public function test_the_mail_links_to_the_unsubscribe_page_with_the_token(): void
    {
        $mail = (new NewsletterSubscribedNotification($this->subscriber()))->toMail(null);

        $this->assertSame(self::UNSUBSCRIBE_URL, $mail->actionUrl);
        $this->assertStringContainsString(self::UNSUBSCRIBE_URL, (string) $mail->render());
    }

    public function test_the_sent_message_carries_the_one_click_unsubscribe_headers(): void
    {
        Notification::route('mail', 'reader@example.test')
            ->notifyNow(new NewsletterSubscribedNotification($this->subscriber()));

        $headers = $this->sentEmail()->getHeaders();

        $this->assertSame('<' . self::UNSUBSCRIBE_URL . '>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());
    }

    public function test_the_mail_reads_in_english_under_en(): void
    {
        Notification::route('mail', 'reader@example.test')
            ->notifyNow((new NewsletterSubscribedNotification($this->subscriber()))->locale('en'));

        $email = $this->sentEmail();

        $this->assertSame('You subscribed to our newsletter', $email->getSubject());
        $this->assertStringContainsString('You consented on March 4, 2026.', (string) $email->getHtmlBody());
    }

    public function test_the_mail_reads_in_turkish_under_tr(): void
    {
        Notification::route('mail', 'reader@example.test')
            ->notifyNow((new NewsletterSubscribedNotification($this->subscriber()))->locale('tr'));

        $email = $this->sentEmail();

        $this->assertSame('Bültene abone oldunuz', $email->getSubject());
        $this->assertStringContainsString('4 Mart 2026 tarihinde onay verdiniz.', (string) $email->getHtmlBody());
    }

    public function test_the_consent_line_falls_back_to_the_creation_date(): void
    {
        $subscriber = $this->subscriber();
        $subscriber->forceFill([
            'consented_at' => null,
            'created_at' => Carbon::parse('2025-12-24 08:00:00'),
        ]);

        Notification::route('mail', 'reader@example.test')
            ->notifyNow((new NewsletterSubscribedNotification($subscriber))->locale('en'));

        $this->assertStringContainsString(
            'You consented on December 24, 2025.',
            (string) $this->sentEmail()->getHtmlBody(),
        );
    }

    private function subscriber(): NewsletterSubscriber
    {
        // Never persisted: the notification reads two attributes and the table is another step's business.
        return (new NewsletterSubscriber)->forceFill([
            'email' => 'reader@example.test',
            'consented_at' => Carbon::parse('2026-03-04 10:30:00'),
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'unsubscribe_token' => self::TOKEN,
        ]);
    }

    private function sentEmail(): Email
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $sent = $transport->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $sent);

        $email = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
