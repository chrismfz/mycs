<?php

namespace App\Admin\Resources\DomainTldResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\DomainTldResource;
use App\Exceptions\DisplayException;
use App\Models\DomainTld;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDomainTld extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = DomainTldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DomainTldResource::syncCostsAction(),
            // A TLD with domains is kept (D-18): refused with its message, not a 500.
            Action::make('delete')->label(__('Delete'))->color('danger')->icon('heroicon-o-trash')->requiresConfirmation()
                ->visible(fn (DomainTld $record) => DomainTldResource::canDelete($record))
                ->action(function (DomainTld $record, Action $action) {
                    try {
                        $record->delete();
                    } catch (\Exception $e) {
                        if (!$e instanceof DisplayException) {
                            throw $e;
                        }
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }
                    $action->redirect(DomainTldResource::getUrl('index'));
                }),
        ];
    }
}
