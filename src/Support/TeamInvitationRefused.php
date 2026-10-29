<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Actions\AcceptTeamInvitation;
use FlutterSdk\MagicStarter\Contracts\AcceptsTeamInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * An invitation that cannot be accepted, whatever the person does next.
 *
 * Thrown by {@see AcceptTeamInvitation} for the two ways an invitation stops
 * being usable: it expired, or its person already belongs to the team. A
 * {@see ValidationException} like every other refusal of {@see AcceptsTeamInvitations}, so
 * a caller that handles those handles this, and an uncaught one answers 422
 * with `{message, code, errors}`. The invitation endpoint catches it to keep
 * its own answers (410 and 200), and `refusal()` is the stable code it reads.
 * The message is the translated `magic-starter::teams.invitations.<code>`
 * sentence, addressed to the invited person.
 */
class TeamInvitationRefused extends ValidationException
{
    /**
     * The invitation's `expires_at` has passed.
     */
    public const EXPIRED = 'expired';

    /**
     * The person already belongs to the team, as a member or as its owner.
     */
    public const ALREADY_JOINED = 'already_joined';

    private string $refusal = '';

    /**
     * Build the refusal for one of this class's codes.
     *
     * @param  string  $refusal  a key of `lang/<locale>/teams.php` `invitations`, one of the constants above
     */
    public static function because(string $refusal): static
    {
        $message = (string) __('magic-starter::teams.invitations.' . $refusal);

        $exception = static::withMessages([
            'invitation' => $message,
        ]);

        $exception->refusal = $refusal;
        $exception->response = new JsonResponse([
            'message' => $message,
            'code' => $refusal,
            'errors' => $exception->errors(),
        ], 422);

        return $exception;
    }

    /**
     * The stable snake_case code a caller switches on.
     */
    public function refusal(): string
    {
        return $this->refusal;
    }
}
