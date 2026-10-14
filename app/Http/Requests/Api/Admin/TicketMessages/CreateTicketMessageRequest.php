<?php

namespace App\Http\Requests\Api\Admin\TicketMessages;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use Illuminate\Validation\Rule;

class CreateTicketMessageRequest extends AdminApiRequest
{
    protected $permission = 'ticket_messages.create';

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:5000',
            // A reply by the customer (user_id) or by a member of staff (staff_id), one of the two (docs/accounts.md, PR 1).
            'user_id' => 'nullable|required_without:staff_id|prohibits:staff_id|exists:users,id',
            // As the staff member whose key it is, never as another one.
            'staff_id' => ['nullable', 'required_without:user_id', Rule::in(array_filter([$this->attributes->get('api_key')?->staff_id]))],
            'ticket_id' => 'required|exists:tickets,id',
        ];
    }
}
