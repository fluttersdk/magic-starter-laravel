<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Notifications\TrialEndingNotification;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

/**
 * Locks the "your trial ends soon" mail: it names the plan by its display name, gives the end date in the
 * recipient's own timezone and language, says plainly whether the card will be charged, and carries one billing
 * link. No price and no pitch: it states a fact and its consequence.
 */
final class TrialEndingNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.test',
            'app.timezone' => 'UTC',
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
        $notification = $this->notification();

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['mail'], $notification->via($this->recipient()));
    }

    public function test_the_subject_and_charge_line_name_the_plan_and_the_date(): void
    {
        $mail = $this->notification(cancelling: false)->toMail($this->recipient());

        $this->assertSame('Your Pro trial ends soon', $mail->subject);
        $this->assertSame([
            'Your Pro trial ends on October 21, 2026.',
            'Your saved card will be charged then and the plan continues.',
        ], $mail->introLines);
    }

    public function test_a_cancelling_trial_says_nothing_will_be_charged(): void
    {
        $mail = $this->notification(cancelling: true)->toMail($this->recipient());

        $this->assertSame([
            'Your Pro trial ends on October 21, 2026.',
            'The subscription is cancelled, so nothing will be charged and your account returns to the free plan.',
        ], $mail->introLines);
    }

    public function test_an_unknown_tier_falls_back_to_its_id(): void
    {
        $mail = $this->notification(tier: 'enterprise')->toMail($this->recipient());

        $this->assertSame('Your enterprise trial ends soon', $mail->subject);
    }

    public function test_a_translated_tier_name_is_used_in_turkish(): void
    {
        app()->setLocale('tr');
        app('translator')->addLines(['*.Professional' => 'Profesyonel'], 'tr');
        config(['magic-starter.billing.tiers.pro.name' => 'Professional']);

        $mail = $this->notification(cancelling: false)->toMail($this->recipient());

        $this->assertStringContainsString('Profesyonel', (string) $mail->subject);
        $this->assertStringContainsString('Profesyonel', $mail->introLines[0]);
    }

    public function test_every_line_is_turkish_for_a_turkish_recipient(): void
    {
        app()->setLocale('tr');

        $charge = $this->notification(cancelling: false)->toMail($this->recipient());
        $cancelled = $this->notification(cancelling: true)->toMail($this->recipient());

        $this->assertSame('Pro deneme süreniz yakında bitiyor', $charge->subject);
        $this->assertSame('Pro deneme süreniz 21 Ekim 2026 tarihinde sona eriyor.', $charge->introLines[0]);
        $this->assertSame(
            'Kayıtlı kartınızdan o tarihte ödeme alınacak ve plan devam edecek.',
            $charge->introLines[1],
        );
        $this->assertSame(
            'Abonelik iptal edildiği için ücret alınmayacak ve hesabınız ücretsiz plana dönecek.',
            $cancelled->introLines[1],
        );
        $this->assertSame('Planı görüntüleyin', $charge->actionText);
    }

    /**
     * 22:30 UTC on the 20th is already the 21st in Istanbul (UTC+3).
     */
    public function test_the_date_is_rendered_in_the_recipients_timezone(): void
    {
        $endsAt = CarbonImmutable::parse('2026-10-20 22:30:00', 'UTC');

        $istanbul = $this->notification(endsAt: $endsAt)->toMail($this->recipient('Europe/Istanbul'));
        $utc = $this->notification(endsAt: $endsAt)->toMail($this->recipient('UTC'));

        $this->assertSame('Your Pro trial ends on October 21, 2026.', $istanbul->introLines[0]);
        $this->assertSame('Your Pro trial ends on October 20, 2026.', $utc->introLines[0]);
    }

    public function test_an_invalid_timezone_falls_back_to_the_app_timezone(): void
    {
        config(['app.timezone' => 'Europe/Istanbul']);
        $endsAt = CarbonImmutable::parse('2026-10-20 22:30:00', 'UTC');

        $mail = $this->notification(endsAt: $endsAt)->toMail($this->recipient('Not/AZone'));

        $this->assertSame('Your Pro trial ends on October 21, 2026.', $mail->introLines[0]);
    }

    public function test_a_recipient_without_a_timezone_falls_back_to_the_app_timezone(): void
    {
        config(['app.timezone' => 'Europe/Istanbul']);
        $endsAt = CarbonImmutable::parse('2026-10-20 22:30:00', 'UTC');

        $mail = $this->notification(endsAt: $endsAt)->toMail($this->recipient());

        $this->assertSame('Your Pro trial ends on October 21, 2026.', $mail->introLines[0]);
    }

    public function test_the_action_points_at_the_billing_screen_on_the_frontend(): void
    {
        $mail = $this->notification()->toMail($this->recipient());

        $this->assertSame('View your plan', $mail->actionText);
        $this->assertSame('https://app.example.test/teams/billing', $mail->actionUrl);
    }

    private function notification(
        string $tier = 'pro',
        ?CarbonImmutable $endsAt = null,
        bool $cancelling = false,
    ): TrialEndingNotification {
        return new TrialEndingNotification(
            tier: $tier,
            endsAt: $endsAt ?? CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'),
            cancelling: $cancelling,
        );
    }

    private function recipient(?string $timezone = null): ConcreteUser
    {
        return new ConcreteUser(['timezone' => $timezone]);
    }
}
