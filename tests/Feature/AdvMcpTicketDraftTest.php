<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\McpProposal;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Services\Mcp\Proposals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adversarial review of the MCP ticket reply drafts (propose_ticket_reply, «Use the draft»). */
class AdvMcpTicketDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function mcp(Staff $staff, string $tool, array $arguments): array
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin', 'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);
        $text = (string) ($this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
            ->assertOk()->json('result.content.0.text'));
        // The answer opens with a notice; the JSON follows.
        $json = json_decode(substr($text, (int) strpos($text, '{')), true);

        return is_array($json) ? $json : ['raw' => $text];
    }

    /**
     * ADV-1: a key that is one ticket's id and another's reference (myip's references are six digits, ids reach six
     * digits too). The agent lists tickets, gets A's `id` and drafts on it: the reference wins, so a reply written from
     * customer A's thread lands on customer B's ticket. An ambiguous key is refused.
     */
    public function test_adv1_an_id_that_is_another_tickets_reference_is_refused(): void
    {
        $staff = $this->staff(['*']);
        $read = Ticket::factory()->create(['subject' => 'Customer A']);
        $other = Ticket::factory()->create(['subject' => 'Customer B', 'reference' => (string) $read->id]);

        $listed = collect($this->mcp($staff, 'ticket', [])['tickets'] ?? [])->firstWhere('subject', 'Customer A');
        $this->assertSame($read->id, $listed['id'] ?? null);

        $this->mcp($staff, 'propose_ticket_reply', ['ticket' => (string) $listed['id'], 'message' => 'Dear A, about your invoice…']);

        $this->assertFalse(McpProposal::query()->get()->contains(fn (McpProposal $p) => $p->payload['ticket_id'] === $other->id),
            'the draft written for A (by its id) is on B\'s ticket');
    }

    /**
     * ADV-2: the draft is untrusted text (it may carry what a customer wrote), yet bidi overrides and zero-width
     * characters stay in it: in the <pre> staff read and in the reply box they reorder or hide what a link says, as
     * ChatText strips them for the chat.
     */
    public function test_adv2_format_characters_are_stripped_from_a_draft(): void
    {
        $ticket = Ticket::factory()->create();
        $draft = "See https://nixpal.example/\u{202E}moc.live\u{202C} and\u{200B}reset";

        $proposal = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => $draft], $this->staff(['*']));

        $this->assertDoesNotMatchRegularExpression('/\p{Cf}/u', (string) $proposal->payload['message']);
    }

    /**
     * ADV-3: the dedupe compares payloads with `==`: two different numeric drafts («100», «1e2») are «the same», so
     * the second agent's draft is silently the first one's.
     */
    public function test_adv3_two_different_drafts_are_two_proposals(): void
    {
        $ticket = Ticket::factory()->create();
        $staff = $this->staff(['*']);

        $first = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => '100'], $staff);
        $second = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => '1e2'], $staff);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('1e2', $second->payload['message']);
    }

    /**
     * ADV-4: drafts() is a public Livewire method, so `$wire.drafts()` returns its value to the browser: every
     * proposal model with its proposer, a Staff row (email and the rest), not only what the page shows.
     */
    public function test_adv4_drafts_is_not_returned_to_the_browser(): void
    {
        $ticket = Ticket::factory()->create();
        $proposer = $this->staff(['*']);
        Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Draft'], $proposer);

        $page = $this->livewireAs($this->staff(['*']))->test(EditTicket::class, ['record' => $ticket->id])->call('drafts');

        $this->assertStringNotContainsString($proposer->email, (string) json_encode($page->effects['returns'] ?? []));
    }
}
