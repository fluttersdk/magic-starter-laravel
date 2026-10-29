# Teams

## Table of Contents

- [Introduction](#introduction)
- [Team CRUD](#team-crud)
  - [List Teams](#list-teams)
  - [Create Team](#create-team)
  - [Show Team](#show-team)
  - [Update Team](#update-team)
  - [Delete Team](#delete-team)
  - [Restore Team](#restore-team)
- [Restorable Delete](#restorable-delete)
- [Team Members](#team-members)
  - [List Members](#list-members)
  - [Update Member Role](#update-member-role)
  - [Remove Member](#remove-member)
  - [Leave Team](#leave-team)
- [Team Invitations](#team-invitations)
  - [List Invitations](#list-invitations)
  - [Create Invitation](#create-invitation)
  - [Cancel Invitation](#cancel-invitation)
  - [Accept Invitation](#accept-invitation)
- [Team Photos](#team-photos)
  - [Upload Team Photo](#upload-team-photo)
  - [Delete Team Photo](#delete-team-photo)
- [Switch Current Team](#switch-current-team)
- [Lifecycle Events](#lifecycle-events)
  - [Transferring Ownership](#transferring-ownership)
- [Authorization](#authorization)
  - [TeamPolicy](#teampolicy)
  - [Policy Abilities Summary](#policy-abilities-summary)
- [Models](#models)
  - [Team](#team-model)
  - [TeamUser (Pivot)](#teamuser-pivot)
  - [TeamInvitation](#teaminvitation-model)
- [Roles](#roles)
- [HasTeams Trait](#hasteams-trait)

---

<a name="introduction"></a>
## Introduction

Team management is a feature-gated module. All team routes and logic are only registered when the `teams` feature is enabled:

```php
// config/magic-starter.php
'features' => [
    Features::teams(),
],
```

All team endpoints require `auth:sanctum` middleware. Routes are handled by `TeamController`, `TeamMemberController`, `TeamInvitationController`, and `TeamPhotoController`.

---

<a name="team-crud"></a>
## Team CRUD

<a name="list-teams"></a>
### List Teams

```
GET /teams
```

Returns a paginated list of all teams the authenticated user owns or belongs to, ordered by name.

**Query Parameters:**

| Parameter  | Type    | Default | Description                     |
|------------|---------|---------|---------------------------------|
| `per_page` | integer | 15      | Items per page (max 100)        |

**Response:** `200 OK`

```json
{
  "data": [
    {
      "id": "9a8b7c6d-...",
      "name": "Acme Corp",
      "personal_team": false,
      "owner_id": "1a2b3c4d-...",
      "user_role": "owner",
      "profile_photo_url": "https://...",
      "created_at": "2026-01-15T10:30:00.000000Z",
      "updated_at": "2026-01-15T10:30:00.000000Z"
    }
  ],
  "links": { "..." : "..." },
  "meta": { "..." : "..." }
}
```

**Controller:** `TeamController@index`

---

<a name="create-team"></a>
### Create Team

```
POST /teams
```

Creates a new team. The authenticated user becomes the owner.

**Request Body:**

| Field  | Type   | Rules                          |
|--------|--------|--------------------------------|
| `name` | string | required, string, max:255      |

**Response:** `201 Created`

```json
{
  "data": {
    "id": "9a8b7c6d-...",
    "name": "New Team",
    "personal_team": false,
    "owner_id": "1a2b3c4d-...",
    "user_role": "owner",
    "profile_photo_url": null,
    "created_at": "2026-03-25T12:00:00.000000Z",
    "updated_at": "2026-03-25T12:00:00.000000Z"
  }
}
```

**Contract:** `CreatesTeams` (overridable via service container binding)

**Controller:** `TeamController@store`

---

<a name="show-team"></a>
### Show Team

```
GET /teams/{team}
```

Returns a single team. Requires `view` policy authorization (user must belong to the team).

**Response:** `200 OK`

```json
{
  "data": {
    "id": "9a8b7c6d-...",
    "name": "Acme Corp",
    "personal_team": false,
    "owner_id": "1a2b3c4d-...",
    "user_role": "admin",
    "profile_photo_url": "https://...",
    "created_at": "2026-01-15T10:30:00.000000Z",
    "updated_at": "2026-01-15T10:30:00.000000Z"
  }
}
```

**Controller:** `TeamController@show`

---

<a name="update-team"></a>
### Update Team

```
PUT /teams/{team}
```

Updates team details. Requires `update` policy authorization (owner only).

**Request Body:**

| Field   | Type   | Rules                              |
|---------|--------|------------------------------------|
| `name`  | string | required, string, max:255          |
| `photo` | file   | nullable, image, max:1024 KB       |

**Response:** `200 OK` -- returns updated `TeamResource`.

**Contract:** `UpdatesTeams` (overridable via service container binding)

**Controller:** `TeamController@update`

---

<a name="delete-team"></a>
### Delete Team

```
DELETE /teams/{team}
```

Deletes the team. Requires `delete` policy authorization (owner only). Personal teams cannot be deleted -- returns a `422` validation error.

If the deleted team was the user's current active team, the user is automatically switched to their next available team.

**Response:** `200 OK`

```json
{
  "data": null,
  "message": "Team deleted successfully."
}
```

**Error (personal team):** `422 Unprocessable Entity`

```json
{
  "message": "You may not delete your personal team.",
  "errors": {
    "team": ["You may not delete your personal team."]
  }
}
```

**Contract:** `DeletesTeams` (overridable via service container binding)

**Controller:** `TeamController@destroy`

With the [restorable delete](#restorable-delete) on, this endpoint trashes the team instead and answers with the end of its restore window:

```json
{
  "data": {
    "restore_until": "2026-11-28T12:00:00.000000Z"
  },
  "message": "Team deleted. Its owner can restore it until the restore window closes."
}
```

The same refusals apply, including the subscription guard, and the contract is `TrashesTeams`.

---

<a name="restore-team"></a>
### Restore Team

```
POST /teams/{team}/restore
```

Registered only with the [restorable delete](#restorable-delete) on. Brings a trashed team back. Requires `restore` policy authorization (owner only). This is the one endpoint that finds a trashed team; every other team endpoint answers `404` for it.

Restoring a team that is already live changes nothing and answers `200`, so a client that sends the request twice is not punished.

**Response:** `200 OK` -- returns the `TeamResource` with a `message`.

**Error (window closed):** `422 Unprocessable Entity`

```json
{
  "message": "This team can no longer be restored.",
  "errors": {
    "team": ["This team can no longer be restored."]
  }
}
```

**Contract:** `TrashesTeams` (overridable via service container binding)

**Controller:** `TeamController@restore`

---

<a name="restorable-delete"></a>
## Restorable Delete

By default `DELETE /teams/{team}` deletes the team for good. The `restorable-teams` feature makes that delete reversible: the team is hidden for a window, its owner can restore it, and a scheduled command deletes it once the window has closed. It is off until you switch it on, and while it is off nothing about team deletion changes.

Switching it on takes three things in the consuming application:

1. **The column.** `magic-starter:install` publishes `add_soft_deletes_to_teams_table` with the teams feature; on an existing install copy it from `vendor/fluttersdk/magic-starter-laravel/database/migrations/` into `database/migrations/`. It adds `deleted_at` and an indexed, nullable `restore_until`.
2. **The trait.** The team model uses `Illuminate\Database\Eloquent\SoftDeletes`. The published `App\Models\Team` stub does; add it by hand to an older one. The package's own `Models\Team` does not, so that applications that never opt in keep a teams table without the column.
3. **The feature and the schedule.**

```php
// config/magic-starter.php
'features' => [
    \FlutterSdk\MagicStarter\Features::teams(),
    \FlutterSdk\MagicStarter\Features::restorableTeams(),
],

'teams' => [
    'restore_days' => 30, // MAGIC_STARTER_TEAM_RESTORE_DAYS
],
```

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('magic-starter:purge-trashed-teams')->daily();
```

The package registers the command and does not schedule it. Without the schedule line a trashed team is never deleted.

### What a trash does

`TrashesTeams::trash()` runs in one transaction:

1. clears `current_team_id` for every user whose active team is this one, with a query-builder update that ignores `$fillable`. A soft delete is an update and never triggers the foreign key's `nullOnDelete`, so without this the pointer would name a team nobody can see;
2. cancels the pending invitations, because a link to a hidden team must not be acceptable;
3. writes `restore_until`, `teams.restore_days` days from now (`0` closes the window at once);
4. soft deletes the team.

Members stay attached, so a restore brings the team back with the people it had. The team disappears from `GET /teams` and from every relation, because the model's soft delete scope hides it. A restore does not put the pointers back (they are each person's choice, and they have been somewhere else since) and does not bring the invitations back; the owner invites again.

The subscription guard of the plain delete applies to a trash as well (`Actions\SubscriptionGuardedTrashTeam`), so a team a store or Stripe is still billing is refused with the same sentence.

### What a restore does

`TrashesTeams::restore()` clears `deleted_at` and `restore_until` in one write, and refuses with a `422` once `restore_until` is not in the future. A trashed team with no `restore_until` (trashed by other means) has no window to close and is restored.

### The purge

```bash
php artisan magic-starter:purge-trashed-teams
```

Selects the trashed teams whose `restore_until` is not in the future and deletes each through `DeletesTeams`, so the subscription guard and the photo cleanup run as they do for any team. `DeletesTeams` is the purge: on a model that soft deletes it force deletes. Each team is read again under a lock first, so a restore that lands after the walk loaded it wins.

| Outcome | Meaning |
|---------|---------|
| **Deleted** | Removed with its photo, memberships and invitations. |
| **Held** | The billing guard refused. Logged as well as printed, left trashed, and tried again on the next run. |
| **Restored** | Its owner restored it after the walk loaded it. Skipped. |
| **Failed** | Reported, and the run moves on. The exit code is non-zero. |

```
Purge finished: 12 deleted, 1 held, 0 restored, 0 failed.
```

A team model that does not use `SoftDeletes`, or a table without `restore_until`, has nothing to purge and the command says so.

### Account deletion

An account purge deletes the teams the user trashed too, for good and through `DeletesTeams`: `OwnedTeams::includingTrashed()` reads them, and a trashed team a subscription still bills holds the purge back like any other. A trashed team does not count as a shared team, so it never blocks scheduling the deletion.

---

<a name="team-members"></a>
## Team Members

<a name="list-members"></a>
### List Members

```
GET /teams/{team}/members
```

Returns all members of the team, including the owner. The owner is injected with the `owner` role. Requires `view` policy authorization.

**Response:** `200 OK`

```json
{
  "data": [
    {
      "id": "1a2b3c4d-...",
      "name": "John Doe",
      "email": "john@example.com",
      "profile_photo_url": "https://...",
      "role": "owner"
    },
    {
      "id": "5e6f7g8h-...",
      "name": "Jane Smith",
      "email": "jane@example.com",
      "profile_photo_url": "https://...",
      "role": "admin"
    }
  ]
}
```

**Controller:** `TeamMemberController@index`

---

<a name="update-member-role"></a>
### Update Member Role

```
PUT /teams/{team}/members/{user}
```

Updates a team member's role. Requires `manageMembers` policy authorization (owner or admin). Cannot change the role of the team owner (returns `403`).

**Request Body:**

| Field  | Type   | Rules                                |
|--------|--------|--------------------------------------|
| `role` | string | required, string, in:admin,editor,member |

**Response:** `200 OK`

```json
{
  "message": "Team member updated successfully."
}
```

**Error (changing owner role):** `403 Forbidden`

```
Cannot change role of team owner.
```

**Controller:** `TeamMemberController@update`

---

<a name="remove-member"></a>
### Remove Member

```
DELETE /teams/{team}/members/{user}
```

Removes a member from the team. Requires `manageMembers` policy authorization (owner or admin). Cannot remove the team owner (returns `403`).

If the removed member's `current_team_id` points at this team, it is cleared, so their next read lands on their personal team instead of the team they were removed from. A member whose current team is another team keeps it.

**Response:** `200 OK`

```json
{
  "message": "Team member removed successfully."
}
```

**Error (removing owner):** `403 Forbidden`

```
Cannot remove team owner.
```

**Contract:** `RemovesTeamMembers` (overridable via service container binding)

**Controller:** `TeamMemberController@destroy`

---

<a name="leave-team"></a>
### Leave Team

```
DELETE /teams/{team}/leave
```

Allows the authenticated user to leave a team. The team owner cannot leave (returns `403`). If the user is not a member, returns `404`.

If the team being left was the user's current active team, `current_team_id` is cleared and the user falls back to their personal team (`getCurrentTeamOrPersonal()`). A user whose current team is another team keeps it.

**Response:** `200 OK`

```json
{
  "message": "You have left the team."
}
```

**Error (owner leaving):** `403 Forbidden`

```
Team owner cannot leave the team. Delete the team instead.
```

On the owner's personal team, which cannot be deleted either:

```
You may not leave your personal team.
```

**Error (not a member):** `404 Not Found`

```
You are not a member of this team.
```

**Contract:** `RemovesTeamMembers`

**Controller:** `TeamMemberController@leave`

---

<a name="team-invitations"></a>
## Team Invitations

<a name="list-invitations"></a>
### List Invitations

```
GET /teams/{team}/invitations
```

Returns a paginated list of pending invitations for the team, ordered by most recent. Requires `manageInvitations` policy authorization (owner or admin).

**Query Parameters:**

| Parameter  | Type    | Default | Description                     |
|------------|---------|---------|---------------------------------|
| `per_page` | integer | 15      | Items per page (max 100)        |

**Response:** `200 OK`

```json
{
  "data": [
    {
      "id": "a1b2c3d4-...",
      "team_id": "9a8b7c6d-...",
      "email": "invite@example.com",
      "role": "member",
      "token": "abc123def456...",
      "created_at": "2026-03-25T12:00:00.000000Z",
      "updated_at": "2026-03-25T12:00:00.000000Z"
    }
  ],
  "links": { "..." : "..." },
  "meta": { "..." : "..." }
}
```

**Controller:** `TeamInvitationController@index`

---

<a name="create-invitation"></a>
### Create Invitation

```
POST /teams/{team}/invitations
```

Sends a team invitation to an email address. Requires `manageInvitations` policy authorization. Returns `422` if an invitation already exists for that email or the user is already a team member.

**Request Body:**

| Field   | Type   | Rules                                |
|---------|--------|--------------------------------------|
| `email` | string | required, email, max:255             |
| `role`  | string | required, string, in:admin,editor,member |

**Response:** `201 Created`

```json
{
  "data": {
    "id": "a1b2c3d4-...",
    "team_id": "9a8b7c6d-...",
    "email": "invite@example.com",
    "role": "member",
    "token": "abc123def456...",
    "created_at": "2026-03-25T12:00:00.000000Z",
    "updated_at": "2026-03-25T12:00:00.000000Z"
  }
}
```

**Error (duplicate invitation):** `422 Unprocessable Entity`

```json
{
  "message": "An invitation has already been sent to this email.",
  "errors": {
    "email": ["An invitation has already been sent to this email."]
  }
}
```

**Error (already a member):** `422 Unprocessable Entity`

```json
{
  "message": "This user is already a member of the team.",
  "errors": {
    "email": ["This user is already a member of the team."]
  }
}
```

**Contract:** `InvitesTeamMembers` (overridable via service container binding)

**Controller:** `TeamInvitationController@store`

---

<a name="cancel-invitation"></a>
### Cancel Invitation

```
DELETE /teams/{team}/invitations/{invitation}
```

Cancels (deletes) a pending invitation. Requires `manageInvitations` policy authorization. The invitation must belong to the specified team (returns `404` otherwise).

**Response:** `200 OK`

```json
{
  "data": null,
  "message": "Invitation canceled successfully."
}
```

**Controller:** `TeamInvitationController@destroy`

---

<a name="accept-invitation"></a>
### Accept Invitation

```
POST /invitations/{token}/accept
```

Accepts a team invitation using a token. The authenticated user's email must match the invitation email (case-insensitive). Expired invitations return `410 Gone` and are automatically deleted.

If the user is already a member, the invitation is deleted and a success message is returned.

**Response:** `200 OK`

```json
{
  "data": null,
  "message": "Invitation accepted. You have joined the team."
}
```

**Error (email mismatch):** `403 Forbidden`

```json
{
  "message": "This invitation was sent to a different email address."
}
```

**Error (expired):** `410 Gone`

```json
{
  "message": "This invitation has expired."
}
```

**Controller:** `TeamInvitationController@accept`

---

<a name="team-photos"></a>
## Team Photos

Team photo endpoints are only available when both `teams` and `profile-photos` features are enabled.

<a name="upload-team-photo"></a>
### Upload Team Photo

```
POST /teams/{team}/profile-photo
```

Uploads or replaces the team's profile photo. Requires `update` policy authorization (owner only). The previous photo is deleted from disk before storing the new one.

**Request Body:** `multipart/form-data`

| Field   | Type | Rules                       |
|---------|------|-----------------------------|
| `photo` | file | required, image, max:2048 KB |

**Response:** `200 OK` -- returns updated `TeamResource`.

The photo is stored using the disk configured in `magic-starter.team_photo_disk` (falls back to `magic-starter.profile_photo_disk`, then `filesystems.default`). The storage path is configured via `magic-starter.team_photo_path` (default: `team-photos`).

**Controller:** `TeamPhotoController@update`

---

<a name="delete-team-photo"></a>
### Delete Team Photo

```
DELETE /teams/{team}/profile-photo
```

Removes the team's profile photo. Requires `update` policy authorization (owner only). The photo file is deleted from disk and `profile_photo_path` is set to `null`. `profile_photo_url` answers `null` again, and the client draws its own placeholder.

**Response:** `200 OK` -- returns updated `TeamResource`.

**Controller:** `TeamPhotoController@delete`

---

<a name="switch-current-team"></a>
## Switch Current Team

```
PUT /user/current-team
```

Switches the authenticated user's active team. The request is authorized via the `switchTo` policy gate (user must belong to the team).

**Request Body:**

| Field     | Type   | Rules                                        |
|-----------|--------|----------------------------------------------|
| `team_id` | string | required, uuid, exists in teams table         |

**Response:** `200 OK`

```json
{
  "data": { "...UserResource..." },
  "message": "Team switched successfully"
}
```

**Error (not a member):** `403 Forbidden`

```json
{
  "message": "You are not a member of this team."
}
```

**Request:** `SwitchTeamRequest` (authorization is handled in the request's `authorize()` method via `Gate::allows('switchTo', $team)`)

**Controller:** `AuthController@switchTeam`

---

<a name="lifecycle-events"></a>
## Lifecycle Events

A host that tells people when something happens to their team (a member joined, the household was deleted, ownership passed) listens to six events instead of overriding the actions:

| Event | Dispatched | Properties |
|-------|------------|------------|
| `Events\TeamMemberJoined` | After a member is attached: an invitation accepted (`AcceptTeamInvitation`) or a user added by email (`AddTeamMember`). | `teamId`, `userId`, `actorId` (the joiner when they accepted, the user who added them otherwise) |
| `Events\TeamMemberRemoved` | After a member is detached by `RemoveTeamMember`, which serves both the remove and the leave endpoints. | `teamId`, `userId`, `actorId` (equal to `userId` when the member left) |
| `Events\TeamDeleted` | After a team is deleted for good by `DeleteTeam`: the delete endpoint with the restorable delete off, `magic-starter:purge-trashed-teams`, the admin panel, and the account purge for each team the user owned. | `teamId`, `ownerId`, `memberIds` (everyone who belonged, the owner included, in no particular order) |
| `Events\TeamTrashed` | After `TrashTeam` hides a team for its restore window. | `teamId` |
| `Events\TeamRestored` | After `TrashTeam` brings a trashed team back inside the window. | `teamId` |
| `Events\TeamOwnershipTransferred` | After `TransferTeamOwnership` hands a team to another member: a host's own transfer, the admin panel, and the account purge when it hands on a departing owner's team. | `teamId`, `previousOwnerId`, `newOwnerId`, `actorId` |

```php
use FlutterSdk\MagicStarter\Events\TeamDeleted;
use FlutterSdk\MagicStarter\Events\TeamMemberJoined;
use Illuminate\Support\Facades\Event;

Event::listen(TeamMemberJoined::class, fn (TeamMemberJoined $event) => /* tell the owner */);
Event::listen(TeamDeleted::class, fn (TeamDeleted $event) => /* tell every former member */);
```

Four rules hold for all six:

- **After the commit.** Each event implements `ShouldDispatchAfterCommit`. The account purge calls these actions inside its own transaction, so a listener hears of a change only once the whole of it has committed, and never hears of one that rolled back. Outside a transaction an event is dispatched at once.
- **Once, and only for a change.** An action that refuses fires nothing: a join the plan or `ensureCanJoin()` refuses, the owner being removed, a delete or trash the billing guard refuses, a restore after the window closed, a transfer to a user who is not a member. Neither does a call that changes nothing: removing someone who is not a member, restoring a team that is not trashed, transferring a team to the owner it already has.
- **Keys, not models.** The payload is keys as strings, whatever the key type, and nothing else: no model, name or address, so a queued listener loads what it needs when it runs. `TeamDeleted` cannot load its members, because the team and its memberships are gone by then, so it carries `memberIds`, read before the members are detached.
- **A bound action owns its event.** The events are dispatched by the default actions. An application that binds its own class over `AddsTeamMembers`, `RemovesTeamMembers`, `DeletesTeams`, `TrashesTeams`, `TransfersTeamOwnership` or `AcceptsTeamInvitations` without extending the default has to dispatch them itself. Extending the default and overriding one hook keeps them.

Two departures follow from what the actions do. Deleting a team detaches its members without firing `TeamMemberRemoved` for each: `TeamDeleted` and its `memberIds` are the record. Deleting an account detaches the user from the other teams they belonged to the same way, and fires no event for it.

<a name="transferring-ownership"></a>
### Transferring Ownership

`TransferTeamOwnership` makes the new owner the owner and leaves the outgoing owner on the team as an `admin`. A host whose teams have fewer roles overrides `outgoingRole()` and keeps the rest of the transfer, the event included:

```php
use FlutterSdk\MagicStarter\Actions\TransferTeamOwnership;
use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Enums\Role;

class TransferHouseholdOwnership extends TransferTeamOwnership
{
    protected function outgoingRole(): Role
    {
        return Role::MEMBER;
    }
}

// AppServiceProvider::register()
$this->app->bind(TransfersTeamOwnership::class, TransferHouseholdOwnership::class);
```

Return anything but `Role::OWNER`: the new owner takes that role in the same transfer.

---

<a name="authorization"></a>
## Authorization

<a name="teampolicy"></a>
### TeamPolicy

`TeamPolicy` is automatically registered by the service provider when the teams feature is enabled. Consumers can override it:

```php
// In your AuthServiceProvider
Gate::policy(Team::class, CustomTeamPolicy::class);
```

App providers boot after package providers, so the override takes precedence.

<a name="policy-abilities-summary"></a>
### Policy Abilities Summary

| Ability             | Allowed For                | Used By                                       |
|---------------------|----------------------------|-----------------------------------------------|
| `view`              | Team members (owner + members) | `TeamController@show`, `TeamMemberController@index` |
| `update`            | Owner only                 | `TeamController@update`, `TeamPhotoController`     |
| `delete`            | Owner only                 | `TeamController@destroy`                       |
| `restore`           | Owner only                 | `TeamController@restore`                       |
| `manageMembers`     | Owner or admin role        | `TeamMemberController@update`, `TeamMemberController@destroy` |
| `manageInvitations` | Owner or admin role        | `TeamInvitationController@index`, `store`, `destroy` |
| `switchTo`          | Team members (owner + members) | `SwitchTeamRequest` authorization              |

---

<a name="models"></a>
## Models

<a name="team-model"></a>
### Team

**Class:** `FlutterSdk\MagicStarter\Models\Team`

| Property              | Type          | Description                                     |
|-----------------------|---------------|-------------------------------------------------|
| `id`                  | string        | Primary key (UUID or integer via `ConditionallyUsesUuids`) |
| `user_id`             | string        | Owner's user ID (foreign key)                   |
| `name`                | string        | Team name                                       |
| `personal_team`       | bool          | Whether this is a personal (non-deletable) team |
| `profile_photo_path`  | string\|null  | Stored photo path on disk                       |
| `profile_photo_url`   | string\|null (read) | Stored photo URL, or `null` when none is stored |
| `created_at`          | Carbon\|null  | Timestamp                                       |
| `updated_at`          | Carbon\|null  | Timestamp                                       |
| `deleted_at`          | Carbon\|null  | Set on a trashed team; only with the [restorable delete](#restorable-delete) |
| `restore_until`       | Carbon\|null  | End of a trashed team's restore window; only with the [restorable delete](#restorable-delete) |

**Relationships:**

| Method        | Type           | Description                                 |
|---------------|----------------|---------------------------------------------|
| `owner()`     | BelongsTo      | The user who owns the team                  |
| `users()`     | BelongsToMany  | Members via `team_user` pivot (with `role`, uses `MagicStarter::membershipModel()`) |
| `invitations()` | HasMany      | Pending `TeamInvitation` records            |

**Fillable:** `user_id`, `name`, `personal_team`, `profile_photo_path`

**Casts:** `personal_team` as `boolean`, `restore_until` as `datetime`

**Appends:** `profile_photo_url`

---

<a name="teamuser-pivot"></a>
### TeamUser (Pivot)

**Class:** `FlutterSdk\MagicStarter\Models\TeamUser`

Extends `Illuminate\Database\Eloquent\Relations\Pivot`. Uses the `team_user` table.

| Property     | Type         | Description               |
|--------------|--------------|---------------------------|
| `id`         | string       | Primary key               |
| `team_id`    | string       | Foreign key to teams      |
| `user_id`    | string       | Foreign key to users      |
| `role`       | string\|null | Member role (admin, editor, member) |
| `created_at` | Carbon\|null | Timestamp                 |
| `updated_at` | Carbon\|null | Timestamp                 |

Uses `ConditionallyUsesUuids` for runtime UUID/integer PK switching.

---

<a name="teaminvitation-model"></a>
### TeamInvitation

**Class:** `FlutterSdk\MagicStarter\Models\TeamInvitation`

| Property     | Type         | Description                           |
|--------------|--------------|---------------------------------------|
| `id`         | string       | Primary key                           |
| `team_id`    | string       | Foreign key to teams                  |
| `email`      | string       | Invited email address                 |
| `role`       | string       | Role to assign upon acceptance        |
| `token`      | string       | Unique acceptance token               |
| `expires_at` | Carbon\|null | Expiration timestamp (null = no expiry) |
| `created_at` | Carbon\|null | Timestamp                             |

**Fillable:** `email`, `role`, `token`, `expires_at`

**Casts:** `expires_at` as `datetime`

**Relationships:**

| Method   | Type      | Description                    |
|----------|-----------|--------------------------------|
| `team()` | BelongsTo | The team this invitation is for |

**Methods:**

| Method        | Returns | Description                                            |
|---------------|---------|--------------------------------------------------------|
| `isExpired()` | bool    | `true` if `expires_at` is set and in the past          |
| `scopeValid()`| Builder | Scope to non-expired invitations (null or future date) |

---

<a name="roles"></a>
## Roles

Roles are defined in `FlutterSdk\MagicStarter\Enums\Role`:

| Role     | Value      | Assignable | Description                              |
|----------|------------|------------|------------------------------------------|
| `OWNER`  | `owner`    | No         | Determined by `team.user_id`, not pivot  |
| `ADMIN`  | `admin`    | Yes        | Can manage members and invitations       |
| `EDITOR` | `editor`   | Yes        | Standard elevated role                   |
| `MEMBER` | `member`   | Yes        | Default member role                      |

The `owner` role is never assigned via the pivot table -- it is resolved at runtime by comparing `team.user_id` with the user's ID. Only `admin`, `editor`, and `member` are valid values for invitation and member role updates.

---

<a name="hasteams-trait"></a>
## HasTeams Trait

**Trait:** `FlutterSdk\MagicStarter\Traits\HasTeams`

Applied to the User model to provide team relationship methods.

| Method                         | Returns          | Description                                         |
|--------------------------------|------------------|-----------------------------------------------------|
| `ownedTeams()`                 | HasMany          | Teams where the user is the owner (`user_id`)       |
| `teams()`                      | BelongsToMany    | Teams the user is a member of (via `team_user` pivot with role) |
| `personalTeam()`               | Model\|null      | The user's personal team (`personal_team = true`)   |
| `currentTeam()`                | BelongsTo        | The user's currently active team (`current_team_id`) |
| `allTeams()`                   | Collection       | Merged owned + member teams, sorted by name         |
| `getCurrentTeamOrPersonal()`   | Model\|null      | Current team with personal team fallback             |
| `belongsToTeam(Model $team)`   | bool             | Whether the user owns or is a member of the team    |
| `ownsTeam(Model $team)`        | bool             | Whether the user owns the team (`user_id` match)    |
| `hasTeamRole(Model $team, string $role)` | bool  | Whether the user has the given role on the team (does not check ownership) |
