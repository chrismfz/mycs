<?php

namespace App\Mcp\Tools;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Ops\NotFound;
use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('ticket')]
#[Description('One ticket with everything staff see: subject, status, priority, department, account or guest, assignee, service, CCs, merges, and its messages oldest first (who, when, internal note or not, how it came, the whole text up to 10,000 characters, secrets masked; attachments by name and size). By id or reference; without one, the latest tickets (status, account filters). Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class TicketTool extends McpTool
{
    public const MESSAGES = 100;

    public const MAX_MESSAGES = 500;

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Ticket id or reference (none: a list).'),
            'messages' => $schema->integer()->description('Latest messages to show (default 100, at most 500).'),
            'client' => $schema->integer()->description('For the list: one account\'s tickets.'),
            'status' => $schema->string()->description('For the list: one status (open, answered, customer-reply, closed…).'),
            'limit' => $schema->integer()->description('List size (default 20, at most 100).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.tickets.viewAny';
    }

    protected function run(Request $request): array
    {
        $key = trim((string) $request->get('ticket'));
        if ($key === '') {
            return ['tickets' => Ticket::query()->with(['department'])
                ->when(filled($request->get('status')), fn ($query) => $query->where('status', (string) $request->get('status')))
                ->when(filled($request->get('client')), fn ($query) => $query->where('client_id', (int) $request->get('client')))
                ->orderByDesc('id')->limit(self::limit($request, 20, 100))->get()
                ->map(fn (Ticket $ticket) => ['id' => $ticket->id, 'reference' => $ticket->reference, 'subject' => Scrub::text($ticket->subject),
                    'status' => $ticket->status, 'department' => $ticket->department?->name, 'client_id' => $ticket->client_id, 'updated_at' => $ticket->updated_at?->toDateTimeString()])->all()];
        }
        $ticket = Ticket::query()->with(['department', 'client', 'service.product', 'assignedTo', 'ccs'])
            ->where(fn ($query) => $query->where('reference', $key)->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key)))->first()
            ?? throw new NotFound("No ticket {$key}.");
        $shown = max(1, min(self::MAX_MESSAGES, (int) ($request->get('messages') ?? self::MESSAGES)));
        $messages = $ticket->messages()->with('attachments')->latest('id')->limit($shown)->get()->reverse()->values();

        return [
            'id' => $ticket->id, 'reference' => $ticket->reference, 'subject' => Scrub::text($ticket->subject), 'status' => $ticket->status,
            'priority' => $ticket->priority, 'department' => $ticket->department?->name, 'opened_via' => $ticket->opened_via,
            'account' => $ticket->client ? '#' . $ticket->client_id . ' ' . $ticket->client->name : null,
            'guest' => $ticket->client_id === null ? trim($ticket->guest_name . ' <' . $ticket->guest_email . '>') : null,
            'assigned_to' => $ticket->assignedTo instanceof Staff ? '#' . $ticket->assigned_to . ' ' . $ticket->assignedTo->name : null,
            'ccs' => $ticket->ccs->pluck('email')->all(), 'merged_into' => $ticket->merged_into_id, 'service' => $ticket->service ? '#' . $ticket->service_id . ' ' . ($ticket->service->product->name ?? '') : null,
            'created_at' => $ticket->created_at?->toDateTimeString(), 'updated_at' => $ticket->updated_at?->toDateTimeString(),
            'messages_total' => $ticket->messages()->count(),
            'messages' => $messages->map(fn (TicketMessage $message) => [
                'id' => $message->id, 'at' => $message->created_at?->toDateTimeString(),
                'from' => $message->staff_id ? 'staff #' . $message->staff_id : ($message->user_id ? 'user #' . $message->user_id : 'guest'),
                'internal' => (bool) $message->is_internal, 'via' => $message->via,
                'sender_email' => $message->sender_email,
                'text' => Scrub::text(mb_substr((string) $message->message, 0, 10000)),
                'attachments' => $message->attachments->map(fn ($file) => ['name' => $file->filename, 'bytes' => $file->filesize])->all(),
            ])->all(),
        ];
    }
}
