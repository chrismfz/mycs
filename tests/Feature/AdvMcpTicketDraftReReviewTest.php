<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Services\Mcp\Proposals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Independent re-review of the fix commit 7a280870 (#423, MCP ticket reply drafts). */
class AdvMcpTicketDraftReReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * RR-1: the fix strips format characters (bidi overrides) from the draft's message, but the proposal's summary also
     * carries the ticket's subject, which the customer wrote. A RIGHT-TO-LEFT OVERRIDE there (no closing PDF) reorders
     * everything after it in the summary: the message preview and «never sent by approving», as shown in Other → MCP
     * Proposals and returned to the agent. The summary should hold no \p{Cf} either.
     */
    public function test_rr1_the_summary_carries_no_format_characters_from_the_customers_subject(): void
    {
        $ticket = Ticket::factory()->create(['subject' => "Invoice question \u{202E}moc.live//:sptth"]);

        $proposal = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Pay at https://example.test'], $this->staff());

        $this->assertDoesNotMatchRegularExpression('/\p{Cf}/u', (string) $proposal->summary);
    }
}
