<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Livewire\Invoices\Show;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\PaymentReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Round-3 (final) adversarial review of #539: the preferred payment method per invoice. */
class InvoicePreferredMethodRound3Test extends TestCase
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

    /**
     * ADV-539-R3-1. A customer who paid by bank transfer (the invoice prefers it: the gateway it last paid with, or WHMCS's
     * «banktransfer», or staff's choice) then saves a card with Auto-charge on: the open renewal invoice is now paid on
     * its own, and the bank transfer refuses it with its «charged» page (BT-ADV-3). R2-1 stopped that refusal from
     * becoming the preferred method, but the method that was already preferred is still named and chosen: the «Payment
     * failed» email (and the overdue notice, the domain expired notice) says «Pay with Bank Transfer», its page opens on
     * the bank transfer, and Pay shows «it will be paid automatically». The button names, and the page preselects, a way
     * that cannot take the invoice.
     */
    public function test_adv_539_r3_1_a_bank_transfer_that_refuses_an_invoice_paid_on_its_own_is_neither_named_nor_chosen(): void
    {
        MailFacade::fake();
        config(['settings.mail_disable' => false]);
        $user = User::factory()->create();
        $client = $user->client();
        $agreement = BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $client->id, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'preferred_gateway_id' => $this->bank->id]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10.00, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        // The card saved afterwards, Auto-charge on: the invoice is now paid on its own.
        $client->forceFill(['default_billing_agreement_id' => $agreement->id, 'auto_charge' => true])->save();
        $invoice = $invoice->fresh();
        $this->assertTrue(PaymentReminders::chargedOnItsOwn($invoice), 'setup: paid on its own');

        // The page: the bank transfer chosen, and Pay is its refusal.
        $page = $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice]);
        $chosen = $page->get('selectedMethod');
        $page->call('processPayment');
        $refused = str_contains($page->html(), 'paid automatically');

        // The email after a failed charge.
        NotificationHelper::invoiceNotification($client, $invoice, 'invoice_payment_failed');
        $mail = MailFacade::queued(Mail::class)->first(fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed');

        $this->assertSame(['', false, false], [
            NotificationHelper::preferredMethod($invoice),
            $chosen === 'gateway-' . $this->bank->id && $refused,
            str_contains((string) $mail?->renderedHtml, 'Pay with Bank Transfer'),
        ], 'the bank transfer that refuses this invoice is named on its button and chosen on its page');
    }

    /**
     * ADV-539-R3-2. Round 2 made a split move take the new account's own method («never the old account's card's
     * gateway», docs/payments.md: «Μια μεταφορά σε άλλον πελάτη παίρνει του νέου λογαριασμού»). An invoice that bills only
     * the moved service moves whole (MoveToClient's $whole branch: client_id changed, saved): its preferred_gateway_id,
     * set from the old account's card, goes with it. The new account (no saved method, nothing paid) gets the old
     * account's card's gateway named and chosen, against the doc and against what its split sibling does.
     */
    public function test_adv_539_r3_2_a_whole_move_takes_the_new_accounts_method_too(): void
    {
        MailFacade::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $from = User::factory()->create()->client();
        $to = User::factory()->create()->client();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $from->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 10, 'expires_at' => now()->addMonth()]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        // The old account's card's gateway, as the observer set it for it.
        $invoice = Invoice::query()->create(['client_id' => $from->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID,
            'preferred_gateway_id' => $this->stripe->id]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'quantity' => 1, 'price' => 10, 'description' => 'Line']);

        $result = app(MoveToClient::class)->service($service, $to, $staff);

        $this->assertSame([$invoice->id], $result['moved'], 'setup: moved whole');
        $this->assertSame(Invoice::preferredGatewayFor($to->id), $invoice->fresh()->preferred_gateway_id,
            'a whole move kept the old account\'s card\'s gateway as the new account\'s preferred method');
    }
}
