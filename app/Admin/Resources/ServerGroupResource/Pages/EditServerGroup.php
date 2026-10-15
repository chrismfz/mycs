<?php

namespace App\Admin\Resources\ServerGroupResource\Pages;

use App\Admin\Resources\ServerGroupResource;
use App\Models\ServerGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditServerGroup extends EditRecord
{
    protected static string $resource = ServerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Not while products use it (review R3; the model refuses it too): a message, not an error page.
            DeleteAction::make()->before(function (DeleteAction $action, ServerGroup $record) {
                if (($products = $record->products()->count()) > 0) {
                    Notification::make()->danger()->title("Used by {$products} product(s)")->body('Choose another group for them first.')->send();
                    $action->cancel();
                }
            }),
        ];
    }
}
