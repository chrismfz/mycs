<?php

namespace App\Http\Requests\Api\Admin\Tickets;

use App\Models\Ticket;
use App\Models\TicketDepartment;
use Illuminate\Contracts\Validation\Validator;

/** What the API took before A1 (docs/tickets.md §6α), still taken: a department by name, «replied», «normal». */
trait TakesOldTicketFields
{
    protected function takeOldTicketFields(): void
    {
        if ($this->filled('department') && !$this->has('department_id')) {
            $id = TicketDepartment::query()->where('name', trim((string) $this->input('department')))->value('id');
            $this->merge(['department_id' => $id ?? 0]);
        }
        if ($this->input('status') === 'replied') {
            $this->merge(['status' => Ticket::STATUS_ANSWERED]);
        }
        if ($this->input('priority') === 'normal') {
            $this->merge(['priority' => 'medium']);
        }
    }

    /** An unknown department name is reported under «department», the field that was sent. */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->filled('department') && $validator->errors()->has('department_id')) {
            $validator->errors()->add('department', $validator->errors()->first('department_id'));
        }
        parent::failedValidation($validator);
    }
}
