# Teams

## Where to Find It

- Actions: `src/Actions/CreateTeam.php`, `AddTeamMember.php`, `InviteTeamMember.php`, `RemoveTeamMember.php`, `UpdateTeamMemberRole.php`, `DeleteTeam.php`
- Listener: `src/Listeners/CreatePersonalTeamListener.php`
- Enum: `src/Enums/Role.php`
- Model: `src/Models/Team.php`
- Contracts: `src/Contracts/` — one interface per action

## What to Watch For

### Personal Teams

Personal teams are auto-created by `CreatePersonalTeamListener` on the Laravel `Registered` event. The listener uses a raw DB query (not a cached relation) for its idempotency check to avoid race conditions. The team name is localized via `magic-starter::teams.personal_team_name` using the user's `locale` attribute or the app locale as fallback. The `personal_team` boolean column distinguishes personal teams from regular ones — never allow deletion of a personal team from the controller layer.

### Team Creation Flow

`CreateTeam::create()` follows four steps: validate the name, create the team record with `personal_team = false` via `$user->ownedTeams()->create()`, attach the creator to the pivot with `Role::OWNER`, clear the cached `ownedTeams` and `teams` relations via `unsetRelation()`, then set `current_team_id` on the user. The relation cache clear is required so subsequent Gate checks observe the new team immediately.

### Membership and the Pivot Model

All member relations go through the pivot. `Team::users()` chains `->using(MagicStarter::membershipModel())` and `->withPivot('role')` — never bypass this with a raw `team_user` query. Use `membershipModel()` everywhere, never hardcode `TeamUser`. Role updates use `updateExistingPivot()`. Removal uses `detach()`. Owner detection compares `(string) $team->user_id === (string) $member->id` — the string cast is required for UUID/integer compatibility.

### Roles

`Role` is a backed enum with four cases: `OWNER`, `ADMIN`, `EDITOR`, `MEMBER`. Only `ADMIN`, `EDITOR`, and `MEMBER` are returned by `Role::assignable()` — owner is determined by team ownership, not by pivot assignment. Use `Role::assignableForValidation()` to build `in:` validation rules. Never accept `owner` as a user-supplied role value.

### Invitations

`InviteTeamMember` creates a `TeamInvitation` record with a 32-char random token and immediately sends `TeamInvitationNotification` via the anonymous mail route. Invitation lookup is email-based. Check `magic-starter.invitation_expiry_days` config for expiry enforcement in custom overrides.

### Deletion Constraints

`DeleteTeam::delete()` runs three steps: delete the team photo from the configured storage disk, detach all members and delete all invitations, then delete the team record. Before calling this action, the controller must verify the team is not a `personal_team` and must switch the owner's `current_team_id` if it matches the deleted team.
