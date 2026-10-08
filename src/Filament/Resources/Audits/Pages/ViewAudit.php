<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Audits\Pages;

use Filament\Resources\Pages\ViewRecord;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\AuditResource;

class ViewAudit extends ViewRecord
{
    protected static string $resource = AuditResource::class;

    /**
     * The record title alone, such as `Team updated`: a read-only page has no
     * other verb, so Filament's "View" prefix says nothing.
     */
    public function getTitle(): string
    {
        return (string) static::getResource()::getRecordTitle($this->getRecord());
    }
}
