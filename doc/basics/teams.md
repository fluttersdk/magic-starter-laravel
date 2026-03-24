# Teams

## Table of Contents

- [Introduction](#introduction)
- [Team CRUD](#team-crud)
  - [List Teams](#list-teams)
  - [Create Team](#create-team)
  - [Show Team](#show-team)
  - [Update Team](#update-team)
  - [Delete Team](#delete-team)
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
    "profile_photo_url": "https://ui-avatars.com/api/?name=N%20T&color=7F9CF5&background=EBF4FF",
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

If the team being left was the user's current active team, the user is switched to their next available team.

**Response:** `200 OK`

```json
{
  "message": "You have left the team."
}
```

**Error (owner leaving):** `403 Forbidden`

```
Team owner cannot leave the team. Transfer ownership first or delete the team.
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

Removes the team's profile photo. Requires `update` policy authorization (owner only). The photo file is deleted from disk and `profile_photo_path` is set to `null`. The team falls back to a generated avatar URL via ui-avatars.com.

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
| `profile_photo_url`   | string (read) | Computed URL -- stored photo or ui-avatars fallback |
| `created_at`          | Carbon\|null  | Timestamp                                       |
| `updated_at`          | Carbon\|null  | Timestamp                                       |

**Relationships:**

| Method        | Type           | Description                                 |
|---------------|----------------|---------------------------------------------|
| `owner()`     | BelongsTo      | The user who owns the team                  |
| `users()`     | BelongsToMany  | Members via `team_user` pivot (with `role`, uses `MagicStarter::membershipModel()`) |
| `invitations()` | HasMany      | Pending `TeamInvitation` records            |

**Fillable:** `user_id`, `name`, `personal_team`, `profile_photo_path`

**Casts:** `personal_team` as `boolean`

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
