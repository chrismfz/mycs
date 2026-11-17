<?php

namespace App\Http\Requests\Api\Admin\TicketMessages;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\ClientUser;
use App\Models\Ticket;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * A reply as a customer only by a login of the ticket's own account that may use tickets there (audit 2026-11-17):
     * any user id on any ticket put another account's name and avatar in a customer's thread. A guest's ticket has no
     * login to reply as.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $userId = $this->input('user_id');
            if ($userId === null || $validator->errors()->isNotEmpty()) {
                return;
            }
            $clientId = Ticket::query()->whereKey($this->input('ticket_id'))->value('client_id');
            if ($clientId === null || ClientUser::liveGrant((int) $userId, (int) $clientId)?->allows('tickets') !== true) {
                $validator->errors()->add('user_id', 'This user is not a login of the ticket\'s account with access to its tickets.');
            }
        }];
    }
}
