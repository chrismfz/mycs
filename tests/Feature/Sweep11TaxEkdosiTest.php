<?php

namespace Tests\Feature;

use App\Events\Service\RenewalTaxChanged;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Tax\EuVat;
use App\Services\Tax\VatNumberCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\Vies\Transport;
use MyCS\Vies\Vies;
use Tests\TestCase;

/**
 * Sweep 11, tax/Ekdosi (S11-TAX-EKDOSI-1). The nixpal cut-over shape (docs/accounts.md: 27 EU accounts with a VAT
 * number, all `taxexempt` in WHMCS, i.e. reverse charge): the importer carries the flag as clients.tax_exempt and the
 * number as contacts.vat_number. OrderPricing::taxDecision reads tax_exempt before EuVat::zeroReason, so the VIES
 * status of such an account decides nothing, while VatNumberCheck still mails staff that it does.
 */
class Sweep11TaxEkdosiTest extends TestCase
{
    use RefreshDatabase;

    private bool $viesValid = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'EUR',
            'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE', 'settings.eu_vat_requester' => 'EE100000000',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        $test = $this;
        $this->app->bind(Vies::class, fn () => new Vies(new class($test) implements Transport
        {
            public function __construct(private Sweep11TaxEkdosiTest $test) {}

            public function postJson(string $url, array $body): array
            {
                return $this->test->answer();
            }
        }, config('settings.eu_vat_requester')));
    }

    /** @internal the fake VIES: no network */
    public function answer(): array
    {
        return ['status' => 200, 'body' => json_encode(['valid' => $this->viesValid, 'name' => 'Example', 'address' => '---', 'requestIdentifier' => 'WAPI1'])];
    }

    /** As imported: a German business, WHMCS `taxexempt`, its number found valid by VIES (vat:recheck). */
    private function importedEuBusiness(): Client
    {
        $client = User::factory()->create()->client();
        Queue::fake();
        $client->primaryContact->update(['country' => 'DE', 'vat_number' => 'DE129273398', 'type' => 'business', 'company_name' => 'Example']);
        $client->primaryContact->forceFill(['vat_status' => EuVat::STATUS_VALID, 'vat_checked_number' => 'DE129273398', 'vat_checked_at' => now()])->save();
        $client->forceFill(['tax_exempt' => true])->save();

        return $client->fresh();
    }

    private function line(Client $client): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);

        return $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);
    }

    /**
     * VIES stops finding the number valid. Staff are mailed «Its invoices are charged VAT from now on», but the next
     * renewal line is still 0% («exempt»): VAT is not charged and nothing else tells staff.
     */
    public function test_staff_are_not_told_vat_is_charged_while_the_exempt_flag_keeps_it_at_zero(): void
    {
        $this->markTestIncomplete('known (2026-11-12): a client imported as WHMCS tax-exempt stays 0% «exempt» with no reverse charge or VIES; with the owner for a decision');
        $client = $this->importedEuBusiness();
        Event::fake([RenewalTaxChanged::class]);
        $this->viesValid = false;

        app(VatNumberCheck::class)->check($client->primaryContact);

        $this->assertSame(EuVat::STATUS_INVALID, $client->primaryContact->fresh()->vat_status);
        $line = $this->line($client->fresh());
        $told = EmailLog::query()->where('subject', 'VAT number no longer valid')->get()
            ->contains(fn (EmailLog $mail) => str_contains(json_encode($mail->getAttributes()), 'charged VAT from now on'));
        $charged = (float) $line->tax_rate > 0;

        $this->assertSame($told, $charged, sprintf(
            'staff were %s that the invoices are charged VAT from now on, and the next line has tax_rate %s (%s)',
            $told ? 'told' : 'not told', (string) $line->tax_rate, $line->tax_reason ?? 'no reason'));
    }

    /**
     * The same account while its number is valid: 0% as «exempt», not «reverse_charge», so the proforma prints no
     * Art. 196 note and the Ekdosi feed would carry `exempt`.
     */
    public function test_an_imported_eu_business_with_a_valid_number_is_reverse_charged_on_the_proforma(): void
    {
        $this->markTestIncomplete('known (2026-11-12): a client imported as WHMCS tax-exempt stays 0% «exempt» with no reverse charge or VIES; with the owner for a decision');
        $line = $this->line($this->importedEuBusiness());

        $this->assertSame('0.0000', (string) $line->tax_rate);
        $this->assertSame(EuVat::REASON_REVERSE_CHARGE, $line->tax_reason);
        $this->assertSame([__('invoices.reverse_charge_note')], $line->invoice->fresh()->taxNotes());
    }
}
