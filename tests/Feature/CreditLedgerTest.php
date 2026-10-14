<?php

namespace Tests\Feature;

use App\Admin\Resources\UserResource\Pages\ShowCredits;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Upgrade;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\MoneyEventNote;
use App\Models\Price;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * MT-1b (docs/money-trail.md §2): account credit changes only through CreditLedger, each change is journaled, staff
 * give a reason (kept apart from the journal), and the balance can be checked and rebuilt from the journal.
 */
class CreditLedgerTest extends TestCase
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

    private function balance(?User $user = null, string $currency = 'USD'): string
    {
        return number_format((float) (Credit::query()->where('user_id', ($user ?? $this->user)->id)->where('currency_code', $currency)->value('amount') ?? 0), 2, '.', '');
    }

    /** Σ credit effects of the customer's events, as the ledger's check computes it. */
    private function journal(?User $user = null): string
    {
        $cents = MoneyEvent::query()->where('user_id', ($user ?? $this->user)->id)->get()->sum(fn (MoneyEvent $event) => $event->creditEffectCents());

        return number_format($cents / 100, 2, '.', '');
    }

    private function owner(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    private function apiToken(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    // ------------------------------------------------------------------ the ledger

    public function test_add_and_remove_journal_each_move_with_the_balance_after(): void
    {
        $ledger = new CreditLedger;

        $ledger->add($this->user->id, 'USD', 2500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $ledger->remove($this->user->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Correction');

        $this->assertSame('15.00', $this->balance());
        $this->assertSame([['credit_in', '25.00', '25.00', 'staff_adjustment'], ['credit_out', '10.00', '15.00', 'staff_adjustment']],
            MoneyEvent::query()->orderBy('id')->get()->map(fn (MoneyEvent $e) => [$e->kind->value, $e->amount, $e->credit_balance_after, $e->cause])->all());
        $this->assertSame(['Goodwill', 'Correction'], MoneyEventNote::query()->orderBy('id')->pluck('note')->all());
        $this->assertNull(MoneyEvent::query()->first()->reason, 'The free-text reason lives in the notes, not in the append-only journal.');
    }

    public function test_remove_is_refused_below_zero_and_changes_nothing(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');

        try {
            (new CreditLedger)->remove($this->user->id, 'USD', 600, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
            $this->fail('Credit went below zero.');
        } catch (DisplayException) {
        }

        $this->assertSame('5.00', $this->balance());
        $this->assertSame(1, MoneyEvent::query()->count());
    }

    /** Decision 2: a staff adjustment without a reason is refused by the ledger itself, not only by the forms. */
    public function test_a_staff_adjustment_needs_a_reason(): void
    {
        $this->expectException(\LogicException::class);
        (new CreditLedger)->add($this->user->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: '  ');
    }

    public function test_set_balance_journals_only_the_difference(): void
    {
        $ledger = new CreditLedger;
        $ledger->setBalance($this->user->id, 'USD', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_API, 'Opening');
        $ledger->setBalance($this->user->id, 'USD', 2500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_API, 'Lower');
        $ledger->setBalance($this->user->id, 'USD', 2500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_API, 'Same');

        $this->assertSame('25.00', $this->balance());
        $this->assertSame([['credit_in', '40.00'], ['credit_out', '15.00']],
            MoneyEvent::query()->orderBy('id')->get()->map(fn (MoneyEvent $e) => [$e->kind->value, $e->amount])->all());
    }

    public function test_one_credit_row_per_customer_and_currency(): void
    {
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 2]);
    }

    /** The payment paths (MT-1a) now go through the ledger: the journal still adds up to the balance. */
    public function test_payment_paths_still_add_up_through_the_ledger(): void
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        $other = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $other->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 30]);
        $deposit = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $deposit->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);

        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 140.00, null, 'pi_l1', currency: 'USD'); // +40
        (new RecordPaymentService)->payWithCredit($other);                                                     // −30
        $bought = ExtensionHelper::addPayment($deposit->id, 'Stripe', 50.00, null, 'pi_l2', currency: 'USD');   // +50
        ExtensionHelper::refundLocally($bought, 20.00);                                                         // −20
        (new RecordPaymentService)->deleteTransaction($payment);                                                // −40

        $this->assertSame('0.00', $this->balance());
        $this->assertSame($this->balance(), $this->journal());
    }

    // ------------------------------------------------------------------ the other writers

    public function test_a_downgrade_credits_the_difference_through_the_ledger(): void
    {
        $from = $this->createProduct();
        $to = $this->createProduct();
        Price::query()->where('plan_id', $to->plan->id)->update(['price' => 2.00]);
        DB::table('product_upgrades')->insert(['product_id' => $from->product->id, 'upgrade_id' => $to->product->id]);
        $service = Service::factory()->create([
            'user_id' => $this->user->id, 'product_id' => $from->product->id, 'plan_id' => $from->plan->id,
            'status' => 'active', 'price' => 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(20),
        ]);

        Livewire::actingAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $to->product->id)
            ->call('doUpgrade');

        $this->assertGreaterThan(0, (float) $this->balance(), 'A downgrade gave no credit.');
        $event = MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_DOWNGRADE)->sole();
        $this->assertSame(['credit_in', 'system'], [$event->kind->value, $event->source]);
        $this->assertSame($this->balance(), $this->journal());
    }

    public function test_the_api_creates_updates_and_deletes_credit_through_the_ledger(): void
    {
        $token = $this->apiToken(['admin.credits.create', 'admin.credits.update', 'admin.credits.delete']);

        $created = $this->withToken($token)->postJson(route('api.v1.admin.credits.store'), [
            'user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 30, 'reason' => 'API goodwill',
        ])->assertSuccessful()->json('data.id');
        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $created), ['amount' => 12])
            ->assertStatus(422); // a new amount needs a reason
        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $created), ['amount' => 12, 'reason' => 'Lower'])
            ->assertSuccessful();
        $this->withToken($token)->deleteJson(route('api.v1.admin.credits.destroy', $created))
            ->assertStatus(422); // a balance is never deleted
        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $created), ['amount' => 0, 'reason' => 'Zero'])
            ->assertSuccessful();
        $this->withToken($token)->deleteJson(route('api.v1.admin.credits.destroy', $created))
            ->assertSuccessful();

        $this->assertSame([['credit_in', '30.00', 'api'], ['credit_out', '18.00', 'api'], ['credit_out', '12.00', 'api']],
            MoneyEvent::query()->orderBy('id')->get()->map(fn (MoneyEvent $e) => [$e->kind->value, $e->amount, $e->source])->all());
        $this->assertSame(['API goodwill', 'Lower', 'Zero'], MoneyEventNote::query()->orderBy('id')->pluck('note')->all());
        $this->assertSame('0.00', $this->journal());
    }

    /** In its own process: after some earlier tests Livewire's update route is gone (route state leaking between tests). */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_staff_add_and_remove_credit_with_a_reason_in_the_admin(): void
    {
        $owner = $this->owner();
        // A fresh page per action: a refused action stays mounted (its modal open) on the same component.
        $page = fn () => Livewire::actingAs($owner)->test(ShowCredits::class, ['record' => $this->user->getKey()]);

        $page()->callTableAction('addCredit', data: ['currency_code' => 'USD', 'amount' => '20.00', 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $page()->callTableAction('addCredit', data: ['currency_code' => 'USD', 'amount' => '20.00', 'reason' => 'Goodwill'])
            ->assertHasNoTableActionErrors();
        $page()->callTableAction('removeCredit', data: ['currency_code' => 'USD', 'amount' => '50.00', 'reason' => 'Too much'])
            ->assertNotified();
        $page()->callTableAction('removeCredit', data: ['currency_code' => 'USD', 'amount' => '5.00', 'reason' => 'Correction'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('15.00', $this->balance());
        $this->assertSame(['Goodwill', 'Correction'], MoneyEventNote::query()->orderBy('id')->pluck('note')->all());
        $this->assertSame([$owner->getMorphClass(), $owner->id], [MoneyEvent::query()->first()->actor_type, MoneyEvent::query()->first()->actor_id]);
    }

    // ------------------------------------------------------------------ the check

    public function test_the_check_finds_a_balance_that_differs_and_rebuilds_it_only_with_write(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        $this->assertSame(0, Artisan::call('mycs:credits-check'));

        DB::table('credits')->where('user_id', $this->user->id)->update(['amount' => 99]); // a write that skipped the ledger

        $this->assertSame(1, Artisan::call('mycs:credits-check'));
        $this->assertSame('99.00', $this->balance(), 'The check changed a balance without --write.');
        $this->assertSame(0, Artisan::call('mycs:credits-check', ['--write' => true]));
        $this->assertSame('10.00', $this->balance());
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    public function test_the_migration_merges_duplicate_credit_rows_before_the_unique_key(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Re-running a migration inside the test transaction needs transactional DDL (sqlite).');
        }
        $migration = require database_path('migrations/2026_10_15_000000_credit_ledger.php');
        $migration->down();
        DB::table('credits')->insert([
            ['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 3.50, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 1.25, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        $this->assertSame(1, Credit::query()->where('user_id', $this->user->id)->count());
        $this->assertSame('4.75', $this->balance());
    }
}
