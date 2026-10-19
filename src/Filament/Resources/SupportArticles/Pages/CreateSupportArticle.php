<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages;

use Filament\Resources\Pages\CreateRecord;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\SupportArticleResource;

class CreateSupportArticle extends CreateRecord
{
    use WritesThroughContracts;

    protected static string $resource = SupportArticleResource::class;
}
