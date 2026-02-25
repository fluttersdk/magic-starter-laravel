<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * Notification sent to a user when they are invited to a team.
 */
class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  TeamInvitation  $invitation  The team invitation instance.
     * @return void
     */
    public function __construct(
        public readonly TeamInvitation $invitation,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $acceptUrl = rtrim((string) config('magic-starter.frontend_url'), '/')
            . '/team-invitations/accept?token='
            . urlencode($this->invitation->token);

        return (new MailMessage)
            ->subject(Lang::get('Team Invitation'))
            ->greeting(Lang::get('Hello!'))
            ->line(Lang::get('You have been invited to join the :team team!', ['team' => $this->invitation->team->name]))
            ->line(Lang::get('If you do not have an account, you may create one by clicking the button below. After creating an account, you may click the invitation acceptance button in this email to accept the invitation:'))
            ->action(Lang::get('Accept Invitation'), $acceptUrl)
            ->line(Lang::get('If you did not expect to receive an invitation to this team, you may discard this email.'));
    }
}
