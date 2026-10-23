<?php

namespace App\Admin\Resources\OfferResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\OfferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOffer extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = OfferResource::class;
}
