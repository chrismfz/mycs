<?php

namespace App\Admin\Resources\ServiceCancellationResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ServiceCancellationResource;
use App\Models\Service;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceCancellation extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ServiceCancellationResource::class;

    /** The service of the same unpaid order that keeps a «Requires» discount without this one (S7-CLIENT-PORTAL-1): staff are told. */
    private ?Service $keepsDiscount = null;

    protected function beforeCreate(): void
    {
        $this->keepsDiscount = Service::query()->find($this->data['service_id'] ?? null)?->neededByOrderPromotion();
    }

    protected function afterCreate(): void
    {
        if ($this->keepsDiscount !== null) {
            Notification::make()->warning()->persistent()->title('A discount depended on this service')
                ->body("Service #{$this->keepsDiscount->id} of the same unpaid order has a «Requires» discount for this product and keeps it on the order's invoice: cancel it too, or correct its line.")
                ->send();
        }
    }
}
