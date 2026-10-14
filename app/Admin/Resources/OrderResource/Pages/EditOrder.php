<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Models\Order;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(function (DeleteAction $action, Order $record) {
                if ($record->services()->exists()) {
                    Notification::make()->danger()->title('Whoops!')->body(__('This order has services: delete them first.'))->send();
                    $action->cancel();
                }
            }),
        ];
    }
}
