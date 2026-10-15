<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\PayTogether;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use Tests\TestCase;

/**
 * PR F2 (decision 7, WHMCS «Mass Payment»): the customer chooses unpaid invoices and pays them at once through a gateway.
 * The gateway is paid on the invoice due first for the whole amount; what that one cannot take pays the others, oldest
 * due first, as allocations of the payment. A new choice replaces the open one.
 */
class MassPayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['separate_invoices' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = [], ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => ($user ?? $this->user)->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    /** @return Collection<int, Invoice> 10.00 due the 25th, 15.00 the 26th, 20.00 the 24th (in id order) */
    private function three(): Collection
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26', 'price' => 15.00]);
        $this->service(['expires_at' => '2026-10-24', 'price' => 20.00]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->orderBy('id')->get();
    }

    private function mollie(): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'api_key'], ['value' => 'test_key']);
        (new Mollie(['api_key' => 'test_key']))->boot();

        return $gateway->fresh();
    }

    private function credit(): int
    {
        return (new CreditLedger)->balanceCents($this->user->client()->id, 'USD');
    }

    /** @return list<string> */
    private function statuses(Collection $invoices): array
    {
        return $invoices->map(fn (Invoice $invoice) => $invoice->fresh()->status)->all();
    }

    private function attention(): int
    {
        return EmailLog::query()->where('subject', 'Payment needs attention')->count();
    }

    public function test_the_customer_chooses_invoices_and_one_gateway_payment_pays_them_all(): void
    {
        $invoices = $this->three();
        $lead = $invoices[2]; // due the 24th
        $gateway = $this->mollie();
        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(['id' => 'tr_mass', '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout/tr_mass']]]),
            'https://api.mollie.com/v2/payments/tr_mass' => Http::response([
                'id' => 'tr_mass', 'status' => 'paid', 'amount' => ['value' => '45.00', 'currency' => 'USD'], 'metadata' => ['invoice_id' => $lead->id],
            ]),
        ]);
        $this->actingAs($this->user);

        $this->livewireAs($this->user)->test(Index::class)
            ->set('selected', $invoices->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('payTogether')
            ->assertRedirect(route('invoices.pay-together', PaymentGroup::query()->sole()));

        $group = PaymentGroup::query()->sole();
        $this->assertSame([$lead->id, $invoices[0]->id, $invoices[1]->id], $group->invoices->pluck('id')->all(), 'oldest due first');
        $this->assertSame([$lead->id, '45.00', PaymentGroup::STATUS_OPEN], [(int) $group->invoice_id, (string) $group->total, $group->status]);

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])
            ->set('selectedMethod', 'gateway-' . $gateway->id)
            ->call('processPayment')
            ->assertRedirect('https://mollie.test/checkout/tr_mass');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://api.mollie.com/v2/payments'
            && $request['amount']['value'] === '45.00' && (int) $request['metadata']['invoice_id'] === $lead->id);

        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_mass'])->assertOk();

        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses($invoices));
        $payment = InvoiceTransaction::query()->where('transaction_id', 'tr_mass')->sole();
        $this->assertSame($lead->id, (int) $payment->invoice_id);
        $this->assertSame(2, InvoiceTransaction::query()->where('source_transaction_id', $payment->id)->count());
        $this->assertSame([PaymentGroup::STATUS_PAID, $payment->id], [$group->fresh()->status, (int) $group->fresh()->invoice_transaction_id]);
        $this->assertSame(0, $this->credit());
        $this->assertSame(0, $this->attention(), 'a spread payment is not news for staff');
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_a_new_choice_replaces_the_open_group_and_a_late_payment_for_the_old_one_still_pays_its_invoices(): void
    {
        [$a, $b, $c] = $this->three()->all(); // due 25th, 26th, 24th
        $old = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);
        $new = (new MassPay)->open($this->user->client(), [$a->id, $c->id], $this->user);
        $this->assertSame(PaymentGroup::STATUS_REPLACED, $old->fresh()->status);
        $this->assertSame((int) $c->id, (int) $new->invoice_id);
        $this->mollie();

        // The checkout of the old choice completes after all.
        ExtensionHelper::addPayment($a->id, 'Mollie', '25.00', transactionId: 'tr_old', currency: 'USD');

        $this->assertSame(['paid', 'paid', 'unpaid'], $this->statuses(collect([$a, $b, $c])));
        $this->assertSame(PaymentGroup::STATUS_PAID, $old->fresh()->status);
        $this->assertNotNull(MassPay::staleness($new->fresh()), 'the new choice holds a paid invoice now');
        $this->assertSame(0, $this->credit());
    }

    public function test_a_payment_in_progress_pays_the_others_once_it_succeeds_and_a_replay_pays_nothing_more(): void
    {
        $invoices = $this->three();
        $group = (new MassPay)->open($this->user->client(), $invoices->pluck('id')->all(), $this->user);
        $this->mollie();

        ExtensionHelper::addProcessingPayment($group->invoice_id, 'Mollie', '45.00', transactionId: 'tr_sepa', currency: 'USD');
        $this->assertSame(['unpaid', 'unpaid', 'unpaid'], $this->statuses($invoices));

        ExtensionHelper::addPayment($group->invoice_id, 'Mollie', '45.00', transactionId: 'tr_sepa', currency: 'USD');
        ExtensionHelper::addPayment($group->invoice_id, 'Mollie', '45.00', transactionId: 'tr_sepa', currency: 'USD');

        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses($invoices));
        $this->assertSame(3, InvoiceTransaction::query()->count());
        $this->assertSame(0, $this->credit());
    }

    public function test_an_overpayment_staff_record_on_the_first_invoice_is_not_spread(): void
    {
        $invoices = $this->three();
        $group = (new MassPay)->open($this->user->client(), $invoices->pluck('id')->all(), $this->user);

        (new RecordPaymentService)->recordByStaff($group->invoice, null, '45.00', 'wire-1');

        $this->assertSame(['unpaid', 'unpaid', 'paid'], $this->statuses($invoices), 'staff spread a transfer with «Add payment to invoices»');
        $this->assertSame(2500, $this->credit());
        $this->assertSame(PaymentGroup::STATUS_OPEN, $group->fresh()->status);
    }

    public function test_an_invoice_paid_meanwhile_leaves_its_share_as_credit(): void
    {
        [$a, $b, $c] = $this->three()->all();
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id, $c->id], $this->user);
        (new RecordPaymentService)->handle($b, null, '15.00', transactionId: 'paid-apart');
        $this->mollie();

        ExtensionHelper::addPayment($group->invoice_id, 'Mollie', '45.00', transactionId: 'tr_late', currency: 'USD');

        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses(collect([$a, $b, $c])));
        $this->assertSame(1500, $this->credit());
    }

    public function test_what_cannot_be_paid_together_is_refused_and_nothing_is_made(): void
    {
        $invoices = $this->three();
        $other = User::factory()->create();
        $other->client()->update(['separate_invoices' => true]);
        $this->service([], $other);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $theirs = Invoice::query()->where('client_id', $other->client()->id)->sole();
        $credit = Invoice::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'due_at' => '2026-10-30', 'status' => Invoice::STATUS_UNPAID]);
        $credit->items()->create(['reference_type' => Credit::class, 'price' => 50, 'quantity' => 1, 'description' => 'Add funds']);
        $this->mollie();
        ExtensionHelper::addProcessingPayment($invoices[1]->id, 'Mollie', '15.00', transactionId: 'tr_wait', currency: 'USD');

        foreach ([[$invoices[0]->id], [$invoices[0]->id, $theirs->id], [$invoices[0]->id, $credit->id], [$invoices[0]->id, $invoices[1]->id]] as $ids) {
            try {
                (new MassPay)->open($this->user->client(), $ids, $this->user);
                $this->fail('grouped ' . implode(',', $ids));
            } catch (DisplayException) {
            }
        }
        config(['settings.mass_pay_enabled' => false]);
        try {
            (new MassPay)->open($this->user->client(), [$invoices[0]->id, $invoices[2]->id], $this->user);
            $this->fail('grouped with Mass Pay off');
        } catch (DisplayException) {
        }
        $this->assertSame(0, PaymentGroup::query()->count());
    }

    public function test_the_page_is_the_accounts_own(): void
    {
        $invoices = $this->three();
        $group = (new MassPay)->open($this->user->client(), $invoices->pluck('id')->all(), $this->user);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('invoices.pay-together', $group))->assertForbidden();
        $this->actingAs($this->user)->get(route('invoices.pay-together', $group))->assertOk()->assertSee('45.00');
    }

    public function test_a_changed_invoice_is_not_paid_on_the_old_amount(): void
    {
        $invoices = $this->three();
        $group = (new MassPay)->open($this->user->client(), $invoices->pluck('id')->all(), $this->user);
        $gateway = $this->mollie();
        $invoices[0]->items()->first()->update(['price' => 12.00]); // staff changed it after the customer chose
        Http::fake();

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])
            ->set('selectedMethod', 'gateway-' . $gateway->id)
            ->call('processPayment')
            ->assertNoRedirect();

        Http::assertNothingSent();
    }
}
