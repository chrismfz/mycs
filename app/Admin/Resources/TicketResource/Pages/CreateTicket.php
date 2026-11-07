<?php

namespace App\Admin\Resources\TicketResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\TicketResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateTicket extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = TicketResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // One write: a ticket whose first message fails is not left behind without one.
        return DB::transaction(function () use ($data) {
            $record = static::getModel()::create($data);

            // Opened by staff for the customer: the first message is the staff member's.
            $record->messages()->create([
                'staff_id' => Auth::guard('staff')->id(),
                'message' => $data['message'],
            ]);

            return $record;
        });
    }
}
