<?php

namespace App\Mcp\Tools;

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
            'ticket' => $schema->string()->description('The ticket\'s reference, or its id (#123 for the id only).')->required(),
            'message' => $schema->string()->description('The drafted reply (Markdown, at most ' . Proposals::DRAFT_MAX . ' characters).')->required(),
        ];
    }

    protected function run(Request $request): array
    {
        // Not «approve it in the admin»: a draft is used from its ticket, and never sent by approving (review of #423).
        return ['note' => 'Nothing was sent. The draft waits on the ticket\'s page; a member of staff uses it (changes and sends it) or discards it. `proposal` shows done once they took it into the reply box, which is not «sent».']
            + array_diff_key(parent::run($request), ['note' => 0, 'approve_at' => 0]);
    }

    protected function type(): string
    {
        return Proposals::TICKET_REPLY;
    }

    protected function payload(Request $request): array
    {
        return ['ticket_id' => TicketTool::find((string) $request->get('ticket'))->id, 'message' => (string) $request->get('message')];
    }
}
