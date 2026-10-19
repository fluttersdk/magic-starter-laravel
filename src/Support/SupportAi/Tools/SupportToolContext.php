<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Database\Eloquent\Model;

/**
 * What a support tool may know about the turn it serves: the conversation, the
 * team it may read for, and the audience whose knowledge base it may read.
 *
 * Built per turn and never cached across one. The conversation is the instance
 * the turn started with, so a `user_id` or `team_id` reassigned mid-turn is not
 * seen; the team and the opener, however, are decided again on every
 * {@see self::team()} and {@see self::opener()} call, so a member removed
 * mid-turn loses access at the very next tool call. The audience is fixed by
 * whoever built the context and never by a tool argument.
 */
final class SupportToolContext
{
    private function __construct(
        private ?SupportConversation $conversation,
        private SupportAudience $audience,
    ) {}

    /**
     * A context for the turn, reading the knowledge base as the customer would
     * unless the caller names another audience.
     */
    public static function forConversation(
        SupportConversation $conversation,
        SupportAudience $audience = SupportAudience::CUSTOMER,
    ): self {
        return new self($conversation, $audience);
    }

    /**
     * A context for a staff member working the conversation, who may read
     * staff-only articles.
     */
    public static function forStaff(SupportConversation $conversation): self
    {
        return self::forConversation($conversation, SupportAudience::STAFF);
    }

    /**
     * A context with no conversation, for an SDK that asks for its tools
     * outside a pinned turn: every tool sees no team and refuses, and the
     * knowledge base reads as the public page, the narrowest audience.
     */
    public static function none(): self
    {
        return new self(null, SupportAudience::PAGE);
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
        return $this->member()[1] ?? null;
    }

    /**
     * The key of {@see self::team()}, never the conversation's own `team_id`.
     */
    public function teamKey(): int|string|null
    {
        return $this->team()?->getKey();
    }

    /**
     * The user who opened the conversation, re-read at this call, or null.
     *
     * The same rule as {@see self::team()}: the opener is answered only while
     * they still own or belong to the conversation's team, so a removed member
     * is nobody to the tools, mid-turn included.
     */
    public function opener(): ?Model
    {
        return $this->member()[0] ?? null;
    }

    /**
     * Whose knowledge base the tools may read: the visibilities an article
     * needs for a tool to return it.
     */
    public function audience(): SupportAudience
    {
        return $this->audience;
    }

    /**
     * The conversation the turn started with. Its `team_id` and its `team`
     * relation skip the membership check: read the team through
     * {@see self::team()} instead.
     */
    public function conversation(): ?SupportConversation
    {
        return $this->conversation;
    }

    /**
     * The opener and the team, both read fresh, or null unless the opener still
     * owns or belongs to the team.
     *
     * @return array{0: Model, 1: Model}|null
     */
    private function member(): ?array
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

        return [
            $opener,
            $team,
        ];
    }
}
