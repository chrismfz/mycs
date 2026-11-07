<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Independent review of the sweep's «payments» fixes (2026-11-07): failing tests for what the fixes leave open. */
#[Group('adversarial')]
class RevPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * REV-PAYMENTS-1: PAYMENTS-1's main path is a vault charge (PayPal::charge(), the cron's saved PayPal method). Its
     * capture came back PENDING: the attempt finished «succeeded» and the payment was recorded processing. PayPal then
     * denies the capture. The fix fails the processing payment, but the charge attempt is left «succeeded»: with no
     * succeeded/processing payment of its amount, notRecorded() now reads it as «taken, payment not recorded», so after
     * an hour the invoice is still held from every way of paying (and the cron tells staff money was taken that never
     * came). PayPal said no money came for this very capture: the attempt is settled as failed, the invoice payable.
     */
    public function test_a_denied_pending_vault_capture_does_not_leave_its_charge_attempt_holding_the_invoice(): void
    {
        $paypal = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $paypal->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $paypal->id,
            'name' => 'PayPal', 'external_reference' => 'vault-token-1', 'type' => 'paypal']);

        // Two hours ago the cron charged the saved PayPal method: PayPal answered with a PENDING capture.
        Carbon::setTestNow('2026-11-10 07:00:00');
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $this->user->client()->id,
            'billing_agreement_id' => $agreement->id, 'amount' => 10, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_SUCCEEDED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-VAULT-1', InvoiceTransactionStatus::Processing, currency: 'USD');
        Carbon::setTestNow('2026-11-10 09:00:00');

        $this->postJson('/extensions/paypal/webhook', ['id' => 'WH-EV-REV-1', 'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => ['id' => 'CAP-VAULT-1', 'status' => 'DENIED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']]])->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status, 'precondition: the fix failed the payment');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()),
            'PayPal denied the very capture of this charge: no money came, the invoice is payable again');
    }
}
