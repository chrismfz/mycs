<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\TicketMessages\CreateTicketMessageRequest;
use App\Http\Requests\Api\Admin\TicketMessages\DeleteTicketMessageRequest;
use App\Http\Requests\Api\Admin\TicketMessages\GetTicketMessageRequest;
use App\Http\Requests\Api\Admin\TicketMessages\GetTicketMessagesRequest;
use App\Http\Resources\TicketMessageResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(name: 'Ticket Messages', weight: 6)]
class TicketMessageController extends ApiController
{
    protected const INCLUDES = [
        'user',
        'staff',
        'ticket',
        'attachments',
    ];

    /**
     * List TicketMessages
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetTicketMessagesRequest $request)
    {
        // Fetch ticketMessages with pagination
        $ticketMessages = QueryBuilder::for(TicketMessage::class)
            ->allowedFilters('id', 'currency_code')
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->allowedSorts('id', 'created_at', 'updated_at', 'currency_code')
            ->simplePaginate(min(max((int) request('per_page', 15), 1), 100));

        // Return the ticketMessages as a JSON response
        return TicketMessageResource::collection($ticketMessages);
    }

    /**
     * Create a new ticketMessage
     */
    public function store(CreateTicketMessageRequest $request)
    {
        // A merged ticket takes nothing more: its thread goes on in the one it was merged into (docs/tickets.md §6κ).
        $merged = Ticket::query()->find($request->validated('ticket_id'))?->mergedInto;
        abort_if($merged !== null, 422, 'This ticket was merged into ticket #' . $merged?->reference . '.');

        // Validate and create the ticketMessage
        $ticketMessage = TicketMessage::create($request->validated());

        // Return the created ticketMessage as a JSON response
        return new TicketMessageResource($this->loadAllowedIncludes($ticketMessage, self::INCLUDES));
    }

    /**
     * Show a specific ticketMessage
     */
    public function show(GetTicketMessageRequest $request, TicketMessage $ticketMessage)
    {
        $ticketMessage = QueryBuilder::for(TicketMessage::class)
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->findOrFail($ticketMessage->id);

        // Return the ticketMessage as a JSON response
        return new TicketMessageResource($ticketMessage);
    }

    /**
     * Delete a specific ticketMessage
     */
    public function destroy(DeleteTicketMessageRequest $request, TicketMessage $ticketMessage)
    {
        // Delete the ticketMessage
        $ticketMessage->delete();

        return $this->returnNoContent();
    }
}
