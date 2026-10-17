<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\BillingRecipient;
use FlutterSdk\MagicStarter\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person who manages billing that a renewal payment was declined and what to do about it.
 *
 * Addressed to the {@see BillingRecipient} and gated by `magic-starter.billing.payment_failed_notification`. It
 * states the fact, that Stripe retries on its own, and one link to the billing screen to update the payment method.
 * It carries no amount and no pitch. The copy lives under `payment_failed_mail`, apart from the inline
 * `refusals.payment_failed` sentence an API caller reads, so the two are never confused.
 *
 * Queued, so a mail transport failure never fails the webhook that raised it.
 */
class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $tier  The tier id of the plan being renewed; a tier with no definition is named by its id.
     */
    public function __construct(public readonly string $tier) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable  The person who manages billing.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable  The person who manages billing.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $plan = $this->planName();

        return (new MailMessage)
            ->subject((string) __('magic-starter::billing.payment_failed_mail.subject', ['plan' => $plan]))
            ->line((string) __('magic-starter::billing.payment_failed_mail.declined', ['plan' => $plan]))
            ->line((string) __('magic-starter::billing.payment_failed_mail.retry'))
            ->action((string) __('magic-starter::billing.payment_failed_mail.action'), $this->billingUrl());
    }

    /**
     * The tier's display name, translated; the bare id when the tier has no definition.
     */
    private function planName(): string
    {
        return __((string) (BillingCatalogue::tiers()[$this->tier]['name'] ?? $this->tier));
    }

    /**
     * The absolute URL of the client's billing screen.
     *
     * Absolute because a mail client cannot resolve a relative link, so it falls back to the application's own root
     * exactly as {@see TrialRefusedNotification} does. The path is read with an explicit default because a
     * consumer's published `billing` array replaces the package's whole array and may predate `client_path`.
     */
    private function billingUrl(): string
    {
        return FrontendUrl::to((string) config('magic-starter.billing.client_path', '/teams/billing'));
    }
}
