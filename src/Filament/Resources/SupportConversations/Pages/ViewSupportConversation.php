<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Contracts\ChangesSupportConversations;
use FlutterSdk\MagicStarter\Contracts\RepliesToSupport;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\SupportConversation;

/**
 * The thread page. Its header actions are how staff answer and triage, each one
 * a package contract run through {@see ContractAction} with the acting staff user.
 *
 * Opening the page marks the conversation seen by staff.
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
            $this->noteAction(),
            $this->assignAction(),
            $this->changeAction(),
        ];
    }

    protected function replyAction(): Action
    {
        return Action::make('reply')
            ->label(__('Reply'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->schema([
                $this->bodyField(__('Reply')),
            ])
            ->successNotificationTitle(__('Reply sent'))
            ->action(
                fn (array $data, Action $action) => $this->post($action, $data['body'], false, 'support.replied'),
            );
    }

    protected function noteAction(): Action
    {
        return Action::make('add_note')
            ->label(__('Add note'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalDescription(__('Only staff can read a note. The customer is never told.'))
            ->schema([
                $this->bodyField(__('Note')),
            ])
            ->successNotificationTitle(__('Note added'))
            ->action(
                fn (array $data, Action $action) => $this->post($action, $data['body'], true, 'support.note_added'),
            );
    }

    protected function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('Assign'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->fillForm(fn (): array => [
                'assignee_id' => $this->conversation()->assignee_id,
            ])
            ->schema([
                Select::make('assignee_id')
                    ->label(__('Assignee'))
                    ->options(static fn (): array => SupportConversationResource::staffOptions())
                    ->placeholder(__('Unassigned')),
            ])
            ->successNotificationTitle(__('Assignee saved'))
            ->action(fn (array $data, Action $action) => $this->change($action, [
                'assignee_id' => $data['assignee_id'] ?? null,
            ], 'support.assigned'));
    }

    protected function changeAction(): Action
    {
        return Action::make('change')
            ->label(__('Change status or priority'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->fillForm(fn (): array => [
                'status' => $this->conversation()->status->value,
                'priority' => $this->conversation()->priority->value,
            ])
            ->schema([
                Select::make('status')
                    ->label(__('Status'))
                    ->options(SupportConversationResource::enumOptions(SupportConversationStatus::cases()))
                    ->required()
                    ->selectablePlaceholder(false),
                Select::make('priority')
                    ->label(__('Priority'))
                    ->options(SupportConversationResource::enumOptions(SupportPriority::cases()))
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->successNotificationTitle(__('Conversation updated'))
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

    private function conversation(): SupportConversation
    {
        /** @var SupportConversation $conversation */
        $conversation = $this->getRecord();

        return $conversation;
    }
}
