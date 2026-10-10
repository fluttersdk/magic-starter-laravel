<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users;

use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\RelationManagers\AuditsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Billing\RelationManagers\BillingRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\CreateUser;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\EditUser;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Pages\ListUsers;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\PushDevicesRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\SocialAccountsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\TeamsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\RelationManagers\TokensRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Schemas\UserForm;
use FlutterSdk\MagicStarter\Filament\Resources\Users\Tables\UsersTable;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The user directory: profile fields only, every write through the contracts.
 *
 * No credential reaches the form: the password, the two-factor secret and the
 * recovery codes are changed by the product's own flows (a reset, a
 * re-enrolment), never typed by staff. A user created here gets a random
 * password nobody sees and reaches the account through the password reset.
 *
 * There is no delete action and no bulk action: an account leaves through the
 * scheduled-deletion contract, which refuses what the purge could not finish.
 */
class UserResource extends MagicStarterResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function resolveModel(): string
    {
        return MagicStarter::userModel();
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_users.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * The tabs under the edit form, each behind the feature that fills it.
     *
     * @return list<class-string>
     */
    public static function getRelations(): array
    {
        return array_values(array_filter([
            Features::hasSessionFeatures() ? TokensRelationManager::class : null,
            Features::hasSocialLoginFeatures() ? SocialAccountsRelationManager::class : null,
            Features::hasOnesignalFeatures() ? PushDevicesRelationManager::class : null,
            Features::hasTeamFeatures() ? TeamsRelationManager::class : null,
            Features::hasBillingFeatures() && config('magic-starter.billing.billable', 'user') === 'user'
                ? BillingRelationManager::class
                : null,
            Features::hasAuditFeatures() ? AuditsRelationManager::class : null,
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Saved through the profile contract, which validates the input and resets
     * the verification when the address changes.
     */
    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        /** @var Model&Authenticatable $user */
        $user = $record;

        ContractAction::run(
            null,
            static fn () => app(UpdatesUserProfiles::class)->update($user, $data),
            'user.updated',
            $user,
        );

        return $record->refresh();
    }

    /**
     * Created through the registration contract. Its rules require a password,
     * and the form has none by design, so it gets one nobody will ever read.
     */
    public static function createRecordUsing(array $data, Authenticatable $actor): Model
    {
        /** @var Model $user */
        $user = ContractAction::run(
            null,
            static fn () => app(CreatesUsers::class)->create([
                ...$data,
                'password' => Str::password(64),
            ]),
            'user.created',
            null,
        );

        return $user;
    }
}
