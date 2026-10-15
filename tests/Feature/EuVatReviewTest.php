<?php

namespace Tests\Feature;

use App\Console\Commands\VatRecheck;
use App\Jobs\CheckVatNumber;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Order\OrderPricing;
use App\Services\Tax\EuVat;
use App\Services\Tax\VatNumberCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use MyCS\Vies\Transport;
use MyCS\Vies\Vies;
use MyCS\Vies\ViesUnavailable;
use Tests\TestCase;

/**
 * Independent code review of PR G1 (EU VAT / VIES). Each test_cr_* failed on feat/eu-vat-vies and names the finding it
 * proves; test_guard_* pass and pin behaviour worth keeping.
 */
class EuVatReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{status: int, body: string}|\Throwable> */
    private array $answers = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'EUR',
            'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE', 'settings.eu_vat_requester' => 'EE100000000',
            'settings.system_email_address' => 'staff@example.test']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        TaxRate::create(['name' => 'USt', 'rate' => 20, 'country' => 'AT']);
        TaxRate::create(['name' => 'VAT', 'rate' => 20, 'country' => 'all']);
        $test = $this;
        $this->app->bind(Vies::class, fn () => new Vies(new class($test) implements Transport
        {
            public function __construct(private EuVatReviewTest $test) {}

            public function postJson(string $url, array $body): array
            {
                return $this->test->answer($body);
            }
        }, config('settings.eu_vat_requester')));
    }

    /** @internal the fake VIES: queued answers, then «no answer» */
    public function answer(array $body): array
    {
        $this->sent[] = $body;
        $answer = array_shift($this->answers) ?? new ViesUnavailable('MS_UNAVAILABLE');
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    private function vies(bool $valid): array
    {
        return ['status' => 200, 'body' => json_encode(['valid' => $valid, 'name' => '---', 'address' => '---', 'requestIdentifier' => 'WAPI1'])];
    }

    private function client(?string $country, ?string $vat = null, ?string $status = null): Client
    {
        $client = User::factory()->create()->client();
        Queue::fake();
        $client->primaryContact->update(['country' => $country, 'vat_number' => $vat]);
        if ($status !== null) {
            $client->primaryContact->forceFill(['vat_status' => $status, 'vat_checked_at' => now()])->save();
        }

        return $client->fresh();
    }

    /**
     * CR-1. The status is keyed on the number as normalized with the contact's country, but only a change of
     * vat_number re-checks it. A number kept without its prefix (staff form, importer: WHMCS tax_id) checked under the
     * wrong country stays «invalid» when the country is corrected: the business is charged VAT until the monthly
     * recheck, and the status shown is that of another number (AT…), not of the one now billed (DE…).
     */
    public function test_cr_1_a_country_change_rechecks_a_number_without_prefix(): void
    {
        $client = $this->client('AT', '129273398');
        $contact = $client->primaryContact->fresh();
        $this->assertSame('AT129273398', $contact->vat_checked_number, 'precondition: normalized with the country');
        $this->answers[] = $this->vies(false);
        app(VatNumberCheck::class)->check($contact);
        $this->assertSame(EuVat::STATUS_INVALID, $contact->fresh()->vat_status, 'precondition');
        Queue::fake();

        $contact->fresh()->update(['country' => 'DE']);

        $contact = $contact->fresh();
        $this->assertSame('DE129273398', $contact->vat_checked_number, 'the status must be about the number as now read');
        $this->assertSame(EuVat::STATUS_PENDING, $contact->vat_status);
        Queue::assertPushed(CheckVatNumber::class);
    }

    /**
     * CR-2. vat:recheck takes the never-answered first, in id order, 100 per run, and a «no answer» leaves
     * vat_checked_at null. While 100 numbers keep getting no answer (a member state's VIES down for days, or a large
     * import backlog), the same 100 are taken every hour and a reverse-charged number older than 30 days is never
     * re-verified (Π6.4): it stays 0% indefinitely. Non-primary and archived contacts, whose status bills nothing,
     * take slots too (all 100 below are non-primary).
     */
    public function test_cr_2_unanswered_numbers_do_not_starve_the_monthly_recheck(): void
    {
        $stale = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID)->primaryContact;
        $stale->forceFill(['vat_checked_at' => now()->subDays(VatRecheck::RECHECK_DAYS + 5)])->save();
        $other = User::factory()->create()->client();
        Queue::fake();
        for ($i = 0; $i < VatRecheck::BATCH; $i++) {
            $other->contacts()->create(['first_name' => 'C' . $i, 'country' => 'FR', 'vat_number' => sprintf('FR%011d', $i)]);
        }

        $this->artisan('vat:recheck')->assertSuccessful();

        $this->assertContains('129273398', array_column($this->sent, 'vatNumber'),
            'the month-old reverse-charged number is never re-verified while 100 others get no answer');
    }

    /**
     * CR-3. The job and vat:recheck check every contact's number, primary or not, and VatNumberCheck::record alerts
     * staff when any of them turns invalid, saying the customer's invoices are now charged VAT. Only the primary
     * contact's status bills (EuVat::zeroReason): for another contact the mail is false, and the check spends VIES quota
     * (see CR-2).
     */
    public function test_cr_3_a_non_primary_contact_turning_invalid_is_not_a_billing_alert(): void
    {
        config(['settings.mail_disable' => false]);
        $client = $this->client('DE');
        $other = $client->contacts()->create(['first_name' => 'B', 'country' => 'AT', 'vat_number' => 'ATU18522105']);
        $other->forceFill(['vat_status' => EuVat::STATUS_VALID, 'vat_checked_at' => now()])->save();
        $this->answers[] = $this->vies(false);

        app(VatNumberCheck::class)->check($other->fresh());

        $this->assertSame(0, EmailLog::query()->where('subject', 'VAT number no longer valid')->count(),
            'staff are told the customer is now charged VAT; its billing did not change');
    }

    /** Guard: a staff override is kept with its reason, and VIES's later «no answer» does not undo it. */
    public function test_guard_a_vies_outage_keeps_a_staff_set_status(): void
    {
        $client = $this->client('DE', 'DE129273398');
        app(VatNumberCheck::class)->set($client->primaryContact, true, 'certificate on file', null);

        app(VatNumberCheck::class)->check($client->primaryContact->fresh());

        $this->assertSame(EuVat::STATUS_VALID, $client->primaryContact->fresh()->vat_status);
    }

    /** Guard: a reformatted number (spaces, GR for EL) is the same number: no new check, the status stays. */
    public function test_guard_reformatting_the_number_keeps_its_status(): void
    {
        $client = $this->client('GR', 'EL094014201', EuVat::STATUS_VALID);
        Queue::fake();

        $client->primaryContact->update(['vat_number' => 'GR 094.014.201']);

        $this->assertSame(EuVat::STATUS_VALID, $client->primaryContact->fresh()->vat_status);
        Queue::assertNotPushed(CheckVatNumber::class);
    }

    /** Guard: a non-primary contact's valid number never makes the account reverse charged. */
    public function test_guard_only_the_primary_contact_counts(): void
    {
        $client = $this->client('DE');
        $other = $client->contacts()->create(['first_name' => 'B', 'country' => 'DE', 'vat_number' => 'DE129273398']);
        $other->forceFill(['vat_status' => EuVat::STATUS_VALID])->save();

        $this->assertSame('19.0000', OrderPricing::taxRate($client->fresh()));
        $this->assertInstanceOf(Contact::class, $other);
    }
}
