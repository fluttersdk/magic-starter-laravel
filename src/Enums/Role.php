<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Team member roles used throughout the package.
 */
enum Role: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case EDITOR = 'editor';
    case MEMBER = 'member';

    /**
     * Get the roles that can be assigned to team members.
     *
     * Owner is excluded because it is determined by team ownership,
     * not by assignment.
     *
     * @return array<int, string>
     */
    public static function assignable(): array
    {
        return [
            self::ADMIN->value,
            self::EDITOR->value,
            self::MEMBER->value,
        ];
    }

    /**
     * Get a comma-separated string of assignable roles for validation rules.
     */
    public static function assignableForValidation(): string
    {
        return implode(',', self::assignable());
    }
}
