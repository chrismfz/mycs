<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Jobs\Server\CreateJob;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Service\RenewServiceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/**
 * Σ1-b1: when a product's service is set up (WHMCS «Auto Setup»): on order, on payment, on staff's Accept, never; and
 * its dates when it starts late (paid, waiting) or early (set up before payment).
 */
class AutoSetupTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Server $server;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_invoice' => 7, 'settings.cronjob_order_cancel' => 7]);
        $this->server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $this->product = $this->createProduct()->product;
        $this->product->update(['server_id' => $this->server->id]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $autosetup, bool $server = true): Service
    {
        $this->product->update(['autosetup' => $autosetup, 'server_id' => $server ? $this->server->id : null]);
        $product = $this->product->fresh();
        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);

        return Service::query()->latest('id')->firstOrFail();
    }

    private function pay(Service $service): void
    {
        $invoice = $service->invoices()->firstOrFail();
        $item = $invoice->items()->where('reference_id', $service->id)->firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        (new RenewServiceService)->handle($service->fresh(), $item);
    }

    public function test_every_product_is_set_up_on_payment_as_before(): void
    {
        $this->assertSame(Product::AUTOSETUP_PAYMENT, $this->createProduct()->product->fresh()->autosetup);
        $service = $this->order(Product::AUTOSETUP_PAYMENT);
        Queue::assertNotPushed(CreateJob::class);

        $this->pay($service);

        Queue::assertPushed(CreateJob::class);
    }

    public function test_on_order_the_account_is_made_before_payment_and_an_unpaid_one_is_suspended_as_overdue(): void
    {
        $service = $this->order(Product::AUTOSETUP_ORDER);
        Queue::assertPushed(CreateJob::class);

        (new CreateJob($service))->handle();
        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertSame('2026-01-01', $service->expires_at->toDateString(), 'due from the order date (WHMCS: next due = registration)');

        Carbon::setTestNow('2026-01-20 10:00:00'); // the first invoice unpaid past Suspend Days
        $this->artisan('app:cron-job');
        $this->assertSame('suspended', $service->fresh()->status);

        $this->pay($service->fresh());
        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertSame('2026-02-01', $service->expires_at->toDateString(), 'its first period is from the order');
    }

    public function test_on_order_without_a_module_it_starts_at_once(): void
    {
        $service = $this->order(Product::AUTOSETUP_ORDER, server: false);

        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame('2026-01-01', $service->fresh()->expires_at->toDateString());
    }

    public function test_on_accept_or_never_a_paid_service_waits_for_staff_with_or_without_a_module(): void
    {
        foreach ([[Product::AUTOSETUP_ACCEPT, true], [Product::AUTOSETUP_NEVER, true], [Product::AUTOSETUP_NEVER, false]] as [$autosetup, $server]) {
            Queue::fake();
            $service = $this->order($autosetup, $server);
            $this->pay($service);

            $service->refresh();
            $this->assertSame('pending', $service->status, "{$autosetup}: paid, waiting for staff");
            $this->assertSame('2026-02-01', $service->expires_at->toDateString(), 'the period paid is recorded');
            Queue::assertNotPushed(CreateJob::class);
        }
    }

    public function test_a_dedicated_server_built_by_hand_ten_days_later_is_billed_from_the_day_it_started(): void
    {
        $service = $this->order(Product::AUTOSETUP_NEVER, server: false);
        $this->pay($service); // 1/1, paid until 1/2

        Carbon::setTestNow('2026-01-11 10:00:00'); // built, OS installed: staff make it active
        $service->fresh()->applyStaffChanges(['status' => 'active']);

        $this->assertSame('2026-02-11', $service->fresh()->expires_at->toDateString(), 'a month from the day it started');

        Carbon::setTestNow('2026-02-05 10:00:00'); // Invoice Days (7) before the new date
        $this->artisan('app:cron-job');
        $renewal = $service->fresh()->invoiceItems()->whereNotNull('period_start')->latest('id')->first();
        $this->assertSame(['2026-02-11', '2026-03-11'], [$renewal?->period_start->toDateString(), $renewal?->period_end->toDateString()]);
    }

    public function test_a_date_staff_set_when_they_start_it_is_kept(): void
    {
        $service = $this->order(Product::AUTOSETUP_NEVER, server: false);
        $this->pay($service);

        Carbon::setTestNow('2026-01-11 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'active', 'expires_at' => '2026-03-01']); // the 1st of the month

        $this->assertSame('2026-03-01', $service->fresh()->expires_at->toDateString());
    }

    public function test_staff_choose_the_auto_setup_on_the_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->set('data.autosetup', Product::AUTOSETUP_ACCEPT)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Product::AUTOSETUP_ACCEPT, $this->product->fresh()->autosetup);
        $this->assertSame([Product::AUTOSETUP_NEVER, Product::AUTOSETUP_ACCEPT], [Product::AUTOSETUP_FROM_WHMCS[''], Product::AUTOSETUP_FROM_WHMCS['on']]);
    }
}
