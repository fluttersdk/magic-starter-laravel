<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Teams;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\RelationManagers\AuditsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Billing\RelationManagers\BillingRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Pages\EditTeam;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Pages\ListTeams;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\RelationManagers\InvitationsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\RelationManagers\MembersRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Schemas\TeamForm;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Tables\TeamsTable;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The cross-team staff resource for the configured team model.
 *
 * Teams are created by their users, so there is no create page. The form edits
 * the name only: `user_id`, `personal_team` and the billing columns are
 * ownership and entitlement facts that only their own contracts and webhooks
 * move, never a field here and never a key {@see updateRecordUsing()} forwards.
 */
class TeamResource extends MagicStarterResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function resolveModel(): string
    {
        return MagicStarter::teamModel();
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_teams.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_teams.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_teams.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return TeamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamsTable::configure($table);
    }

    /**
     * The tabs under the edit form: billing only when the application bills teams,
     * the audit trail only with the audit feature.
     *
     * @return list<class-string>
     */
    public static function getRelations(): array
    {
        return array_values(array_filter([
            MembersRelationManager::class,
            InvitationsRelationManager::class,
            Features::hasBillingFeatures() && config('magic-starter.billing.billable') === 'team'
                ? BillingRelationManager::class
                : null,
            Features::hasAuditFeatures() ? AuditsRelationManager::class : null,
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }

    /**
     * Rename through {@see UpdatesTeams}, forwarding the name and nothing else.
     *
     * Filtering the form state down to `name` here, rather than trusting the
     * form to expose only that field, is what keeps a crafted Livewire payload
     * from reaching an owner or billing column.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        ContractAction::run(
            null,
            static fn () => app(UpdatesTeams::class)->update($actor, $record, [
                'name' => $data['name'],
            ]),
            'team.updated',
            $record,
        );

        return $record;
    }

    /**
     * The signed-in panel user, for the relation managers' contract calls.
     *
     * @throws AuthenticationException when no panel user is signed in
     */
    public static function actor(): Authenticatable
    {
        return Filament::auth()->user() ?? throw new AuthenticationException;
    }

    /**
     * The roles a member can be given, labelled, keyed by the stored value.
     *
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return collect(Role::assignable())
            ->mapWithKeys(static fn (string $role): array => [
                $role => static::roleLabel($role),
            ])
            ->all();
    }

    /**
     * A role's label, or the raw value when no label is translated for it.
     */
    public static function roleLabel(?string $role): string
    {
        $key = 'magic-starter::admin_teams.roles.' . $role;
        $label = (string) __($key);

        return $label === $key ? (string) $role : $label;
    }
}
