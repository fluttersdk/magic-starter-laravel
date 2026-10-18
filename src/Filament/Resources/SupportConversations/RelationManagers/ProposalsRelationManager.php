<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\RelationManagers;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Contracts\DecidesSupportProposals;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Filament\Support\BillingAuthorization;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Support\StripeBillingState;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The billing requests tab on the support thread page: what the account owner
 * asked the AI desk for, recorded as a proposal, and decided here by staff.
 *
 * A draft is never listed: its AI turn has not reached the customer yet. Every
 * decision goes through {@see DecidesSupportProposals}, the only path that runs
 * billing, as the signed-in staff member; nothing here writes the row or calls
 * the billing contract. The decisions are shown only to a staff member
 * {@see BillingAuthorization} allows and only in the status they apply to, and
 * Filament refuses to mount or call a hidden action, so both hold against a
 * crafted Livewire call; the contract re-checks both on its own.
 *
 * The customer's message and the AI rationale are untrusted text: they are
 * rendered as escaped plain text, never as HTML or Markdown, and labelled
 * unverified.
 */
class ProposalsRelationManager extends RelationManager
{
    /**
     * Shown for a fact a proposal does not carry.
     */
    protected const ABSENT = '-';

    protected static string $relationship = 'proposals';

    protected static bool $shouldSkipAuthorization = true;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('magic-starter::admin_support.proposals.title');
    }

    /**
     * Never read-only: the tab lives on the View page, where Filament would
     * otherwise hide the decisions, which are its whole purpose.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Sized to fit the panel's content width with the sidebar open, and a phone.
     *
     * The decisions share one menu behind a 44 px trigger, so Approve and Reject
     * are reached without scrolling the table sideways. Below `lg` only the
     * request, wrapped, and its status stay: the rest is repeated in the Details and
     * decision modals, and the status says when a request lapsed, since the
     * deadline column is hidden there.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->where('status', '!=', SupportProposalStatus::DRAFT->value)
                ->with('requester'))
            ->columns([
                TextColumn::make('action')
                    ->label(static::text('columns.action'))
                    ->formatStateUsing(static fn (SupportProposalAction $state): string => $state->label())
                    ->wrap(),
                TextColumn::make('params_summary')
                    ->label(static::text('columns.params'))
                    ->state(static fn (SupportProposal $record): ?string => static::paramsSummary($record))
                    ->placeholder(static::ABSENT)
                    ->visibleFrom('lg'),
                TextColumn::make('status')
                    ->label(static::text('columns.status'))
                    ->badge()
                    ->formatStateUsing(static fn (SupportProposalStatus $state): string => static::statusLabel($state))
                    ->color(static fn (SupportProposalStatus $state): string => static::statusColor($state))
                    ->description(static fn (SupportProposal $record): ?string => $record->isExpired()
                        ? static::text('labels.expired')
                        : null),
                TextColumn::make('requester_name')
                    ->label(static::text('columns.requested_by'))
                    ->state(static fn (SupportProposal $record): ?string => static::requesterLabel($record))
                    ->placeholder(static::ABSENT)
                    ->visibleFrom('lg'),
                TextColumn::make('created_at')
                    ->label(static::text('columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->visibleFrom('lg'),
                TextColumn::make('expires_at')
                    ->label(static::text('columns.expires_at'))
                    ->dateTime()
                    ->visibleFrom('lg'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    $this->viewAction(),
                    $this->approveAction(),
                    $this->rejectAction(),
                    $this->markFailedAction(),
                    $this->markAppliedAction(),
                ])
                    ->size(Size::ExtraLarge),
            ]);
    }

    /**
     * The facts beside a request, with no decision attached.
     */
    protected function viewAction(): Action
    {
        return Action::make('view')
            ->label(static::text('decisions.view.label'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(static fn (SupportProposal $record): string => $record->action->label())
            ->schema(fn (SupportProposal $record): array => $this->details($record))
            ->modalSubmitAction(false);
    }

    /**
     * The approval runs billing, so the modal repeats every fact the staff
     * member decides on. It opts out of the panel's database transaction: the
     * contract refuses to run inside one, since a rollback would hide a billing
     * call that already happened.
     */
    protected function approveAction(): Action
    {
        return $this->decisionAction('approve', Heroicon::OutlinedCheckCircle, SupportProposalStatus::PENDING)
            ->color('success')
            ->databaseTransaction(false)
            ->schema(fn (SupportProposal $record): array => $this->details($record))
            ->action(function (Action $action, SupportProposal $record): void {
                $this->decide(
                    $action,
                    $record,
                    'support.proposal_approved',
                    static fn (DecidesSupportProposals $decider, Authenticatable $staff, Panel $panel) => $decider
                        ->approve($staff, $record, $panel),
                );
            });
    }

    protected function rejectAction(): Action
    {
        return $this->decisionAction('reject', Heroicon::OutlinedXCircle, SupportProposalStatus::PENDING)
            ->color('danger')
            ->schema([
                $this->reasonField(),
            ])
            ->action(function (Action $action, SupportProposal $record, array $data): void {
                $reason = (string) $data['reason'];

                $this->decide(
                    $action,
                    $record,
                    'support.proposal_rejected',
                    static fn (DecidesSupportProposals $decider, Authenticatable $staff, Panel $panel) => $decider
                        ->reject($staff, $record, $panel, $reason),
                );
            });
    }

    /**
     * Closes an approval left running (a crash, or a Stripe call that never
     * answered), once staff checked by hand that the change did not apply.
     */
    protected function markFailedAction(): Action
    {
        return $this
            ->decisionAction('markFailed', Heroicon::OutlinedExclamationTriangle, SupportProposalStatus::EXECUTING)
            ->color('warning')
            ->schema([
                $this->reasonField(),
            ])
            ->action(function (Action $action, SupportProposal $record, array $data): void {
                $reason = (string) $data['reason'];

                $this->decide(
                    $action,
                    $record,
                    'support.proposal_marked_failed',
                    static fn (DecidesSupportProposals $decider, Authenticatable $staff, Panel $panel) => $decider
                        ->markFailed($staff, $record, $panel, $reason),
                );
            });
    }

    /**
     * Closes an approval left running whose change did apply, once staff
     * checked the billing events; billing is not called again.
     */
    protected function markAppliedAction(): Action
    {
        return $this
            ->decisionAction('markApplied', Heroicon::OutlinedCheckBadge, SupportProposalStatus::EXECUTING)
            ->color('success')
            ->schema([
                $this->reasonField(),
            ])
            ->action(function (Action $action, SupportProposal $record, array $data): void {
                $reason = (string) $data['reason'];

                $this->decide(
                    $action,
                    $record,
                    'support.proposal_marked_applied',
                    static fn (DecidesSupportProposals $decider, Authenticatable $staff, Panel $panel) => $decider
                        ->markApplied($staff, $record, $panel, $reason),
                );
            });
    }

    /**
     * The parts every decision shares: its labels, a confirmation, and
     * visibility that requires the billing permission, the status the decision
     * applies to, and an open deadline.
     *
     * @param  string  $name  the action name; its strings live under the snake_case key
     */
    protected function decisionAction(string $name, Heroicon $icon, SupportProposalStatus $status): Action
    {
        $key = 'decisions.' . str($name)->snake();

        return Action::make($name)
            ->label(static::text("{$key}.label"))
            ->icon($icon)
            ->requiresConfirmation()
            ->modalHeading(static fn (SupportProposal $record): string => static::text("{$key}.heading", [
                'action' => $record->action->label(),
            ]))
            ->modalDescription(static::text("{$key}.description"))
            ->successNotificationTitle(static::text("{$key}.success"))
            ->visible(fn (SupportProposal $record): bool => $record->status === $status
                && ! $record->isExpired()
                && $this->canDecide());
    }

    protected function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label(static::text('labels.reason'))
            ->required()
            ->maxLength(255);
    }

    /**
     * Run one decision through the contract as the signed-in staff member,
     * audited as `$event`. A refusal halts the action with its message.
     *
     * @param  Closure(DecidesSupportProposals, Authenticatable, Panel): mixed  $call
     *
     * @throws AuthenticationException when no panel user is signed in
     */
    protected function decide(Action $action, SupportProposal $record, string $event, Closure $call): void
    {
        $staff = Filament::auth()->user() ?? throw new AuthenticationException;
        $panel = Filament::getCurrentPanel() ?? throw new LogicException('A proposal is decided inside a panel.');

        ContractAction::run(
            $action,
            static fn (): mixed => $call(app(DecidesSupportProposals::class), $staff, $panel),
            $event,
            $record,
        );

        $action->success();
    }

    protected function canDecide(): bool
    {
        $staff = Filament::auth()->user();

        return $staff !== null && BillingAuthorization::allows($staff);
    }

    /**
     * What a staff member reads before deciding, all of it plain escaped text.
     *
     * @return list<TextEntry>
     */
    protected function details(SupportProposal $proposal): array
    {
        $entries = [
            TextEntry::make('customer_words')
                ->label(static::text('labels.customer_words'))
                ->state($proposal->triggerMessage?->body)
                ->placeholder(static::ABSENT),
            TextEntry::make('ai_rationale')
                ->label(static::text('labels.rationale'))
                ->state($proposal->rationale)
                ->placeholder(static::ABSENT),
        ];

        if ($proposal->action === SupportProposalAction::EXTEND_TRIAL) {
            $entries[] = TextEntry::make('trial_ends_at')
                ->label(static::text('labels.trial_ends_at'))
                ->state(static::trialEndsAt($proposal))
                ->dateTime()
                ->placeholder(static::ABSENT);
            $entries[] = TextEntry::make('prior_extensions')
                ->label(static::text('labels.prior_extensions'))
                ->state(static::priorTrialExtensions($proposal));
        }

        $entries[] = TextEntry::make('messages_since')
            ->label(static::text('labels.messages_since'))
            ->state(static::customerMessagesSince($proposal));

        return $entries;
    }

    /**
     * The action's own input in a few words, or null when it takes none.
     */
    protected static function paramsSummary(SupportProposal $proposal): ?string
    {
        $until = $proposal->params['until'] ?? null;

        if ($proposal->action !== SupportProposalAction::EXTEND_TRIAL || ! is_string($until)) {
            return null;
        }

        return static::text('labels.until', [
            'date' => $until,
        ]);
    }

    /**
     * Who asked: always the account owner when the request was recorded.
     */
    protected static function requesterLabel(SupportProposal $proposal): ?string
    {
        $requester = $proposal->requester;

        if ($requester === null) {
            return null;
        }

        return static::text('labels.owner', [
            'name' => (string) ($requester->getAttribute('name') ?: $requester->getAttribute('email')),
        ]);
    }

    /**
     * The billable's current trial end, read from Cashier's local subscription
     * only, since the table renders it on every open.
     */
    protected static function trialEndsAt(SupportProposal $proposal): mixed
    {
        $billable = $proposal->billable;

        if ($billable === null) {
            return null;
        }

        return StripeBillingState::defaultSubscription($billable)?->getAttribute('trial_ends_at');
    }

    /**
     * How often this account's trial was already moved, from the billing history.
     *
     * The key is compared as a string, which is how `billing_events` stores it.
     */
    protected static function priorTrialExtensions(SupportProposal $proposal): int
    {
        return BillingEvent::query()
            ->where('type', BillingEventType::TRIAL_EXTENDED->value)
            ->where('billable_type', $proposal->billable_type)
            ->where('billable_id', (string) $proposal->billable_id)
            ->count();
    }

    /**
     * How many messages the customer wrote after the request, which may have
     * changed or withdrawn it.
     */
    protected static function customerMessagesSince(SupportProposal $proposal): int
    {
        return SupportMessage::query()
            ->where('conversation_id', $proposal->conversation_id)
            ->where('sender_type', SupportSenderType::CUSTOMER->value)
            ->where('kind', SupportMessageKind::MESSAGE->value)
            ->where('created_at', '>', $proposal->created_at)
            ->count();
    }

    protected static function statusLabel(SupportProposalStatus $status): string
    {
        return static::text('statuses.' . $status->value);
    }

    protected static function statusColor(SupportProposalStatus $status): string
    {
        return match ($status) {
            SupportProposalStatus::PENDING => 'warning',
            SupportProposalStatus::EXECUTING => 'info',
            SupportProposalStatus::APPROVED => 'success',
            SupportProposalStatus::FAILED => 'danger',
            SupportProposalStatus::DRAFT,
            SupportProposalStatus::REJECTED,
            SupportProposalStatus::EXPIRED => 'gray',
        };
    }

    /**
     * @param  array<string, string>  $replace
     */
    protected static function text(string $key, array $replace = []): string
    {
        return (string) __('magic-starter::admin_support.proposals.' . $key, $replace);
    }
}
