<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Notifications\PaymentFailedNotification;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

/**
 * Locks the "your payment was declined" mail: the plan by display name, the fact, what happens next, and one link
 * to update the payment method. No amount and no pitch. "Renewal" is avoided on purpose: the same mail covers the
 * first charge after a trial.
 */
final class PaymentFailedNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.test',
            'magic-starter.frontend_url' => 'https://app.example.test',
            'magic-starter.billing.tier_order' => ['free', 'pro'],
            'magic-starter.billing.tiers' => [
                'free' => ['name' => 'Free'],
                'pro' => ['name' => 'Pro'],
            ],
        ]);
        URL::forceRootUrl('https://api.example.test');
        URL::forceScheme('https');
    }

    public function test_it_is_a_queued_mail_only_notification(): void
    {
        $notification = new PaymentFailedNotification('pro');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['mail'], $notification->via(new ConcreteUser));
    }

    public function test_the_mail_names_the_plan_and_says_what_happens_next(): void
    {
        $mail = (new PaymentFailedNotification('pro'))->toMail(new ConcreteUser);

        $this->assertSame('Your Pro payment was declined', $mail->subject);
        $this->assertSame([
            'The payment for your Pro plan was declined.',
            'Stripe will retry automatically. Update your payment method to keep the plan.',
        ], $mail->introLines);
        $this->assertSame('Update payment method', $mail->actionText);
    }

    public function test_an_unknown_tier_falls_back_to_its_id(): void
    {
        $mail = (new PaymentFailedNotification('enterprise'))->toMail(new ConcreteUser);

        $this->assertSame('Your enterprise payment was declined', $mail->subject);
    }

    public function test_a_tier_with_an_empty_name_falls_back_to_its_id(): void
    {
        config(['magic-starter.billing.tiers.pro' => ['name' => '']]);

        $mail = (new PaymentFailedNotification('pro'))->toMail(new ConcreteUser);

        $this->assertSame('Your pro payment was declined', $mail->subject);
    }

    public function test_every_line_is_turkish_for_a_turkish_recipient(): void
    {
        app()->setLocale('tr');

        $mail = (new PaymentFailedNotification('pro'))->toMail(new ConcreteUser);

        $this->assertSame('Pro ödemeniz reddedildi', $mail->subject);
        $this->assertSame([
            'Pro planınız için yapılan ödeme reddedildi.',
            'Stripe ödemeyi otomatik olarak yeniden deneyecek. '
                . 'Planınızı korumak için ödeme yönteminizi güncelleyin.',
        ], $mail->introLines);
        $this->assertSame('Ödeme yöntemini güncelleyin', $mail->actionText);
    }

    public function test_the_action_points_at_the_billing_screen_on_the_frontend(): void
    {
        $mail = (new PaymentFailedNotification('pro'))->toMail(new ConcreteUser);

        $this->assertSame('https://app.example.test/teams/billing', $mail->actionUrl);
    }
}
