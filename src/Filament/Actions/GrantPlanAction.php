<?php

namespace FlutterSdk\MagicStarter\Filament\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Filament\Support\BillingAuthorization;
use FlutterSdk\MagicStarter\Filament\Support\BillingFormFields;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * "Grant a plan" from a billing list: the operator picks the customer as well
 * as the plan, so an enterprise contract, a partner or a comp is set up without
 * opening the customer's page first. The Billing tab's own grant is the same
 * write for a customer already open.
 *
 * It goes through {@see AdministersBilling::grant()} like every billing write,
 * so the contract refuses what it refuses there (a paid rail already granting
 * a plan) and the refusal reaches the operator as a danger notification. It is
 * shown only to an admin {@see BillingAuthorization} allows, and Filament
 * refuses to call a hidden action, so a crafted Livewire call does not pass.
 */
class GrantPlanAction extends Action
{
    /**
     * The most customers the picker lists for one search.
     */
    public const SEARCH_LIMIT = 50;

    public static function getDefaultName(): ?string
    {
        return 'grantPlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $key = 'magic-starter::admin_billing.actions.grant';

        $this
            ->label(__("{$key}.label"))
            ->icon(Heroicon::OutlinedGift)
            ->modalHeading(__("{$key}.heading"))
            ->modalDescription(__("{$key}.description"))
            ->successNotificationTitle(__("{$key}.success"))
            ->visible(static function (): bool {
                $admin = Filament::auth()->user();

                return $admin !== null && BillingAuthorization::allows($admin);
            })
            ->schema([
                Select::make('billable')
                    ->label(__('magic-starter::admin_billing.fields.billable'))
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => static::searchBillables($search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => static::billableLabel($value))
                    ->required(),
                BillingFormFields::plan(),
                BillingFormFields::reason(),
                BillingFormFields::expiresAt(),
            ])
            ->action(function (array $data): void {
                $this->grant($data);
            });
    }

    /**
     * The customers whose name or email holds `$search`, in any case, as key =>
     * label. A user billable is matched on its own name and email, a team on its
     * name and on the name or email of its owner or any member.
     *
     * On SQLite the match is case-insensitive for ASCII only (its `like`);
     * PostgreSQL answers through `ilike`.
     *
     * @return array<int|string, string>
     */
    public static function searchBillables(string $search): array
    {
        $model = MagicStarter::billableModel();
        $pattern = '%' . $search . '%';
        $ownsEmail = static::billableOwnsEmail();

        return $model::query()
            ->where(static function (Builder $query) use ($pattern, $ownsEmail): void {
                $query->whereLike('name', $pattern);

                if ($ownsEmail) {
                    $query->orWhereLike('email', $pattern);

                    return;
                }

                // A team is found through any person on it, owner included,
                // by name or email: the person who wrote in is often a member.
                $person = static fn (Builder $user): Builder => $user->where(
                    static fn (Builder $match): Builder => $match
                        ->whereLike('name', $pattern)
                        ->orWhereLike('email', $pattern),
                );

                $query->orWhereHas('owner', $person)->orWhereHas('users', $person);
            })
            ->when(! $ownsEmail, static fn (Builder $query): Builder => $query->with('owner'))
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->mapWithKeys(static fn (Model $billable): array => [$billable->getKey() => static::optionLabel($billable)])
            ->all();
    }

    /**
     * The label the picker shows for a chosen key, or null for a key that does
     * not resolve, which Filament's own validation then refuses.
     */
    public static function billableLabel(mixed $key): ?string
    {
        $billable = static::findBillable($key);

        return $billable === null ? null : static::optionLabel($billable);
    }

    /**
     * The name and an email, so two customers who share a name are told apart
     * before a contract tier is granted to the wrong one.
     */
    protected static function optionLabel(Model $billable): string
    {
        $email = static::billableOwnsEmail()
            ? $billable->getAttribute('email')
            : $billable->getRelationValue('owner')?->getAttribute('email');

        $name = (string) $billable->getAttribute('name');

        return is_string($email) && $email !== '' ? "{$name} · {$email}" : $name;
    }

    /**
     * Whether the billable carries its own email (a user) rather than reaching
     * one through its owner (a team).
     */
    protected static function billableOwnsEmail(): bool
    {
        return is_a(MagicStarter::billableModel(), MagicStarter::userModel(), true);
    }

    /**
     * The billable for a submitted key, or null. A key that cannot be a uuid
     * on a uuid-keyed model is not looked up: PostgreSQL refuses to compare a
     * uuid column with text (SQLSTATE 22P02), which would answer 500.
     */
    protected static function findBillable(mixed $key): ?Model
    {
        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $model = MagicStarter::billableModel();
        $keysOnUuids = MigrationHelper::usesUuids()
            && in_array(ConditionallyUsesUuids::class, class_uses_recursive($model), true);

        if ($keysOnUuids && ! Str::isUuid((string) $key)) {
            return null;
        }

        $billable = $model::query()->find($key);

        return $billable instanceof Model ? $billable : null;
    }

    /**
     * Grant the plan to the chosen customer as the signed-in panel user, audited
     * as `billing.grant_added`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthenticationException when no panel user is signed in
     */
    protected function grant(array $data): void
    {
        $billable = static::findBillable($data['billable'] ?? null)
            ?? throw (new ModelNotFoundException)->setModel(MagicStarter::billableModel());
        $actor = Filament::auth()->user() ?? throw new AuthenticationException;
        $plan = (string) $data['plan'];
        $reason = (string) $data['reason'];
        $expiresAt = BillingFormFields::endOfDay($data['expires_at'] ?? null);

        ContractAction::run(
            $this,
            static fn (): mixed => app(AdministersBilling::class)->grant($actor, $billable, $plan, $reason, $expiresAt),
            'billing.grant_added',
            $billable,
            [
                'plan' => $plan,
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
        );

        $this->success();
    }
}
