<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent and adversarial review of PR #779 (staff's freedom on services). Each test states what should hold and fails
 * on the PR's head; the findings table is in the review report (REV-779-n).
 */
class StaffFreedomServicesReviewTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parent(string $expires = '2026-12-01', string $status = 'active', array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'domain' => 'vm104.example.test', ...$attributes,
        ]);
    }

    private function addon(Service $parent, string $status = 'active', ?int $stock = null, string $name = 'Extra IPv4', array $attributes = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['name' => $name, 'is_addon' => true, 'stock' => $stock]);

        return Service::factory()->create([
            'client_id' => $parent->client_id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent->id, 'status' => $status, 'expires_at' => $parent->expires_at, 'currency_code' => 'USD',
            'price' => 3.00, 'quantity' => 1, ...$attributes,
        ]);
    }

    private function openInvoiceFor(Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $services[0]->client_id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        foreach ($services as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => $service->price, 'period_start' => $service->expires_at, 'period_end' => $service->expires_at->copy()->addMonth()]);
        }

        return $invoice;
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * REV-779-1 (high, money). A VM with no API (OfflineVm: its termination is staff's task by hand) and its addon renew on
     * one invoice, 10.00 + 3.00, with 10.00 of the customer's credit on it. Staff delete the service with its addons,
     * «Also trigger deletion of server» ticked (the default). The termination leaves its task, so the delete falls back to
     * «terminated, not deleted» (Service::markTerminated). That path does not cancel as one: the addon ends first
     * (AddonCascade::end, inside the parent's save), its 3.00 line is removed, the credit covers the 10.00 left and the
     * invoice is PAID: the customer's 10.00 pays the renewal of a service terminated a moment ago, nobody told. The PR
     * cancels «as one» exactly so that this cannot happen (its own test covers only the path with no termination).
     */
    public function test_rev_779_1_credit_on_the_common_renewal_is_not_spent_when_the_delete_ends_as_terminated_not_deleted(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $parent = $this->parent();
        $server = Server::query()->create(['name' => 'Rack', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $parent->product->update(['server_id' => $server->id]);
        $addon = $this->addon($parent);
        $renewal = $this->openInvoiceFor($parent, $addon);
        (new CreditLedger)->add((int) $parent->client_id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'test');
        (new RecordPaymentService)->payWithCredit($renewal->id, upToCents: 1000);
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->fresh()->status, 'partly paid by credit');

        $message = '';
        try {
            (new DeleteService)->handle($parent->fresh(), terminate: true, withAddons: true);
        } catch (DisplayException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('terminated but not deleted', $message, 'the by-hand termination keeps it, terminated');
        $this->assertSame(Service::STATUS_TERMINATED, $parent->fresh()->status);
        $this->assertSame(Service::STATUS_TERMINATED, $addon->fresh()->status);
        $this->assertNotSame(Invoice::STATUS_PAID, $renewal->fresh()->status, 'the renewal of a terminated service was paid with the customer\'s credit');
        $this->assertSame('10.00', number_format((float) Credit::query()->where('client_id', $parent->client_id)->value('amount'), 2, '.', ''),
            'all of the credit is back on the account');
    }

    /**
     * REV-779-2 (medium, provisioning). An addon's own server actions are staff's tasks by hand (ExtensionHelper::addonTask):
     * deleted on its own with the server tick, an «Extra IPv4» is kept terminated with the task «terminate the addon by
     * hand» open, so somebody takes the IP off the machine. Deleted with its main service that task is skipped because «its
     * service's machine covers it». That holds only when the main service's account is in fact terminated. Here the main
     * service has no module (hasServerActions() false: nothing is terminated, the page does not even offer the tick), and
     * the same goes for a pending one: the addon is deleted, its stock is back in the catalogue (the IP can be sold again)
     * while it is still bound by hand on a machine that goes on, and staff are told nothing.
     */
    public function test_rev_779_2_an_addon_whose_main_service_has_no_account_to_terminate_is_not_forgotten_on_the_machine(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $parent = $this->parent();
        $this->assertFalse($parent->hasServerActions(), 'no module: nothing of the main service is terminated');
        $addon = $this->addon($parent, stock: 0);
        $mails = EmailLog::query()->where('template_key', 'system')->count();

        try {
            (new DeleteService)->handle($parent, terminate: true, withAddons: true);
        } catch (DisplayException) {
            // kept with a task open would be an answer too
        }

        $task = ModuleAction::query()->open()->where('subject', ModuleAction::SUBJECT_BY_HAND)->exists();
        $mailed = EmailLog::query()->where('template_key', 'system')->count() > $mails;
        $this->assertTrue($task || $mailed || $addon->fresh() !== null,
            'the addon is gone, its stock is ' . (int) $addon->product->fresh()->stock . ' again, and nobody was told to take it off the machine');
    }

    /**
     * REV-779-3 (low). The PR and docs/addons.md say «an addon fastened meanwhile (it was not in the confirmation) refuses the
     * delete». On the page the confirmed list is not what staff read: DeleteService::handle reads the running addons again
     * when the action runs and takes them as confirmed (withAddons: true). An addon added after the confirmation opened
     * (another member of staff, an order paid) is deleted without ever being named, and what the customer just paid for
     * it stays as a paid invoice of a service that is gone.
     */
    public function test_rev_779_3_an_addon_fastened_after_the_confirmation_opened_is_not_deleted_unseen(): void
    {
        $parent = $this->parent();
        $seen = $this->addon($parent);
        $page = $this->livewireAs($this->staff())->test(EditService::class, ['record' => $parent->getRouteKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee("#{$seen->id} Extra IPv4");

        $unseen = $this->addon($parent, name: 'Backup 50GB'); // added while the confirmation is open
        $paid = $this->openInvoiceFor($unseen);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        $page->callMountedAction();

        $this->assertNotNull($unseen->fresh(), 'an addon staff were never shown was deleted with the service (its paid invoice left as history)');
        $this->assertNotNull($parent->fresh(), 'refused: staff confirm again, with the new addon named');
    }
}
