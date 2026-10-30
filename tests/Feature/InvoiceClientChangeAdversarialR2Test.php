<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\ApiKey;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceSnapshot;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #554 (the round-1 fixes in InvoiceObserver: retax/readdress only from an open status,
 * the card-on-its-way refusal, the preferred method reset). test_bug_* fail on the PR as it stands; test_holds_* are
 * guarantees that held.
 */
class InvoiceClientChangeAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    private Gateway $card;

    private Gateway $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR', 'settings.invoice_snapshot' => true, 'settings.tax_enabled' => true,
            'settings.tax_type' => 'exclusive', 'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'GR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $this->card = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        $this->bank = Gateway::query()->firstOrCreate(['extension' => 'BankTransfer'], ['name' => 'Bank Transfer', 'type' => 'gateway', 'enabled' => true]);
    }

    private function customer(string $country, string $first): Client
    {
        $user = User::factory()->create(['first_name' => $first]);
        $user->client()->primaryContact->update(['country' => $country, 'first_name' => $first]);

        return $user->client()->fresh();
    }

    private function withCard(Client $client): BillingAgreement
    {
        return BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $this->card->id, 'name' => 'Visa 4242',
            'external_reference' => 'pm_' . Str::random(6), 'type' => 'card']);
    }

    private function invoice(Client $client, float $price = 100, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $client->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => $status]);
        $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /** @param array<string, mixed> $changes */
    private function save(Invoice $invoice, array $changes): Invoice
    {
        Invoice::query()->findOrFail($invoice->id)->update($changes);

        return Invoice::query()->findOrFail($invoice->id);
    }

    private function snapshots(Invoice $invoice): array
    {
        return InvoiceSnapshot::query()->where('invoice_id', $invoice->id)->pluck('name')->map(fn ($name) => (string) $name)->all();
    }

    // ---------------------------------------------------------------- bugs

    /**
     * ADV-554-R2-1 (Medium). A cancelled invoice moved to another customer is no longer readdressed (round 1 skips
     * InvoiceReaddress for a closed one, to keep its amounts). Its snapshot keeps the first customer's name and tax: the
     * second customer opens it in their client area and reads the first one's name. Readdressing touches no amount; only
     * the retax needs the open status.
     */
    public function test_bug_a_cancelled_invoice_moved_shows_the_first_customers_name_to_the_second(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $invoice = $this->save($this->invoice($greek), ['status' => Invoice::STATUS_CANCELLED]);
        $this->assertStringContainsString('Eleni', implode(',', $this->snapshots($invoice)), 'precondition: the first customer\'s snapshot');

        $abroad = $this->customer('US', 'John');
        // The fix: a closed invoice keeps its customer (reopened first), so the second customer never sees the first's name.
        try {
            $this->save($invoice, ['client_id' => $abroad->id]);
            $this->fail('a closed invoice is not moved');
        } catch (DisplayException) {
        }

        $this->assertSame($invoice->client_id, Invoice::query()->whereKey($invoice->id)->value('client_id'));
    }

    /**
     * ADV-554-R2-2 (Medium). Cancelled, moved (not retaxed: closed), then reopened (cancelled → unpaid): an open invoice of
     * the second customer, billed with the first customer's VAT (24% to a customer abroad) and addressed to them. The
     * reopen is the moment it becomes open again and nothing retaxes it then.
     */
    public function test_bug_a_cancelled_invoice_moved_then_reopened_bills_the_first_customers_tax(): void
    {
        $invoice = $this->save($this->invoice($this->customer('GR', 'Eleni')), ['status' => Invoice::STATUS_CANCELLED]);
        $abroad = $this->customer('US', 'John');
        // The fix: not moved while closed; reopened and moved in one save it is retaxed (R2-3's test).
        try {
            $this->save($invoice, ['client_id' => $abroad->id]);
            $this->fail('a closed invoice is not moved');
        } catch (DisplayException) {
        }

        $reopened = $this->save(Invoice::query()->findOrFail($invoice->id), ['status' => Invoice::STATUS_UNPAID, 'client_id' => $abroad->id]);

        $this->assertSame(Invoice::STATUS_UNPAID, $reopened->status);
        $this->assertSame(100.0, (float) $reopened->total, 'open for the customer abroad, with no Greek VAT on it');
    }

    /**
     * ADV-554-R2-3 (Medium, same root). Reopened and moved in one save (staff's edit form sends status and customer
     * together; the API takes both): getOriginal('status') is cancelled, so no retax and no readdress, and the invoice is
     * open for the new customer with the old one's tax and name.
     */
    public function test_bug_reopened_and_moved_in_one_save_is_not_retaxed(): void
    {
        $invoice = $this->save($this->invoice($this->customer('GR', 'Eleni')), ['status' => Invoice::STATUS_CANCELLED]);
        $abroad = $this->customer('US', 'John');

        $moved = $this->save($invoice, ['client_id' => $abroad->id, 'status' => Invoice::STATUS_UNPAID]);

        $this->assertSame([Invoice::STATUS_UNPAID, $abroad->id], [$moved->status, (int) $moved->client_id]);
        $this->assertSame(100.0, (float) $moved->total, 'open for the customer abroad, with Greek VAT on it');
        $this->assertStringNotContainsString('Eleni', implode(',', $this->snapshots($moved)));
    }

    /**
     * ADV-554-R2-4 (Low). Staff's edit form (and the API's model update) changes the customer and picks the preferred
     * method in the same save: updating() throws the choice away for the new account's default. Only a gateway left as
     * it was (not dirty) should be reset.
     */
    public function test_bug_a_gateway_staff_chose_in_the_same_save_is_overridden(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $this->withCard($greek);
        $invoice = $this->invoice($greek->refresh());
        $this->assertSame($this->card->id, (int) $invoice->preferred_gateway_id, 'precondition');
        $abroad = $this->customer('US', 'John');
        $this->assertNull(Invoice::preferredGatewayFor($abroad->id), 'precondition: the new account has no method of its own');

        $moved = $this->save($invoice, ['client_id' => $abroad->id, 'preferred_gateway_id' => $this->bank->id]);

        $this->assertSame($this->bank->id, $moved->preferred_gateway_id, 'staff picked Bank Transfer for the new customer');
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_a_currency_change_keeps_the_preferred_gateway(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $this->withCard($greek);
        $invoice = $this->invoice($greek->refresh());

        $changed = $this->save($invoice, ['currency_code' => 'USD']);

        $this->assertSame(['USD', $this->card->id], [$changed->currency_code, (int) $changed->preferred_gateway_id]);
    }

    public function test_holds_a_currency_change_is_refused_while_a_card_charge_is_on_its_way(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $card = $this->withCard($greek);
        $invoice = $this->invoice($greek);
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $greek->id,
            'billing_agreement_id' => $card->id, 'amount' => 124, 'currency_code' => 'EUR', 'source' => ChargeAttempt::SOURCE_CLIENT,
            'status' => ChargeAttempt::STATUS_SUCCEEDED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);

        $this->expectException(DisplayException::class);
        $this->save($invoice, ['currency_code' => 'USD']);
    }

    public function test_holds_a_new_invoice_keeps_the_gateway_it_was_given(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $this->withCard($greek);
        $invoice = Invoice::query()->create(['client_id' => $greek->id, 'currency_code' => 'EUR', 'due_at' => now(),
            'status' => Invoice::STATUS_UNPAID, 'preferred_gateway_id' => $this->bank->id]);

        $this->assertSame($this->bank->id, (int) $invoice->fresh()->preferred_gateway_id);
    }

    /** Draft → unpaid and moved in one save: one snapshot, the new customer's, and the new customer's tax. */
    public function test_holds_a_draft_issued_and_moved_in_one_save_has_one_snapshot_for_the_new_customer(): void
    {
        $draft = $this->invoice($this->customer('GR', 'Eleni'), status: Invoice::STATUS_DRAFT);
        $abroad = $this->customer('US', 'John');

        $issued = $this->save($draft, ['client_id' => $abroad->id, 'status' => Invoice::STATUS_UNPAID]);

        $this->assertCount(1, $this->snapshots($issued));
        $this->assertStringContainsString('John', $this->snapshots($issued)[0]);
        $this->assertSame(100.0, (float) $issued->total);
    }

    /**
     * ADV-554-R2-5 (Low). A draft that was unpaid before (it holds a snapshot) issued again and moved in one save:
     * InvoiceReaddress::readdress deletes the snapshot, and CreateInvoiceSnapshotService reads $invoice->snapshot (caching
     * null) before creating the new one; the draft → unpaid branch of updated() then sees that cached null and inserts a
     * second snapshot: UNIQUE(invoice_snapshots.invoice_id), and staff's save fails with a 500. Needs unsetRelation('snapshot')
     * after readdress (or handle() to set the relation it created).
     */
    public function test_bug_a_draft_with_an_old_snapshot_issued_and_moved_in_one_save_crashes(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'));
        $draft = $this->save($invoice, ['status' => Invoice::STATUS_DRAFT]);
        $this->assertCount(1, $this->snapshots($draft), 'precondition');

        $issued = $this->save($draft, ['client_id' => $this->customer('US', 'John')->id, 'status' => Invoice::STATUS_UNPAID]);

        $this->assertCount(1, $this->snapshots($issued));
        $this->assertStringContainsString('John', $this->snapshots($issued)[0]);
        $this->assertSame(100.0, (float) $issued->total);
    }

    /** Unpaid → draft and moved in one save: retaxed and readdressed (it was open). */
    public function test_holds_set_back_to_draft_and_moved_in_one_save_is_the_new_customers(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'));

        $draft = $this->save($invoice, ['client_id' => $this->customer('US', 'John')->id, 'status' => Invoice::STATUS_DRAFT]);

        $this->assertSame(100.0, (float) $draft->total);
        $this->assertCount(1, $this->snapshots($draft));
        $this->assertStringContainsString('John', $this->snapshots($draft)[0]);
    }

    /** Moved and cancelled in one save: the note is the new total, and the money check is clean. */
    public function test_holds_moved_and_cancelled_in_one_save_leaves_the_money_check_clean(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'));

        $cancelled = $this->save($invoice, ['client_id' => $this->customer('US', 'John')->id, 'status' => Invoice::STATUS_CANCELLED]);

        $note = AdjustmentNote::query()->where('invoice_id', $invoice->id)->where('is_cancellation', true)->sole();
        $this->assertSame(-100.0, -abs((float) $note->amount));
        $this->assertSame(0.0, (float) $cancelled->total);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_holds_the_api_move_is_retaxed_readdressed_and_resets_the_method(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $this->withCard($greek);
        $invoice = $this->invoice($greek->refresh());
        $abroad = $this->customer('US', 'John');
        $token = 'ADV2' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.invoices.update']]);

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['client_id' => $abroad->id])->assertOk();

        $moved = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame([$abroad->id, 100.0, null], [(int) $moved->client_id, (float) $moved->total, $moved->preferred_gateway_id]);
        $this->assertStringContainsString('John', implode(',', $this->snapshots($moved)));
        $this->assertSame([], (new MoneyCheck)->run());
    }

    /** MoveToClient sets the method, then the observer again: the same answer, the invoice retaxed once, one snapshot. */
    public function test_holds_move_to_client_whole_move_is_consistent(): void
    {
        $from = $this->customer('GR', 'Eleni');
        $this->withCard($from);
        $to = $this->customer('US', 'John');
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $from->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 100, 'expires_at' => now()->addMonth()]);
        $invoice = Invoice::query()->create(['client_id' => $from->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'quantity' => 1, 'price' => 100, 'description' => 'Hosting']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $result = app(MoveToClient::class)->service($service, $to, $staff);

        $this->assertSame([$invoice->id], $result['moved']);
        $moved = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame([$to->id, null, 100.0], [(int) $moved->client_id, $moved->preferred_gateway_id, (float) $moved->total]);
        $this->assertCount(1, $this->snapshots($moved));
        $this->assertStringContainsString('John', $this->snapshots($moved)[0]);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    /** A split child (MoveToClient's partial move) is created for the new customer: its tax and snapshot are theirs. */
    public function test_holds_a_split_child_is_the_new_customers(): void
    {
        $from = $this->customer('GR', 'Eleni');
        $to = $this->customer('US', 'John');
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $from->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 100, 'expires_at' => now()->addMonth()]);
        $invoice = Invoice::query()->create(['client_id' => $from->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'quantity' => 1, 'price' => 100, 'description' => 'Hosting']);
        $stays = Service::factory()->create(['client_id' => $from->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 50, 'expires_at' => now()->addMonth()]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $stays->id, 'quantity' => 1, 'price' => 50, 'description' => 'Other']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $result = app(MoveToClient::class)->service($service, $to, $staff);

        $this->assertArrayHasKey($invoice->id, $result['split']);
        $child = Invoice::query()->findOrFail($result['split'][$invoice->id]);
        $parent = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame([100.0, 62.0], [(float) $child->total, (float) $parent->total]);
        $this->assertStringContainsString('John', implode(',', $this->snapshots($child)));
        $this->assertStringContainsString('Eleni', implode(',', $this->snapshots($parent)));

        $this->assertSame([], (new MoneyCheck)->run());
    }
}
