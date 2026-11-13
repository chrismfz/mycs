<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adversarial review of #831: the domain's billing on its page (no «Edit» modal). Each finding is a test that fails on
 * the code as reviewed; the «holds» tests are attacks that did not break it.
 */
class DomainBillingFormAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $com;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true]);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'grace_days' => 40, 'redemption_days' => 30]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['renew', 2, '45.00'], ['renew', 3, '66.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
    }

    /** @param  array<string, mixed>  $attributes */
    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'mine', 'tld' => 'com',
            'fqdn' => 'mine.com', 'domain_tld_id' => $this->com->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true]);
    }

    private function openRenewal(Domain $domain): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-01']);

        return $invoice->items()->create(['description' => 'Renewal', 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => '2026-11-01', 'period_end' => '2027-11-01']);
    }

    private function editor(): Staff
    {
        return Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.update'])->create();
    }

    /** @return array<string, mixed> */
    private function billing(Domain $domain): array
    {
        $domain->refresh();

        return [$domain->auto_renew, $domain->no_auto_charge, $domain->years, $domain->recurring_amount === null ? null : (string) $domain->recurring_amount,
            $domain->payment_gateway_id, $domain->notes];
    }

    public function test_holds_staff_without_update_cannot_save_a_forged_form(): void
    {
        $domain = $this->domain(['recurring_amount' => '20.00', 'notes' => 'old']);
        $before = $this->billing($domain);
        $viewer = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view'])->create();
        $page = $this->livewireAs($viewer)->test(ViewDomain::class, ['record' => $domain->id])
            ->set('billing.recurring_amount', '0.01')->set('billing.notes', 'forged')->set('billing.auto_renew', false)
            ->set('billing.no_auto_charge', true)->set('billing.years', 3);
        try {
            $page->callAction(TestAction::make('saveBilling')->schemaComponent('billing_section', 'billingForm'));
        } catch (\Throwable) {
        }
        try {
            $page->callAction(TestAction::make('recalculate')->schemaComponent('billing.recurring_amount', 'billingForm'));
        } catch (\Throwable) {
        }
        $this->assertSame($before, $this->billing($domain));
    }

    public function test_holds_billing_loaded_is_locked(): void
    {
        $domain = $this->domain(['recurring_amount' => '20.00']);
        $this->expectException(\Throwable::class);
        $this->livewireAs($this->editor())->test(ViewDomain::class, ['record' => $domain->id])->set('billingLoaded.recurring_amount', '1.00');
    }

    /** @return array<string, array{0: string}> */
    public static function badPrices(): array
    {
        return ['negative' => ['-5'], 'exponent' => ['1e3'], 'three decimals' => ['12.345'], 'too big' => ['100000000'],
            'comma' => ['20,5'], 'spaces' => [' 20 '], 'hex' => ['0x14'], 'dot only' => ['.5'], 'array' => ['[]']];
    }

    #[DataProvider('badPrices')]
    public function test_holds_a_bad_price_is_refused_and_nothing_is_saved(string $price): void
    {
        $domain = $this->domain(['recurring_amount' => '20.00', 'notes' => 'old']);
        $before = $this->billing($domain);
        $page = $this->livewireAs($this->editor())->test(ViewDomain::class, ['record' => $domain->id]);
        if ($price === '[]') {
            try {
                $page->saveBilling(['recurring_amount' => ['x' => 1], 'notes' => 'new', 'years' => 3]);
            } catch (\Throwable) {
            }
        } else {
            $page->saveBilling(['recurring_amount' => $price, 'notes' => 'new', 'years' => 3])->assertHasFormErrors(['recurring_amount'], 'billingForm');
        }
        $this->assertSame($before, $this->billing($domain), $price);
    }

    public function test_holds_a_refused_period_saves_nothing_else(): void
    {
        $domain = $this->domain(['notes' => 'old']);
        $before = $this->billing($domain);
        $lock = Cache::lock("domain-registrar:{$domain->id}", 60);
        $this->assertTrue($lock->get());
        $this->livewireAs($this->editor())->test(ViewDomain::class, ['record' => $domain->id])
            ->saveBilling(['years' => 3, 'recurring_amount' => '9.00', 'notes' => 'new', 'auto_renew' => false, 'no_auto_charge' => true]);
        $lock->release();
        $this->assertSame($before, $this->billing($domain));
    }

    public function test_holds_forged_gateway_and_period_are_refused(): void
    {
        $domain = $this->domain(['notes' => 'old']);
        $before = $this->billing($domain);
        foreach ([['payment_gateway_id' => 999999, 'notes' => 'x'], ['years' => 7, 'notes' => 'x']] as $data) {
            try {
                $this->livewireAs($this->editor())->test(ViewDomain::class, ['record' => $domain->id])->saveBilling($data);
            } catch (\Throwable) {
            }
            $this->assertSame($before, $this->billing($domain), json_encode($data));
        }
    }

    public function test_holds_the_same_price_in_another_format_does_not_overwrite_a_concurrent_change(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway']);
        $domain = $this->domain(['recurring_amount' => '20.00', 'payment_gateway_id' => null, 'notes' => null]);
        $page = $this->livewireAs($this->editor())->test(ViewDomain::class, ['record' => $domain->id]);
        $domain->forceFill(['recurring_amount' => '15.00', 'payment_gateway_id' => $gateway->id, 'notes' => 'theirs', 'no_auto_charge' => true])->save();

        $page->saveBilling(['recurring_amount' => '20', 'payment_gateway_id' => '', 'notes' => '', 'no_auto_charge' => false])->assertHasNoFormErrors([], 'billingForm');
        $this->assertSame([true, true, 1, '15.00', $gateway->id, 'theirs'], $this->billing($domain));
    }

    /**
     * ADV-831-1: price and period changed in one «Save» (what the form now invites: both side by side, the helper says
     * «change that too»). The period goes first, against the old price (none by hand), so the open renewal invoice is
     * re-priced to the catalog's 2 years (45.00); then the hand price 30.00 is written. The customer is billed 45.00
     * for a domain staff just priced at 30.00.
     */
    public function test_price_and_period_saved_together_leave_the_open_invoice_at_the_catalog_price(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain);
        $this->livewireAs($this->editor())->test(ViewDomain::class, ['record' => $domain->id])
            ->saveBilling(['years' => 2, 'recurring_amount' => '30.00'])->assertHasNoFormErrors([], 'billingForm');

        $this->assertSame([2, '30.00'], [$domain->fresh()->years, (string) $domain->fresh()->recurring_amount]);
        $this->assertNotSame('45.00', (string) $line->fresh()->price, 'the open invoice is at the catalog price, not the price staff set');
    }
}
