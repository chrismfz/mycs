<?php

namespace App\Admin\Resources\DomainTldResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\DomainTldResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDomainTld extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = DomainTldResource::class;
}
