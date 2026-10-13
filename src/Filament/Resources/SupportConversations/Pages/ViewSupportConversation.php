<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Contracts\ChangesSupportConversations;
use FlutterSdk\MagicStarter\Contracts\RepliesToSupport;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Jobs\PrepareSupportStaffAssist;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssistCache;

/**
 * The thread page. Its header actions are how staff answer and triage, each one
 * a package contract run through {@see ContractAction} with the acting staff user.
 *
 * Opening the page marks the conversation seen by staff.
 *
 * Draft reply and Summarize exist only while the application binds
 * {@see AssistsSupportStaff}. Neither calls it: Draft reply reads the cache when
 * the modal opens and queues {@see PrepareSupportStaffAssist} when there is
 * nothing to read, and the draft only pre-fills a form staff still submit.
 */
class ViewSupportConversation extends ViewRecord
{
    protected static string $resource = SupportConversationResource::class;

    /**
     * Stamped without model events: reading a thread is not an edit, and an
     * audit row per page view would bury the ones that are.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->conversation()->updateQuietly([
            'staff_seen_at' => now(),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->replyAction(),
            $this->draftReplyAction(),
            $this->summarizeAction(),
            $this->noteAction(),
            $this->assignAction(),
            $this->changeAction(),
        ];
    }

    protected function replyAction(): Action
    {
        return $this->composeReplyAction('reply', 'reply')->color('success');
    }

    /**
     * The reply form, opened with the cached draft. A miss opens no form: it asks for a draft in the background and
     * says so, since an empty draft form is only Reply under another name.
     */
    protected function draftReplyAction(): Action
    {
        return $this->composeReplyAction('draft_reply', 'draft_reply')
            ->icon(Heroicon::OutlinedSparkles)
            ->color(Color::Violet)
            ->visible(fn (): bool => $this->assistIsBound())
            ->fillForm(fn (): array => [
                'body' => $this->cachedDraft(),
            ]);
    }

    protected function summarizeAction(): Action
    {
        return Action::make('summarize')
            ->label((string) __('magic-starter::admin_support.actions.summarize.label'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color(Color::Violet)
            ->visible(fn (): bool => $this->assistIsBound())
            ->action(function (): void {
                $this->queueAssist();

                Notification::make()
                    ->title((string) __('magic-starter::admin_support.ai.summarize_queued'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The reply form both Reply and Draft reply submit: one contract call, one audit event.
     *
     * @param  string  $name  The action name.
     * @param  string  $labelKey  The `admin_support.actions.<key>` the button reads; the form copy is Reply's.
     */
    protected function composeReplyAction(string $name, string $labelKey): Action
    {
        return Action::make($name)
            ->label((string) __("magic-starter::admin_support.actions.{$labelKey}.label"))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->schema([
                $this->bodyField((string) __('magic-starter::admin_support.actions.reply.body')),
            ])
            ->successNotificationTitle((string) __('magic-starter::admin_support.actions.reply.sent'))
            ->action(
                fn (array $data, Action $action) => $this->post($action, $data['body'], false, 'support.replied'),
            );
    }

    protected function noteAction(): Action
    {
        return Action::make('add_note')
            ->label((string) __('magic-starter::admin_support.actions.note.label'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalDescription((string) __('magic-starter::admin_support.actions.note.description'))
            ->schema([
                $this->bodyField((string) __('magic-starter::admin_support.actions.note.body')),
            ])
            ->successNotificationTitle((string) __('magic-starter::admin_support.actions.note.added'))
            ->action(
                fn (array $data, Action $action) => $this->post($action, $data['body'], true, 'support.note_added'),
            );
    }

    protected function assignAction(): Action
    {
        return Action::make('assign')
            ->label((string) __('magic-starter::admin_support.actions.assign.label'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->fillForm(fn (): array => [
                'assignee_id' => $this->conversation()->assignee_id,
            ])
            ->schema([
                Select::make('assignee_id')
                    ->label(SupportConversationResource::fieldLabel('assignee'))
                    ->options(static fn (): array => SupportConversationResource::staffOptions())
                    ->placeholder((string) __('magic-starter::admin_support.actions.assign.unassigned')),
            ])
            ->successNotificationTitle((string) __('magic-starter::admin_support.actions.assign.saved'))
            ->action(fn (array $data, Action $action) => $this->change($action, [
                'assignee_id' => $data['assignee_id'] ?? null,
            ], 'support.assigned'));
    }

    protected function changeAction(): Action
    {
        return Action::make('change')
            ->label((string) __('magic-starter::admin_support.actions.change.label'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->fillForm(fn (): array => [
                'status' => $this->conversation()->status === SupportConversationStatus::PENDING
                    ? null
                    : $this->conversation()->status->value,
                'priority' => $this->conversation()->priority->value,
            ])
            ->schema([
                Select::make('status')
                    ->label(SupportConversationResource::fieldLabel('status'))
                    ->options(SupportConversationResource::changeableStatusOptions())
                    ->required()
                    ->selectablePlaceholder(false),
                Select::make('priority')
                    ->label(SupportConversationResource::fieldLabel('priority'))
                    ->options(SupportConversationResource::enumOptions(SupportPriority::cases()))
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->successNotificationTitle((string) __('magic-starter::admin_support.actions.change.updated'))
            ->action(fn (array $data, Action $action) => $this->change($action, [
                'status' => $data['status'],
                'priority' => $data['priority'],
            ], 'support.changed'));
    }

    protected function bodyField(string $label): Textarea
    {
        return Textarea::make('body')
            ->label($label)
            ->required()
            ->rows(6);
    }

    /**
     * A reply, or an internal note, as the acting staff user.
     */
    protected function post(Action $action, string $body, bool $note, string $event): void
    {
        ContractAction::run(
            $action,
            fn () => app(RepliesToSupport::class)->reply(TeamResource::actor(), $this->conversation(), $body, $note),
            $event,
            $this->conversation(),
        );

        $action->success();
    }

    /**
     * @param  array{status?: string, priority?: string, assignee_id?: string|int|null}  $changes
     */
    protected function change(Action $action, array $changes, string $event): void
    {
        ContractAction::run(
            $action,
            fn () => app(ChangesSupportConversations::class)->change(
                TeamResource::actor(),
                $this->conversation(),
                $changes,
            ),
            $event,
            $this->conversation(),
            $changes,
        );

        $action->success();
    }

    /**
     * The stored draft for this turn. With none stored, a draft is queued, staff are told, and the form is cancelled
     * before it opens; nothing waits on a model.
     *
     * @throws Cancel on a miss, which Filament takes as "do not mount".
     */
    private function cachedDraft(): string
    {
        $stored = app(SupportStaffAssistCache::class)->get($this->conversation());

        if ($stored !== null) {
            return $stored['draft'];
        }

        $this->queueAssist();

        Notification::make()
            ->title((string) __('magic-starter::admin_support.ai.draft_queued'))
            ->info()
            ->send();

        throw new Cancel;
    }

    /**
     * Queue one assist for this turn; a second ask while one is queued or running adds nothing.
     */
    private function queueAssist(): void
    {
        if (app(SupportStaffAssistCache::class)->claim($this->conversation())) {
            PrepareSupportStaffAssist::dispatch($this->conversation()->getKey());
        }
    }

    private function assistIsBound(): bool
    {
        return app()->bound(AssistsSupportStaff::class);
    }

    private function conversation(): SupportConversation
    {
        /** @var SupportConversation $conversation */
        $conversation = $this->getRecord();

        return $conversation;
    }
}
