<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Jobs\CheckTrialCard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a person that the trial their checkout opened was refused because the
 * card on file had already taken one, and that nothing was charged.
 *
 * Sent by {@see CheckTrialCard} for a `card_reused` refusal only. A
 * `duplicate` refusal is the same person (or the same subject) opening a second
 * trial beside one that is still running, so the trial they meant to have is
 * the surviving one and a mail saying "no trial was opened" would be wrong. An
 * adopter switches it off with `magic-starter.billing.trial_refused_notification`.
 *
 * Mail only: the refusal happens minutes after a checkout, usually after the
 * person has left the app, and a push or an in-app row would need channels an
 * adopter may not run.
 *
 * Queued, so a mail transport failure fails this notification and not the card
 * check that already cancelled the subscription. The lines are translated when
 * the mail is rendered, and Laravel's notification sender renders it in the
 * notifiable's `HasLocalePreference` locale when it declares one
 * (`Illuminate\Notifications\NotificationSender::withLocale`), the same
 * contract the package's `HasNotifications` trait honours for its labels.
 */
class TrialRefusedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable  The person whose trial was refused.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable  The person whose trial was refused.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('magic-starter::billing.trial_refused.subject'))
            ->line((string) __('magic-starter::billing.trial_refused.card_used'))
            ->line((string) __('magic-starter::billing.trial_refused.nothing_charged'))
            ->line((string) __('magic-starter::billing.trial_refused.subscribe'));
    }
}
