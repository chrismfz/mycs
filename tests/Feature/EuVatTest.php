<?php

namespace Tests\Feature;

use App\Console\Commands\VatRecheck;
use App\Events\Service\RenewalTaxChanged;
use App\Jobs\CheckVatNumber;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\VatCheck;
use App\Services\Tax\EuVat;
use App\Services\Tax\VatNumberCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use MyCS\Vies\Transport;
use MyCS\Vies\Vies;
use MyCS\Vies\ViesUnavailable;
use Tests\TestCase;

/**
 * PR G (docs/eu-vat.md): EU VAT for nixpal (home EE, decision 9). Outside the EU no VAT; an EU business of another
 * member state with a number VIES found valid is reverse charged; everything else pays its country's rate. Fail closed:
 * a number not known valid is charged VAT, and a VIES outage changes nothing.
 */
class EuVatTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{status: int, body: string}|\Throwable|\Closure> what VIES answers, in order */
    private array $answers = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'EUR',
            'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE', 'settings.eu_vat_requester' => 'EE100000000',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        TaxRate::create(['name' => 'VAT', 'rate' => 20, 'country' => 'all']);
        $test = $this;
        $this->app->bind(Vies::class, fn () => new Vies(new class($test) implements Transport
        {
            public function __construct(private EuVatTest $test) {}

            public function postJson(string $url, array $body): array
            {
                return $this->test->answer($body);
            }
        }, config('settings.eu_vat_requester')));
    }

    /** @internal the fake VIES */
    public function answer(array $body): array
    {
        $this->sent[] = $body;
        $answer = array_shift($this->answers) ?? new ViesUnavailable('no answer queued');
        if ($answer instanceof \Closure) {
            $answer = $answer();
        }
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    private function vies(bool $valid, string $identifier = 'WAPI1'): array
    {
        return ['status' => 200, 'body' => json_encode(['valid' => $valid, 'name' => 'Example', 'address' => '---', 'requestIdentifier' => $identifier])];
    }

    /** An account; its primary contact in $country with $vat, not checked yet unless $status. */
    private function client(?string $country, ?string $vat = null, ?string $status = null): Client
    {
        $client = User::factory()->create()->client();
        Queue::fake();
        // With a VAT number, a business: only a business is reverse charged (adversarial ADV-8).
        $client->primaryContact->update(['country' => $country, 'vat_number' => $vat] + ($vat !== null ? ['type' => 'business', 'company_name' => 'Example'] : []));
        if ($status !== null) {
            $client->primaryContact->forceFill(['vat_status' => $status, 'vat_checked_at' => now()])->save();
        }

        return $client->fresh();
    }

    private function line(Client $client, array $extra = []): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);

        return $invoice->items()->create($extra + ['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);
    }

    /** @return array{string, ?string} */
    private function tax(Client $client): array
    {
        $line = $this->line($client);

        return [(string) $line->tax_rate, $line->tax_reason];
    }

    public function test_who_pays_what(): void
    {
        $this->assertSame(['24.0000', null], $this->tax($this->client('EE', 'EE100000001', EuVat::STATUS_VALID)), 'a business at home: never reverse charged');
        $this->assertSame(['0.0000', 'reverse_charge'], $this->tax($this->client('DE', 'DE129273398', EuVat::STATUS_VALID)));
        $this->assertSame(['19.0000', null], $this->tax($this->client('DE')), 'a consumer: its country\'s rate');
        $this->assertSame(['0.0000', 'outside_eu'], $this->tax($this->client('US')), 'outside the EU: none, whatever «all» says');
        $this->assertSame(['20.0000', null], $this->tax($this->client(null)), 'no country: as before');
    }

    /** Fail closed: a number not known valid is charged VAT. */
    public function test_a_number_not_known_valid_is_charged_vat(): void
    {
        $this->assertSame(['19.0000', null], $this->tax($this->client('DE', 'DE129273398')), 'pending');
        $this->assertSame(['19.0000', null], $this->tax($this->client('DE', 'DE129273398', EuVat::STATUS_INVALID)));
    }

    public function test_the_number_must_be_of_the_accounts_country(): void
    {
        $this->assertSame(['19.0000', null], $this->tax($this->client('DE', 'ATU18522105', EuVat::STATUS_VALID)));
    }

    public function test_off_nothing_changes(): void
    {
        config(['settings.eu_vat_enabled' => false]);

        $this->assertSame(['19.0000', null], $this->tax($this->client('DE', 'DE129273398', EuVat::STATUS_VALID)));
        $this->assertSame(['20.0000', null], $this->tax($this->client('US')));
    }

    /** VIES answers EL for Greece, the country field says GR (Blesta's defect, Π6.2). */
    public function test_greece_el_and_gr(): void
    {
        $this->assertSame(['0.0000', 'reverse_charge'], $this->tax($this->client('GR', 'EL094014201', EuVat::STATUS_VALID)));
        $this->assertSame('EL094014201', Contact::query()->where('vat_number', 'EL094014201')->value('vat_checked_number'));

        config(['settings.eu_vat_home_country' => 'GR']);
        $this->assertSame(['24.0000', null], $this->tax($this->client('GR', 'EL094014201', EuVat::STATUS_VALID)), 'a Greek business at a Greek home');
        $this->assertSame(['24.0000', null], $this->tax($this->client('GR', 'GR094014201', EuVat::STATUS_VALID)));
    }

    public function test_a_new_number_is_pending_and_checked_after_the_save(): void
    {
        $client = $this->client('DE');
        Queue::fake();

        $client->primaryContact->update(['vat_number' => 'DE 129 273 398', 'type' => 'business', 'company_name' => 'Example']);

        $contact = $client->primaryContact->fresh();
        $this->assertSame([EuVat::STATUS_PENDING, 'DE129273398'], [$contact->vat_status, $contact->vat_checked_number]);
        Queue::assertPushed(CheckVatNumber::class, fn (CheckVatNumber $job) => $job->contactId === $contact->id);

        $this->answers[] = $this->vies(true, 'WAPI42');
        (new CheckVatNumber($contact->id))->handle(app(VatNumberCheck::class));

        $this->assertSame(EuVat::STATUS_VALID, $contact->fresh()->vat_status);
        $check = VatCheck::query()->sole();
        $this->assertSame(['DE129273398', 'valid', 'vies', 'WAPI42', 'Example', null], [$check->vat_number, $check->result, $check->source,
            $check->request_identifier, $check->name, $check->address]);
        $this->assertSame(['countryCode' => 'DE', 'vatNumber' => '129273398', 'requesterMemberStateCode' => 'EE', 'requesterNumber' => '100000000'], $this->sent[0]);
        $this->assertSame(['0.0000', 'reverse_charge'], $this->tax($client->fresh()));
    }

    /** VIES down: a pending number stays pending (VAT charged), never exempted (Blesta's defect, Π6.1). */
    public function test_vies_down_exempts_nobody(): void
    {
        $client = $this->client('DE', 'DE129273398');
        $this->answers[] = new ViesUnavailable('MS_UNAVAILABLE');

        $this->assertSame(VatCheck::RESULT_UNAVAILABLE, app(VatNumberCheck::class)->check($client->primaryContact));

        $this->assertSame(EuVat::STATUS_PENDING, $client->primaryContact->fresh()->vat_status);
        $this->assertSame(['19.0000', null], $this->tax($client->fresh()));
        $this->assertSame('MS_UNAVAILABLE', VatCheck::query()->sole()->error);
    }

    /** VIES down: a number known valid stays valid; it is not charged VAT because of an outage. */
    public function test_vies_down_keeps_a_valid_number_valid(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);
        $this->answers[] = ['status' => 200, 'body' => json_encode(['errorWrappers' => [['error' => 'SERVICE_UNAVAILABLE']]])];

        app(VatNumberCheck::class)->check($client->primaryContact);

        $this->assertSame(EuVat::STATUS_VALID, $client->primaryContact->fresh()->vat_status);
    }

    public function test_a_number_that_turns_invalid_is_charged_and_staff_are_told(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);
        Event::fake([RenewalTaxChanged::class]);
        $this->answers[] = $this->vies(false);

        app(VatNumberCheck::class)->check($client->primaryContact);

        $this->assertSame(EuVat::STATUS_INVALID, $client->primaryContact->fresh()->vat_status);
        $this->assertSame(['19.0000', null], $this->tax($client->fresh()));
        $this->assertSame(1, EmailLog::query()->where('subject', 'VAT number no longer valid')->count());
    }

    /** A new number, or one changed while VIES answered about the old one, is not valid by the old answer. */
    public function test_a_changed_number_is_not_valid_by_an_old_answer(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);
        $contact = $client->primaryContact;

        $contact->update(['vat_number' => 'DE811569869']);
        $this->assertSame(['19.0000', null], $this->tax($client->fresh()), 'pending from the save on');

        $this->answers[] = function () use ($contact) {
            Contact::query()->whereKey($contact->id)->update(['vat_number' => 'DE999999999']);

            return $this->vies(true);
        };
        app(VatNumberCheck::class)->check($contact->fresh());

        $this->assertSame(EuVat::STATUS_PENDING, $contact->fresh()->vat_status);
        $this->assertSame(1, VatCheck::query()->count(), 'the answer is kept, with the number it was about');
    }

    public function test_a_number_removed_is_no_longer_reverse_charged(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);

        $client->primaryContact->update(['vat_number' => null]);

        $this->assertNull($client->primaryContact->fresh()->vat_status);
        $this->assertSame(['19.0000', null], $this->tax($client->fresh()));
    }

    public function test_a_status_change_updates_the_subscriptions(): void
    {
        $client = $this->client('DE', 'DE129273398');
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'EUR']);
        Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'EUR', 'status' => Service::STATUS_ACTIVE, 'subscription_id' => 'sub_1']);
        Event::fake([RenewalTaxChanged::class]);
        $this->answers[] = $this->vies(true);

        app(VatNumberCheck::class)->check($client->primaryContact);

        Event::assertDispatched(RenewalTaxChanged::class);
    }

    public function test_staff_set_the_status_with_a_reason(): void
    {
        $client = $this->client('DE', 'DE129273398');

        app(VatNumberCheck::class)->set($client->primaryContact, true, 'VIES down for DE for a week; certificate on file', null);

        $this->assertSame(EuVat::STATUS_VALID, $client->primaryContact->fresh()->vat_status);
        $check = VatCheck::query()->sole();
        $this->assertSame(['valid', 'staff', 'VIES down for DE for a week; certificate on file'], [$check->result, $check->source, $check->reason]);
        $this->assertSame([], $this->sent, 'VIES not asked');
    }

    public function test_the_recheck_takes_pending_and_month_old_numbers(): void
    {
        $pending = $this->client('DE', 'DE129273398')->primaryContact;
        $old = $this->client('AT', 'ATU18522105', EuVat::STATUS_VALID)->primaryContact;
        $old->forceFill(['vat_checked_at' => now()->subDays(VatRecheck::RECHECK_DAYS + 1)])->save();
        $recent = $this->client('FR', 'FR40303265045', EuVat::STATUS_VALID)->primaryContact;
        $this->answers = [$this->vies(true), $this->vies(true)];

        $this->artisan('vat:recheck')->assertSuccessful();

        $this->assertSame(['DE', 'AT'], array_column($this->sent, 'countryCode'));
        $this->assertSame(EuVat::STATUS_VALID, $pending->fresh()->vat_status);
        $this->assertTrue($old->fresh()->vat_checked_at->isToday());
        $this->assertFalse($recent->fresh()->vat_checked_at->lt(now()->subDay()));
    }

    public function test_the_recheck_does_nothing_when_eu_vat_is_off(): void
    {
        $this->client('DE', 'DE129273398');
        config(['settings.eu_vat_enabled' => false]);

        $this->artisan('vat:recheck')->assertSuccessful();

        $this->assertSame([], $this->sent);
    }

    public function test_each_line_keeps_why_it_has_no_vat_and_the_proforma_says_so(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);
        $line = $this->line($client);
        $invoice = $line->invoice;

        $this->assertSame([__('invoices.reverse_charge_note')], $invoice->fresh()->taxNotes());
        $this->assertSame([__('invoices.outside_eu_note')], $this->line($this->client('US'))->invoice->fresh()->taxNotes());
        $this->assertSame([], $this->line($this->client('DE'))->invoice->fresh()->taxNotes());

        $exempt = $this->client('DE');
        $exempt->forceFill(['tax_exempt' => true])->save();
        $this->assertSame(['0.0000', 'exempt'], $this->tax($exempt->fresh()));
        $this->assertSame('not_taxable', $this->line($this->client('DE'), ['taxed' => false])->tax_reason);
        $this->assertNull($this->line($this->client('DE'), ['reference_type' => Credit::class, 'reference_id' => 1])->tax_reason, 'credit is not a sale');
    }

    /** An issued line keeps its rate and reason: a later VAT status change does not touch it (G2 re-rates open ones). */
    public function test_an_issued_line_keeps_its_rate_and_reason(): void
    {
        $client = $this->client('DE', 'DE129273398', EuVat::STATUS_VALID);
        $line = $this->line($client);
        $this->answers[] = $this->vies(false);

        app(VatNumberCheck::class)->check($client->primaryContact);

        $this->assertSame(['0.0000', 'reverse_charge'], [(string) $line->fresh()->tax_rate, $line->fresh()->tax_reason]);
    }
}
