<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use App\Support\EmailKinds;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Round-3 (final) adversarial review of «don't suspend until…» warns (sweep 9γ G1, #555): the staff note's $heldUntil
 * across the loop, heldBack() under «suspend only with an overdue invoice», seedKeys, and the ALWAYS kind. No test_bug_*:
 * nothing found that fails; test_holds_* are guarantees that held.
 *
 * Suspend Days 2. Calendar: 2026-11-19 is a Thursday, 11-20 a Friday, 11-21 a Saturday.
 */
class SuspendHoldNoticeAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 03:00:00');
        Queue::fake();
        Mail::fake();
        (new EmailTemplateSeeder)->run();
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_suspend_requires_invoice' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Overdue since $expires with an unpaid renewal (due $due, issued $issued), held until $hold. */
    private function held(?string $hold, string $expires = '2026-11-01', string $due = '2026-11-01', string $issued = '2026-10-25', ?Client $client = null): Service
    {
        $product = $this->createProduct();
        $client ??= User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'expires_at' => "{$expires} 00:00:00", 'suspend_hold_until' => $hold]);
        $this->bill($service, $due, $issued);
        EmailLog::query()->delete();

        return $service;
    }

    private function bill(Service $service, string $due, string $issued): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $due, 'created_at' => $issued]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
    }

    private function cron(string $now): void
    {
        Carbon::setTestNow($now);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function notices(): int
    {
        return EmailLog::query()->where('template_key', 'service_hold_ending')->count();
    }

    private function staffToldHoldEnded(Service $service): bool
    {
        return EmailLog::query()->where('body', 'like', "%Service #{$service->id} was suspended: its hold until%")->exists();
    }

    /**
     * $heldUntil is set by reference in the transaction's closure: reset per service, so a held and told service suspended
     * before another in the same run does not lend the next one its note (the next one held, but not held back).
     */
    public function test_holds_the_staff_note_does_not_leak_to_the_next_service_of_the_run(): void
    {
        $told = $this->held('2026-11-20');
        // Natural day 11-21 (expires 11-18 + 2 + 1): a hold until 11-19 holds nothing back, and is never told.
        $notHeldBack = $this->held('2026-11-19', expires: '2026-11-18', due: '2026-11-18', issued: '2026-11-11');
        $plain = $this->held(null, expires: '2026-11-18', due: '2026-11-18', issued: '2026-11-11');
        $this->assertLessThan($notHeldBack->id, $told->id, 'precondition: the told one comes first');

        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(1, $this->notices(), 'precondition: only the first one is told');
        $this->cron('2026-11-21 03:00:00');

        foreach ([$told, $notHeldBack, $plain] as $service) {
            $this->assertSame('suspended', $service->fresh()->status, "precondition: #{$service->id} suspended");
        }
        $this->assertTrue($this->staffToldHoldEnded($told));
        $this->assertFalse($this->staffToldHoldEnded($notHeldBack), 'the note of the service before it');
        $this->assertFalse($this->staffToldHoldEnded($plain), 'the note of a service before it');
    }

    /** «Suspend only with an overdue invoice»: a hold up to the invoice's own day held it back, and staff are told. */
    public function test_holds_requires_invoice_a_hold_through_the_invoice_day_tells_staff(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        // Natural day by the invoice: due 11-17 + 2 + 1 = 11-20. Held until 11-20: suspendable 11-21.
        $service = $this->held('2026-11-20', due: '2026-11-17', issued: '2026-11-10');

        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(1, $this->notices());
        $this->assertStringContainsString('November 21, 2026',
            (string) EmailLog::query()->where('template_key', 'service_hold_ending')->value('subject'));
        $this->cron('2026-11-20 03:00:00');
        $this->assertSame('active', $service->fresh()->status);
        $this->cron('2026-11-21 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertTrue($this->staffToldHoldEnded($service));
    }

    /** Two open invoices: the natural day is the first one's (as suspendable()'s whereHas), not the later one's. */
    public function test_holds_requires_invoice_the_earliest_open_invoice_sets_the_natural_day(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        Carbon::setTestNow('2026-11-02 03:00:00');
        // Old invoice due 11-01: natural day 11-04. A newer one due 11-17 (natural 11-20) must not move it.
        $service = $this->held('2026-11-10');
        $this->bill($service, '2026-11-17', '2026-11-10');

        $this->cron('2026-11-10 03:00:00');
        $this->assertSame(1, $this->notices());
        $this->cron('2026-11-11 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertTrue($this->staffToldHoldEnded($service), 'the hold until 11-10 kept it from 11-04');
    }

    /** seedKeys adds only the keys asked for, where missing, keeps staff's edits, and runs twice without a duplicate. */
    public function test_holds_seed_keys_adds_the_missing_template_only(): void
    {
        NotificationTemplate::query()->where('key', 'service_hold_ending')->delete();
        NotificationTemplate::query()->where('key', 'server_suspended')->update(['subject' => 'Edited by staff']);
        NotificationTemplate::query()->where('key', 'invoice_paid')->delete();
        $before = NotificationTemplate::query()->count();

        EmailTemplateSeeder::seedKeys(['service_hold_ending']);
        EmailTemplateSeeder::seedKeys(['service_hold_ending', 'no_such_template']);

        $this->assertSame($before + 1, NotificationTemplate::query()->count());
        $this->assertSame(1, NotificationTemplate::query()->where('key', 'service_hold_ending')->count());
        $this->assertFalse(NotificationTemplate::query()->where('key', 'invoice_paid')->exists(), 'a key not asked for');
        $this->assertSame('Edited by staff', NotificationTemplate::query()->where('key', 'server_suspended')->value('subject'));
        $row = DB::table('notification_templates')->where('key', 'service_hold_ending')->first();
        $this->assertSame('force', $row->mail_enabled);
        $this->assertStringContainsString('θα ανασταλεί', (string) $row->translations);
    }

    /** ALWAYS: the notice goes to an account whose primary contact takes no product mails (and the kind is listed). */
    public function test_holds_the_notice_goes_whatever_the_primary_contact_takes(): void
    {
        $this->assertContains('service_hold_ending', EmailKinds::ALWAYS);
        $this->assertSame('product', EmailKinds::of('service_hold_ending'));
        $service = $this->held('2026-11-20');
        $client = $service->client;
        if ($client->primaryContact !== null) {
            $client->primaryContact->forceFill(['email_preferences' => []])->save();
        }

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(1, $this->notices());
        $this->assertSame('2026-11-20', $service->fresh()->suspend_hold_notice_for?->toDateString());
    }
}
