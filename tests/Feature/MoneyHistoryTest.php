<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\MoneyEventsRelationManager;
use App\Admin\Resources\UserResource\Pages\ShowCreditHistory;
use App\Helpers\ExtensionHelper;
use App\Livewire\Client\Credits;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyHistory;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * MT-1d (docs/money-trail.md §2, §3): the money trail on screen, read only. «Movements» on an invoice and «Credit
 * history» on a customer for staff; the customer's own credit history without who, source or the staff reason.
 */
class MoneyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100, ?User $user = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => ($user ?? $this->user)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function owner(): Staff
    {
        return Staff::factory()->create(['first_name' => 'Zebediah', 'last_name' => 'Staffperson', 'role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
    }

    public function test_an_invoices_movements_are_its_journal_and_its_status_changes_in_order(): void
    {
        config(['audit.console' => true]); // the audit keeps status changes (tests run in the console)
        $invoice = $this->invoice(100);
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 120.00, null, 'pi_over', currency: 'USD');
        ExtensionHelper::refundLocally($payment, 10.00);

        $rows = array_values((new MoneyHistory)->invoiceMovements($invoice));
        $whats = array_column($rows, 'what');

        $this->assertContains('Payment', $whats);
        $this->assertContains('Credit in (overpayment)', $whats);
        $this->assertContains('Refund', $whats);
        $this->assertContains('Status: unpaid → paid', $whats); // WHMCS's words for staff (2c)
        $payments = array_values(array_filter($rows, fn (array $row) => $row['what'] === 'Payment'));
        $this->assertSame('$120.00', $payments[0]['amount']);
        $this->assertSame($payment->id, $payments[0]['transaction']);
        $this->assertSame('$20.00', array_values(array_filter($rows, fn (array $row) => str_starts_with($row['what'], 'Credit in')))[0]['credit']);
        $times = array_map(fn (array $row) => $row['at']->getTimestamp(), $rows);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'oldest first');
    }

    public function test_credit_history_has_only_movements_of_the_credit_with_the_balance_after(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, null, 'pi_h', currency: 'USD'); // +30
        (new CreditLedger)->remove($this->user->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Duplicate refund');
        (new RecordPaymentService)->payWithCredit($this->invoice(10)); // −10

        $events = MoneyHistory::creditEvents($this->user->id)->get();

        $this->assertSame(['credit_out', 'credit_out', 'credit_in'], $events->map(fn (MoneyEvent $event) => $event->kind->value)->all(), 'newest first, no payments');
        $this->assertSame(['−$10.00', '−$5.00', '+$30.00'], $events->map(fn (MoneyEvent $event) => MoneyHistory::signedCredit($event))->all());
        $this->assertSame(['15.00', '25.00', '30.00'], $events->pluck('credit_balance_after')->all());
        $this->assertSame(['Invoice paid with credit', 'Adjustment', 'Overpayment of an invoice'], $events->map(fn (MoneyEvent $event) => MoneyHistory::customerLabel($event))->all());
        $this->assertSame('Duplicate refund', MoneyHistory::staffNote($events[1]));
    }

    public function test_a_deleted_payment_reads_as_a_correction_for_the_customer(): void
    {
        $invoice = $this->invoice(100);
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, null, 'pi_del', currency: 'USD');
        (new RecordPaymentService)->deleteTransaction($payment);

        $latest = MoneyHistory::creditEvents($this->user->id)->first();

        $this->assertSame('Payment correction', MoneyHistory::customerLabel($latest));
        $this->assertSame('−$30.00', MoneyHistory::signedCredit($latest));
        $this->assertSame('0.00', $latest->credit_balance_after);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_staff_see_the_movements_tab_on_the_invoice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 120.00, null, 'pi_tab', currency: 'USD');

        $this->livewireAs($this->owner())
            ->test(MoneyEventsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->assertSuccessful()
            ->assertSee('Credit in (overpayment)')
            ->assertSee('$120.00')
            ->assertSee('stripe');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_staff_see_the_credit_history_with_who_and_why(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = $this->owner();
        $this->actingAs($owner);
        (new CreditLedger)->add($this->user->id, 'USD', 2500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill for the outage');
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_exact', currency: 'USD');
        $payment = MoneyEvent::query()->where('kind', 'payment')->sole();
        $credit = MoneyEvent::query()->where('kind', 'credit_in')->sole();

        $this->livewireAs($owner)->test(ShowCreditHistory::class, ['record' => $this->user->getKey()])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$credit])
            ->assertCanNotSeeTableRecords([$payment])
            ->assertSee('Goodwill for the outage')
            ->assertSee('+$25.00')
            ->assertSee($owner->first_name);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_customer_sees_their_credit_history_without_internal_fields(): void
    {
        Setting::query()->updateOrCreate(['key' => 'credits_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        $staff = $this->owner();
        $this->actingAs($staff);
        (new CreditLedger)->add($this->user->id, 'USD', 2500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Internal: angry customer');
        $other = User::factory()->create();
        (new CreditLedger)->add($other->id, 'USD', 77700, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Other customer');
        $own = $this->invoice(10);
        (new RecordPaymentService)->payWithCredit($own);

        $this->actingAs($this->user);
        Livewire::test(Credits::class)
            ->assertSuccessful()
            ->assertSee('Credit history')
            ->assertSee('Adjustment')
            ->assertSee('Invoice paid with credit')
            ->assertSee('+$25.00')
            ->assertSee('−$10.00')
            ->assertSee('$15.00')
            ->assertSee(route('invoices.show', $own), false)
            ->assertDontSee('Internal: angry customer')
            ->assertDontSee($staff->first_name)
            ->assertDontSee('$777.00');
    }
}
