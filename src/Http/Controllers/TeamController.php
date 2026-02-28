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
     * List all teams for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TeamResource::collection($request->user()->allTeams());
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
     * Delete the specified team.
     */
    public function destroy(Request $request, string $team): JsonResponse
    {
        $deleter = app(DeletesTeams::class);
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', $teamModel);

        // Personal teams cannot be deleted — Jetstream convention.
        if ($teamModel->personal_team) {
            throw ValidationException::withMessages([
                'team' => __('You may not delete your personal team.'),
            ])->errorBag('deleteTeam');
        }

        $teamId = $teamModel->getKey();
        $deleter->delete($teamModel);

        // Switch to the next available team if the deleted team was active.
        $this->switchToNextTeamIfNeeded($user, $teamId);

        return response()->json([
            'data' => null,
            'message' => 'Team deleted successfully.',
        ]);
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
            $user->update(['current_team_id' => $nextTeam->getKey()]);
        }
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
