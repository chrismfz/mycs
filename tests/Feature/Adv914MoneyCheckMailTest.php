<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyCheckMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #914 (the money check's readable mail). Failing tests for what is wrong; passing ones for what
 * was tried and held.
 */
class Adv914MoneyCheckMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-19 06:30:00');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.credits_auto_use' => false, 'settings.logs_full' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $first = 'Maria', string $last = 'Example'): Client
    {
        return User::factory()->create(['first_name' => $first, 'last_name' => $last])->client();
    }

    private function invoiceOf(Client $client, array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD'] + $attributes);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    private function transactionOn(Invoice $invoice, string $gatewayId): int
    {
        return (int) DB::table('invoice_transactions')->insertGetId(['invoice_id' => $invoice->id, 'amount' => 10,
            'transaction_id' => $gatewayId, 'status' => InvoiceTransactionStatus::Succeeded->value, 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function clientUrl(Client $client): string
    {
        return e(ClientResource::getUrl('view', ['record' => $client->id], panel: 'admin'));
    }

    /**
     * ADV-914-1. duplicate_gateway_id names its transactions «(#1, #2)», without the word «transaction»: the regex reads
     * nothing, so the mail says neither whose money was recorded twice nor on which invoices; and the rule's own point is
     * that the second one may be on another customer.
     */
    public function test_duplicate_gateway_id_names_the_customers_and_invoices_of_its_transactions(): void
    {
        $maria = $this->customer();
        $nikos = $this->customer('Nikos', 'Other');
        $first = $this->invoiceOf($maria);
        $second = $this->invoiceOf($nikos);
        $one = $this->transactionOn($first, 'PAY-1');
        $two = $this->transactionOn($second, 'PAY-1');

        // As MoneyCheck::duplicateGatewayId words it.
        $html = (new MoneyCheckMail)->html([['rule' => 'duplicate_gateway_id', 'subject' => 'payment id PAY-1', 'detail' => "succeeded 2 times (#{$one}, #{$two})"]]);

        $this->assertStringContainsString(e(InvoiceResource::getUrl('edit', ['record' => $first->id], panel: 'admin')), $html);
        $this->assertStringContainsString(e(InvoiceResource::getUrl('edit', ['record' => $second->id], panel: 'admin')), $html);
        $this->assertStringContainsString(self::clientUrl($maria), $html);
        $this->assertStringContainsString(self::clientUrl($nikos), $html);
    }

    /**
     * ADV-914-2. A finding whose records belong to two customers (the very inconsistency the check is for: a journal
     * row on invoice A whose transaction is on customer B's invoice; an addon whose parent is another customer's) shows
     * «Customer: A» and then B's invoice or service under it: as if it were A's.
     */
    public function test_a_finding_about_two_customers_names_both(): void
    {
        $maria = $this->customer();
        $nikos = $this->customer('Nikos', 'Other');
        $hers = $this->invoiceOf($maria);
        $his = $this->invoiceOf($nikos);
        $transaction = $this->transactionOn($his, 'PAY-2');
        DB::table('invoice_transactions')->where('id', $transaction)->update(['status' => InvoiceTransactionStatus::Failed->value]);

        // As MoneyCheck::journalWithoutPayment words it.
        $html = (new MoneyCheckMail)->html([['rule' => 'journal_without_payment', 'subject' => "money event #77 (invoice #{$hers->id})",
            'detail' => "transaction #{$transaction} gone or not succeeded"]]);

        $this->assertStringContainsString(self::clientUrl($maria), $html);
        $this->assertStringContainsString(self::clientUrl($nikos), $html, 'the second customer is not named: his invoice reads as hers');
    }

    /**
     * ADV-914-3. The mail goes through LogText (Scrub::text), which masks «Basic <8+ chars>» and «Bearer <8+ chars>»
     * anywhere, case-insensitive, as an Authorization header. Before #914 the mail held only the check's own words; now it
     * holds customers' and products' names, and a «Basic Webhosting» product or a «Basic Solutions» customer reads masked.
     */
    public function test_names_in_the_mail_are_not_masked_as_credentials(): void
    {
        $client = $this->customer('Basic', 'Solutionsltd');
        $credit = Credit::query()->create(['client_id' => $client->id, 'currency_code' => 'USD', 'amount' => 0]);
        DB::table('credits')->where('id', $credit->id)->update(['amount' => -2]);

        $this->artisan('mycs:money-check')->assertFailed();
        $body = (string) DB::table('email_logs')->where('subject', 'like', 'Money check:%')->latest('id')->value('body');

        $this->assertStringContainsString(self::clientUrl($client), $body);
        $this->assertStringContainsString(e($client->fresh()->name), $body, 'the customer name was masked as a Basic credential');
    }

    /**
     * ADV-914-4. N+1: each invoice a finding names costs its own queries (the invoice, its client, the client's contact
     * and owner, its items for the total, its currency). A rehearsal's hundreds of findings make thousands of queries in
     * the cron, all before the log line is written («Staff first»).
     */
    public function test_many_findings_do_not_query_per_record(): void
    {
        $client = $this->customer();
        $findings = [];
        for ($i = 0; $i < 40; $i++) {
            $findings[] = ['rule' => 'paid_balance', 'subject' => "invoice #{$this->invoiceOf($client)->id}", 'detail' => 'paid, still owes 10.00'];
        }

        DB::enableQueryLog();
        (new MoneyCheckMail)->html($findings);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(40, $queries, "{$queries} queries for 40 findings");
    }

    // ---- Tried and held ------------------------------------------------------------------------------------------

    /** Free-text invoice number, a product name, a domain, a gateway reference, a customer name: escaped everywhere. */
    public function test_free_text_is_escaped_in_links_and_about(): void
    {
        $client = $this->customer('<img src=x onerror=alert(1)>', '"><script>a()</script>');
        $invoice = $this->invoiceOf($client, ['number' => '<script>n()</script>']);
        $created = $this->createProduct();
        DB::table('products')->where('id', $created->product->id)->update(['name' => '<svg onload=p()>']);
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $created->plan->id, 'product_id' => $created->product->id,
            'status' => Service::STATUS_ACTIVE, 'domain' => '<b>d</b>.example', 'currency_code' => 'USD', 'price' => 1, 'quantity' => 1]);

        $html = (new MoneyCheckMail)->html([
            ['rule' => 'paid_balance', 'subject' => "invoice #{$invoice->id}", 'detail' => 'paid'],
            ['rule' => 'service_unbilled', 'subject' => "service #{$service->id}", 'detail' => "on invoice #{$invoice->id}"],
            ['rule' => 'gateway_unreconciled', 'subject' => 'paypal payment <img src=y onerror=r()>', 'detail' => "x (invoice #{$invoice->id})"],
        ]);

        foreach (['<img', '<script', '<svg', '<b>'] as $markup) {
            $this->assertStringNotContainsString($markup, $html);
        }
    }

    /** An invoice whose number is another invoice's id: the mail says both, and links each to its own. */
    public function test_a_number_equal_to_another_id_is_told_apart(): void
    {
        $client = $this->customer();
        $other = $this->invoiceOf($client, ['number' => 'X-1']);
        $invoice = $this->invoiceOf($client, ['number' => 'X-2']);
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => (string) $other->id]);

        $html = (new MoneyCheckMail)->html([['rule' => 'paid_balance', 'subject' => "invoice #{$invoice->id}", 'detail' => 'paid']]);

        $this->assertStringContainsString("invoice {$other->id} (#{$invoice->id})</a>", $html);
        $this->assertStringContainsString(e(InvoiceResource::getUrl('edit', ['record' => $invoice->id], panel: 'admin')), $html);
        $this->assertStringNotContainsString(e(InvoiceResource::getUrl('edit', ['record' => $other->id], panel: 'admin')) . '"', $html);
    }

    /** A service whose client and product are gone, an invoice whose currency is not in the table: no exception. */
    public function test_records_with_missing_relations_do_not_break_the_mail(): void
    {
        $client = $this->customer();
        $created = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $created->plan->id, 'product_id' => $created->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 1, 'quantity' => 1]);
        $invoice = $this->invoiceOf($client);
        // Never checked: the test's transaction is rolled back (SQLite defers; MariaDB, as SweepImportTest, turns them off).
        $sqlite = DB::getDriverName() === 'sqlite';
        DB::statement($sqlite ? 'PRAGMA defer_foreign_keys = ON' : 'SET foreign_key_checks = 0');
        DB::table('services')->where('id', $service->id)->update(['client_id' => 999999, 'product_id' => 999999]);
        DB::table('invoices')->where('id', $invoice->id)->update(['currency_code' => 'XXX', 'client_id' => 999999]);
        if (!$sqlite) {
            DB::statement('SET foreign_key_checks = 1');
        }

        $html = (new MoneyCheckMail)->html([
            ['rule' => 'service_unbilled', 'subject' => "service #{$service->id}", 'detail' => "runs on invoice #{$invoice->id}"],
            ['rule' => 'credit_journal', 'subject' => 'customer #999999 USD', 'detail' => 'balance 1.00'],
        ]);

        $this->assertStringContainsString(e(ServiceResource::getUrl('edit', ['record' => $service->id], panel: 'admin')), $html);
        $this->assertStringContainsString('customer #999999 USD', $html);
    }
}
