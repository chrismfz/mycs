<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ShowTransactions;
use App\Admin\Resources\ClientResource\Widgets\ClientTransactionTotals;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PayInvoices;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #875: the client's Transactions tab and its totals. */
class Adv875ClientTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        $this->user->client()->update(['separate_invoices' => true]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($staff, 'staff');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(): Client
    {
        return $this->user->client();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => $this->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    /** @return Collection<int, Invoice> */
    private function three(): Collection
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26', 'price' => 15.00]);
        $this->service(['expires_at' => '2026-10-24', 'price' => 20.00]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->orderBy('id')->get();
    }

    /** @return array<string, string> label => value */
    private function stats(): array
    {
        return collect((fn () => $this->getStats())->call(Livewire::test(ClientTransactionTotals::class, ['record' => $this->client()])->instance()))
            ->mapWithKeys(fn (Stat $stat) => [(string) $stat->getLabel() => (string) $stat->getValue()])->all();
    }

    private function digits(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value);
    }

    public function test_a_payment_over_several_invoices_counts_once(): void
    {
        $invoices = $this->three();
        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '50.00', 'wire-1');

        $stats = $this->stats();
        $this->assertSame('5000', $this->digits($stats['Received']));
        $this->assertSame('5000', $this->digits($stats['Balance']));
    }

    public function test_search_filter_and_sort_work_on_the_through_relation(): void
    {
        $invoices = $this->three();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'Cards', 'type' => 'gateway']);
        $a = InvoiceTransaction::query()->create(['invoice_id' => $invoices[0]->id, 'gateway_id' => $gateway->id, 'amount' => '1.00', 'transaction_id' => 'FIND-ME', 'status' => InvoiceTransactionStatus::Succeeded]);
        $b = InvoiceTransaction::query()->create(['invoice_id' => $invoices[1]->id, 'amount' => '2.00', 'transaction_id' => 'OTHER', 'status' => InvoiceTransactionStatus::Succeeded]);

        Livewire::test(ShowTransactions::class, ['record' => $this->client()->getRouteKey()])
            ->searchTable('FIND-ME')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->searchTable(null)
            ->filterTable('gateway_id', $gateway->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->resetTableFilters()
            ->sortTable('invoice_id')->assertCanSeeTableRecords([$a, $b], inOrder: true)
            ->sortTable('created_at')
            ->filterTable('created_at', ['from' => '2026-10-01', 'until' => '2026-10-31'])->assertCountTableRecords(2);
    }

    public function test_two_currencies_have_their_own_totals(): void
    {
        $usd = $this->three()->first();
        $eur = Invoice::factory()->create(['client_id' => $this->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        InvoiceTransaction::query()->create(['invoice_id' => $usd->id, 'amount' => '3.00', 'status' => InvoiceTransactionStatus::Succeeded]);
        InvoiceTransaction::query()->create(['invoice_id' => $eur->id, 'amount' => '7.00', 'status' => InvoiceTransactionStatus::Succeeded]);

        $stats = $this->stats();
        $this->assertSame('300', $this->digits($stats['Received · USD']));
        $this->assertSame('700', $this->digits($stats['Received · EUR']));
    }

    /**
     * Staff refund a payment to the client's credit («Add to Client's Credit Balance»): the invoice becomes refunded, but
     * the tab showed the payment as never refunded, since only refunded_amount was read and a refund to credit is
     * credited_amount. Owner 2026-11-16: a «To credit» column and total (an overpayment's excess too), not Refunded.
     */
    public function test_a_refund_to_credit_shows_as_to_credit(): void
    {
        $invoice = $this->three()->first();
        $payment = InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'amount' => (string) $invoice->fresh()->remaining,
            'transaction_id' => 'wire-9', 'status' => InvoiceTransactionStatus::Succeeded]);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        ExtensionHelper::refundToCredit($payment->fresh(), (float) $payment->amount, false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);

        $cents = $this->digits(number_format((float) $payment->amount, 2));
        $this->get(ClientResource::getUrl('transactions', ['record' => $this->client()]))->assertOk()->assertSee(__('To credit'));
        $this->assertSame($cents, $this->digits($this->stats()['To credit']), 'the refund to credit is in «To credit»');
        $this->assertSame('000', $this->digits($this->stats()['Refunded']), 'nothing went back to the card');
    }
}
