<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Second pass on #780 (head 7537ad7a): the fixes of REV-780-1..8. Each test is a way around one of them. Invented
 * fixtures only.
 */
class StaffFreedomInvoicesReview2Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-03-01 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        foreach (['credits_enabled', 'credits_on_downgrade'] as $key) {
            Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => $key], ['value' => '1', 'type' => 'boolean']);
        }
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $price, string $unit = 'month'): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price . $unit, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => '0.00', 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /** An active service of $product paid until $until, and the unpaid invoice of its next period [$until, $end). */
    private function serviceWithOpenRenewal(Product $product, string $price, string $until, string $end): array
    {
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'status' => 'active', 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => $until,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $until]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'taxed' => false, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $until, 'period_end' => $end]);

        return [$service->fresh(), $invoice->fresh()];
    }

    private function credit(): string
    {
        return number_format((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2, '.', '');
    }

    private function received(Invoice $invoice): int
    {
        return (int) $invoice->transactions()->get()->sum(fn ($payment) => RecordPaymentService::toCents($payment->amount)
            - RecordPaymentService::toCents($payment->refunded_amount) - RecordPaymentService::toCents($payment->credited_amount));
    }

    /** An Admin API key with these permissions, of an admin or ($role) of a staff member whose role has only these. */
    private function token(array $permissions, bool $role = false): string
    {
        $staff = $role ? Staff::factory()->create(['role_id' => Role::create(['name' => 'Rev' . uniqid(), 'permissions' => $permissions])->id])
            : Staff::factory()->admin()->create();
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $staff->id, 'name' => 'rev', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => $permissions]);

        return $token;
    }

    /**
     * REV-780-2-1 (medium). The cut for REV-780-1c («the line that gave a service its period is not removed from a PAID
     * invoice») is one status away. Every refusal tells staff to «set it back to unpaid first»; reopened, the invoice is
     * unpaid with its payment on it, and since item 2 its lines change freely: the line goes, «Close as paid» gives its
     * 10.00 back as credit, the period stays given, and the service again reads as «imported without its lines»: a
     * downgrade gives 5.42 more for the same days. On main the reopened invoice kept its lines (it had a payment).
     */
    public function test_rev_780_2_1_the_period_line_cannot_be_removed_by_reopening_the_paid_invoice_first(): void
    {
        $big = $this->product('10.00');
        $small = $this->product('2.00');
        DB::table('product_upgrades')->insertOrIgnore(['product_id' => $big->id, 'upgrade_id' => $small->id]);
        [$service, $invoice] = $this->serviceWithOpenRenewal($big, '10.00', '2026-03-01', '2026-04-01');
        $invoice->items()->create(['description' => 'Setup of a mailbox', 'price' => '5.00', 'quantity' => 1, 'taxed' => false]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, '15.00', transactionId: 'wire-21', enteredByStaff: true);
        $this->assertSame([Invoice::STATUS_PAID, '2026-04-01'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);
        $line = $invoice->items()->where('reference_type', Service::class)->sole();
        $token = $this->token(['admin.invoices.update', 'admin.invoice_items.delete']);
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertStatus(422); // the cut, on a paid invoice

        // «Set it back to unpaid first», as the refusals say; then the same removal.
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'unpaid'])->assertSuccessful();
        $removed = $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line));
        if ($removed->status() === 422) {
            $this->assertSame('0.00', $this->credit());

            return; // refused there too: the cut holds
        }
        (new RecordPaymentService)->settle($invoice->id); // «Close as paid»
        $this->assertSame([Invoice::STATUS_PAID, '10.00'], [$invoice->fresh()->status, $this->credit()], 'precondition: the month\'s money is back as credit');
        $this->assertSame('2026-04-01', $service->fresh()->expires_at->toDateString(), 'the period given stays given');

        Carbon::setTestNow('2026-03-11 10:00:00');
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $small->id)->call('doUpgrade');

        $this->assertSame((int) $small->id, (int) $service->fresh()->product_id, 'the downgrade happened');
        $this->assertSame('10.00', $this->credit(), 'the same days given back as credit twice');
    }

    /**
     * REV-780-2-2 (medium). «Paid by hand without the tick and its permission» (Invoice::refuseOwingWithoutWord) is refused
     * only while NO money is on the invoice. With any part payment on it (1.00 of 10.00, recorded by whoever may), a key
     * that may not record payments lowers the line to what was paid, sets «paid» (nothing left to pay), and raises the
     * line again: paid, owing 9.00, its service renewed, `paid_by_staff_at` empty (so nothing downstream knows: the
     * affiliates' reward, the «marked paid by staff» of the money check, the income report and the Ekdosi feed).
     */
    public function test_rev_780_2_2_a_part_paid_invoice_is_not_made_paid_and_owing_without_staffs_word(): void
    {
        $product = $this->product('10.00');
        [$service, $invoice] = $this->serviceWithOpenRenewal($product, '10.00', '2026-03-01', '2026-04-01');
        (new RecordPaymentService)->handle($invoice->fresh(), null, '1.00', transactionId: 'wire-22', enteredByStaff: true);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $line = $invoice->items()->sole();
        $token = $this->token(['admin.invoices.update', 'admin.invoice_items.update']); // not admin.invoice_transactions.create

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'paid', 'paid_without_payment' => true])->assertStatus(403);
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '1.00']);
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'paid']);
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '10.00']);

        $fresh = $invoice->fresh();
        $owingWithoutWord = $fresh->status === Invoice::STATUS_PAID && RecordPaymentService::toCents($fresh->remaining) > 0 && $fresh->paid_by_staff_at === null;
        $this->assertFalse($owingWithoutWord, sprintf('paid, owing %s with 1.00 on it, no «paid by hand» on record, by a key that may not record payments; service to %s',
            number_format((float) $fresh->remaining, 2, '.', ''), $service->fresh()->expires_at->toDateString()));
    }

    /**
     * REV-780-2-3 (low-medium). The bound of REV-780-1 is per INVOICE, shared out by each line's current total. Two yearly
     * services billed on one invoice, 10.00 each, 20.00 paid. Staff raise A's paid line to 100.00 (it owes 90.00). The
     * 20.00 is now split 100:10, so A's days «were paid» 18.18 and B's 1.82: a cycle change «now» of A credits 9.11 where
     * 5.01 was paid for its days left (and B, untouched, would get 0.91 for the 5.01 its customer paid).
     */
    public function test_rev_780_2_3_a_raised_line_does_not_take_the_money_another_services_line_was_paid_with(): void
    {
        $yearly = $this->product('10.00', 'year');
        [$a, $invoice] = $this->serviceWithOpenRenewal($yearly, '10.00', '2026-03-01', '2027-03-01');
        $b = Service::factory()->create(['client_id' => $a->client_id, 'product_id' => $yearly->id, 'plan_id' => $a->plan_id, 'status' => 'active',
            'price' => '10.00', 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2026-03-01']);
        $invoice->items()->create(['description' => 'Renewal B', 'price' => '10.00', 'quantity' => 1, 'taxed' => false, 'reference_id' => $b->id,
            'reference_type' => Service::class, 'period_start' => '2026-03-01', 'period_end' => '2027-03-01']);
        (new RecordPaymentService)->handle($invoice->fresh(), null, '20.00', transactionId: 'wire-23', enteredByStaff: true);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        Carbon::setTestNow('2026-08-30 10:00:00');
        $before = [ChangeCycle::unused($a->fresh(), CarbonImmutable::today()), ChangeCycle::unused($b->fresh(), CarbonImmutable::today())];
        $this->assertSame(['5.01', '5.01'], $before, 'precondition: each has 183 of 365 days left of its 10.00');

        $invoice->items()->where('reference_id', $a->id)->sole()->update(['price' => '100.00']);
        $this->assertSame([Invoice::STATUS_PAID, 9000, 2000], [$invoice->fresh()->status, RecordPaymentService::toCents($invoice->fresh()->remaining), $this->received($invoice)]);

        $this->assertSame($before, [ChangeCycle::unused($a->fresh(), CarbonImmutable::today()), ChangeCycle::unused($b->fresh(), CarbonImmutable::today())],
            'no more money came in: each service\'s days are worth what was paid for them');
    }
}
