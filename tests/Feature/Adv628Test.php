<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Whmcs\CatalogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #628 (the myip rehearsal: off-cycle services on a plan without prices, WHMCS's Proxmox nodes as
 * one cluster server). Each test fails on the PR's head for a defect it found.
 */
class Adv628Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT, type TEXT, hostname TEXT, ipaddress TEXT, port INT, username TEXT,
            maxaccounts INT, active INT, disabled INT)');
        $this->whmcs->exec('CREATE TABLE tblservergroups (id INTEGER PRIMARY KEY, name TEXT, filltype INT)');
        $this->whmcs->exec('CREATE TABLE tblservergroupsrel (groupid INT, serverid INT)');
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, servertype TEXT, servergroup INT)');
        $this->whmcs->exec("INSERT INTO tblservers VALUES
            (54, 'Vega', 'pvewhmcs', 'vega.example.test', '192.0.2.54', 0, 'root@pam', 0, 0, 1),
            (61, 'Pegasus', 'pvewhmcs', 'pegasus.example.test', '192.0.2.61', 0, 'root@pam', 0, 1, 0)");
    }

    /** An off-cycle service as the import leaves it: on its product's plan of that cycle without prices, at its own price. */
    private function offCycleService(array $attributes = []): Service
    {
        $product = $this->createProduct(); // Monthly, priced 10.00
        $quarterly = Plan::query()->findOrFail(CatalogImport::unpricedPlan($product->product, 'Quarterly'));
        $client = User::factory()->create()->client();

        return Service::query()->forceCreate(array_merge(['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $quarterly->id,
            'status' => 'active', 'price' => 62.90, 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2026-12-01 00:00:00',
            'activated_at' => '2024-01-01 00:00:00'], $attributes));
    }

    /**
     * ADV-628-1 (medium). A service on the plan the import makes without prices prices at 0.00 from the catalog:
     * `Service::calculatePrice()` reads the plan's missing price as 0. Staff's «Recalculate Price» on the service (the
     * hint action of its price field) then puts 0.00 in the form, and a save makes it renew free for good (RenewalBilling:
     * «price is 0, immediately activate next period»), with no warning. The import lists these services and says so in a
     * comment, but nothing stops it. As for a service with no plan, a plan with no price in its currency (and not free)
     * should give the price it has.
     */
    public function test_adv_628_1_recalculating_an_off_cycle_services_price_does_not_make_it_free(): void
    {
        $service = $this->offCycleService();

        $this->assertSame('62.90', (string) $service->calculatePrice(), 'no catalog price to compute: the price it has, never 0.00');
    }

    /**
     * ADV-628-2 (medium). The same, with nobody clicking: a renewal that reprices from the catalog makes the off-cycle service
     * free. Here a «forever» coupon staff gave it requires a product the account no longer holds; the renewal ends the
     * discount with `calculatePrice(applyCoupon: false)`, which is 0.00 on the plan without prices, saves it, and renews the
     * period free with no invoice (and every period after). The same path runs when a coupon «for N > 1 cycles» runs out.
     */
    public function test_adv_628_2_a_renewal_ending_a_coupon_does_not_renew_an_off_cycle_service_free(): void
    {
        $other = $this->createProduct()->product;
        $coupon = Coupon::create(['code' => 'LOYAL10', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 10, 'recurring' => 0]);
        $coupon->requiredProducts()->attach($other->id); // the account holds none of it (cancelled since)
        $service = $this->offCycleService(['price' => 56.61, 'coupon_id' => $coupon->id, 'coupon_started_at' => '2024-01-01 00:00:00']);

        Carbon::setTestNow('2026-11-25 09:00:00');
        DB::transaction(fn () => (new RenewalBilling)->billRenewal($service->id, now()->addDays(7)));
        Carbon::setTestNow();

        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->first();
        $this->assertNotNull($line, 'its next quarter is invoiced, not renewed free');
        $this->assertGreaterThan(0, (float) $line->price);
        $this->assertGreaterThan(0, (float) $service->fresh()->price, 'its price is not left at 0.00 for every renewal after');
    }

    private function servers(): array
    {
        return (new CatalogImport($this->whmcs))->serversForFullImport();
    }

    /**
     * ADV-628-3 (low). «Found again by any node's id» holds only for the one node whose id it keeps (the first live one,
     * Pegasus here). When that node leaves WHMCS's servers (decommissioned, the cluster now Vega and a new node), a re-run
     * of the catalog import finds no server by the other nodes' ids, and since the found one has a `whmcs_id` neither
     * `sameServer` nor `onlyServer` look at it: a second Proxmox server for the same cluster, enabled, with no token, and
     * the products' default server moved to it.
     */
    public function test_adv_628_3_the_cluster_is_found_again_when_the_node_whose_id_it_keeps_leaves_whmcs(): void
    {
        $this->servers();
        $cluster = Server::query()->where('extension', 'Proxmox')->sole();
        $this->assertSame(61, (int) $cluster->whmcs_id);

        $this->whmcs->exec('DELETE FROM tblservers WHERE id = 61');
        $this->whmcs->exec("INSERT INTO tblservers VALUES (70, 'Orion', 'pvewhmcs', 'orion.example.test', '192.0.2.70', 0, 'root@pam', 0, 1, 0)");
        $result = $this->servers();

        $this->assertSame(1, Server::query()->where('extension', 'Proxmox')->count(), 'one cluster, one server');
        $this->assertSame($cluster->id, $result['server_ids'][54] ?? null, 'Vega, a node of it, finds it');
    }

    /**
     * ADV-628-4 (low). Staff's own server for the cluster is adopted only at a node's host name, while «at any node's
     * address» includes its IP: `cluster()` matches `hostname ?: ipaddress`, so a server staff set up at Vega's IP
     * (Proxmox's own self-signed default, https://IP:8006) is not found while WHMCS has a host name for it. With a second
     * Proxmox server of staff's (a lab), `onlyServer` gives none either, and the import makes a third server for the
     * cluster beside staff's working one: the services and products go to the one with no token.
     */
    public function test_adv_628_4_staffs_server_at_a_nodes_ip_is_adopted(): void
    {
        $own = Server::query()->create(['name' => 'vega-proxmox', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $own->settings()->create(['key' => 'host', 'value' => 'https://192.0.2.54:8006']);
        Server::query()->create(['name' => 'lab', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);

        $result = $this->servers();

        $this->assertSame(2, Server::query()->where('extension', 'Proxmox')->count(), 'no third one');
        $this->assertSame($own->id, $result['server_ids'][61] ?? null, 'staff\'s, at Vega\'s IP');
    }
}
