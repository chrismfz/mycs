<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Throwable;

/**
 * Round-3 review of #530 (after 8cb0667e). The rule: an imported service whose account is on a WHMCS server > 0 that is
 * not here, and is not pending (Service::serverLeftInWhmcs), never acts on its product's server, where the same
 * username may be another customer's account (AUD4-1). No real server is called (Http::preventStrayRequests, cPanel's
 * answers faked); the queue is sync.
 */
class ImportServiceServersRound3Test extends TestCase
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

    /**
     * ADV-530-R3-1 (Medium). An active account imported on WHMCS server 9 (not here), its product on orion here. Staff
     * run «Module command… → Create server» on it (the refusal of every other action says «set its server first»; Create
     * is the one that is not refused). checkServer('createServer') goes through ServerSelector::assign, which saves
     * orion as the service's server_id *before* the create runs, and keeps it when WHM refuses the create. From then on
     * the service is no longer «left in WHMCS»: the cron's suspend and terminate, a cancellation, a delete with
     * terminate all go to orion with the imported username (getServiceProperties: cpanel_username = username), which on
     * orion may be another customer's account: what AUD4-1 set out to prevent. A failed create must leave it unbound.
     */
    public function test_adv_530_r3_1_a_failed_create_does_not_bind_an_account_left_on_a_whmcs_server_to_its_products_server(): void
    {
        $orion = $this->cpanel('orion', 1);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $orion->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD',
            'username' => 'shopgr', 'domain' => 'shop.example']);
        DB::table('services')->where('id', $service->id)->update(['server_id' => null, 'whmcs_server_id' => 9]);
        $service = $service->fresh();
        $this->assertTrue($service->serverLeftInWhmcs());

        // WHM refuses the create (the domain is already there, a package missing, any reason).
        Http::fake(['*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'Sorry, that domain is already set up.']])]);
        try {
            ExtensionHelper::createServer($service);
        } catch (Throwable) {
            // recorded in Pending Module Actions
        }

        $service = $service->fresh();
        $this->assertNull($service->server_id,
            'a refused create left the account on WHMCS server #9 bound to orion: its terminate now goes there with username shopgr');
        $this->assertTrue($service->serverLeftInWhmcs());

        // What the binding leads to: the next terminate (cron, cancellation) on orion, removing «shopgr» there.
        try {
            ExtensionHelper::terminateServer($service);
        } catch (Throwable) {
            // refused to staff, as for any action of an account left on a WHMCS server
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'removeacct'));
    }
}
