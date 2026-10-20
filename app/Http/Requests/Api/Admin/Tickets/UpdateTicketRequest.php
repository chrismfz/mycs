<?php

namespace App\Http\Requests\Api\Admin\Tickets;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Ticket;

class UpdateTicketRequest extends AdminApiRequest
{
    use TakesOldTicketFields;

    protected $permission = 'tickets.update';

    public function prepareForValidation(): void
    {
        $this->takeOldTicketFields();
    }

    public function rules(): array
    {
        return [
            'subject' => 'sometimes|required|string|max:255',
            'client_id' => 'sometimes|required|exists:clients,id',
            // A department of the table (docs/tickets.md §5); its name is still taken (prepareForValidation).
            'department_id' => 'sometimes|nullable|integer|exists:ticket_departments,id',
            'priority' => 'sometimes|required|string|in:low,medium,high',
            'status' => 'sometimes|required|string|in:' . implode(',', array_keys(Ticket::statusOptions())),
        ];
    }
}
