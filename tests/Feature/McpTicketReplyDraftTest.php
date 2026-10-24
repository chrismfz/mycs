<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\McpProposal;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Mcp\Proposals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A reply an MCP agent drafts on a ticket (owner 2026-10-24): staff see it on the ticket, take it into the reply box,
 * change it and send it themselves. Nothing reaches the customer by proposing or approving it.
 */
class McpTicketReplyDraftTest extends TestCase
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

    private function propose(Staff $staff, string $ticket, string $message): array
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin', 'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'propose_ticket_reply',
                'arguments' => ['ticket' => $ticket, 'message' => $message]]])->assertOk()->json('result');
    }

    public function test_a_draft_is_proposed_and_nothing_is_sent(): void
    {
        $ticket = Ticket::factory()->create();
        $before = TicketMessage::query()->count();

        $result = $this->propose($this->staff(['*']), (string) $ticket->id, 'Hello, we unblocked your address.');

        $this->assertStringContainsString('"proposed": true', (string) ($result['content'][0]['text'] ?? ''));
        $proposal = McpProposal::query()->sole();
        $this->assertSame([Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Hello, we unblocked your address.']], [$proposal->type, $proposal->payload]);
        $this->assertSame($before, TicketMessage::query()->count(), 'nothing reaches the ticket by proposing');
    }

    public function test_approving_it_sends_nothing(): void
    {
        $ticket = Ticket::factory()->create();
        $staff = $this->staff(['*']);
        $proposal = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Draft'], $staff);

        $this->expectException(InvalidArgumentException::class);
        Proposals::approve($proposal, $staff);
    }

    public function test_staff_use_the_draft_change_it_and_send_it(): void
    {
        $ticket = Ticket::factory()->create();
        $staff = $this->staff(['*']);
        $proposal = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Drafted <b>text</b> [link](https://evil.example)'], $staff);

        $page = $this->livewireAs($staff)->test(EditTicket::class, ['record' => $ticket->id])
            ->assertSee('Drafted reply (MCP)')
            ->assertSee('[link](https://evil.example)', false) // the link as plain text, never a hidden one
            ->assertDontSee('<b>text</b>', false)
            ->call('useDraft', $proposal->id)
            ->assertSet('data.message', 'Drafted <b>text</b> [link](https://evil.example)');

        $this->assertSame(McpProposal::STATUS_DONE, $proposal->fresh()->status);
        $this->assertSame(0, $ticket->messages()->count(), 'still not sent');

        $page->set('data.message', 'Changed by staff')->call('send');
        $this->assertSame('Changed by staff', $ticket->messages()->sole()->message);
        $this->assertSame($staff->id, $ticket->messages()->sole()->staff_id);
    }

    public function test_a_discarded_draft_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $staff = $this->staff(['*']);
        $proposal = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Draft'], $staff);

        $this->livewireAs($staff)->test(EditTicket::class, ['record' => $ticket->id])->call('discardDraft', $proposal->id)->assertDontSee('Drafted reply (MCP)');

        $this->assertSame(McpProposal::STATUS_REJECTED, $proposal->fresh()->status);
    }

    public function test_staff_who_may_not_reply_neither_see_nor_use_it(): void
    {
        $ticket = Ticket::factory()->create();
        $proposal = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Secret draft'], $this->staff(['*']));
        $viewer = $this->staff(['admin.tickets.viewAny', 'admin.tickets.view']);

        $this->livewireAs($viewer)->test(EditTicket::class, ['record' => $ticket->id])->assertDontSee('Secret draft')->call('useDraft', $proposal->id);

        $this->assertSame(McpProposal::STATUS_PENDING, $proposal->fresh()->status);
    }

    public function test_an_empty_draft_or_a_merged_ticket_is_refused(): void
    {
        $staff = $this->staff(['*']);
        $merged = Ticket::factory()->create(['merged_into_id' => Ticket::factory()->create()->id]);

        foreach ([[Ticket::factory()->create()->id, '   '], [$merged->id, 'Draft'], [999999, 'Draft']] as [$id, $message]) {
            try {
                Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $id, 'message' => $message], $staff);
                $this->fail("proposed on #{$id}");
            } catch (\RuntimeException|InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_ticket_holds_a_few_drafts_at_most(): void
    {
        $ticket = Ticket::factory()->create();
        for ($i = 1; $i <= Proposals::DRAFTS_PER_TICKET; $i++) {
            Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => "Draft {$i}"], $this->staff(['*']));
        }

        $this->expectException(InvalidArgumentException::class);
        Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'One more'], $this->staff(['*']));
    }

    public function test_a_merged_ticket_shows_no_drafts(): void
    {
        $ticket = Ticket::factory()->create();
        Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => 'Draft'], $this->staff(['*']));
        $ticket->forceFill(['merged_into_id' => Ticket::factory()->create()->id])->save();

        $this->assertCount(0, Proposals::draftsFor($ticket->id));
    }
}
