<?php

namespace App\Admin\Resources\DomainResource\Pages;

use App\Admin\Resources\DomainResource;
use App\Models\Domain;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The registrar's truth now (docs/domains.md D-9): reads the registrar, changes nothing there.
            Action::make('sync')->label(__('Sync now'))->icon('heroicon-o-arrow-path')
                ->visible(fn (Domain $record) => (bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.update') && app(DomainSyncService::class)->isSyncable($record))
                ->action(function (Domain $record) {
                    try {
                        $changes = app(DomainSyncService::class)->sync($record);
                        app(DomainLifecycle::class)->advance($record->refresh());
                        Notification::make()->success()->title(__('Synced.'))
                            ->body($changes === [] ? __('Nothing changed.') : implode(', ', array_keys($changes)))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('The registrar could not be read.'))->body($e->getMessage())->send();
                    }
                    $this->refreshFormData([]);
                }),
        ];
    }
}
