<?php

namespace Tests\Feature;

use App\Events\Invoice\Finalized;
use App\Models\ApiKey;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #357 (a service or a domain moved to another client): one test per finding, each failing on
 * the PR as written. The setup is MoveToClientTest's.
 */
class MoveToClientAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $from;

    private User $to;

    private Service $service;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR', 'settings.invoice_snapshot' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->from = User::factory()->create();
        $this->to = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 10, 'expires_at' => now()->addMonth()]);
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    private function invoice(User $owner, array $lines): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $owner->client()->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        foreach ($lines as $line) {
            $invoice->items()->create($line + ['quantity' => 1, 'price' => 10, 'description' => 'Line']);
        }

        return $invoice->fresh();
    }

    private function serviceLine(array $extra = []): array
    {
        return $extra + ['reference_type' => Service::class, 'reference_id' => $this->service->id];
    }

    /**
     * ADV-1 (high). An unpaid invoice is issued with a snapshot of its recipient (name, address, VAT id, tax:
     * InvoiceObserver::created → CreateInvoiceSnapshotService). Moved whole, its client_id changes but its snapshot does
     * not: the new client is emailed (Finalized) and shown an invoice addressed to the first client, with the first
     * client's address and VAT details. Its lines were re-taxed for the new client; the snapshot's tax was not.
     * Fix: replace (delete and recreate) the snapshot when the invoice moves whole.
     */
    public function test_an_invoice_moved_whole_is_addressed_to_its_new_client(): void
    {
        $invoice = $this->invoice($this->from, [$this->serviceLine()]);
        $this->assertNotNull($invoice->snapshot, 'issued with a snapshot of its recipient');
        $this->assertNotSame($this->from->client()->name, $this->to->client()->name);

        app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $moved = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame($this->to->client()->id, (int) $moved->client_id);
        $this->assertSame($this->to->client()->name, $moved->user_name, 'the new client\'s invoice still names the first client');
    }

    /**
     * ADV-2 (high). A line without a reference (a staff discount, a negative adjustment, a setup fee typed by hand) on an
     * invoice that otherwise bills only this service counts as «another item»: the invoice is split. The new client gets
     * the service at its full price (10.00), and the first client keeps an unpaid invoice that holds only the discount,
     * with a negative total (-3.00). Money billed to the wrong person both ways.
     * Fix: lines without a reference go with the item when the invoice bills nothing else (move it whole); or refuse
     * the move when an invoice has unreferenced lines alongside the item's, so staff decide.
     */
    public function test_a_discount_line_stays_with_the_service_it_discounts(): void
    {
        $invoice = $this->invoice($this->from, [$this->serviceLine(), ['price' => -3, 'description' => 'Discount', 'taxed' => false]]);

        app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $left = Invoice::query()->where('client_id', $this->from->client()->id)->whereIn('status', Invoice::OPEN_STATUSES)->get();
        $this->assertTrue($left->every(fn (Invoice $i) => $i->total >= 0), 'the first client is left with an open invoice of a negative total (only the discount)');
        $this->assertSame($this->to->client()->id, (int) $invoice->fresh()->client_id, 'the invoice billed only this service and its discount: it moves whole');
    }

    /**
     * ADV-3 (medium). The new invoice of a split is created unpaid, so InvoiceObserver::created already queues its
     * Finalized (send_create_email). MoveToClient::sendAgain() then queues a second one: the new client receives the
     * same new invoice twice.
     * Fix: do not sendAgain() the new invoice (or create it with send_create_email = false / finalize_later and send once
     * when its lines are on it).
     */
    public function test_the_new_invoice_of_a_split_is_sent_once(): void
    {
        $other = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 5]);
        $invoice = $this->invoice($this->from, [$this->serviceLine(), ['reference_type' => Service::class, 'reference_id' => $other->id, 'price' => 5]]);
        Event::fake([Finalized::class]);

        $result = app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $new = $result['split'][$invoice->id];
        Event::assertDispatchedTimes(Finalized::class, 2); // the old invoice once (its new total), the new one once
        $this->assertSame(1, collect(Event::dispatched(Finalized::class))->filter(fn ($e) => (int) $e[0]->invoice->id === (int) $new && $e[0]->sendEmail)->count(),
            'the new client is emailed the same invoice twice');
    }

    /**
     * ADV-4 (low). Split off an invoice whose other lines are free (a 0.00 line of another service), the first client is
     * left with an unpaid invoice of 0.00 that nothing closes. Everywhere else an open invoice brought down to nothing
     * to pay is closed as paid (ChangeOpenLine, draft → unpaid: RecordPaymentService::settle).
     * Fix: settle() the remaining invoice after the split (outside an upgrade, as ChangeOpenLine).
     */
    public function test_an_invoice_left_with_nothing_to_pay_is_closed(): void
    {
        $other = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 0]);
        $invoice = $this->invoice($this->from, [$this->serviceLine(), ['reference_type' => Service::class, 'reference_id' => $other->id, 'price' => 0]]);

        app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $this->assertNotSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'an unpaid invoice of 0.00 left open on the first client');
    }

    /**
     * ADV-5 (medium). The admin API still moves a service by its client_id (UpdateServiceRequest: «sometimes|required»),
     * with only services.update: no admin.services.move, no invoices with it (the unpaid one stays the first client's
     * while it renews the new client's service), the contact not cleared, no notes. The PR made «Move to another client»
     * the only way (the form field disabled on edit), but left this one.
     * Fix: drop client_id from UpdateServiceRequest (or route it through MoveToClient behind admin.services.move).
     */
    public function test_the_api_does_not_move_a_service_around_move_to_client(): void
    {
        $invoice = $this->invoice($this->from, [$this->serviceLine()]);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.services.update']]);

        $this->withToken($token)->putJson(route('api.v1.admin.services.update', $this->service), ['client_id' => $this->to->client()->id]);

        $this->assertSame($this->from->client()->id, (int) $this->service->fresh()->client_id, 'moved by the API with services.update only, its invoice left behind');
        $this->assertSame($this->from->client()->id, (int) $invoice->fresh()->client_id);
    }
}
