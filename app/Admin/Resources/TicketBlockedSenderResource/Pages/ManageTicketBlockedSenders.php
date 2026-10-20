<?php

namespace App\Admin\Resources\TicketBlockedSenderResource\Pages;

use App\Admin\Resources\TicketBlockedSenderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTicketBlockedSenders extends ManageRecords
{
    protected static string $resource = TicketBlockedSenderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth('staff')->id()])];
    }
}
