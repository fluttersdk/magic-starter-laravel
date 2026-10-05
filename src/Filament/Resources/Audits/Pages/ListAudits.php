<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages;

use Filament\Resources\Pages\ListRecords;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\AuditResource;

class ListAudits extends ListRecords
{
    protected static string $resource = AuditResource::class;
}
