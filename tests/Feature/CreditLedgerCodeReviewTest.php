<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MT-1b code review: the findings' tests stay as regression coverage. */
class CreditLedgerCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    private function apiToken(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'review', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.credits.create', 'admin.credits.update', 'admin.credits.delete']]);

        return $token;
    }

    /** Each credit event's balance_after is the running sum of the journal (spendCredit debits after the listener ran). */
    private function assertBalanceAfterIsRunningSum(): void
    {
        $running = 0;
        foreach (MoneyEvent::query()->where('user_id', $this->user->id)->orderBy('id')->get() as $event) {
            $effect = $event->creditEffectCents();
            if ($effect === 0) {
                continue;
            }
            $running += $effect;
            $this->assertSame(number_format($running / 100, 2, '.', ''), $event->credit_balance_after, "event #{$event->id} ({$event->cause})");
        }
        $this->assertSame($this->balance(), number_format($running / 100, 2, '.', ''));
    }

    public function test_paying_a_credit_purchase_with_credit_keeps_the_journal_consistent(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'seed');
        $deposit = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $deposit->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 20]);

        $paid = (new RecordPaymentService)->payWithCredit($deposit);

        $this->assertNotNull($paid);
        $this->assertSame('paid', $deposit->fresh()->status);
        $this->assertSame('50.00', $this->balance());
        $this->assertBalanceAfterIsRunningSum();
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    public function test_the_check_counts_reversals_of_a_deleted_overpayment(): void
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 25.00, null, 'pi_rv1', currency: 'USD');
        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertSame('0.00', $this->balance());
        $this->assertBalanceAfterIsRunningSum();
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    public function test_a_staff_removal_with_no_credit_row_leaves_no_row_behind(): void
    {
        try {
            (new CreditLedger)->remove($this->user->id, 'EUR', 100, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');
        } catch (DisplayException) {
        }
        $this->assertSame(0, Credit::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(0, MoneyEvent::query()->count());
    }

    /** P2: an amount past the cents range validated, became 0 in toCents() and wiped the balance. */
    public function test_api_update_with_an_out_of_range_amount_is_refused_not_turned_into_zero(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 4200, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'seed');
        $credit = Credit::query()->where('user_id', $this->user->id)->sole();

        $response = $this->withToken($this->apiToken())->putJson(route('api.v1.admin.credits.update', $credit),
            ['amount' => '10000000000000', 'reason' => 'typo']);

        $this->assertSame('42.00', $this->balance(), 'An out-of-range amount silently set the balance to 0 (HTTP ' . $response->status() . ').');
        $response->assertStatus(422);
    }

    /** P2: a currency change or a delete is checked against the balance under the ledger's lock. */
    public function test_the_api_refuses_a_currency_change_or_delete_of_a_balance(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 4200, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'seed');
        $credit = Credit::query()->where('user_id', $this->user->id)->sole();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'format' => '1.000,00']); // a valid target
        $token = $this->apiToken();

        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $credit), ['currency_code' => 'EUR'])->assertStatus(422);
        $this->withToken($token)->deleteJson(route('api.v1.admin.credits.destroy', $credit))->assertStatus(422);

        $this->assertSame('42.00', $this->balance());
        $this->assertSame('USD', $credit->fresh()->currency_code);
    }
}
