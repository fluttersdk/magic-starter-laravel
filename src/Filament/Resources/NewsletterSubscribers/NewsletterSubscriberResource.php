<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\NewsletterSubscribers;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;

/**
 * Newsletter subscribers, with one write: switching a subscriber on or off.
 *
 * No contract owns that flag, so the toggle updates the column directly. The
 * rows are created by the public subscribe endpoint, never from the panel.
 */
class NewsletterSubscriberResource extends MagicStarterResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static function resolveModel(): string
    {
        return NewsletterSubscriber::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('magic-starter::admin_misc.newsletter.plural_model_label');
    }

    public static function getModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.newsletter.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('magic-starter::admin_misc.newsletter.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.email'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.is_active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.source'))
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(static fn (NewsletterSubscriber $record): string => $record->is_active
                        ? __('magic-starter::admin_misc.newsletter.toggle.deactivate')
                        : __('magic-starter::admin_misc.newsletter.toggle.activate'))
                    ->icon(static fn (NewsletterSubscriber $record): Heroicon => $record->is_active
                        ? Heroicon::OutlinedPause
                        : Heroicon::OutlinedPlay)
                    ->action(static fn (NewsletterSubscriber $record, Action $action): mixed => ContractAction::run(
                        $action,
                        static fn (): bool => $record->update(['is_active' => ! $record->is_active]),
                        'newsletter.subscriber_toggled',
                        $record,
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsletterSubscribers::route('/'),
        ];
    }
}
