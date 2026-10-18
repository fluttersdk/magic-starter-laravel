<?php

namespace FlutterSdk\MagicStarter\Notifications;

use Carbon\CarbonImmutable;
use DateTimeZone;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\BillingRecipient;
use FlutterSdk\MagicStarter\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person who manages billing, three days ahead, that a trial is about to end and what happens then.
 *
 * Addressed to the {@see BillingRecipient} and gated by `magic-starter.billing.trial_ending_notification`. It
 * states the fact, the date, the consequence (the saved card is charged, or the subscription is already cancelled
 * and nothing is) and one link to the billing screen. It carries no price and no pitch.
 *
 * The date is rendered in the recipient's own `timezone` attribute when it names a real zone, else in the app
 * timezone, and in the mail locale: a UTC instant late in the evening is already the next day for a recipient east
 * of Greenwich. Queued, so a mail transport failure never fails the webhook that raised it.
 */
class TrialEndingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $tier  The tier id of the trialing plan; a tier with no definition is named by its id.
     * @param  bool  $cancelling  Whether the subscription is set to cancel, so the end of the trial charges nothing.
     */
    public function __construct(
        public readonly string $tier,
        public readonly CarbonImmutable $endsAt,
        public readonly bool $cancelling,
    ) {}

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
            ->subject((string) __('magic-starter::billing.trial_ending.subject', ['plan' => $plan]))
            ->line((string) __('magic-starter::billing.trial_ending.ends_on', [
                'plan' => $plan,
                'date' => $this->date($notifiable),
            ]))
            ->line((string) __($this->cancelling
                ? 'magic-starter::billing.trial_ending.cancelled'
                : 'magic-starter::billing.trial_ending.charge'))
            ->action((string) __('magic-starter::billing.trial_ending.action'), $this->billingUrl());
    }

    /**
     * The tier's display name, translated; the bare id when the tier has no definition.
     */
    private function planName(): string
    {
        $name = BillingCatalogue::tiers()[$this->tier]['name'] ?? null;

        return __(is_string($name) && $name !== '' ? $name : $this->tier);
    }

    /**
     * The end date in the recipient's timezone and the mail locale.
     *
     * The `timezone` attribute is trusted only when PHP knows the zone, because it is free text a person typed.
     */
    private function date(mixed $notifiable): string
    {
        $timezone = data_get($notifiable, 'timezone');

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = (string) config('app.timezone');
        }

        return $this->endsAt->setTimezone($timezone)->locale(app()->getLocale())->isoFormat('LL');
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
