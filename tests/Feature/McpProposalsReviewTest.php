<?php

namespace Tests\Feature;

use App\Admin\Resources\McpProposalResource\Pages\ListMcpProposals;
use App\Mcp\Servers\MycsServer;
use App\Mcp\Tools\ProposalTool;
use App\Mcp\Tools\ProposeResolveModuleActionTool;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of PR #146 (MCP proposals). Each test states what should hold and fails on the PR as it stands.
 */
class McpProposalsReviewTest extends TestCase
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

    private function mail(array $attributes = []): EmailLog
    {
        return EmailLog::query()->create(array_merge(['subject' => 'Invoice 7', 'to' => 'owner@example.test', 'body' => '<p>x</p>',
            'status' => EmailLog::STATUS_FAILED, 'client_id' => $this->service->client_id], $attributes));
    }

    /** R1: the dedupe hands agent B agent A's proposal, which `proposal` then refuses to show B ("No such proposal of yours"). */
    public function test_r1_a_deduplicated_proposal_can_be_followed_by_who_proposed_it_again(): void
    {
        $first = $this->staff();
        $second = $this->staff();
        MycsServer::actingAs($first, 'staff')->tool(ProposeResolveModuleActionTool::class, ['module_action_id' => $this->action->id])->assertOk();
        MycsServer::actingAs($second, 'staff')->tool(ProposeResolveModuleActionTool::class, ['module_action_id' => $this->action->id])->assertOk();
        $proposal = McpProposal::query()->sole();

        MycsServer::actingAs($second, 'staff')->tool(ProposalTool::class, ['proposal_id' => $proposal->id])
            ->assertOk()->assertSee('"status": "pending"');
    }

    /** R2: a run that dies mid-way (PHP timeout on a slow server, OOM, deploy) leaves «approved» forever, and the admin filter cannot even show it. */
    public function test_r2_a_proposal_stuck_in_approved_is_not_left_there_forever(): void
    {
        $proposal = Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $this->action->id], $this->staff());
        $proposal->forceFill(['status' => McpProposal::STATUS_APPROVED, 'decided_at' => now()->subDay(), 'expires_at' => now()->addHour()])->save();
        $proposal->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

        $this->artisan('app:cron-job');

        $this->assertNotSame(McpProposal::STATUS_APPROVED, $proposal->fresh()->status, 'an interrupted run should end failed (outcome unknown), not approved');
    }

    /** R3: the failure text of a run goes back to the agent through `proposal` unscrubbed (every other tool passes errors through Scrub). */
    public function test_r3_a_failed_runs_error_is_scrubbed_before_the_agent_reads_it(): void
    {
        $log = $this->mail();
        $proposer = $this->staff();
        $proposal = Proposals::propose(Proposals::RESEND_MAIL, ['email_log_id' => $log->id], $proposer);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection could not be established with https://relay.example.test/?password=Hunter2Secret&x=1'));

        $done = Proposals::approve($proposal, $this->staff());
        $this->assertSame(McpProposal::STATUS_FAILED, $done->status);

        MycsServer::actingAs($proposer, 'staff')->tool(ProposalTool::class, ['proposal_id' => $proposal->id])
            ->assertOk()->assertDontSee('Hunter2Secret');
    }

    /** R4: approvals and rejections are "audited" (docs/mcp.md), but the claim and the reject are query-builder updates: no audit. */
    public function test_r4_a_rejection_is_audited_with_who_decided(): void
    {
        config(['audit.console' => true]);
        $staff = $this->staff();
        $proposal = Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $this->action->id], $staff);
        $this->actingAs($staff, 'staff');

        Proposals::reject($proposal, $staff);

        $audit = Audit::query()->where('auditable_type', $proposal->getMorphClass())->where('auditable_id', $proposal->id)
            ->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($audit, 'the rejection left no audit');
        $this->assertSame(McpProposal::STATUS_REJECTED, $audit->new_values['status'] ?? null);
    }

    public function test_r4_an_approval_audit_names_the_decider(): void
    {
        config(['audit.console' => true]);
        $approver = $this->staff();
        $proposal = Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $this->action->id], $this->staff());
        $this->actingAs($approver, 'staff');

        Proposals::approve($proposal, $approver);

        $audits = Audit::query()->where('auditable_type', $proposal->getMorphClass())->where('auditable_id', $proposal->id)->where('event', 'updated')->get();
        $this->assertTrue($audits->contains(fn (Audit $audit) => ($audit->new_values['decided_by'] ?? null) == $approver->id),
            'no audit records decided_by (the claim bypasses model events)');
    }

    /** R5: «Use the MCP Server» alone shows every proposal, others' included: mail recipients and subjects of a staff who may not view mail logs. */
    public function test_r5_staff_who_may_neither_decide_nor_proposed_do_not_see_the_proposal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $log = $this->mail(['subject' => 'Your new password']);
        $proposal = Proposals::propose(Proposals::RESEND_MAIL, ['email_log_id' => $log->id, 'to' => 'someone@example.test'], $this->staff());
        $onlooker = $this->staff(['admin.mcp.use']);

        $this->livewireAs($onlooker)->test(ListMcpProposals::class)->assertCanNotSeeTableRecords([$proposal]);
    }

    /** R7: the summary column is 500 chars; a 255-char subject and a long address overflow it (MySQL strict: the insert fails). */
    public function test_r7_the_summary_fits_its_column(): void
    {
        $log = $this->mail(['subject' => str_repeat('S', 255)]);
        $to = str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.example.test';

        $proposal = Proposals::propose(Proposals::RESEND_MAIL, ['email_log_id' => $log->id, 'to' => $to], $this->staff());

        $this->assertLessThanOrEqual(500, mb_strlen($proposal->summary));
    }

    /** R8: no bound on what one agent files: at 120 calls a minute it buries the admin's queue (each distinct `to` is new). */
    public function test_r8_one_agent_cannot_file_an_unbounded_number_of_pending_proposals(): void
    {
        $log = $this->mail();
        $staff = $this->staff();
        $refused = false;
        for ($i = 0; $i < 120; $i++) {
            try {
                Proposals::propose(Proposals::RESEND_MAIL, ['email_log_id' => $log->id, 'to' => "x{$i}@example.test"], $staff);
            } catch (\InvalidArgumentException) {
                $refused = true;
                break;
            }
        }

        $this->assertTrue($refused, '120 pending proposals from one agent were all accepted');
    }

    /** R9: server_test is unthrottled: an agent loop logs into WHM 120 times a minute (cPHulk / fail2ban lock MyCS out with bad creds). */
    public function test_r9_server_test_is_throttled_per_server(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://whm.test:2087/*' => Http::response(['metadata' => ['result' => 1]])]);
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $staff = $this->staff();

        for ($i = 0; $i < 10; $i++) {
            MycsServer::actingAs($staff, 'staff')->tool(ServerTestTool::class, ['server' => (string) $server->id]);
        }

        $this->assertLessThanOrEqual(3, count(Http::recorded()), 'ten tests in a row all reached the server');
    }
}
