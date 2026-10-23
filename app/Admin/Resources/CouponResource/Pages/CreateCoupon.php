<?php

namespace App\Admin\Resources\CouponResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\CouponResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCoupon extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = CouponResource::class;
}
