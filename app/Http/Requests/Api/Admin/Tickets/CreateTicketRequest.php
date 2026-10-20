<?php

namespace App\Http\Requests\Api\Admin\Tickets;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Ticket;

class CreateTicketRequest extends AdminApiRequest
{
    use TakesOldTicketFields;

    protected $permission = 'tickets.create';

    public function rules(): array
    {
        return [
            'subject' => 'required|string|max:255',
            'client_id' => 'required|exists:clients,id',
            // A department of the table (docs/tickets.md §5); its name is still taken (prepareForValidation).
            'department_id' => 'nullable|integer|exists:ticket_departments,id',
            'priority' => 'required|string|in:low,medium,high',
            'status' => 'required|string|in:' . implode(',', array_keys(Ticket::statusOptions())),
        ];
    }

    public function prepareForValidation()
    {
        $this->takeOldTicketFields();
        $this->mergeIfMissing([
            'priority' => 'medium', // Default priority to 'medium' if not provided
            'status' => 'open', // Default status to 'open' if not provided
        ]);
    }
}
