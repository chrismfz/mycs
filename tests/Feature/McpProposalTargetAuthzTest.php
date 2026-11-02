<?php

namespace Tests\Feature;

use App\Admin\Resources\McpProposalResource\Pages\ListMcpProposals;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\McpProposal;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Policies\ModuleActionPolicy;
use App\Services\Mcp\Proposals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Predeploy R1 (the reviewer's two tests, and two more): an MCP proposal is asked for and decided only by who may do
 * what it asks on its target, as the admin page would allow (a domain's module action: the domains' permission).
 */
class McpProposalTargetAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Review role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function domainAction(): ModuleAction
    {
        $domain = Domain::factory()->create(['status' => DomainStatus::PendingRegister]);

        return ModuleAction::query()->create(['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_REGISTER,
            'status' => ModuleAction::STATUS_OPEN, 'attempts' => 1, 'last_error' => 'Manual registrar: staff must register it']);
    }

    public function test_staff_without_domain_permission_cannot_propose_a_domain_action(): void
    {
        $staff = $this->staff(['admin.mcp.use', 'admin.services.update']);
        $action = $this->domainAction();
        $this->assertFalse((new ModuleActionPolicy)->update($staff, $action));
        try {
            Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $action->id], $staff);
            $this->fail('proposed without the domains\' permission');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, McpProposal::query()->count(), 'The MCP path must honor the domain action policy');
    }

    public function test_approval_ui_cannot_resolve_domain_action_without_domain_permission(): void
    {
        $action = $this->domainAction();
        $proposal = Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $action->id], $this->staff(['*']));
        $restricted = $this->staff(['admin.mcp.use', 'admin.services.update']);
        $this->assertFalse((new ModuleActionPolicy)->update($restricted, $action));
        // Hidden to them (it was offered and ran before R1's fix); nothing changes.
        $this->livewireAs($restricted)->test(ListMcpProposals::class)->assertTableActionHidden('approve', $proposal);
        $this->assertSame(DomainStatus::PendingRegister, $action->domain->fresh()->status,
            'Approving a proposal must not let service-only staff register/activate a domain');
        $this->assertSame(ModuleAction::STATUS_OPEN, $action->fresh()->status);
    }

    /** A role changed between the proposal and its approval: read again when it is approved. */
    public function test_approval_reads_the_approvers_role_again(): void
    {
        $action = $this->domainAction();
        $proposal = Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $action->id], $this->staff(['*']));
        $approver = $this->staff(['admin.mcp.use', 'admin.services.update', 'admin.domains.renew']);
        $this->assertTrue($approver->can('decide', $proposal));
        $approver->role->update(['permissions' => ['admin.mcp.use', 'admin.services.update']]);
        $approver->refresh();

        $this->assertFalse($approver->can('decide', $proposal->fresh()));
        try {
            Proposals::approve($proposal->fresh(), $approver);
            $this->fail('approved by a role that lost the domains\' permission');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(McpProposal::STATUS_PENDING, $proposal->fresh()->status);
    }

    /** A service's action stays the services\' (nothing more asked of it). */
    public function test_a_service_action_still_needs_only_the_services_permission(): void
    {
        $staff = $this->staff(['admin.mcp.use', 'admin.services.update']);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $action = ModuleAction::query()->create(['service_id' => $service->id, 'action' => 'create', 'status' => ModuleAction::STATUS_OPEN,
            'attempts' => 4, 'given_up_at' => now(), 'last_error' => 'down']);

        $this->assertTrue(Proposals::allows($staff, Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $action->id]));
    }
}
