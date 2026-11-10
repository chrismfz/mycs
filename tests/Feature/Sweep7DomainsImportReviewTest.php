<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\BillingAgreement;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\AcrPay\AcrPay;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Independent and adversarial review of PR #775 (sweep 7: domains and the WHMCS importer). Invented data only.
 * test_rev_775_* fail on bc0d8b52 for the reason in their name; test_shown_* pass and show what MyCS does with a shape
 * the import refuses (for the owner's decision).
 */
class Sweep7DomainsImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [true, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_reminder_days' => '',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.domain_billing_enabled' => true,
            // Only the billing is looked at: no suspension or termination of the unpaid services meanwhile.
            'settings.cronjob_suspend_enabled' => false, 'settings.cronjob_terminate_enabled' => false]);
        $this->user = User::factory()->create();
        $this->tld = $this->tld('com', null, null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tld(string $name, ?int $grace, ?int $redemption): DomainTld
    {
        $tld = DomainTld::query()->create(['tld' => $name, 'grace_days' => $grace, 'redemption_days' => $redemption, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $name === 'gr' ? 2 : 1, 'currency_code' => 'EUR', 'price' => '23.33']);

        return $tld;
    }

    private function domain(string $sld, array $attributes = [], ?DomainTld $tld = null): Domain
    {
        $tld ??= $this->tld;

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => $tld->tld, 'fqdn' => "{$sld}.{$tld->tld}",
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => $tld->tld === 'gr' ? 2 : 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-01',
        ]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function renewalLine(Domain $domain): InvoiceItem
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->sole();
    }

    /** The «needs staff attention» mails of the cron that name $name as past its last recoverable day. */
    private function told(string $name): int
    {
        return EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->get()
            ->filter(fn (EmailLog $mail) => str_contains((string) $mail->body, $name) && str_contains((string) $mail->body, 'past its last recoverable day'))->count();
    }

    /**
     * REV-775-1. The renewal of a domain is grouped with the services due the same day (RenewalBilling, PR C): the usual
     * shape of its invoice is «hosting + domain». When the domain lapses for good, CancelOpenInvoices takes only its line
     * off (the invoice stays for the hosting), so there is no cancelled invoice to carry forgone_at (CronJob: «whereKey($done)
     * ->where('status', cancelled)»). The domain is still «expired», auto renew on, nothing billed: from the next night on
     * the billing step says «past its last recoverable day: not billed» about it, every night, for good. The PR's promise
     * («staff are told, once», tested only for an invoice of the domain alone) does not hold for the grouped invoice.
     */
    public function test_rev_775_1_a_lapsed_domain_on_an_invoice_with_its_hosting_is_told_to_staff_every_night(): void
    {
        $service = $this->service();
        $dead = $this->domain('dead');
        $this->cron(); // 2026-10-26: both due 2026-11-01, one invoice
        $invoice = $this->renewalLine($dead)->invoice;
        $this->assertTrue($invoice->items()->where('reference_type', Service::class)->where('reference_id', $service->id)->exists(),
            'precondition: the hosting and the domain are renewed on one invoice');

        Carbon::setTestNow('2026-12-02 10:00:00'); // 31 days past the expiry, stage days not known
        DB::table('domains')->where('id', $dead->id)->update(['status' => DomainStatus::Expired->value]);
        $this->assertTrue($dead->fresh()->lapsedForGood());
        $this->cron();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'the hosting is still billed');
        $this->assertSame(0, InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $dead->id)->count(), 'the domain\'s line is off');
        $this->assertSame(1, $this->told('dead.com'));

        foreach (['2026-12-03', '2026-12-04', '2026-12-05'] as $night) {
            Carbon::setTestNow("{$night} 10:00:00");
            $this->cron();
        }
        $this->assertSame(1, $this->told('dead.com'), 'told once: not a «past its last recoverable day: not billed» notice every night after its line was taken off');
    }

    /**
     * REV-775-2. After the cron cancelled the lapsed domain's renewal, the customer asks for the name back (a TLD whose
     * redemption is longer than the 30 days assumed where its days are not known) and staff bill the restore on a NEW
     * invoice (a renewal line of the same period). Only an invoice that already carries the cron's own reason is «staff's
     * to decide» (ADV-6); a new one has none, so the next night the cron cancels it, and every night's new one after it.
     * The customer cannot pay what staff just asked for.
     */
    public function test_rev_775_2_a_renewal_staff_bill_again_for_a_lapsed_domain_is_cancelled_the_next_night(): void
    {
        $dead = $this->domain('dead');
        $this->cron();
        $first = $this->renewalLine($dead);
        Carbon::setTestNow('2026-12-02 10:00:00');
        DB::table('domains')->where('id', $dead->id)->update(['status' => DomainStatus::Expired->value]);
        $this->cron();
        $this->assertSame(Invoice::STATUS_CANCELLED, $first->invoice->fresh()->status, 'precondition: the cron gave it up');

        // Staff, the same day: a new invoice for the same period (Invoices → New, a renewal line of the domain).
        $again = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-12-09']);
        $again->items()->create(['reference_type' => Domain::class, 'reference_id' => $dead->id, 'price' => '23.33', 'quantity' => 1, 'description' => 'Renewal dead.com',
            'period_start' => $first->period_start, 'period_end' => $first->period_end]);
        $this->assertSame(Invoice::STATUS_UNPAID, $again->fresh()->status);

        Carbon::setTestNow('2026-12-03 10:00:00');
        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $again->fresh()->status, 'an invoice staff made after the cron gave the period up is theirs: not cancelled the next night');
    }

    /**
     * REV-775-3. The saved-card charge has no rule for a lapsed domain (AutoCharge::due: only ended domains and auto renew
     * off); the PR relies on the cancel step running before it. An invoice the cancel step leaves open for staff (here: a
     * part of it paid through a gateway, «it holds money») is still charged: with a retry day set past the domain's last
     * recoverable day (Settings → retry days «20»; .gr lapses on day 16) the cron takes the rest from the card for a name
     * that can no longer be renewed, the same night it tells staff the invoice was left open. Credit is refused for the
     * same invoice (CreditAutoApply::eligible); the card is not.
     */
    public function test_rev_775_3_the_saved_card_is_charged_for_the_renewal_of_a_lapsed_domain_whose_invoice_was_left_open(): void
    {
        if (!class_exists(AcrPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcrPay; class AcrPay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups(): bool { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcrPay::$charges = [];
        AcrPay::$answer = null;
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '20', 'settings.autocharge_max_failures' => 0]);
        $client = $this->user->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcrPay'], ['name' => 'AcrPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_rev775', 'type' => 'card']);
        $client->update(['auto_charge' => true]);

        $gr = $this->tld('gr', DomainTld::GR_STAGE_DAYS['grace_days'], DomainTld::GR_STAGE_DAYS['redemption_days']);
        $domain = $this->domain('example', [], $gr);
        $this->cron(); // billed, due 2026-11-01; not charged yet
        $invoice = $this->renewalLine($domain)->invoice;
        $this->assertSame([], AcrPay::$charges, 'precondition: nothing charged before its due date');
        // A part of it paid by a transfer (a gateway payment), the rest never.
        (new RecordPaymentService)->handle($invoice->fresh(), null, 5.00, transactionId: 'rev-775-part');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        Carbon::setTestNow('2026-11-21 10:00:00'); // day 20: 5 days past its last recoverable day
        DB::table('domains')->where('id', $domain->id)->update(['status' => DomainStatus::Grace->value]);
        $this->assertTrue($domain->fresh()->lapsedForGood());
        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: left open for staff, it holds money');
        $this->assertSame([], AcrPay::$charges, 'the card is not charged for the renewal of a domain past its last recoverable day');
    }

    /** The WHMCS tables the addon checks read. */
    private function whmcsAddons(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, type TEXT, relid INT, duedate TEXT)');

        return $whmcs;
    }

    private function check(PDO $whmcs, string $method): array
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);

        return (new ReflectionMethod($command, $method))->invoke($command);
    }

    /**
     * REV-775-4 (for the owner's decision). The mirror of the one shape the import takes: not the addon behind its paid
     * service with its own renewal unpaid, but the SERVICE one period behind its paid addon, with the service's own renewal
     * of that period unpaid (its «Hosting» line due on the service's nextduedate, on an Unpaid invoice). The shape of the
     * one addon the check names on real data. Both are «the same date, one of the two has not paid its period yet», and
     * both meet again when the invoice is paid (test_shown_* below). The import takes the first and refuses the second,
     * and the refusal has no remedy in WHMCS that leaves the money alone: the addon's date moved back bills a month it
     * has paid again; the service's date moved forward gives its unpaid month away, and its unpaid invoice then is a
     * «stale renewal» that refuses the import in its turn (staleRenewals). Only the customer paying unblocks the cut-over.
     */
    public function test_rev_775_4_an_addon_ahead_only_because_its_services_own_renewal_is_unpaid_blocks_the_import(): void
    {
        $whmcs = $this->whmcsAddons();
        $whmcs->exec("INSERT INTO tblhosting VALUES (7, 'Active', 'Monthly', '2026-10-28')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (70, 7, 'Active', 'Monthly', '2026-11-28')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (1, 'Unpaid')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (10, 1, 'Hosting', 7, '2026-10-28')");
        $this->assertSame([], $this->check($whmcs, 'staleRenewals'), 'the service\'s unpaid renewal is of the period it is in: a debt, not refused');
        // The remedy the runbook names («align it»), on a copy: the service's date moved to its addon's makes its unpaid
        // invoice a stale renewal, so the import refuses for the invoice instead.
        $aligned = $this->whmcsAddons();
        $aligned->exec("INSERT INTO tblhosting VALUES (7, 'Active', 'Monthly', '2026-11-28')");
        $aligned->exec("INSERT INTO tblhostingaddons VALUES (70, 7, 'Active', 'Monthly', '2026-11-28')");
        $aligned->exec("INSERT INTO tblinvoices VALUES (1, 'Unpaid')");
        $aligned->exec("INSERT INTO tblinvoiceitems VALUES (10, 1, 'Hosting', 7, '2026-10-28')");
        $this->assertSame([[], [1 => ['service #7']]], [$this->check($aligned, 'addonsApart'), $this->check($aligned, 'staleRenewals')]);

        $this->assertSame([], $this->check($whmcs, 'addonsApart'),
            'a pair one period apart only because the service\'s own renewal is unpaid is as transient as the addon behind with its own renewal unpaid');
    }

    /**
     * Shown, not a defect: what MyCS does with that pair if it were imported as it is (the service due 2026-10-28 with its
     * imported unpaid renewal, its addon due 2026-11-28). Nothing is billed twice or lost: the addon's next period is
     * billed at its time on its own, the service's is not billed while its renewal is open, and once that invoice is paid
     * the service is due on its addon's date and both renew together from then on.
     */
    public function test_shown_a_service_one_unpaid_period_behind_its_addon_meets_it_again_when_its_invoice_is_paid(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $parent = $this->service(['expires_at' => '2026-10-28']);
        $addon = $this->service(['expires_at' => '2026-11-28', 'parent_service_id' => $parent->id, 'price' => 3.00]);
        // The service's unpaid renewal as the importer writes it: no kind, no period.
        $id = DB::table('invoices')->insertGetId(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR',
            'due_at' => '2026-10-28', 'created_at' => '2026-10-21', 'updated_at' => '2026-10-21']);
        DB::table('invoice_items')->insert(['invoice_id' => $id, 'reference_type' => Service::class, 'reference_id' => $parent->id, 'description' => 'Hosting',
            'price' => '10.00', 'quantity' => 1, 'taxed' => false, 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => '10.00', 'created_at' => '2026-10-21', 'updated_at' => '2026-10-21']);
        $lines = fn (Service $service) => InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', '!=', Invoice::STATUS_CANCELLED))->orderBy('id')->get()
            ->map(fn (InvoiceItem $line) => $line->period_start?->toDateString())->all();

        $this->cron();
        $this->assertSame([[null], []], [$lines($parent), $lines($addon)], 'today: nothing new billed');

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->cron();
        $this->assertSame([[null], ['2026-11-28']], [$lines($parent), $lines($addon)], 'the addon\'s next period is billed alone; the service\'s waits for its open renewal');

        (new RecordPaymentService)->handle(Invoice::query()->findOrFail($id), null, (float) Invoice::query()->findOrFail($id)->remaining, transactionId: 'rev-775-parent');
        $this->assertSame(['2026-11-28', '2026-11-28'], [$parent->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString()],
            'paid: the service is due on its addon\'s date');

        $this->cron();
        $this->assertSame(['2026-11-28', '2026-11-28'], [last($lines($parent)), last($lines($addon))], 'and its next period is billed for the same day as its addon\'s');
        $this->assertCount(1, $lines($addon), 'the addon is not billed twice');
    }
}
