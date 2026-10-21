<?php

namespace App\Admin\Resources\BulkMailResource\Pages;

use App\Admin\Resources\BulkMailResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBulkMail extends CreateRecord
{
    protected static string $resource = BulkMailResource::class;

    /** A draft: staff preview, test and send it from its page. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
