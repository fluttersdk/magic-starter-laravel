---
path: "src/Models/**/*.php"
alsoApplyTo: "src/Traits/**/*.php"
---

# Models & Traits

## Models

- Apply `ConditionallyUsesUuids` trait on every model (runtime UUID/integer PK switching)
- PHPDoc `@property` for every column, `@property-read` for accessors and computed attributes
- Type relations explicitly: `BelongsTo<User, $this>`, `HasMany<Team, $this>`, `BelongsToMany<User, $this>`
- Use `casts()` method (Laravel 11+ syntax), not `$casts` property
- Resolve related models dynamically: `MagicStarter::userModel()` in relation definitions
- BelongsToMany pivot: always chain `->using(MagicStarter::membershipModel())` and `->withPivot('role')`
- No soft deletes — use `cascadeOnDelete()` on foreign keys instead
- `$fillable` array for mass assignment, not `$guarded`

## Traits

- Defensive: `method_exists($this, 'method')` before calling methods from other optional traits
- Return typed morphMany/morphTo: `MorphMany<NotificationSetting, $this>`
- HasProfilePhoto: falls back to ui-avatars.com URL using `config('magic-starter.ui_avatars_url')`
- HasNotifications: `prefers()` returns true by default if notification type not in registry
- HasNotifications: `routeNotificationForOneSignal()` prefixes user ID with `user_` (OneSignal requires it)
