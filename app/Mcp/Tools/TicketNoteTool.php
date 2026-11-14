<?php

namespace App\Mcp\Tools;

use App\Models\Staff;
use App\Models\TicketMessage;
use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * An internal note on a ticket, written at once (owner 2026-11-14: the one write that needs no approval). A note is for
 * staff only, as the ticket page's «Internal note»: never mailed, never shown to the customer, moves no status
 * (TicketMessageCreatedListener). Written as the key's member of staff, marked as the MCP's (`via` mcp and its first
 * line), its secrets masked; bounded in length and in how many a day per ticket, so a looping agent never buries one.
 */
#[Name('add_ticket_note')]
#[Description('Write an INTERNAL note on a ticket, now (no approval): staff only, never mailed, never shown to the customer, changes no status. For what you found (the account, its services, what the logs and modules say, what to check next). Written as your member of staff and marked as the MCP\'s. Never a reply to the customer (that is `propose_ticket_reply`) and never secrets (they are masked). Markdown, at most 5,000 characters; at most 10 a day per ticket.')]
class TicketNoteTool extends McpTool
{
    public const MAX = 5000;

    /** MCP notes on one ticket in 24 hours. */
    public const PER_DAY = 10;

    public const VIA = 'mcp';

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('The ticket\'s reference, or its id (#123 for the id only).')->required(),
            'note' => $schema->string()->description('The note (Markdown, at most ' . self::MAX . ' characters).')->required(),
        ];
    }

    protected function permission(): string
    {
        return 'admin.tickets.update';
    }

    protected function run(Request $request): array
    {
        $ticket = TicketTool::find((string) $request->get('ticket'));
        $staff = $request->user();
        // As the ticket page asks of who adds a note there (TicketPolicy::update).
        if (!$staff instanceof Staff || !Gate::forUser($staff)->allows('update', $ticket)) {
            throw new \InvalidArgumentException('Your role may not write on this ticket.');
        }
        if ($ticket->isMerged()) {
            throw new \InvalidArgumentException('This ticket was merged into another: write there.');
        }
        // Without format characters: a bidi override reorders what staff read (re-review of #423).
        $note = trim((string) preg_replace('/\p{Cf}/u', '', (string) $request->get('note')));
        if ($note === '') {
            throw new \InvalidArgumentException('The note is empty.');
        }
        if (mb_strlen($note) > self::MAX) {
            throw new \InvalidArgumentException('A note holds at most ' . self::MAX . ' characters.');
        }
        $today = TicketMessage::query()->where('ticket_id', $ticket->id)->where('via', self::VIA)->where('created_at', '>', now()->subDay())->count();
        if ($today >= self::PER_DAY) {
            throw new \InvalidArgumentException('This ticket already has ' . self::PER_DAY . ' MCP notes from the last 24 hours.');
        }

        $message = $ticket->messages()->create([
            'staff_id' => $staff->id,
            'message' => "**MCP note**\n\n" . Scrub::text($note),
            'is_internal' => true,
            'via' => self::VIA,
        ]);

        return ['written' => true, 'ticket' => $ticket->reference, 'message_id' => $message->id,
            'note' => 'An internal note: staff see it on the ticket; the customer does not, and nothing was mailed.'];
    }
}
