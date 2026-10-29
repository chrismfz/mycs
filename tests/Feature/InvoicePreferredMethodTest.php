<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\TemplateRenderer;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Each invoice's preferred payment method, as WHMCS's invoice payment method (owner 2026-10-29): set when it is made (the
 * account's saved method's gateway, else the last one it paid with), its page opens with it chosen (the customer may pick
 * another, which it then keeps), its email's button names it.
 */
class InvoicePreferredMethodTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $bank;

    private Gateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
    }

    private function agreement(int $clientId): BillingAgreement
    {
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $clientId, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
    }

    public function test_a_new_invoice_prefers_the_saved_methods_gateway_else_the_last_one_paid_with(): void
    {
        $client = User::factory()->create()->client();
        $this->assertNull(Invoice::factory()->create(['client_id' => $client->id])->preferred_gateway_id, 'nothing known: none, every method offered');

        $paid = Invoice::factory()->create(['client_id' => $client->id]);
        DB::table('invoice_transactions')->insert(['invoice_id' => $paid->id, 'gateway_id' => $this->bank->id, 'amount' => 10,
            'status' => InvoiceTransactionStatus::Succeeded->value, 'transaction_id' => 'bt-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($this->bank->id, Invoice::factory()->create(['client_id' => $client->id])->preferred_gateway_id, 'the gateway it last paid with');

        $client->forceFill(['default_billing_agreement_id' => $this->agreement($client->id)->id])->save();
        $this->assertSame($this->stripe->id, Invoice::factory()->create(['client_id' => $client->id])->preferred_gateway_id, 'its saved method\'s, first');
        $this->assertSame($this->bank->id, Invoice::factory()->create(['client_id' => $client->id, 'preferred_gateway_id' => $this->bank->id])->preferred_gateway_id,
            'one given (a checkout, staff) stays');
    }

    public function test_its_page_opens_with_it_chosen_and_keeps_the_one_the_customer_pays_with(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'preferred_gateway_id' => $this->bank->id]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);

        $page = $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()]);
        $page->assertSet('selectedMethod', 'gateway-' . $this->bank->id);

        // Its saved method on the preferred gateway: that one chosen.
        $agreement = $this->agreement($client->id);
        $client->forceFill(['default_billing_agreement_id' => $agreement->id])->save();
        $invoice->update(['preferred_gateway_id' => $this->stripe->id]);
        $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()])->assertSet('selectedMethod', $agreement->ulid);

        // The customer pays another way: the invoice keeps it, as WHMCS keeps the one picked.
        $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->set('selectedMethod', 'gateway-' . $this->bank->id)->call('processPayment');
        $this->assertSame($this->bank->id, $invoice->fresh()->preferred_gateway_id);
    }

    public function test_a_gateway_that_cannot_take_the_invoice_chooses_nothing(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'preferred_gateway_id' => $this->bank->id]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);
        $this->bank->settings()->create(['key' => 'currencies', 'value' => 'EUR']); // it takes none of this invoice's currency

        $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()])->assertSet('selectedMethod', null);
    }

    public function test_its_email_button_names_it_and_opens_its_payment(): void
    {
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'preferred_gateway_id' => $this->bank->id]);
        $template = DB::table('notification_templates')->where('key', 'new_invoice_created')->value('body');
        $this->assertStringContainsString('{{ urls.pay }}', (string) $template);

        $context = TemplateRenderer::context(['invoice' => $invoice, 'preferred_method' => 'Bank Transfer']);
        $this->assertSame(route('invoices.show', ['invoice' => $invoice, 'pay' => 1]), $context['urls']['pay']);
        $html = TemplateRenderer::render('<a href="{{ urls.pay }}">{% if preferred_method %}Pay with {{ preferred_method }}{% else %}View & pay invoice{% endif %}</a>', $context);
        $this->assertStringContainsString('Pay with Bank Transfer', $html);
    }

    public function test_the_migration_changes_only_a_default_button(): void
    {
        DB::table('notification_templates')->insert(['key' => 'custom_a', 'subject' => 's', 'enabled' => true,
            'body' => "<a class=\"button\" href=\"{{ urls.invoice }}\">\n\tView & pay invoice\n</a>",
            'translations' => json_encode(['el' => ['body' => '<a href="{{ urls.invoice }}">Προβολή & πληρωμή</a>']], JSON_UNESCAPED_UNICODE)]);
        DB::table('notification_templates')->insert(['key' => 'custom_b', 'subject' => 's', 'enabled' => true,
            'body' => '<a href="{{ urls.invoice }}">Click here, dear customer</a>', 'translations' => null]);

        (require database_path('migrations/2027_01_23_000001_invoice_buttons_name_preferred_method.php'))->up();

        $a = DB::table('notification_templates')->where('key', 'custom_a')->first();
        $this->assertStringContainsString('href="{{ urls.pay }}">' . "\n\t" . '{% if preferred_method %}Pay with', $a->body);
        $this->assertStringContainsString('Πληρωμή με {{ preferred_method }}', json_decode($a->translations, true)['el']['body']);
        $this->assertSame('<a href="{{ urls.invoice }}">Click here, dear customer</a>', DB::table('notification_templates')->where('key', 'custom_b')->value('body'),
            'a button staff wrote themselves: as it is');
    }

    /** From WHMCS: each invoice's paymentmethod as its preferred gateway (bank transfer too); a module with none here: none. */
    public function test_the_import_brings_each_invoices_payment_method(): void
    {
        $client = User::factory()->create()->client();
        $a = Invoice::factory()->create(['client_id' => $client->id]);
        $b = Invoice::factory()->create(['client_id' => $client->id]);
        $c = Invoice::factory()->create(['client_id' => $client->id]);
        DB::table('invoices')->update(['preferred_gateway_id' => null]);
        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, paymentmethod TEXT)');
        $whmcs->prepare("INSERT INTO tblinvoices VALUES (?, 'banktransfer'), (?, 'stripe'), (?, 'someoldmodule')")->execute([$a->id, $b->id, $c->id]);
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new \ReflectionMethod($command, 'importInvoicePreferences'))->invoke($command);

        $this->assertSame([$a->id => $this->bank->id, $b->id => $this->stripe->id, $c->id => null],
            Invoice::query()->orderBy('id')->pluck('preferred_gateway_id', 'id')->all());
    }
}
