<?php

namespace App\Admin\Resources\IpPoolResource\Pages;

use App\Admin\Resources\IpPoolResource;
use App\Models\IpPool;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIpPool extends EditRecord
{
    protected static string $resource = IpPoolResource::class;

    protected function getHeaderActions(): array
    {
        // Only a pool none of whose addresses a service holds (its policy).
        return [DeleteAction::make()->visible(fn (IpPool $record) => (bool) auth()->user()?->can('delete', $record))];
    }
}
