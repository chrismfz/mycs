<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\DeleteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Deleting services and orders (audit sweep DATA-2, SVC-7, AUTH-9). One way for every path: a service is deleted in
 * any status (as WHMCS: e.g. an account already terminated on the server), with its account on the server first if
 * asked (refused → nothing deleted); its unpaid lines and pending upgrades are cancelled, its options go, its stock
 * comes back; paid lines stay as history. An order with services is not deleted (the cascade wiped them silently).
 */
class ServiceOrderDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct(['stock' => 5]);
        $user = User::factory()->create();
        $order = Order::query()->create(['user_id' => $user->id, 'currency_code' => 'USD']);

        return Service::factory()->create(array_merge([
            'user_id' => $user->id, 'order_id' => $order->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    private function invoiceFor(Service $service, string $status): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-15']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    public function test_a_service_is_deleted_with_what_would_point_at_nothing(): void
    {
        $service = $this->service();
        $open = $this->invoiceFor($service, Invoice::STATUS_UNPAID);
        $paid = $this->invoiceFor($service, Invoice::STATUS_PAID);
        $target = $this->createProduct();
        $upgradeInvoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id, 'invoice_id' => $upgradeInvoice->id]);
        $upgradeInvoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);
        $service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'example.test']);

        (new DeleteService)->handle($service); // record only: the account is already gone on the server

        $this->assertNull(Service::find($service->id));
        $this->assertSame(Invoice::STATUS_CANCELLED, $open->fresh()->status, 'not left payable for a service that is gone');
        $this->assertSame(Invoice::STATUS_CANCELLED, $upgradeInvoice->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status, 'paid lines stay, as history');
        $this->assertSame(1, $paid->items()->count());
        $this->assertSame(6, (int) $service->product->fresh()->stock, 'its stock back');
        $this->assertSame(0, DB::table('properties')->where('model_type', Service::class)->where('model_id', $service->id)->count());
    }

    public function test_an_ended_service_gives_no_stock_back_twice(): void
    {
        $service = $this->service(['status' => Service::STATUS_TERMINATED]);

        (new DeleteService)->handle($service);

        $this->assertSame(5, (int) $service->product->fresh()->stock);
    }

    public function test_when_the_server_refuses_nothing_is_deleted(): void
    {
        require_once __DIR__ . '/../Fixtures/FalseServer.php';
        $service = $this->service();
        $server = Server::query()->create(['name' => 'Legacy', 'extension' => 'FalseServer', 'type' => 'server', 'enabled' => true]);
        $service->product->update(['server_id' => $server->id]);
        $open = $this->invoiceFor($service, Invoice::STATUS_UNPAID);

        try {
            (new DeleteService)->handle($service->fresh(), terminate: true);
            $this->fail('deleted although the server refused');
        } catch (\Throwable) {
        }

        $this->assertNotNull(Service::find($service->id));
        $this->assertSame(Invoice::STATUS_UNPAID, $open->fresh()->status);
    }

    public function test_an_order_with_services_is_not_deleted(): void
    {
        $service = $this->service();

        try {
            $service->order->delete();
            $this->fail('deleted with its services');
        } catch (DisplayException) {
        }
        $this->assertNotNull(Service::find($service->id));

        $this->expectException(QueryException::class);
        DB::table('orders')->where('id', $service->order_id)->delete(); // around the model: the foreign key holds
    }

    public function test_the_api_deletes_a_service_one_way_and_refuses_an_order_with_services(): void
    {
        $service = $this->service();
        $open = $this->invoiceFor($service, Invoice::STATUS_UNPAID);
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => \App\Models\Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'user_id' => $owner->id,
            'permissions' => ['admin.services.delete', 'admin.orders.delete']]);

        $this->withToken($token)->deleteJson(route('api.v1.admin.orders.destroy', $service->order_id))->assertUnprocessable();

        $this->withToken($token)->deleteJson(route('api.v1.admin.services.destroy', $service))->assertNoContent();
        $this->assertNull(Service::find($service->id));
        $this->assertSame(Invoice::STATUS_CANCELLED, $open->fresh()->status);

        $this->withToken($token)->deleteJson(route('api.v1.admin.orders.destroy', $service->order_id))->assertNoContent();
    }
}
