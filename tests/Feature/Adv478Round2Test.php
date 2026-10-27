<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Events\Invoice\Updating;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\StaffLineEdit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of #478 (E5, «Send to Ekdosi»), round 2: against the fixes of round 1 (the observer's clear, the
 * guarded UPDATE, the merge clear, whereAddFunds). Each test is one finding and fails on the PR.
 */
class Adv478Round2Test extends TestCase
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
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'A478b' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
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
     * R2-1 (P2): the observer's automatic withdraw leaves no trace in the invoice's audit. It clears the request by a query
     * and then syncs the model's original, so the «updated» audit of the cancel does not show ekdosi_requested_at either,
     * and no `ekdosi_request_withdrawn` is written. docs/ekdosi-bridge.md promises every change in the audit; staff see
     * «requested by X» and then nothing explains why Ekdosi stopped listing it. (MergeInvoices' clear is unaudited too.)
     *
     * Scenario: staff send an unpaid invoice; it is cancelled; look for any audit of the request ending.
     */
    public function test_the_automatic_withdraw_on_cancel_is_audited(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $invoice = $this->invoice();
        SendToEkdosi::request([$invoice], true);

        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'precondition: cleared');

        $traced = Audit::query()->where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)->get()
            ->contains(fn (Audit $audit) => $audit->event === 'ekdosi_request_withdrawn'
                || array_key_exists('ekdosi_requested_at', (array) $audit->old_values));
        $this->assertTrue($traced, 'the request ended on cancel, and the audit says nothing about it');
    }

    /**
     * R2-2 (P2): whereAddFunds needs every line to be credit, so an unpaid credit purchase with a discount line (no
     * reference) is not «add funds»: it is sendable and Ekdosi lists it for a document on credit, the very case R-1 shut
     * (a credit purchase owes nothing until paid; its credit is given only on paid).
     *
     * Scenario: unpaid invoice of 100 credit with a -10 discount line; staff send it.
     */
    public function test_an_unpaid_credit_purchase_with_a_discount_is_not_sent(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $topUp = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $topUp->items()->create(['description' => 'Add Funds', 'quantity' => 1, 'price' => '100.00', 'reference_type' => Credit::class]);
        $topUp->items()->create(['description' => 'Discount', 'quantity' => 1, 'price' => '-10.00']);

        SendToEkdosi::request([$topUp->fresh()], true);

        $this->assertNotContains($topUp->id, $this->requested(), 'an unpaid credit purchase is listed for a document on credit');
    }

    /**
     * R2-3 (P2): a line added after the request keeps it, while a merge (MergeInvoices) clears it because «it gained
     * lines nobody asked a document for». The same invoice gaining a line by StaffLineEdit is listed in `requested` with
     * a total staff never sent.
     *
     * Scenario: staff send an unpaid invoice of 100; another staff adds a 500 line; the request stays.
     */
    public function test_a_line_added_after_the_request_takes_it_off_as_a_merge_does(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $invoice = $this->invoice();
        SendToEkdosi::request([$invoice], true);

        StaffLineEdit::run($invoice->id, true, fn (Invoice $locked) => $locked->items()->create(['description' => 'Dedicated server', 'quantity' => 1, 'price' => '500.00']));

        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'it bills a line nobody asked a document for');
    }

    /**
     * R2-4 (P2): a change of currency keeps the request, while a change of customer clears it. The observer itself treats
     * customer and currency as one identity (an active document keeps both); a request for 100 USD becomes one for
     * 100 EUR that nobody asked for.
     *
     * Scenario: staff send an unpaid USD invoice; it is switched to EUR (no payments, allowed); still requested.
     */
    public function test_a_currency_change_takes_the_request_off_as_a_move_does(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' €', 'format' => '1.000,00']);
        $this->actingAs($this->staff(), 'staff');
        $invoice = $this->invoice();
        SendToEkdosi::request([$invoice], true);

        $invoice->fresh()->update(['currency_code' => 'EUR']);

        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'asked in USD, listed in EUR');
    }

    /**
     * R2-5 (P2): the observer clears in `updating`, before the row is written, and unpaid → draft is saved without
     * Invoice::save's transaction or lockInvoice. A «Send to Ekdosi» landing between the clear and the UPDATE of the status
     * sees it still unpaid in the database, so its guarded UPDATE writes the request; the save then makes it a draft. A
     * draft keeps a request, and once issued again (draft → unpaid, which clears nothing) Ekdosi lists it unasked.
     *
     * Scenario: the interleaving is replayed at the end of updating() (the Updating event, after the clear).
     */
    public function test_a_request_racing_a_move_to_draft_does_not_survive(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $invoice = $this->invoice();
        SendToEkdosi::request([$invoice], true);
        $once = false;
        Event::listen(Updating::class, function (Updating $event) use (&$once, $invoice) {
            if (!$once && $event->invoice->id === $invoice->id) {
                $once = true;
                SendToEkdosi::request([Invoice::query()->findOrFail($invoice->id)], true); // the other staff's click
            }
        });

        $invoice->fresh()->update(['status' => Invoice::STATUS_DRAFT]);

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'a draft holds a request');
    }
}
