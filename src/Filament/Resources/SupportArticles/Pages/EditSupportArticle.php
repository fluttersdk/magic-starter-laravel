<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\SupportArticleResource;
use FlutterSdk\MagicStarter\Models\SupportArticle;

class EditSupportArticle extends EditRecord
{
    use WritesThroughContracts;

    protected static string $resource = SupportArticleResource::class;

    /**
     * One approve action per locale, then the delete. The delete goes through the model so its
     * observer drops the cached corpus, which a query-level delete would skip.
     */
    protected function getHeaderActions(): array
    {
        return [
            ...array_map(SupportArticleResource::approveAction(...), SupportArticleResource::locales()),
            DeleteAction::make()
                ->using(static fn (SupportArticle $record): ?bool => $record->delete()),
        ];
    }
}
