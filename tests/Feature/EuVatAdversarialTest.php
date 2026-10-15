<?php

namespace Tests\Feature;

use App\Jobs\CheckVatNumber;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\VatCheck;
use App\Services\Tax\EuVat;
use App\Services\Tax\VatNumberCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use MyCS\Vies\Transport;
use MyCS\Vies\Vies;
use Tests\TestCase;

/**
 * Adversarial review of PR G1 (EU VAT / VIES). Every test here failed on feat/eu-vat-vies @ ef33cb7; each names its
 * finding (ADV-n). Example VAT numbers only.
 */
class EuVatAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var \Closure(array<string, mixed>): array{status: int, body: string} what the fake VIES answers to a request */
    public \Closure $respond;

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'EUR',
            'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE', 'settings.eu_vat_requester' => 'EE100000000',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        TaxRate::create(['name' => 'USt', 'rate' => 20, 'country' => 'AT']);
        TaxRate::create(['name' => 'TVA', 'rate' => 20, 'country' => 'FR']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        TaxRate::create(['name' => 'VAT', 'rate' => 20, 'country' => 'all']);
        $this->respond = fn (array $body) => self::unavailable();
        $test = $this;
        $this->app->bind(Vies::class, fn () => new Vies(new class($test) implements Transport
        {
            public function __construct(private EuVatAdversarialTest $test) {}

            public function postJson(string $url, array $body): array
            {
                $this->test->sent[] = $body;

                return ($this->test->respond)($body);
            }
        }, config('settings.eu_vat_requester')));
    }

    private static function vies(bool $valid, array $extra = []): array
    {
        return ['status' => 200, 'body' => json_encode($extra + ['valid' => $valid, 'name' => 'Example', 'address' => '---', 'requestIdentifier' => 'WAPI1'])];
    }

    private static function unavailable(string $error = 'MS_UNAVAILABLE'): array
    {
        return ['status' => 200, 'body' => json_encode(['actionSucceed' => false, 'errorWrappers' => [['error' => $error]]])];
    }

    private function client(?string $country, ?string $vat = null, ?string $status = null): Client
    {
        $client = User::factory()->create()->client();
        Queue::fake();
        // A business: only a business is reverse charged (ADV-8).
        $client->primaryContact->update(['country' => $country, 'vat_number' => $vat] + ($vat !== null ? ['type' => 'business', 'company_name' => 'Example'] : []));
        if ($status !== null) {
            $client->primaryContact->forceFill(['vat_status' => $status, 'vat_checked_at' => now()])->save();
        }

        return $client->fresh();
    }

    /** @return array{string, ?string} */
    private function tax(Client $client): array
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        /** @var InvoiceItem $line */
        $line = $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);

        return [(string) $line->tax_rate, $line->tax_reason];
    }

    /**
     * ADV-1 (Medium): vat:recheck starves. VIES-unavailable answers never move vat_checked_at, and the selection is
     * «checked_at IS NULL first, then by id», so the same 100 rows come first every hour. Any customer can fill those
     * slots: Contacts.php lets a customer add (non-primary, even archived later) contacts with VAT numbers, each one
     * pending and in the batch though it never affects tax. A reverse-charged customer whose number VIES now finds
     * invalid is then never rechecked and keeps paying 0% VAT (too little VAT, indefinitely).
     */
    public function test_adv1_recheck_is_not_starved_by_numbers_vies_cannot_answer(): void
    {
        $client = $this->client('AT', 'ATU18522105', EuVat::STATUS_VALID);
        $client->primaryContact->forceFill(['vat_checked_at' => now()->subDays(40)])->save();
        $other = User::factory()->create()->client();
        for ($i = 1; $i <= 100; $i++) {
            $other->contacts()->create(['first_name' => 'C' . $i, 'country' => 'DE', 'vat_number' => 'DE' . str_pad((string) $i, 9, '0', STR_PAD_LEFT)]);
        }
        // Germany's VIES is down; Austria answers that the number is no longer valid.
        $this->respond = fn (array $body) => $body['countryCode'] === 'AT' ? self::vies(false) : self::unavailable();

        for ($run = 0; $run < 3; $run++) {
            $this->artisan('vat:recheck')->assertSuccessful();
        }

        $this->assertContains('AT', array_column($this->sent, 'countryCode'), 'the month-old reverse-charged number is never rechecked');
        $this->assertSame(EuVat::STATUS_INVALID, $client->primaryContact->fresh()->vat_status);
        $this->assertSame(['20.0000', null], $this->tax($client->fresh()));
    }

    /**
     * ADV-2 (Medium): VIES's trader name goes unbounded into vat_checks.name (varchar 255). On MySQL strict mode a long
     * name throws 22001 inside the transaction: nothing is recorded, the status is not updated, the job fails, and
     * vat:recheck (no per-contact catch) dies on the same contact every hour, never reaching the rest of the batch.
     * SQLite does not enforce the length, so this asserts the stored value fits the column.
     */
    public function test_adv2_a_long_vies_name_fits_its_column(): void
    {
        $contact = $this->client('DE', 'DE129273398')->primaryContact;
        $this->respond = fn () => self::vies(true, ['name' => str_repeat('Example Handels GmbH & Co. KG ', 12)]);

        app(VatNumberCheck::class)->check($contact);

        $this->assertLessThanOrEqual(255, mb_strlen((string) VatCheck::query()->sole()->name));
    }

    /**
     * ADV-2b (Low): one contact whose check throws something other than ViesUnavailable/ViesInvalidFormat (here an
     * unexpected VIES field shape; on MySQL also ADV-2, or a failing staff mail) aborts the whole vat:recheck run, and
     * since its status is unchanged it is first again next hour: every contact after it is never checked.
     */
    public function test_adv2b_one_failing_check_does_not_abort_the_recheck(): void
    {
        $first = $this->client('DE', 'DE129273398')->primaryContact;
        $second = $this->client('AT', 'ATU18522105')->primaryContact;
        $this->respond = fn (array $body) => $body['countryCode'] === 'DE'
            ? self::vies(true, ['requestIdentifier' => ['unexpected']])
            : self::vies(true);

        try {
            $this->artisan('vat:recheck')->run();
        } catch (\Throwable) {
            // the run dies; what matters is whether the next contact was checked
        }

        $this->assertSame(EuVat::STATUS_VALID, $second->fresh()->vat_status, 'the contact after the failing one is never checked');
        $this->assertInstanceOf(Contact::class, $first);
    }

    /**
     * ADV-3 (Medium): Monaco is in the French VAT territory (Art. 7, Directive 2006/112/EC), but VatCountry::EU has no
     * MC, so a consumer in Monaco gets 0% «outside_eu» and the proforma says «services supplied outside the EU». They owe
     * French VAT (too little VAT). A Monaco business with a French FR… number is reverse charged only if the country is
     * read as FR.
     */
    public function test_adv3_monaco_is_in_the_french_vat_territory(): void
    {
        $this->assertSame(['20.0000', null], $this->tax($this->client('MC')));
    }

    /**
     * ADV-4 (Low): any two-letter code that is not an ISO country of the list is treated as «outside the EU» and pays 0%
     * (fail open). E.g. «EL» (VIES's code for Greece, which the contacts migration itself treats as an alias of GR) in a
     * row written by an import/API path that does not go through EditsContact's Rule::in. Unknown should be treated as
     * «no country» (the existing rule), never as an exemption.
     */
    public function test_adv4_an_unknown_country_code_is_not_an_exemption(): void
    {
        $client = $this->client('GR');
        Contact::query()->whereKey($client->primaryContact->id)->update(['country' => 'EL']);

        [$rate, $reason] = $this->tax($client->fresh());

        $this->assertNotSame(EuVat::REASON_OUTSIDE_EU, $reason);
        $this->assertNotSame('0.0000', $rate);
    }

    /**
     * ADV-5 (Low): a VAT number entered without prefix (staff ContactForm only checks maxLength) takes the contact's
     * country. Changing the country changes the effective number (DE129273398 → AT129273398), but the saving hook only
     * looks at vat_number: the status stays «valid» (staff see «Valid (VIES)»), no CheckVatNumber is dispatched and the
     * account silently pays VAT until the 30-day recheck (too much VAT; wrong status shown).
     */
    public function test_adv5_a_country_change_rechecks_a_number_without_prefix(): void
    {
        $client = $this->client('DE', '129273398', EuVat::STATUS_VALID);
        $this->assertSame(['0.0000', 'reverse_charge'], $this->tax($client), 'precondition');
        Queue::fake();

        $client->primaryContact->update(['country' => 'AT']);

        $this->assertSame(EuVat::STATUS_PENDING, $client->primaryContact->fresh()->vat_status);
        Queue::assertPushed(CheckVatNumber::class);
    }

    /**
     * ADV-6 (Low/Medium): a wrong «Own EU VAT number» setting (parses, but VIES rejects it: INVALID_REQUESTER_INFO) makes
     * every check «unavailable», forever and silently: new numbers stay pending (VAT charged), valid ones are never
     * re-verified (0% continues without proof), and nothing tells staff. It is a configuration error, not an outage.
     */
    public function test_adv6_a_rejected_requester_is_not_a_silent_outage(): void
    {
        $contact = $this->client('DE', 'DE129273398')->primaryContact;
        $this->respond = fn (array $body) => isset($body['requesterNumber']) ? self::unavailable('INVALID_REQUESTER_INFO') : self::vies(true);

        $result = app(VatNumberCheck::class)->check($contact);

        $this->assertTrue($result !== VatCheck::RESULT_UNAVAILABLE || EmailLog::query()->where('subject', 'like', '%VAT%')->exists(),
            'INVALID_REQUESTER_INFO is recorded as a plain outage: no answer, no retry without requester, no staff notice');
    }

    /**
     * ADV-7 (Low, design): a «valid» number VIES has not confirmed for months keeps 0% reverse charge with no bound and
     * no notice (VIES/member-state outage, or ADV-6). Sales in that window have no consultation number, i.e. no proof.
     * At least staff should be told once a reverse-charged number has gone unverified past the recheck window.
     */
    public function test_adv7_a_valid_number_unverified_for_months_is_flagged_to_staff(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);
        $client->primaryContact->forceFill(['vat_checked_at' => now()->subDays(90)])->save();

        $this->artisan('vat:recheck')->assertSuccessful();

        $this->assertSame(['0.0000', 'reverse_charge'], $this->tax($client->fresh()), 'still reverse charged, 90 days unverified');
        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%VAT%')->exists(), 'nobody is told');
    }

    /**
     * ADV-8 (Low, design): reverse charge needs a taxable person, but taxDecision never looks at the contact's type.
     * A contact the customer (or the WHMCS importer: no company name) marked «individual» who types any VIES-valid number
     * of their country, e.g. their employer's, gets 0% (too little VAT). VIES's name is stored but never compared either.
     * At least an «individual» contact should not be reverse charged (or should need staff confirmation).
     */
    public function test_adv8_an_individual_is_not_reverse_charged(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);
        $client->primaryContact->forceFill(['type' => 'individual', 'company_name' => null])->save();

        $this->assertSame(['19.0000', null], $this->tax($client->fresh()));
    }
}
