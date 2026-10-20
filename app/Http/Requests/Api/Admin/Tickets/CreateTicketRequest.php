<?php

namespace App\Http\Requests\Api\Admin\Tickets;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Ticket;
use App\Models\TicketDepartment;

class CreateTicketRequest extends AdminApiRequest
{
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
        $this->departmentByName();
        $this->mergeIfMissing([
            'priority' => 'medium', // Default priority to 'medium' if not provided
            'status' => 'open', // Default status to 'open' if not provided
        ]);
    }

    /** The department by its name too, as before the departments were a table: the API keeps working. */
    protected function departmentByName(): void
    {
        if ($this->filled('department') && !$this->has('department_id')) {
            $id = TicketDepartment::query()->where('name', (string) $this->input('department'))->value('id');
            $this->merge(['department_id' => $id ?? 0]);
        }
    }
}
