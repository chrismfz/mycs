<?php

namespace Tests\Feature;

use App\Admin\Resources\McpProposalResource\Pages\ListMcpProposals;
use App\Mail\ResentMail;
use App\Mcp\Servers\MycsServer;
use App\Mcp\Tools\ProposalTool;
use App\Mcp\Tools\ProposeResendMailTool;
use App\Mcp\Tools\ProposeResolveModuleActionTool;
use App\Mcp\Tools\ProposeRetryModuleActionTool;
use App\Mcp\Tools\ServerTestTool;
use App\Models\EmailLog;
use App\Models\McpProposal;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mcp\Proposals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What the MCP may only propose (docs/mcp.md): a propose_* tool changes nothing and files the request; staff approve
 * (it runs, once, with their permission checked again and the state checked again) or reject in the admin within a day.
 */
class McpProposalsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private ModuleAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $product = $this->createProduct();
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1]);
        $this->action = new ModuleAction;
        $this->action->forceFill(['service_id' => $this->service->id, 'action' => 'suspend', 'status' => ModuleAction::STATUS_OPEN,
            'attempts' => 4, 'given_up_at' => now(), 'last_error' => 'Timeout'])->save();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_a_proposal_changes_nothing_and_the_same_request_is_one_proposal(): void
    {
        $staff = $this->staff();

        MycsServer::actingAs($staff, 'staff')->tool(ProposeRetryModuleActionTool::class, ['module_action_id' => $this->action->id])
            ->assertOk()->assertSee('"proposed": true')->assertSee('"done": false')->assertSee('MCP Proposals');
        MycsServer::actingAs($staff, 'staff')->tool(ProposeRetryModuleActionTool::class, ['module_action_id' => $this->action->id])->assertOk();

        $proposal = McpProposal::query()->sole();
        $this->assertSame([McpProposal::STATUS_PENDING, $staff->id], [$proposal->status, $proposal->proposed_by]);
        $this->assertSame([ModuleAction::STATUS_OPEN, 4], [$this->action->fresh()->status, $this->action->fresh()->attempts], 'nothing ran');
    }

    public function test_a_retry_is_proposed_only_once_the_queue_gave_up_and_only_by_who_may(): void
    {
        $this->action->update(['given_up_at' => null]);
        MycsServer::actingAs($this->staff(), 'staff')->tool(ProposeRetryModuleActionTool::class, ['module_action_id' => $this->action->id])
            ->assertHasErrors(['still being retried by the queue']);

        MycsServer::actingAs($this->staff(['admin.mcp.use', 'admin.services.viewAny']), 'staff')
            ->tool(ProposeResolveModuleActionTool::class, ['module_action_id' => $this->action->id])->assertHasErrors();
        $this->assertSame(0, McpProposal::query()->count());
    }

    public function test_staff_approve_in_the_admin_it_runs_once_and_its_result_is_kept(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $proposal = Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $this->action->id], $this->staff());
        $approver = $this->staff(['admin.mcp.use', 'admin.services.update']);

        $this->livewireAs($approver)->test(ListMcpProposals::class)->callTableAction('approve', $proposal);

        $proposal->refresh();
        $this->assertSame([McpProposal::STATUS_DONE, $approver->id, 'Marked resolved.'], [$proposal->status, $proposal->decided_by, $proposal->result]);
        $this->assertSame([ModuleAction::STATUS_RESOLVED, $approver->id], [$this->action->fresh()->status, $this->action->fresh()->resolved_by_staff_id]);

        $this->expectException(InvalidArgumentException::class);
        Proposals::approve($proposal, $approver); // twice: no
    }

    public function test_the_state_and_the_approver_are_checked_again(): void
    {
        $proposal = Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $this->action->id], $this->staff());

        try {
            Proposals::approve($proposal, $this->staff(['admin.mcp.use']));
            $this->fail('an approver without the permission');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(McpProposal::STATUS_PENDING, $proposal->fresh()->status);

        $this->action->markResolved(null); // fixed meanwhile
        $done = Proposals::approve($proposal, $this->staff());
        $this->assertSame(McpProposal::STATUS_FAILED, $done->status);
        $this->assertStringContainsString('not open', (string) $done->result);
    }

    public function test_a_retry_approved_runs_the_admins_retry(): void
    {
        $proposal = Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $this->action->id], $this->staff());

        $done = Proposals::approve($proposal, $this->staff());

        // The service is active: the suspend has nothing to do, as the admin's Retry says.
        $this->assertSame([McpProposal::STATUS_DONE], [$done->status]);
        $this->assertStringContainsString('Nothing to do', (string) $done->result);
    }

    public function test_rejected_or_expired_never_runs(): void
    {
        $staff = $this->staff();
        $rejected = Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $this->action->id], $staff);
        Proposals::reject($rejected, $staff);
        $this->assertSame(McpProposal::STATUS_REJECTED, $rejected->fresh()->status);

        $late = Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $this->action->id], $staff);
        $late->update(['expires_at' => now()->subMinute()]);
        try {
            Proposals::approve($late, $staff);
            $this->fail('an expired proposal ran');
        } catch (InvalidArgumentException) {
        }
        $this->artisan('app:cron-job');
        $this->assertSame(McpProposal::STATUS_EXPIRED, $late->fresh()->status);
        $this->assertSame(ModuleAction::STATUS_OPEN, $this->action->fresh()->status);
    }

    public function test_a_resend_is_queued_on_approval_as_the_approvers(): void
    {
        Mail::fake();
        $log = EmailLog::query()->create(['subject' => 'Invoice 7', 'to' => 'owner@example.test', 'body' => '<p>x</p>', 'status' => EmailLog::STATUS_FAILED,
            'client_id' => $this->service->client_id]);
        $proposer = $this->staff();

        MycsServer::actingAs($proposer, 'staff')->tool(ProposeResendMailTool::class, ['email_log_id' => $log->id, 'to' => 'other@example.test'])
            ->assertOk()->assertSee('other@example.test');
        Mail::assertNothingQueued();

        $approver = $this->staff(['admin.mcp.use', 'admin.email_logs.resend']);
        $done = Proposals::approve(McpProposal::query()->sole(), $approver);

        $this->assertSame(McpProposal::STATUS_DONE, $done->status);
        $resend = EmailLog::query()->where('resent_from_id', $log->id)->sole();
        $this->assertSame(['other@example.test', $approver->id], [$resend->to, $resend->staff_id]);
        Mail::assertQueued(ResentMail::class);

        MycsServer::actingAs($proposer, 'staff')->tool(ProposalTool::class, ['proposal_id' => $done->id])->assertOk()->assertSee('"status": "done"');
        $onlooker = $this->staff(['admin.mcp.use']);
        MycsServer::actingAs($onlooker, 'staff')->tool(ProposalTool::class, ['proposal_id' => $done->id])->assertHasErrors(['No such proposal you may follow.']);
    }

    public function test_server_test_lists_and_tests_without_changing_anything(): void
    {
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $staff = $this->staff();

        MycsServer::actingAs($staff, 'staff')->tool(ServerTestTool::class)->assertOk()->assertSee('"name": "Licensing"');
        MycsServer::actingAs($staff, 'staff')->tool(ServerTestTool::class, ['server' => (string) $server->id])->assertOk()->assertSee('"server": "Licensing"');
        MycsServer::actingAs($this->staff(['admin.mcp.use', 'admin.servers.viewAny']), 'staff')->tool(ServerTestTool::class)->assertHasErrors();
    }
}
