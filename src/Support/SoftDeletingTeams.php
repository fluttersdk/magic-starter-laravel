<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Whether a team model can be trashed.
 *
 * Soft deletes are the consumer's to opt in to: a team model that does not use
 * {@see SoftDeletes} has no `deleted_at` column to hide trashed rows with, and
 * the package's team code has to keep working on it. Every place that touches a
 * trashed team asks here first.
 */
final class SoftDeletingTeams
{
    private function __construct() {}

    /**
     * Whether the given team model, or an instance of it, uses SoftDeletes.
     *
     * @param  Model|class-string<Model>  $team
     */
    public static function usedBy(Model|string $team): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($team), true);
    }
}
