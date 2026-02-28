<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

trait HasTeams
{
    /**
     * Get all of the teams the user owns.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(MagicStarter::teamModel(), 'user_id');
    }

    /**
     * Get all of the teams the user belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(MagicStarter::teamModel(), 'team_user', 'user_id', 'team_id')
            ->using(MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the user's personal team.
     */
    public function personalTeam(): ?Model
    {
        return $this->ownedTeams->where('personal_team', true)->first();
    }

    /**
     * Get the current team of the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::teamModel(), 'current_team_id');
    }

    /**
     * Get all of the teams the user owns or belongs to.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function allTeams(): Collection
    {
        return $this->ownedTeams->merge($this->teams)->sortBy('name');
    }

    /**
     * Get the current team or fall back to the personal team.
     */
    public function getCurrentTeamOrPersonal(): ?Model
    {
        return $this->currentTeam ?? $this->personalTeam();
    }

    /**
     * Determine if the user belongs to the given team.
     *
     * Checks both owned teams and member teams.
     *
     * @param  Model  $team  The team to check membership for.
     */
    public function belongsToTeam(Model $team): bool
    {
        return $this->allTeams()->contains(fn (Model $t): bool => $t->getKey() === $team->getKey());
    }

    /**
     * Determine if the user owns the given team.
     *
     * @param  Model  $team  The team to check ownership for.
     */
    public function ownsTeam(Model $team): bool
    {
        return $this->getKey() === $team->getAttribute('user_id');
    }

    /**
     * Determine if the user has the given role on the given team.
     *
     * Owners are not checked here — use ownsTeam() separately.
     *
     * @param  Model  $team  The team to check the role on.
     * @param  string  $role  The role to check for.
     */
    public function hasTeamRole(Model $team, string $role): bool
    {
        $membership = $this->teams
            ->first(fn (Model $t): bool => $t->getKey() === $team->getKey());

        if ($membership === null) {
            return false;
        }

        return $membership->pivot?->role === $role;
    }
}
