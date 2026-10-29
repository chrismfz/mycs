<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Helpers\NotificationHelper;
use App\Livewire\Invoices\Show;
use App\Mail\Mail;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Domains\DomainReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Round-2 adversarial review of #539: the preferred payment method per invoice. */
class InvoicePreferredMethodRound2Test extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * ADV-539-R2-1. The round-1 fix keeps the method once ExtensionHelper::pay returns anything but null/false. The bank
     * transfer refuses an invoice a saved method or subscription pays on its own (BT-ADV-3) by returning a View too: its
     * «charged» page («it will be paid on its due date, do not transfer»). That refusal is taken for «opened»: the invoice
     * now prefers the transfer it refused, its next email says «Pay with Bank Transfer» and its page opens on it, which
     * refuses again. A refusal changes nothing (ADV-539-4).
     */
    public function test_adv_539_r2_1_the_bank_transfers_refusal_does_not_become_the_preferred_method(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00, 'subscription_id' => 'I-SUB1']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'preferred_gateway_id' => $this->stripe->id]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10.00, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->set('selectedMethod', 'gateway-' . $this->bank->id)->call('processPayment')
            ->assertSee('paid automatically', false);

        $this->assertSame($this->stripe->id, $invoice->fresh()->preferred_gateway_id,
            'the bank transfer\'s «charged on its own» refusal became the invoice\'s preferred method');
    }

    /**
     * ADV-539-R2-2. The email now calls getCheckoutGateways, which builds every gateway's extension. One gateway row whose
     * extension is gone (removed from disk, renamed) throws «Extension not found» there: the invoice email is not sent at
     * all, though it only wanted a label for its button. Before #539 it was sent. A failure there names nothing.
     */
    public function test_adv_539_r2_2_a_broken_gateway_does_not_stop_the_invoice_email(): void
    {
        MailFacade::fake();
        config(['settings.mail_disable' => false]);
        Gateway::query()->create(['name' => 'Gone', 'extension' => 'GoneGateway', 'type' => 'gateway', 'enabled' => true]);
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'preferred_gateway_id' => $this->bank->id]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);

        try {
            NotificationHelper::invoiceNotification($client, $invoice->fresh(), 'new_invoice_created');
        } catch (\Throwable $e) {
            $this->fail('the invoice email failed on the preferred method\'s label: ' . $e->getMessage());
        }

        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_invoice_created');
    }

    /**
     * ADV-539-R2-3. The seeder and the migration changed «domain_expired_notice» to «Pay with {{ preferred_method }}», and
     * that notice stands in for a domain renewal's own overdue reminders (DomainReminders::covers). But DomainReminders
     * never passes preferred_method: a domain renewal's chasing email never names it, while the invoice's own reminders
     * do (docs/payments.md says the reminders name it).
     */
    public function test_adv_539_r2_3_the_domain_expired_notice_names_the_preferred_method(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        MailFacade::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.mail_disable' => false, 'settings.domain_billing_enabled' => true]);
        $user = User::factory()->create();
        $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'grace', 'years' => 1, 'currency_code' => 'EUR', 'price' => '100.30']);
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Grace, 'expires_at' => '2026-10-17',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR',
            'due_at' => '2026-10-17', 'preferred_gateway_id' => $this->bank->id]);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'Renewal', 'quantity' => 1,
            'price' => '23.33', 'period_start' => '2026-10-17', 'period_end' => '2027-10-17']);

        $this->assertSame(1, (new DomainReminders)->send());

        $mail = MailFacade::queued(Mail::class)->first();
        $this->assertSame('domain_expired_notice', $mail->templateKey);
        $this->assertStringContainsString('Pay with Bank Transfer', $mail->renderedHtml,
            'the domain renewal\'s chasing email never names the invoice\'s preferred method');
    }
}
