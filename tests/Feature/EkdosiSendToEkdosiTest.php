<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Client;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * E5 (docs/ekdosi-bridge.md): «Send to Ekdosi», as the WHMCS plugin's button above the invoices. Staff ask Ekdosi for a
 * document of an invoice its feed leaves out (an account with «ignore invoicing», or one not paid yet: issued on credit);
 * the feed's `requested` lists it until a document is active.
 */
class EkdosiSendToEkdosiTest extends TestCase
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
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'E5' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID, ?Client $client = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        if ($status !== Invoice::STATUS_UNPAID) {
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);
        }

        return $invoice->fresh();
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);
    }

    /** @return list<int> */
    private function feed(string $status): array
    {
        return array_map(fn (array $invoice) => (int) $invoice['invoiceid'], $this->bridge(['op' => 'invoices', 'status' => $status])->assertOk()->json('invoices'));
    }

    public function test_an_unpaid_invoice_of_an_ignored_account_is_listed_once_staff_send_it(): void
    {
        $this->client->forceFill(['ignore_invoicing' => true])->save();
        $invoice = $this->invoice();
        $this->assertSame([], $this->feed('requested'));

        $this->actingAs($staff = $this->staff(), 'staff');
        $this->assertSame(1, SendToEkdosi::request([$invoice], true));

        $this->assertSame([$invoice->id], $this->feed('requested'));
        $this->assertSame([], $this->feed('paid_unfiled'), 'the inbox still leaves the account out');
        $payload = $this->bridge(['op' => 'invoices', 'status' => 'requested'])->json('invoices.0');
        $this->assertSame('Unpaid', $payload['status']);
        $this->assertTrue($payload['mycs']['ignore_invoicing']);
        $this->assertNotNull($payload['mycs']['requested_at']);
        $this->assertSame($staff->id, $invoice->fresh()->ekdosi_requested_by);
    }

    public function test_drafts_cancelled_refunded_and_documented_invoices_are_never_sent(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $documented = $this->invoice(Invoice::STATUS_PAID);
        EkdosiDocument::query()->create(['invoice_id' => $documented->id, 'mark' => '400001234567890', 'code' => 'ΤΠΥ1', 'state' => EkdosiDocument::ACTIVE]);
        $invoices = [$this->invoice(Invoice::STATUS_DRAFT), $this->invoice(Invoice::STATUS_CANCELLED), $this->invoice(Invoice::STATUS_REFUNDED), $documented];

        $this->assertSame(0, SendToEkdosi::request($invoices, true));
        $this->assertSame([], $this->feed('requested'));
    }

    public function test_it_leaves_the_feed_once_a_document_is_active_or_staff_withdraw_it(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $issued = $this->invoice();
        $withdrawn = $this->invoice();
        SendToEkdosi::request([$issued, $withdrawn], true);

        EkdosiDocument::query()->create(['invoice_id' => $issued->id, 'mark' => '400001234567891', 'code' => 'ΤΠΥ2', 'state' => EkdosiDocument::ACTIVE]);
        $this->assertSame(1, SendToEkdosi::request([$withdrawn->fresh()], false));

        $this->assertSame([], $this->feed('requested'));
        $this->assertNull($withdrawn->fresh()->ekdosi_requested_at);
    }

    public function test_each_request_is_audited_with_the_staff_who_made_it(): void
    {
        $this->actingAs($staff = $this->staff(), 'staff');
        $invoice = $this->invoice();

        SendToEkdosi::request([$invoice], true);
        SendToEkdosi::request([$invoice->fresh()], true); // once: nothing more

        $audits = Audit::query()->where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->where('event', 'ekdosi_requested')->get();
        $this->assertCount(1, $audits);
        $this->assertSame($staff->id, $audits->sole()->new_values['staff_id']);
    }

    public function test_staff_send_it_from_the_invoice_and_from_the_list(): void
    {
        $one = $this->invoice();
        $two = $this->invoice(Invoice::STATUS_PAID);
        $draft = $this->invoice(Invoice::STATUS_DRAFT);

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $one->id])
            ->assertActionVisible('sendToEkdosi')
            ->callAction('sendToEkdosi')
            ->assertActionHidden('sendToEkdosi')
            ->assertActionVisible('withdrawFromEkdosi');
        $this->assertNotNull($one->fresh()->ekdosi_requested_at);

        $this->livewireAs($this->staff())->test(ListInvoices::class)
            ->callTableBulkAction('sendToEkdosi', [$two, $draft]);
        $this->assertNotNull($two->fresh()->ekdosi_requested_at);
        $this->assertNull($draft->fresh()->ekdosi_requested_at);
    }

    public function test_nothing_shows_without_the_bridge(): void
    {
        config(['settings.ekdosi_enabled' => false]);

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $this->invoice()->id])
            ->assertActionHidden('sendToEkdosi');
    }

    public function test_ping_tells_ekdosi_the_feed_has_requested(): void
    {
        $this->assertContains('requested', $this->bridge(['op' => 'ping'])->assertOk()->json('statuses'));
    }

    public function test_an_unpaid_add_funds_invoice_is_never_sent(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $topUp = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $topUp->items()->create(['description' => 'Add Funds', 'quantity' => 1, 'price' => '50.00', 'reference_type' => Credit::class]);

        $this->assertSame(0, SendToEkdosi::request([$topUp->fresh()], true), 'nothing is owed: no document on credit');
        Invoice::query()->whereKey($topUp->id)->update(['ekdosi_requested_at' => now()]); // set by any other way
        $this->assertSame([], $this->feed('requested'));
    }

    public function test_requested_reads_since_when_staff_asked(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $old = $this->invoice();
        $new = $this->invoice();
        SendToEkdosi::request([$old, $new], true);
        Invoice::query()->whereKey($old->id)->update(['ekdosi_requested_at' => now()->subDays(10)]);

        $ids = array_map(fn (array $invoice) => (int) $invoice['invoiceid'],
            $this->bridge(['op' => 'invoices', 'status' => 'requested', 'since' => now()->subDay()->toDateString()])->assertOk()->json('invoices'));

        $this->assertSame([$new->id], $ids);
    }

    public function test_a_merge_takes_the_request_off_its_target(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $target = $this->invoice();
        $source = $this->invoice();
        SendToEkdosi::request([$target], true);

        app(MergeInvoices::class)->handle($target->id, [$source->id]);

        $this->assertNull($target->fresh()->ekdosi_requested_at, 'it bills lines nobody asked a document for');
    }

    public function test_the_bulk_action_is_hidden_without_the_bridge(): void
    {
        config(['settings.ekdosi_enabled' => false]);
        $this->invoice();

        $this->livewireAs($this->staff())->test(ListInvoices::class)->assertTableBulkActionHidden('sendToEkdosi');
    }
}
