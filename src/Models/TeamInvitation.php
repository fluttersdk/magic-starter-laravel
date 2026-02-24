<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $team_id
 * @property string $email
 * @property string $role
 * @property string $token
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class TeamInvitation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'email',
        'role',
        'token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the team that the invitation belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\FlutterSdk\MagicStarter\Models\Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::teamModel());
    }

    /**
     * Determine whether the invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Scope to only valid (non-expired) invitations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
