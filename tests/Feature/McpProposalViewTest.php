<?php

namespace Tests\Feature;

use App\Admin\Resources\McpProposalResource\Pages\ListMcpProposals;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Services\Mcp\Proposals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** «View» on the proposals' list (owner 2026-11-19: the summary is cut): the whole draft, escaped, without leaving the list. */
class McpProposalViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_shows_a_drafted_reply_whole_and_escaped_and_sends_nothing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
        $this->actingAs($staff, 'staff');
        $ticket = Ticket::factory()->create(['subject' => 'Repository', 'reference' => '2026-11-12-506671']);
        $message = 'Hello Paulo, ' . str_repeat('please run the command below. ', 20) . "\n<script>alert(1)</script>\nTHE-LAST-LINE";
        $proposal = Proposals::propose(Proposals::TICKET_REPLY, ['ticket_id' => $ticket->id, 'message' => $message], $staff);
        $this->assertStringNotContainsString('THE-LAST-LINE', $proposal->summary, 'the summary is cut: the reason for View');
        // Owner 2026-11-19: the ticket as staff search for it, its reference with its id.
        $this->assertStringStartsWith("Draft reply on ticket 2026-11-12-506671 (#{$ticket->id}) «Repository»", $proposal->summary);

        Livewire::test(ListMcpProposals::class)
            ->mountTableAction('view', $proposal)
            ->assertMountedActionModalSee('THE-LAST-LINE')
            ->assertMountedActionModalSee("2026-11-12-506671 (#{$ticket->id})")
            ->assertMountedActionModalSeeHtml(e('<script>alert(1)</script>'))
            ->assertMountedActionModalDontSeeHtml('<script>alert(1)</script>');

        $this->assertSame('pending', $proposal->fresh()->status, 'viewing decides nothing');
    }
}
