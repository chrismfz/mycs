<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #478 (E5, «Send to Ekdosi»). Each test is one finding and fails on the PR.
 */
class Adv478Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test/ekdosi',
            'settings.default_currency' => 'USD']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'A478' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function invoice(?Client $client = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);

        return $invoice->fresh();
    }

    /** @return list<int> */
    private function requested(): array
    {
        $raw = json_encode(['op' => 'invoices', 'status' => 'requested'], JSON_THROW_ON_ERROR);
        $response = $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);

        return array_map(fn (array $invoice) => (int) $invoice['invoiceid'], $response->assertOk()->json('invoices'));
    }

    /**
     * ADV-1 (P2): «Withdraw from Ekdosi» shows on a requested invoice that was cancelled since, but does nothing:
     * `request()` skips it as not eligible (only unpaid/paid), so the request can never be taken back while it is
     * cancelled (and comes back with the invoice, ADV-2). The button says «Nothing to change».
     */
    public function test_withdraw_shown_on_a_cancelled_requested_invoice_actually_withdraws(): void
    {
        $this->actingAs($staff = $this->staff(), 'staff');
        $invoice = $this->invoice();
        SendToEkdosi::request([$invoice], true);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('withdrawFromEkdosi')
            ->callAction('withdrawFromEkdosi');

        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'the withdraw button was shown and clicked, the request stays');
    }

    /**
     * ADV-2 (P1): a request outlives the invoice's cancellation. Staff send an unpaid invoice, then cancel it (the
     * customer will not take it); months later it is reopened (to bill it again, other price) and Ekdosi's `requested`
     * lists it again for a document on credit that nobody asked for this time: a wrong legal document, unasked.
     */
    public function test_a_cancelled_and_reopened_invoice_is_not_requested_again_unasked(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $invoice = $this->invoice();
        SendToEkdosi::request([$invoice], true);
        $this->assertSame([$invoice->id], $this->requested());

        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'customer declined']);
        $this->assertSame([], $this->requested());
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);

        $this->assertSame([], $this->requested(), 'the reopened invoice is listed for a document on the old request');
    }

    /**
     * ADV-3 (P2): `request()` decides eligibility on the model it is given, then updates by id with no status in the
     * WHERE. A bulk selection loaded before another staff cancelled one of them (or the cron cancelled it) marks the
     * cancelled invoice requested, counts it as sent and audits `ekdosi_requested` on a cancelled invoice (which ADV-2
     * then revives).
     */
    public function test_a_stale_model_does_not_request_an_invoice_cancelled_meanwhile(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $stale = $this->invoice();
        Invoice::query()->whereKey($stale->id)->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(0, SendToEkdosi::request([$stale], true), 'a cancelled invoice counted as sent to Ekdosi');
        $this->assertNull($stale->fresh()->ekdosi_requested_at);
    }

    /**
     * ADV-4 (P1): the request is staff's override of one account's «ignore invoicing», but it stays on the invoice when
     * the invoice moves to another account. Moved to an account with «ignore invoicing», Ekdosi's `requested` lists it and
     * a document is issued to a customer nobody asked one for.
     */
    public function test_moving_a_requested_invoice_to_an_ignored_account_drops_the_request(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $invoice = $this->invoice();
        SendToEkdosi::request([$invoice], true);
        $ignored = User::factory()->create()->client();
        $ignored->forceFill(['ignore_invoicing' => true])->save();

        $moved = $invoice->fresh();
        $moved->client_id = $ignored->id;
        $moved->save();

        $this->assertSame([], $this->requested(), 'the invoice is listed for a document of the account that ignores invoicing');
    }
}
