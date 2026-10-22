<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Domain sweep (2026-10-22), state and dates: expiry dates, lifecycle, the domain steps of the cron. Each test is a
 * finding that fails on the code as it is (test_F<n>_...).
 */
class DomainSweepDeletedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    /** What the fake CentralNic Reseller answers to StatusDomain. */
    private string $paidUntil = '2026-11-20';

    private ?string $renewalDate = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);
            $renewal = $this->renewalDate !== null ? "property[RENEWAL DATE][0] = {$this->renewalDate} 00:00:00.0\n" : '';

            return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = {$this->paidUntil} 00:00:00.0\n{$renewal}"
                . "property[STATUS][0] = clientTransferProhibited\nproperty[TRANSFER LOCK][0] = 1\nproperty[NAMESERVER][0] = ns1.example.net\nEOF\n");
        });
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.cronjob_invoice' => 7, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 0]);
        foreach ([['renew', 1, '23.33'], ['grace', 1, '100.30']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cnic(): DomainRegistrarConnection
    {
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $cnic->storeCredentials(['username' => 'reseller', 'password' => 'Cnic-Secret-1'], ['password']);

        return $cnic;
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-20',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** A renewal line of the domain on an invoice of $status, for [$start, $end). */
    private function line(Domain $domain, string $start, string $end, string $status, bool $applied): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $start, 'currency_code' => 'EUR']);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => "Renewal {$domain->fqdn}", 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]); // as the WHMCS import or a payment left it
        }
        if ($applied) {
            $line->forceFill(['applied_at' => now()])->save();
        }

        return $line->fresh();
    }

    /** @return list<InvoiceItem> */
    private function renewalLines(Domain $domain): array
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->orderBy('id')->get()->all();
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * F4 (expiry). The lifecycle deletes by computed dates any domain whose connection is not usable at that moment
     * (DomainLifecycle::next: not syncable and both stages known => Deleted; hasRegistrarStageDates() drops the registrar's
     * own dates when the connection is off). An Openprovider connection switched off for a while (credentials rotated,
     * mode «off») makes the 2-hourly move by dates end a domain the registrar still holds in its quarantine: Deleted is
     * terminal (never synced again), its open renewal invoice is cancelled by the cron, and nobody is told.
     */
    public function test_f4_a_connection_switched_off_for_a_while_deletes_a_domain_still_in_its_registrars_quarantine(): void
    {
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        // Expired on 2026-08-01; the registrar's soft quarantine (our grace) runs to 2026-11-05, by its own word (D5a).
        $domain = $this->domain(['registrar_connection_id' => $op->id, 'expires_at' => '2026-08-01', 'status' => DomainStatus::Grace,
            'grace_ends_on' => '2026-11-05']);
        $this->assertNull(app(DomainLifecycle::class)->next($domain->fresh()), 'precondition: in its grace by the registrar\'s dates');

        $op->update(['is_active' => false]); // switched off for an hour by staff
        $this->artisan('domains:sync', ['--scheduled' => true]);
        $op->update(['is_active' => true]);

        $this->assertNotSame(DomainStatus::Deleted, $domain->fresh()->status, 'still in the registrar\'s quarantine until 2026-11-05: never ended by our guess');
    }
}
