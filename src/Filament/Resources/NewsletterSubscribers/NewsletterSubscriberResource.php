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
 * Newsletter subscribers and the consent each one rests on, with one write:
 * deactivating an active subscriber.
 *
 * Rows come from registration and the profile toggle, never from the panel.
 * Staff can withdraw a subscription but never restore one: re-activating
 * needs the person's own consent, so no action here turns a row back on.
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
        return (string) __('magic-starter::admin_misc.newsletter.navigation_label');
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
                TextColumn::make('consented_at')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.consented_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('consent_version')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.consent_version')),
                TextColumn::make('consent_source')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.consent_source')),
                TextColumn::make('unsubscribed_at')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.unsubscribed_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('magic-starter::admin_misc.newsletter.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('deactivate')
                    ->label(__('magic-starter::admin_misc.newsletter.deactivate'))
                    ->icon(Heroicon::OutlinedPause)
                    ->visible(static fn (NewsletterSubscriber $record): bool => $record->is_active)
                    ->action(static function (NewsletterSubscriber $record, Action $action): void {
                        ContractAction::run(
                            $action,
                            static function () use ($record): void {
                                $record->unsubscribe('staff');
                            },
                            'newsletter.subscriber_deactivated',
                            $record,
                        );
                    }),
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
