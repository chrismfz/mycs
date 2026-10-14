<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\MoneyEventsRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Resources\UserResource\Pages\ShowCreditHistory;
use App\Admin\Resources\UserResource\Pages\ShowCredits;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyHistory;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Code review of MT-1d (PR #58): failing tests for the findings.
 */
class MoneyHistoryCodeReviewTest extends TestCase
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

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /**
     * F1: «Credit history» has no policy (no MoneyEventPolicy), so ManageRelatedRecords::canAccess() allows every
     * admin user, while the «Credits» page next to it needs admin.credits.viewAny. A support role without any credit
     * (or even user) permission reads every customer's credit movements, balances and the staff reasons.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_credit_history_needs_the_same_permission_as_the_credits_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $support = $this->staff(['admin.tickets.viewAny']);
        $this->actingAs($support);

        $this->assertFalse(ShowCredits::canAccess(['record' => $this->user]), 'sanity: the Credits page is closed to this role');
        $this->assertFalse(ShowCreditHistory::canAccess(['record' => $this->user]), 'the credit history (with staff reasons) is open to any staff role');
    }

    /**
     * F1b: the same for the «Movements» tab: visible to staff who may open the invoice but not see its transactions
     * (TransactionsRelationManager is gated by admin.invoice_transactions.viewAny), showing payments, credit balances,
     * actors and staff reasons.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_movements_tab_needs_a_money_permission_like_the_transactions_tab(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $clerk = $this->staff(['admin.invoices.viewAny', 'admin.invoices.update']);
        $this->actingAs($clerk);
        $invoice = $this->invoice(100);

        $this->assertFalse(TransactionsRelationManager::canViewForRecord($invoice, EditInvoice::class), 'sanity: transactions tab is closed');
        $this->assertFalse(MoneyEventsRelationManager::canViewForRecord($invoice, EditInvoice::class), 'movements tab is open without any money permission');
    }

    /**
     * F2: journal rows and audit rows in the same second are not interleaved: the stable sort puts every journal row
     * before every status change of that second, so «paid» shows after the reversal that undid the payment.
     */
    public function test_status_changes_keep_their_place_among_movements_of_the_same_second(): void
    {
        $this->markTestIncomplete('Kept as is in MT-1d: the journal and the audit keep whole seconds; within one second the journal rows come first (documented in MoneyHistory).');
        config(['audit.console' => true]);
        Carbon::setTestNow('2026-10-13 10:00:00');
        $invoice = $this->invoice(100);
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_same_sec', currency: 'USD');
        (new RecordPaymentService)->deleteTransaction($payment);

        $whats = array_values(array_filter(
            array_column(array_values((new MoneyHistory)->invoiceMovements($invoice)), 'what'),
            fn (string $what) => $what === 'Payment' || str_starts_with($what, 'Reversal') || str_contains($what, '→'),
        ));

        $this->assertSame(['Payment', 'Status: pending → paid', 'Reversal of #' . MoneyEvent::query()->where('kind', 'payment')->value('id') . ' (transaction deleted)', 'Status: paid → pending'], $whats);
    }

    /**
     * F3: a staff member's action shows as «System» once that staff user is deleted (the morph resolves to null):
     * the credit history then claims nobody did a manual adjustment. actor_type/actor_id are still on the row.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_deleted_staff_actor_is_not_shown_as_system(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['first_name' => 'Quentin', 'last_name' => 'Gone', 'role_id' => Role::create(['name' => 'Gone' . uniqid(), 'permissions' => ['*']])->id]);
        $this->actingAs($staff);
        (new CreditLedger)->add($this->user->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $staffId = $staff->id;
        // Staff with money history are disabled, not deleted (DATA-3): one gone anyway, straight from the database.
        DB::table('staff')->where('id', $staffId)->delete();
        $owner = $this->staff(['*']);

        $this->livewireAs($owner)->test(ShowCreditHistory::class, ['record' => $this->user->getKey()])
            ->assertSuccessful()
            ->assertSee('Goodwill')
            ->assertDontSee('System')
            ->assertSee('#' . $staffId);
    }
}
