<?php

namespace App\Mcp\Tools;

use App\Models\Ticket;
use App\Services\Mcp\Proposals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propose_ticket_reply')]
#[Description('PROPOSE (does not send) a reply to a ticket: a draft staff see on the ticket\'s page, take into the reply box with «Use the draft», change and send themselves, or discard. Nothing reaches the customer from here; approving it in MCP Proposals sends nothing either. Write it as staff would, in the ticket\'s language, in Markdown; never include secrets. Returns the proposal\'s id.')]
class ProposeTicketReplyTool extends ProposeTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('The ticket\'s id or reference.')->required(),
            'message' => $schema->string()->description('The drafted reply (Markdown, at most ' . Proposals::DRAFT_MAX . ' characters).')->required(),
        ];
    }

    protected function type(): string
    {
        return Proposals::TICKET_REPLY;
    }

    protected function payload(Request $request): array
    {
        $key = trim((string) $request->get('ticket'));
        $id = Ticket::query()->where('reference', $key)->value('id') ?? (ctype_digit($key) ? (int) $key : 0);

        return ['ticket_id' => (int) $id, 'message' => (string) $request->get('message')];
    }
}
