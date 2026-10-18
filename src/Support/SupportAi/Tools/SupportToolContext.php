<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Database\Eloquent\Model;

/**
 * What a support tool may know about the turn it serves: the conversation, and
 * the team it may read for.
 *
 * Built per turn and never cached across one. The conversation is the instance
 * the turn started with, so a `user_id` or `team_id` reassigned mid-turn is not
 * seen; the team, however, is decided again on every {@see self::team()} call,
 * so a member removed mid-turn loses access at the very next tool call.
 */
final class SupportToolContext
{
    private function __construct(
        private ?SupportConversation $conversation,
    ) {}

    public static function forConversation(SupportConversation $conversation): self
    {
        return new self($conversation);
    }

    /**
     * A context with no conversation, for an SDK that asks for its tools
     * outside a pinned turn: every tool sees no team and refuses.
     */
    public static function none(): self
    {
        return new self(null);
    }

    /**
     * The team the tools may read for, or null.
     *
     * The conversation's `team_id` is honoured only while its opener still owns
     * or belongs to that team, with both re-read at this call: a member removed
     * after writing in, a visitor without an account and a teamless or deleted
     * team all get null. Membership compares two fresh models, never the raw
     * `team_id` attribute, whose type depends on the driver.
     */
    public function team(): ?Model
    {
        $userId = $this->conversation?->user_id;
        $teamId = $this->conversation?->team_id;

        if ($userId === null || $teamId === null) {
            return null;
        }

        $opener = MagicStarter::userModel()::query()->find($userId);
        $team = MagicStarter::teamModel()::query()->find($teamId);

        if ($opener === null || $team === null || ! $opener->belongsToTeam($team)) {
            return null;
        }

        return $team;
    }

    /**
     * The key of {@see self::team()}, never the conversation's own `team_id`.
     */
    public function teamKey(): int|string|null
    {
        return $this->team()?->getKey();
    }

    public function conversation(): ?SupportConversation
    {
        return $this->conversation;
    }
}
