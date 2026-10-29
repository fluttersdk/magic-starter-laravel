<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\TrashesTeams;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Requests\StoreTeamRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdateTeamRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\SoftDeletingTeams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Handles team CRUD operations with authorization gates.
 */
class TeamController
{
    /**
     * List all teams for the authenticated user with pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            (int) $request->input('per_page', 15),
            100,
        );

        $user = $request->user();
        $ownedTeamIds = $user->ownedTeams()->pluck('teams.id');
        $memberTeamIds = $user->teams()->pluck('teams.id');
        $allTeamIds = $ownedTeamIds->merge($memberTeamIds)->unique();

        $teams = MagicStarter::teamModel()::query()
            ->whereIn('id', $allTeamIds)
            ->orderBy('name')
            ->paginate($perPage);

        return TeamResource::collection($teams);
    }

    /**
     * Store a newly created team.
     */
    public function store(StoreTeamRequest $request): TeamResource
    {
        $creator = app(CreatesTeams::class);

        $team = $creator->create(
            $request->user(),
            $request->validated(),
        );

        return new TeamResource($team);
    }

    /**
     * Display the specified team.
     */
    public function show(Request $request, string $team): TeamResource
    {
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $teamModel);

        return new TeamResource($teamModel);
    }

    /**
     * Update the specified team.
     */
    public function update(UpdateTeamRequest $request, string $team): TeamResource
    {
        $updater = app(UpdatesTeams::class);
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $teamModel);

        $updater->update(
            $user,
            $teamModel,
            $request->validated(),
        );

        if (method_exists($teamModel, 'refresh')) {
            $teamModel->refresh();
        }

        return new TeamResource($teamModel);
    }

    /**
     * Delete the specified team, or trash it while the restorable delete is on.
     */
    public function destroy(Request $request, string $team): JsonResponse
    {
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', $teamModel);

        // Personal teams cannot be deleted — Jetstream convention.
        if ($teamModel->personal_team) {
            throw ValidationException::withMessages([
                'team' => __('magic-starter::teams.personal_team_undeletable'),
            ])->errorBag('deleteTeam');
        }

        $teamId = $teamModel->getKey();
        $trashed = Features::hasRestorableTeamFeatures();

        if ($trashed) {
            app(TrashesTeams::class)->trash($teamModel);
        } else {
            app(DeletesTeams::class)->delete($teamModel);
        }

        // Switch to the next available team if the deleted team was active.
        $this->switchToNextTeamIfNeeded($user, $teamId);

        if ($trashed) {
            return response()->json([
                'data' => [
                    'restore_until' => $teamModel->restore_until,
                ],
                'message' => __('magic-starter::teams.trashed'),
            ]);
        }

        return response()->json([
            'data' => null,
            'message' => __('magic-starter::teams.deleted'),
        ]);
    }

    /**
     * Restore a trashed team. Registered only with the restorable delete on.
     */
    public function restore(Request $request, string $team): TeamResource
    {
        $teamModel = $this->findTeam($team, withTrashed: true);
        Gate::forUser($request->user())->authorize('restore', $teamModel);

        app(TrashesTeams::class)->restore($teamModel);

        return (new TeamResource($teamModel->refresh()))
            ->additional(['message' => __('magic-starter::teams.restored')]);
    }

    /**
     * Switch the user to their next available team if needed.
     */
    private function switchToNextTeamIfNeeded(mixed $user, string $deletedTeamId): void
    {
        if ((string) $user->current_team_id !== (string) $deletedTeamId) {
            return;
        }

        $user->unsetRelation('ownedTeams')->unsetRelation('teams');
        $nextTeam = $user->allTeams()->first();

        if ($nextTeam) {
            // current_team_id is kept out of $fillable; forceFill persists it.
            $user->forceFill(['current_team_id' => $nextTeam->getKey()])->save();
        }
    }

    /**
     * Find a team by its ID, trashed ones too when asked.
     *
     * Only the restore route asks: every other endpoint answers 404 for a
     * trashed team, as it does for one that never existed. A team model that
     * cannot be trashed has no trashed rows to include.
     */
    private function findTeam(string $id, bool $withTrashed = false): mixed
    {
        $modelClass = MagicStarter::teamModel();
        $query = $modelClass::query();

        if ($withTrashed && SoftDeletingTeams::usedBy($modelClass)) {
            $query->withTrashed();
        }

        return $query->findOrFail($id);
    }
}
