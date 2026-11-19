<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyCheckMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The money check's mail, readable (owner 2026-11-19, the devbox's «service #12 … invoice #9000004, which is refunded»: «δεν
 * το κατάλαβα… ποιος χρήστης, ποιο service, why?»): by rule what it means, and under each finding the customer, the service
 * and the invoice by its number, linked. The findings' own text is as before (the log and the weekly fingerprint).
 */
class MoneyCheckMailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-19 06:30:00');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Example']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function body(): string
    {
        $this->artisan('mycs:money-check')->assertFailed();

        return (string) DB::table('email_logs')->where('subject', 'like', 'Money check:%')->latest('id')->value('body');
    }

    /** The devbox's own: a refunded invoice that gave the period, numbered INV-13 while its id is 9000004. */
    public function test_a_refunded_period_names_the_customer_the_service_and_the_invoice_by_its_number(): void
    {
        $created = $this->createProduct();
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 1.00]);
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $created->plan->id,
            'product_id' => $created->product->id, 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-03',
            'currency_code' => 'USD', 'price' => 1.00, 'quantity' => 1]);
        $invoice = Invoice::factory()->create(['id' => 9000004, 'number' => 'INV-13', 'client_id' => $service->client_id,
            'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-03']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Payment test',
            'quantity' => 1, 'price' => 1, 'period_start' => '2026-11-03', 'period_end' => '2026-12-03']);
        DB::table('invoice_items')->where('invoice_id', 9000004)->update(['applied_at' => '2026-11-03']);
        DB::table('invoices')->where('id', 9000004)->update(['status' => Invoice::STATUS_REFUNDED]);

        $body = $this->body();

        $client = $this->user->client();
        $this->assertStringContainsString('service_unbilled (1)', $body);
        $this->assertStringContainsString(e(MoneyCheckMail::meaning('service_unbilled')), $body);
        $this->assertStringContainsString('invoice INV-13 (#9000004)</a>, which is refunded', $body);
        $this->assertStringContainsString(e(InvoiceResource::getUrl('edit', ['record' => 9000004], panel: 'admin')), $body);
        $this->assertStringContainsString(e(ServiceResource::getUrl('edit', ['record' => $service->id], panel: 'admin')), $body);
        $this->assertStringContainsString(e(ClientResource::getUrl('view', ['record' => $client->id], panel: 'admin')), $body);
        $this->assertStringContainsString(e("#{$client->id} {$client->name}"), $body);
        $this->assertStringContainsString(e($service->fresh()->mail_name) . '</a> (active)', $body);
        $this->assertStringContainsString('INV-13</a> (refunded, ', $body);
    }

    /** A customer's credit names the customer; a transaction names its invoice and so its customer. */
    public function test_a_credit_and_a_transaction_lead_to_their_customer(): void
    {
        $client = $this->user->client();
        $credit = Credit::query()->create(['client_id' => $client->id, 'currency_code' => 'USD', 'amount' => 0]);
        DB::table('credits')->where('id', $credit->id)->update(['amount' => -2]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 5,
            'status' => InvoiceTransactionStatus::Succeeded->value, 'created_at' => now(), 'updated_at' => now()]);

        $body = $this->body();

        $this->assertStringContainsString('credit_negative (1)', $body);
        $this->assertStringContainsString('payment_not_journaled (1)', $body);
        $this->assertGreaterThanOrEqual(3, substr_count($body, e(ClientResource::getUrl('view', ['record' => $client->id], panel: 'admin'))));
        $this->assertStringContainsString(e(InvoiceResource::getUrl('edit', ['record' => $invoice->id], panel: 'admin')), $body);
    }

    /** A name is the customer's own text: escaped, never markup. A record no longer there stays as the text says. */
    public function test_names_are_escaped_and_a_missing_record_is_left_as_text(): void
    {
        $client = $this->user->client();
        $this->user->forceFill(['first_name' => '<b>Evil</b>', 'last_name' => '& Co'])->saveQuietly();
        $client->refresh();

        $html = (new MoneyCheckMail)->html([
            ['rule' => 'credit_negative', 'subject' => "customer #{$client->id} USD", 'detail' => 'balance -2.00'],
            ['rule' => 'paid_balance', 'subject' => 'invoice #987654', 'detail' => 'paid, <script>x</script>'],
        ]);

        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<strong>invoice #987654</strong>', $html);
    }

    public function test_every_rule_says_what_it_means_in_both_languages(): void
    {
        foreach (MoneyCheck::RULES as $rule) {
            app()->setLocale('en');
            $english = MoneyCheckMail::meaning($rule);
            app()->setLocale('el');
            $this->assertNotSame('', $english, $rule);
            $this->assertNotSame($english, MoneyCheckMail::meaning($rule), "{$rule}: no Greek");
        }
    }
}
