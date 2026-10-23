<?php

namespace App\Admin\Resources\CurrencyResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\CurrencyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCurrency extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = CurrencyResource::class;
}
