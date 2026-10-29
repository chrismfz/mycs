<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Order\AcceptOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Service\RenewServiceService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #530 (after d260d165). The rule: an imported service whose account is on a WHMCS
 * server > 0 that is not here, and is not pending (Service::serverLeftInWhmcs), never acts on its product's server; its
 * actions fail to staff, and the gates use hasServerActions() so nothing is skipped silently. No real server is called
 * (Http::preventStrayRequests); the queue is sync, so jobs run as a worker would.
 */
class ImportServiceServersRound2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
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

    private function pendingOrder(Product $product): Service
    {
        (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);

        return Service::query()->latest('id')->firstOrFail();
    }

    /**
     * ADV-530-R2-1 (Medium). The fix of ADV-530-3 refuses DeleteService(terminate: true) for an account left on a WHMCS
     * server. But the service's own edit page (EditService «Delete») offers the «Also trigger deletion of server»
     * checkbox only when provisioningServer() !== null: for such a service it is null, so the form has no checkbox,
     * DeleteService runs with terminate false and the row is deleted. The cPanel account stays alive on WHMCS's server,
     * unbilled, and no record of it is left here: what ADV-530-3 set out to prevent, from the page staff actually use.
     * The bulk «Delete selected» always offers the checkbox (default ticked) and refuses: only the page is open.
     */
    public function test_adv_530_r2_1_deleting_from_the_edit_page_does_not_drop_an_account_left_on_a_whmcs_server(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $server = $this->cpanel('orion', 1);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = $this->imported(Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD',
            'username' => 'shopgr']), 9);
        $this->assertTrue($service->serverLeftInWhmcs());

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction(DeleteAction::class);

        Http::assertNothingSent();
        $this->assertNotNull(Service::query()->find($service->id),
            'deleted with its account alive on WHMCS\'s server: the page offers no terminate, so the refusal never comes');
    }

    /**
     * ADV-530-R2-2 (Low-Medium, money). A WHMCS order still Pending at the cut-over, on WHMCS server 9 (it had a module
     * server: Proxmox, Hetzner, ngm on myip), for a product that has no server here (the catalog imports only cPanel,
     * Centova and mailcow servers). serverLeftInWhmcs() is false for a pending one, provisioningServer() is null (the
     * product has none), so RenewServiceService sets it ACTIVE on payment with no CreateJob, no Pending Module Action
     * and no mail: the customer paid for a machine WHMCS would have made, nothing is made, nobody is told. Only its
     * later actions (suspend, terminate) fail to staff, once it is active and serverLeftInWhmcs() turns true. WHMCS
     * server 0 (no module, R3 of round 1) is the case where «nothing to make» is right; > 0 is not.
     */
    public function test_adv_530_r2_2_a_paid_pending_import_on_a_whmcs_server_with_no_module_here_reaches_staff(): void
    {
        $product = $this->createProduct()->product;
        $product->update(['server_id' => null, 'autosetup' => Product::AUTOSETUP_PAYMENT]);
        $service = $this->imported($this->pendingOrder($product->fresh()), 9);

        $invoice = $service->invoices()->firstOrFail();
        $item = $invoice->items()->where('reference_id', $service->id)->firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        try {
            (new RenewServiceService)->handle($service, $item);
        } catch (\Exception $e) {
            // The sync queue runs its CreateJob at once: it records the failure, then rethrows (a real queue retries it).
            $this->assertStringContainsString('set its server first', $e->getMessage());
        }

        $service = $service->fresh();
        $this->assertTrue($service->status !== Service::STATUS_ACTIVE || ModuleAction::query()->where('service_id', $service->id)->exists(),
            'paid and set active with nothing made on any server and nothing for staff: WHMCS had a module server for it');
    }

    /**
     * ADV-530-R2-3 (Low). The same pending import (WHMCS server 9, not here), its product on a cPanel here. Staff accept
     * its order with «run the module create» unticked (built by hand), as AcceptOrder allows: it turns active with no
     * server_id. Pending, it fell back to its product's server (round-1 fix); active, serverLeftInWhmcs() flips to true
     * and every later action (the cron's suspend and terminate, an upgrade) fails with «came from WHMCS without its server
     * here», although its account was made here, after the cut-over. A service made in MyCS in the same state acts on its
     * product's server. The status change, not the account, decides where it lives.
     */
    public function test_adv_530_r2_3_a_pending_import_started_here_by_hand_is_not_then_treated_as_left_in_whmcs(): void
    {
        $server = $this->cpanel('orion', 1);
        $product = $this->createProduct()->product;
        $product->update(['server_id' => $server->id, 'autosetup' => Product::AUTOSETUP_ACCEPT]);
        $service = $this->imported($this->pendingOrder($product->fresh()), 9);
        $this->assertSame($server->id, $service->provisioningServer()?->id, 'pending: its product\'s server (round 1)');

        AcceptOrder::handle(Order::query()->findOrFail($service->order_id), null, runCreate: false, sendEmail: false, startUnpaid: true);

        $service = $service->fresh();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status);
        $this->assertSame($server->id, $service->provisioningServer()?->id,
            'started here, its account is where any service of its product is: not «left on WHMCS server #9»');
        Http::assertNothingSent();
    }
}
