<?php

namespace App\Http\Requests\Api\Admin\Tickets;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Ticket;
use App\Models\TicketDepartment;

class UpdateTicketRequest extends AdminApiRequest
{
    protected $permission = 'tickets.update';

    public function prepareForValidation(): void
    {
        $this->departmentByName();
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

    /** The department by its name too, as before the departments were a table: the API keeps working. */
    protected function departmentByName(): void
    {
        if ($this->filled('department') && !$this->has('department_id')) {
            $id = TicketDepartment::query()->where('name', (string) $this->input('department'))->value('id');
            $this->merge(['department_id' => $id ?? 0]);
        }
    }
}
