<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UpgradeJob;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Service\DeleteService;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #530 (AUD4-1/AUD4-2). The PR says an account from WHMCS with no server here makes «every action
 * but create» fail to Pending Module Actions (checkServer). But several callers ask provisioningServer() first and, now
 * that it is null for such a service, skip the action silently: no job, no task, nothing for staff to see. No real
 * server is called (Http::preventStrayRequests, Queue::fake).
 */
class ImportServiceServersAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
    }

    private function cpanel(string $name, int $whmcsId): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $server->forceFill(['whmcs_id' => $whmcsId])->save();
        foreach (['host' => "https://{$name}.test:2087", 'username' => 'reseller', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $server;
    }

    /** An account as the importer leaves it: no server here, WHMCS's server kept (0 = none there). */
    private function imported(Service $service, int $whmcsServer): Service
    {
        DB::table('services')->where('id', $service->id)->update(['server_id' => null, 'whmcs_server_id' => $whmcsServer]);

        return $service->fresh();
    }

    /**
     * ADV-530-1 (High). A WHMCS order still Pending at the cut-over (not paid yet) comes in pending with
     * whmcs_server_id 0 (WHMCS had made no account), or with a WHMCS server not here. Its product sells on a cPanel
     * here. The customer pays: RenewServiceService::activate() dispatches CreateJob only «if provisioningServer()»,
     * which #530 made null for any service with a whmcs_server_id, and $awaitsCreate is false for the same reason, so
     * the service is set ACTIVE with no account made anywhere, no CreateJob, no Pending Module Action: paid, nothing
     * delivered, nobody told. Before #530 it was created on the product's server. AcceptOrder (staff's Accept) and
     * SetUpOnOrder have the same «provisioningServer() !== null» gate. At nixpal every licensing service has server 0
     * in WHMCS (the licensing module has no servers) and WhmcsLicensingImport gives the licensing server only to the
     * services that have a mod_licensing row: a pending license order paid after the cut-over gets no license.
     * The PR's own rule is «every action but create»: a create of an account that exists nowhere is safe anywhere.
     */
    public function test_adv_530_1_an_imported_pending_service_paid_here_is_created_not_just_marked_active(): void
    {
        $server = $this->cpanel('orion', 1);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id, 'autosetup' => Product::AUTOSETUP_PAYMENT]);
        $product = $product->product->fresh();
        (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $service = $this->imported(Service::query()->latest('id')->firstOrFail(), 0);

        $invoice = $service->invoices()->firstOrFail();
        $item = $invoice->items()->where('reference_id', $service->id)->firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        (new RenewServiceService)->handle($service, $item);

        $this->assertFalse($service->fresh()->status === Service::STATUS_ACTIVE && !Queue::pushed(CreateJob::class)->isNotEmpty(),
            'paid: its account is created (or the create fails to staff), never «active» with no account and no job');
        Queue::assertPushed(CreateJob::class);
    }

    /**
     * ADV-530-2 (Medium). An imported active cPanel account whose WHMCS server is not here (blocked by #530, as meant)
     * still offers upgrades (Service::productUpgrades() takes the product's module). The customer pays an upgrade:
     * ServiceUpgradeService changes product, plan and price, then dispatches UpgradeJob only «if provisioningServer()»:
     * null, so no job and no Pending Module Action. The customer is billed the bigger package from now on; the account
     * keeps the old one on WHMCS's server; staff never hear of it. The PR says such a service's Change Package fails to
     * Pending Module Actions with «set its server first»: it only does when something calls changePackage at all.
     */
    public function test_adv_530_2_a_paid_upgrade_of_an_imported_service_without_server_reaches_staff(): void
    {
        $server = $this->cpanel('orion', 1);
        $small = $this->createProduct();
        $small->product->update(['server_id' => $server->id]);
        $bigger = Product::factory()->create(['name' => 'Business 20', 'server_id' => $server->id]);
        $plan = Plan::factory()->create(['priceable_id' => $bigger->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 20, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $service = $this->imported(Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $small->product->id, 'plan_id' => $small->plan->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD',
            'price' => 10, 'username' => 'shopgr']), 9);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $plan->id, 'product_id' => $bigger->id,
            'status' => ServiceUpgrade::STATUS_PENDING, 'invoice_id' => null]);

        (new ServiceUpgradeService)->handle($upgrade);

        $this->assertSame($bigger->id, $service->fresh()->product_id, 'billed as the bigger package from now on');
        // Its Change Package is asked (and fails to Pending Module Actions, «set its server first»), not skipped unseen.
        Queue::assertPushed(UpgradeJob::class);
        Http::assertNothingSent();
    }

    /**
     * ADV-530-3 (Medium). Staff delete an imported active service with «terminate on the server» ticked. Its WHMCS
     * server is not here (#530: no server, as meant). DeleteService terminates only «if provisioningServer() !== null»:
     * the terminate is skipped without a word and the service row is deleted. The cPanel account stays alive on WHMCS's
     * server, unbilled, and no record is left of it (the service is gone, so no Pending Module Action could hold it
     * either). Before #530 it was (wrongly) terminated on the product's server; now the delete should refuse, as it
     * does when the server refuses: «set its server first». CancelOrder's terminate has the same gate.
     */
    public function test_adv_530_3_deleting_an_imported_service_with_terminate_refuses_when_its_server_is_not_here(): void
    {
        $server = $this->cpanel('orion', 1);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = $this->imported(Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD',
            'username' => 'shopgr']), 9);

        $refused = null;
        try {
            (new DeleteService)->handle($service, terminate: true);
        } catch (Throwable $e) {
            $refused = $e;
        }

        Http::assertNothingSent();
        $this->assertNotNull($refused, 'a terminate asked for and not done refuses the delete, as a refusal by the server would');
        $this->assertNotNull(Service::query()->find($service->id), 'the service is kept until staff set its server');
    }
}
