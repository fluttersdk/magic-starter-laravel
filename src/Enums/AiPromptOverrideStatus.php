<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * How a stored prompt override stands against the code registration right now.
 *
 * Derived on every read, never stored, so the labels and colours may be reworded
 * but a value never changes once shipped: the panel's badge keys and translations
 * follow it.
 */
enum AiPromptOverrideStatus: string
{
    /** Written against the live code default and keeps every required phrase: the resolver applies it. */
    case CURRENT = 'current';

    /** The code default changed after the edit, so the resolver ignores it until someone re-saves it. */
    case STALE = 'stale';

    /** Matches the code default but lacks a phrase the registration requires, so the resolver ignores it. */
    case INCOMPLETE = 'incomplete';

    /** The host no longer registers the key, so nothing can apply it. */
    case ORPHANED = 'orphaned';

    /**
     * The status in the staff user's language, for a badge.
     */
    public function label(): string
    {
        return (string) __("magic-starter::admin_ai.prompts.statuses.{$this->value}");
    }

    /**
     * Badge colour: what the resolver applies is green, what needs a person is amber.
     */
    public function color(): string
    {
        return match ($this) {
            self::CURRENT => 'success',
            self::STALE, self::INCOMPLETE => 'warning',
            self::ORPHANED => 'gray',
        };
    }

    /**
     * Whether saving over it needs the operator's tick that the diff against the code default was reviewed.
     */
    public function needsReview(): bool
    {
        return $this === self::STALE || $this === self::INCOMPLETE;
    }
}
