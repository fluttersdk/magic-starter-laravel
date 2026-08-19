<?php

namespace FlutterSdk\MagicStarter\Notifications;

use FlutterSdk\MagicStarter\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
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
     * @param  Model  $invitation  The team invitation instance.
     * @return void
     */
    public function __construct(
        public readonly Model $invitation,
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
        // @phpstan-ignore-next-line: Magic property access on Eloquent model
        $token = (string) $this->invitation->token;

        // @phpstan-ignore-next-line: Magic relation access on Eloquent model
        $teamName = (string) $this->invitation->team->name;

        $acceptUrl = $this->acceptUrl($token);

        return (new MailMessage)
            ->subject(Lang::get('Team Invitation'))
            ->greeting(Lang::get('Hello!'))
            ->line(Lang::get('You have been invited to join the :team team!', ['team' => $teamName]))
            ->line(Lang::get('If you do not have an account, you may create one by clicking the button below. After creating an account, you may click the invitation acceptance button in this email to accept the invitation:'))
            ->action(Lang::get('Accept Invitation'), $acceptUrl)
            ->line(Lang::get('If you did not expect to receive an invitation to this team, you may discard this email.'));
    }

    /**
     * The absolute URL an invited person follows to accept.
     *
     * **Absolute in every configuration, which it was not.** The old form
     * concatenated `config('magic-starter.frontend_url')` unguarded, so an unset or
     * empty value produced the relative `/invitations/<token>/accept`. A mail client
     * cannot resolve that and a human cannot use it either: the delivered message's
     * own fallback line read "copy and paste the URL below into your web browser:
     * /invitations/.../accept". Since the mail is the ONLY way an invitation is
     * accepted, the invitation was simply unacceptable, silently.
     *
     * A whitespace-only value is the same operator mistake as an empty one
     * (`MAGIC_STARTER_FRONTEND_URL= ` in an env file) and produced a URL with
     * leading spaces, so it is trimmed before the emptiness test rather than after.
     *
     * Falls back to the application's own root, matching
     * {@see VerifyEmailNotification::verificationUrl()}, which already guards the
     * same config value. A link to the API is not where an invited person wants to
     * land, but it is a resolvable address and the operator can see what went wrong;
     * a relative URL gives them neither.
     */
    protected function acceptUrl(string $token): string
    {
        $base = FrontendUrl::baseOrNull() ?? rtrim(url('/'), '/');

        return $base . '/invitations/' . urlencode($token) . '/accept';
    }
}
