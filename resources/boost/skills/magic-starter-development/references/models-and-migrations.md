# Models & Migrations

## Where to Find It

- `src/Support/ConditionallyUsesUuids.php` — runtime UUID/integer PK switching trait
- `src/Support/MigrationHelper.php` — primaryKey(), foreignKey(), morphColumns()
- `src/Models/` — Team, PersonalAccessToken (both use ConditionallyUsesUuids)
- `src/Traits/` — HasProfilePhoto, HasNotifications, HasTeams, HasGuestSupport, TwoFactorAuthenticatable
- `config/magic-starter.php` — use_uuids toggle, profile_photo_disk, ui_avatars_url

## What to Watch For

### All models use ConditionallyUsesUuids trait

Every model in the package applies ConditionallyUsesUuids. When config('magic-starter.use_uuids') is true, the trait sets $incrementing=false and $keyType='string' on instantiation, and auto-generates ordered UUIDs on create. When false, standard auto-incrementing integers apply with no special behavior. PHPDoc @property annotations are required for every column; @property-read for accessors and computed attributes.

### Dynamic relation resolution prevents class hardcoding

Relations never reference App\Models\* directly. All model classes are resolved via MagicStarter::userModel(), MagicStarter::teamModel(), MagicStarter::membershipModel(), and MagicStarter::teamInvitationModel(). BelongsToMany relations to users must chain ->using(MagicStarter::membershipModel())->withPivot('role'). Casts are defined via the casts() method (Laravel 11+ syntax), not the $casts property. Use $fillable arrays, not $guarded. No soft deletes — use cascadeOnDelete() on foreign keys instead.

### Five user traits provide optional functionality

HasProfilePhoto exposes getProfilePhotoUrlAttribute(), resolving the configured disk URL when profile_photo_path is set, falling back to a ui-avatars.com URL built from name initials. The ui_avatars_url config key controls the base URL.

HasNotifications provides morphMany notificationSettings() returning MorphMany<NotificationSetting, $this>, a prefers() method with a 3-step fallback (registry miss defaults to true, DB override wins, then registry default channels), and routeNotificationForOneSignal() prefixing IDs with user_ for OneSignal compatibility.

HasTeams provides ownedTeams(), teams() (with pivot), allTeams() (merge + sort), currentTeam() BelongsTo, getCurrentTeamOrPersonal(), belongsToTeam(), ownsTeam(), and hasTeamRole() reading pivot->role.

HasGuestSupport provides isGuest() reading the is_guest flag and isRegistered() checking for non-guest with a valid email+password or phone+password pair. Guest users may have null email AND null password — always guard with null checks.

TwoFactorAuthenticatable stores two_factor_secret and two_factor_recovery_codes as encrypted columns. twoFactorSecret() and recoveryCodes() call decrypt() internally. Always check hasEnabledTwoFactorAuthentication() (confirmed_at not null) before reading secrets.

### MigrationHelper handles UUID/integer dual support

MigrationHelper::primaryKey($table) emits uuid('id')->primary() or id() based on config. MigrationHelper::foreignKey($table, 'col') emits foreignUuid or foreignId. MigrationHelper::morphColumns($table, 'name') emits uuidMorphs or morphs. Never use raw $table->id(), $table->uuid('id'), or $table->morphs() directly in package migrations.

Additional conventions: anonymous class syntax (return new class extends Migration), idempotency via if (! Schema::hasTable('table_name')), explicit string lengths (profile_photo_path: 2048, phone_country: char(2)), and $table->timestamps() on every entity table.
