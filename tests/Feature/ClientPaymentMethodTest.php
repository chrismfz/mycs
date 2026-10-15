<?php

namespace Tests\Feature;

use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PR C2 (decision 6): the saved method that pays renewals is the account's (WHMCS, Blesta), with «Auto-charge» per account
 * (WHMCS `disableautocc`). The customer sets both on «Payment methods»; paying an invoice can set the method too.
 */
class ClientPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private function agreementOf(Client $client, string $ref): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);

        return BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal ' . $ref,
            'external_reference' => $ref, 'type' => 'paypal']);
    }

    public function test_the_first_method_is_the_default_and_a_later_one_is_not(): void
    {
        $client = User::factory()->create()->client();
        $first = $this->agreementOf($client, 'A');
        $this->agreementOf($client, 'B');

        $this->assertSame($first->id, $client->fresh()->autoChargeAgreement()?->id);
    }

    public function test_the_customer_picks_the_method_and_turns_auto_charge_off(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $this->agreementOf($client, 'A');
        $second = $this->agreementOf($client, 'B');
        $this->actingAs($user);

        $page = $this->livewireAs($user)->test(PaymentMethods::class)->call('makeDefault', $second->ulid);
        $this->assertSame($second->id, (int) $client->fresh()->default_billing_agreement_id);

        $page->set('autoCharge', false);
        $this->assertFalse($client->fresh()->auto_charge);
        $this->assertNull($client->fresh()->autoChargeAgreement(), 'auto-charge off: nothing is charged');
    }

    public function test_paying_an_invoice_with_set_as_default_sets_the_accounts_method(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $client = $user->client();
        $this->agreementOf($client, 'A');
        $second = $this->agreementOf($client, 'B');
        $client->update(['auto_charge' => false]);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => 10, 'quantity' => 1, 'description' => 'x']);
        $this->actingAs($user);

        try {
            $this->livewireAs($user)->test(InvoiceShow::class, ['invoice' => $invoice])
                ->set('selectedMethod', $second->ulid)->set('setAsDefault', true)->call('processPayment');
        } catch (\Throwable) {
            // the charge itself may fail without a configured gateway: the change happens before it
        }

        $client->refresh();
        $this->assertSame($second->id, (int) $client->default_billing_agreement_id);
        $this->assertTrue($client->auto_charge, 'asked to use it for the next payments');
    }

    public function test_the_migration_gives_each_account_the_method_most_of_its_services_had(): void
    {
        $client = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $a = $this->agreementOf($client, 'A');
        $b = $this->agreementOf($client, 'B');
        $removed = $this->agreementOf($other, 'R');
        $product = $this->createProduct();
        $service = fn (Client $owner, array $attributes = []) => Service::factory()->create($attributes + ['client_id' => $owner->id,
            'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $ids = [$service($client)->id, $service($client)->id, $service($client)->id, $service($client, ['status' => Service::STATUS_CANCELLED])->id,
            $service($other)->id];

        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0); // this PR's migration is the last one
        $this->assertTrue(Schema::hasColumn('services', 'billing_agreement_id'));
        // Before C2: two running services on B, one on A, a cancelled one on A; the other account's method was removed.
        DB::table('services')->whereIn('id', [$ids[0], $ids[1]])->update(['billing_agreement_id' => $b->id]);
        DB::table('services')->whereIn('id', [$ids[2], $ids[3]])->update(['billing_agreement_id' => $a->id]);
        DB::table('services')->where('id', $ids[4])->update(['billing_agreement_id' => $removed->id]);
        DB::table('billing_agreements')->where('id', $removed->id)->update(['deleted_at' => now()]);
        $this->artisan('migrate')->assertExitCode(0);

        // Dropped on MariaDB; SQLite keeps the unused column (see the migration).
        $this->assertSame(DB::getDriverName() === 'sqlite', Schema::hasColumn('services', 'billing_agreement_id'));
        $this->assertSame($b->id, (int) $client->fresh()->default_billing_agreement_id);
        $this->assertNull($other->fresh()->default_billing_agreement_id);
        $this->assertTrue($client->fresh()->auto_charge);
    }
}
