<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Contracts\ChangesSupportConversations;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportStaffRecipients;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Changes a conversation's status, priority or assignee.
 *
 * Only those three keys are read; anything else in `$changes` is dropped by
 * the validation, so a change can never move a thread onto another account.
 * `pending` is the AI desk's holding state and is never one staff can set.
 * The assignee has to be a support recipient. Resolving stamps `resolved_at`
 * and revokes the public link, so a resolved thread cannot be written to from
 * an old mail; leaving `resolved` clears the stamp.
 */
class ChangeSupportConversation implements ChangesSupportConversations
{
    public function __construct(
        private readonly SupportStaffRecipients $recipients,
    ) {}

    public function change(Authenticatable $staff, SupportConversation $c, array $changes): SupportConversation
    {
        // 1. Keep only the changeable keys, each in its allowed shape.
        $validated = Validator::make($changes, [
            'status' => [
                'sometimes',
                Rule::enum(SupportConversationStatus::class)->except(SupportConversationStatus::PENDING),
            ],
            'priority' => [
                'sometimes',
                Rule::enum(SupportPriority::class),
            ],
            'assignee_id' => [
                'sometimes',
                'nullable',
            ],
        ])->validate();

        // 2. Refuse an assignee who would never be told about the conversation.
        if (($validated['assignee_id'] ?? null) !== null && ! $this->isStaff($validated['assignee_id'])) {
            throw ValidationException::withMessages([
                'assignee_id' => Lang::get('magic-starter::admin_support.refusals.assignee_not_staff'),
            ]);
        }

        // 3. Nothing to write is not a change.
        $c->fill($validated);

        if (! $c->isDirty()) {
            return $c;
        }

        $resolving = $c->isDirty('status') && $c->status === SupportConversationStatus::RESOLVED;

        if ($c->isDirty('status')) {
            $c->resolved_at = $resolving ? now() : null;
        }

        $new = $c->getDirty();
        $old = array_intersect_key($c->getRawOriginal(), $new);

        // 4. Persist, and close the public link with the resolution.
        DB::transaction(function () use ($c, $resolving): void {
            $c->save();

            if ($resolving && $c->thread_token_hash !== null) {
                SupportThreadToken::revoke($c);
            }
        });

        Auditor::record(
            'support.conversation_changed',
            $c,
            [
                'old' => $old,
                'new' => $new,
            ],
            $staff,
        );

        // 5. Nudge the opener; a realtime outage must never fail the stored change.
        if ($c->user_id !== null) {
            rescue(static fn () => event(new SupportConversationUpdated($c)), report: true);
        }

        return $c;
    }

    private function isStaff(mixed $userId): bool
    {
        /** @var class-string<Model> $userModel */
        $userModel = MagicStarter::userModel();
        $user = $userModel::query()->find($userId);

        return $user instanceof Model && $this->recipients->includes($user);
    }
}
