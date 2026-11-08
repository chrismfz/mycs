<?php

namespace Tests\Feature;

use App\Contracts\ReadsAccounts;
use App\Exceptions\DisplayException;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Ops\RollbackReport;
use App\Services\Servers\ServerAccount;
use App\Services\Servers\StrayAccounts;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of «Stray accounts rule for every module» (feat/stray-accounts-rule). Each test fails on the branch
 * as reviewed and passes once the finding is fixed.
 */
class RevStrayAccountsRuleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<ServerAccount> */
    public array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->freezeTime();
    }

    private function reader(): ReadsAccounts
    {
        return new class($this) implements ReadsAccounts
        {
            public function __construct(private readonly RevStrayAccountsRuleTest $test) {}

            public function accounts(Server $server): iterable
            {
                return $this->test->report;
            }
        };
    }

    private function server(): Server
    {
        return Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
    }

    private function service(string $status, Server $server): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status]);
        Service::query()->whereKey($service->id)->update(['updated_at' => now()->subHour()]);

        return $service->fresh();
    }

    private function openStray(): int
    {
        return ModuleAction::query()->open()->whereIn('action', ModuleAction::STRAY)->count();
    }

    /**
     * REV-STRAY-1 (medium). The «ended in MyCS, still on the server» task has the service's id, and module_actions.service_id
     * is ON DELETE CASCADE. DeleteService refuses only while ModuleAction::openTaskKeeps (an open terminate or by-hand task),
     * the rule written for exactly this (sweep3 L-DATA-INTEGRITY-2: «nobody would see the account still on the server»).
     * A stray_ended task is not in it: staff delete the terminated service, the task goes with it, and an account that is
     * suspended on the server is now unmapped and not running: no later read ever opens a task for it again.
     */
    public function test_deleting_the_ended_service_does_not_silently_drop_its_still_on_the_server_task(): void
    {
        $server = $this->server();
        $ended = $this->service(Service::STATUS_TERMINATED, $server);
        $this->report = [new ServerAccount('old', 'old (old.test)', false, $ended->id)]; // suspended on WHM, never removed
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertSame(1, $this->openStray());

        try {
            (new DeleteService)->handle($ended->fresh());
        } catch (DisplayException) {
            // refused while the task is open: the fix the rule asks for
        }

        // The next read: the account is still there, its mapping cleared by the foreign key.
        $this->report = [new ServerAccount('old', 'old (old.test)', false, Service::query()->whereKey($ended->id)->exists() ? $ended->id : null)];
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertSame(1, $this->openStray(), 'the account still on the server is in no task any more: deleted with its service');
    }

    /**
     * REV-STRAY-2 (low). The rollback report's «Server and module actions» lists what was done on servers from MyCS
     * («create, suspend, unsuspend, terminate done here. An open one did not happen there»), for staff to redo in WHMCS.
     * The read-only checks are listed there too, as open actions on the service: a stray_ended row reads as a pending
     * action on service #N that staff should carry over, though nothing was or will be done.
     */
    public function test_the_rollback_report_does_not_list_read_only_checks_as_server_actions(): void
    {
        $server = $this->server();
        $ended = $this->service(Service::STATUS_TERMINATED, $server);
        $this->report = [new ServerAccount('old', 'old', true, $ended->id), new ServerAccount('stray', 'stray', true, null)];
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertSame(2, $this->openStray());

        $section = collect((new RollbackReport(now()->subDay()))->sections())->firstWhere('key', 'module_actions');
        $actions = array_column($section['rows'], 2);
        $this->assertSame([], array_values(array_intersect($actions, ModuleAction::CHECKS)), 'checks listed as server actions to redo in WHMCS');
    }
}
