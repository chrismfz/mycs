<?php

namespace App\Admin\Resources\CouponResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\CouponResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCoupon extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->modalDescription('Services still on a running coupon get their full price back: they renew at it from their next invoice.'),
        ];
    }
}
