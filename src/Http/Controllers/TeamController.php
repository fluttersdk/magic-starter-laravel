<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use FlutterSdk\MagicStarter\Http\Requests\StoreTeamRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdateTeamRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Handles team CRUD operations with authorization gates.
 */
class TeamController
{
    /**
     * List all teams for the authenticated user.
     */
    public function index(): AnonymousResourceCollection
    {
        return TeamResource::collection(request()->user()->allTeams());
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
    public function show(string $team): TeamResource
    {
        $teamModel = $this->findTeam($team);
        $user = request()->user();
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
     * Delete the specified team.
     */
    public function destroy(string $team): JsonResponse
    {
        $deleter = app(DeletesTeams::class);
        $teamModel = $this->findTeam($team);
        $user = request()->user();
        Gate::forUser($user)->authorize('delete', $teamModel);

        if ($user->allTeams()->count() <= 1) {
            return response()->json([
                'message' => 'You cannot delete your last team.',
            ], 403);
        }

        $teamId = $teamModel->getKey();

        $deleter->delete($teamModel);

        if ((string) $user->current_team_id === (string) $teamId) {
            $user->unsetRelation('ownedTeams')->unsetRelation('teams');

            $nextTeam = $user->allTeams()->first();

            if ($nextTeam) {
                $user->update(['current_team_id' => $nextTeam->getKey()]);
            }
        }

        return response()->json(['message' => 'Team deleted successfully.']);
    }

    /**
     * Find a team by its ID.
     */
    private function findTeam(string $id): mixed
    {
        $modelClass = MagicStarter::teamModel();

        return $modelClass::query()->findOrFail($id);
    }
}
