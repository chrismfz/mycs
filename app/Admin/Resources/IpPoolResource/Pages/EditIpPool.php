<?php

namespace App\Admin\Resources\IpPoolResource\Pages;

use App\Admin\Resources\IpPoolResource;
use App\Models\IpPool;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditIpPool extends EditRecord
{
    protected static string $resource = IpPoolResource::class;

    /** Under the pools' write lock (IpPool::writing). */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return IpPool::writing(fn () => parent::handleRecordUpdate($record, $data));
    }

    protected function getHeaderActions(): array
    {
        // Only a pool none of whose addresses a service holds (its policy).
        // Under its addresses' lock: one given while the page was open stops it (round 2 of #505).
        return [DeleteAction::make()->visible(fn (IpPool $record) => (bool) auth()->user()?->can('delete', $record))
            ->using(fn (IpPool $record) => DB::transaction(fn () => !$record->addresses()->whereNotNull('service_id')->lockForUpdate()->exists() && $record->delete()))];
    }
}
