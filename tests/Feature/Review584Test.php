<?php

namespace Tests\Feature;

use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\McpProposalResource;
use App\Enums\DomainStatus;
use App\Mcp\Servers\MycsServer;
use App\Mcp\Tools\ProposalTool;
use App\Mcp\Tools\ProposeResendMailTool;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\McpProposal;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Mcp\Proposals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Independent review of #584: what an MCP proposal still lets staff see or do beyond the admin page. */
class Review584Test extends TestCase
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
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Rev role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function domainAction(): ModuleAction
    {
        $domain = Domain::factory()->create(['status' => DomainStatus::PendingRegister]);

        return ModuleAction::query()->create(['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_REGISTER,
            'status' => ModuleAction::STATUS_OPEN, 'attempts' => 1, 'last_error' => 'Manual registrar: staff must register it']);
    }

    public function test_service_only_staff_badge_does_not_count_what_they_may_not_decide(): void
    {
        Proposals::propose(Proposals::RESOLVE_MODULE_ACTION, ['module_action_id' => $this->domainAction()->id], $this->staff(['*']));
        Auth::guard('staff')->login($this->staff(['admin.mcp.use', 'admin.services.update']));

        $this->assertNull(McpProposalResource::getNavigationBadge());
    }

    public function test_service_only_staff_cannot_follow_a_domain_proposal_via_the_tool(): void
    {
        $proposal = Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $this->domainAction()->id], $this->staff(['*']));
        $restricted = $this->staff(['admin.mcp.use', 'admin.services.update']);

        MycsServer::actingAs($restricted, 'staff')->tool(ProposalTool::class, ['proposal_id' => $proposal->id])->assertHasErrors();
    }

    /** Resend: the admin page needs the email logs' viewAny to reach Resend; the MCP proposal does not. */
    public function test_resend_over_mcp_needs_what_the_admin_page_needs(): void
    {
        $log = EmailLog::query()->create(['subject' => 'Your invoice', 'to' => 'owner@example.test', 'body' => '<p>secret body</p>', 'status' => EmailLog::STATUS_SENT]);
        $staff = $this->staff(['admin.mcp.use', 'admin.email_logs.resend']);
        Auth::guard('staff')->login($staff);
        $this->assertFalse(EmailLogResource::canViewAny(), 'they cannot open Email Logs, so no Resend there');

        MycsServer::actingAs($staff, 'staff')->tool(ProposeResendMailTool::class, ['email_log_id' => $log->id, 'to' => 'outsider@example.test']);
        $proposal = McpProposal::query()->first();
        if ($proposal !== null) {
            Proposals::approve($proposal, $staff);
        }

        $this->assertSame(0, EmailLog::query()->where('to', 'outsider@example.test')->count(), 'a mail they may not read was sent to an address they chose');
    }
}
