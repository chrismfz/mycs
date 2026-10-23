<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Console\Commands\ImportFromWhmcs;
use App\Events\Invoice\Finalized;
use App\Listeners\SendMailListener;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR C1 (renewal grouping, docs/billing-cursor.md §«Ομαδοποίηση ανανεώσεων»). Each test says what
 * it attacks; «FAILING = confirmed defect» tests fail on the branch as reviewed, «regression coverage» ones pass.
 */
class RenewalGroupingAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        RenewalGroupingFakePay::$charges = [];
        if (!class_exists('MyCS\\Extensions\\Gateways\\GroupingFakePay\\GroupingFakePay', false)) {
            class_alias(RenewalGroupingFakePay::class, 'MyCS\\Extensions\\Gateways\\GroupingFakePay\\GroupingFakePay');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function service(array $attributes = [], ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => ($user ?? $this->user)->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    /** The renewals' due date (2026-10-25), when the cron charges the saved method (PR H), and its run that day. */
    private function onTheDueDate(): void
    {
        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->cron();
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** A run that may fail (a failure is rethrown after the run): what it committed stays. */
    private function cronMayFail(): void
    {
        try {
            Artisan::call('app:cron-job');
        } catch (Throwable) {
        }
    }

    /** @return Collection<int, Invoice> */
    private function renewalInvoices(): Collection
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class))->with('items')->orderBy('id')->get();
    }

    private function credit(int $cents): void
    {
        (new CreditLedger)->add($this->user->client()->id, 'USD', $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'credit');
    }

    private function creditLeft(): string
    {
        return number_format((float) Credit::query()->where('client_id', $this->user->client()->id)->value('amount'), 2, '.', '');
    }

    private function agreement(): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'GroupingFakePay'], ['name' => 'Fake', 'type' => 'gateway']);

        return BillingAgreement::create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'Card',
            'external_reference' => 'VAULT-C1', 'type' => 'card']);
    }

    private static function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** @return list<array{rule: string, subject: string, detail: string}> */
    private static function moneyFindings(Invoice $invoice): array
    {
        return array_values(array_filter((new MoneyCheck)->run(), fn (array $finding) => $finding['subject'] === "invoice #{$invoice->id}"));
    }

    /**
     * C1-ADV-1 (medium). billRenewal registers the new invoice as its group's (renewalGroups / renewalInvoices) BEFORE its
     * line is written, inside the service's transaction. When that transaction rolls back (any failure after the invoice
     * is saved: the line's insert, an observer, a deadlock), the entry stays and points at an invoice that no longer
     * exists. Every later service of the same group then calls lockInvoice() on it: on MySQL a ModelNotFoundException,
     * so the whole rest of the customer's group fails this run; where the id is reused (SQLite, here: the next invoice,
     * of ANOTHER customer, gets it), the group's next line lands on that other customer's invoice. finishRenewalInvoices
     * also walks the dead entry (a failure for an invoice that does not exist, or a second finish of the reused one).
     * The reused invoice is never checked against the group's customer and currency.
     *
     * FAILING = confirmed defect.
     */
    public function test_a_failed_first_line_does_not_poison_its_group(): void
    {
        $other = User::factory()->create();
        $first = $this->service();            // its line fails: its transaction rolls back
        $stranger = $this->service([], $other); // another customer, billed next
        $second = $this->service();           // same group as $first
        InvoiceItem::creating(function (InvoiceItem $line) use ($first) {
            if ($line->reference_type === Service::class && (int) $line->reference_id === (int) $first->id) {
                throw new \RuntimeException('injected failure of the first line');
            }
        });

        $this->cronMayFail();

        foreach (InvoiceItem::query()->where('reference_type', Service::class)->with('invoice')->get() as $line) {
            $this->assertSame((int) Service::query()->find($line->reference_id)->client_id, (int) $line->invoice->client_id,
                "service #{$line->reference_id} is billed on invoice #{$line->invoice_id} of another customer");
        }
        $this->assertTrue($second->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->exists(),
            'the second service of the group was not billed: the failed first one poisoned the group');
        $this->assertTrue($stranger->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->exists());
    }

    /**
     * C1-ADV-2 (low). Invoice\Finalized («Invoice Created») now fires twice for every renewal invoice: once by the
     * InvoiceObserver after the first service's commit (sendEmail=false, ONE line on it), then by finishRenewalInvoices.
     * The mail listener filters on sendEmail, but other listeners do not: the built-in Discord extension posts «Invoice
     * Created» twice per renewal invoice, the first time with the first line's total only. On main it fired once.
     *
     * FAILING = confirmed defect.
     */
    public function test_invoice_created_fires_once_per_renewal_invoice_with_all_its_lines(): void
    {
        $seen = [];
        Event::listen(Finalized::class, function (Finalized $event) use (&$seen) {
            $seen[] = $event->invoice->items()->count();
        });
        $this->service();
        $this->service();

        $this->cron();

        $this->assertSame([2], $seen, 'Invoice Created fired with these line counts');
    }

    /**
     * C1-ADV-3 (low). The line's options text is written only by the cron. Service::refreshOpenPeriods (a plan change by
     * staff, an upgrade paid: ServiceUpgradeService) rewrites an open renewal line's description with describePeriod()
     * alone: the options vanish from the invoice the customer is asked to pay (and after an upgrade the new options are
     * never shown).
     *
     * FAILING = confirmed defect.
     */
    public function test_the_options_stay_on_the_line_after_a_plan_change(): void
    {
        $service = $this->service();
        $option = ConfigOption::query()->create(['name' => 'Location', 'type' => 'select', 'hidden' => false]);
        $value = ConfigOption::query()->create(['name' => 'Athens', 'type' => 'select', 'parent_id' => $option->id, 'hidden' => false]);
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);
        $this->cron();
        $this->assertStringContainsString('Location: Athens', $this->renewalInvoices()->sole()->items->sole()->description);

        $plan = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Other',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10.00, 'currency_code' => 'USD']);
        $service->fresh()->update(['plan_id' => $plan->id]); // staff move it to another plan: open lines are refreshed

        $this->assertStringContainsString('Location: Athens', $this->renewalInvoices()->sole()->items->sole()->fresh()->description);
    }

    /**
     * C1-ADV-4 (low). The admin form shows «Separate invoices» on create too, but CreateClient::handleRecordCreation writes
     * only credit_auto_apply and tax_exempt: the toggle is silently dropped for a new account.
     *
     * FAILING = confirmed defect.
     */
    public function test_staff_creating_an_account_with_separate_invoices_keeps_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.clients.create', 'admin.clients.viewAny', 'admin.clients.update'])->create();

        $this->livewireAs($staff)->test(CreateClient::class)
            ->fillForm(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'password' => 'secret-pass-1', 'separate_invoices' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue((bool) User::query()->where('email', 'ada@example.test')->sole()->client()->separate_invoices);
    }

    /**
     * C1-ADV-5 (low, behaviour change). ChangeCycle refuses while ANY open invoice of the service has a payment. With
     * grouping, credit applied in part to the shared invoice (auto-apply, MT-1e) blocks a change of cycle of every service
     * on it, though CancelOpenInvoices' partial path handles credit (only what is over the rest goes back). Before C1 the
     * credit sat on one service's own invoice and only that service was blocked.
     *
     * FAILING = confirmed defect.
     */
    public function test_credit_on_the_shared_invoice_does_not_block_a_cycle_change_of_another_service(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $one = $this->service();
        $two = $this->service();
        $this->credit(500);
        $this->cron();
        $invoice = $this->renewalInvoices()->sole();
        $this->assertSame('15.00', self::money($invoice->fresh()->remaining), 'precondition: credit paid part of it');

        $yearly = Plan::factory()->create(['priceable_id' => $two->product_id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $yearly->id, 'price' => 100.00, 'currency_code' => 'USD']);

        ChangeCycle::apply($two->fresh(), $yearly->fresh(), ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);

        $this->assertSame([(int) $one->id], $invoice->fresh()->items->pluck('reference_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('5.00', self::money($invoice->fresh()->remaining));
    }

    /**
     * Emails: exactly one «invoice created» mail per grouped invoice, queued once all its lines are on it. Regression
     * coverage.
     */
    public function test_one_invoice_created_mail_per_grouped_invoice(): void
    {
        $this->service();
        $this->service();
        $this->service(['expires_at' => '2026-10-26']);

        $this->cron();

        $mails = Queue::pushed(CallQueuedListener::class)->filter(fn (CallQueuedListener $job) => $job->class === SendMailListener::class
            && ($job->data[0] ?? null) instanceof Finalized);
        $this->assertCount(2, $this->renewalInvoices());
        $this->assertCount(2, $mails);
        $this->assertSame([2, 1], $mails->map(fn ($job) => $job->data[0]->invoice->items()->count())->values()->all());
    }

    /**
     * A saved method charges the grouped invoice once, for what the partial credit left. Regression coverage.
     */
    public function test_the_saved_method_charges_once_what_partial_credit_left(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $this->agreement(); // the account's method (decision 6, PR C2)
        $this->service();
        $this->service();
        $this->credit(500);

        $this->cron();
        $this->onTheDueDate(); // charged on its due date (PR H)

        $charged = $this->renewalInvoices()->sole();
        $this->assertSame([[(int) $charged->id, '15.00']], RenewalGroupingFakePay::$charges);
        $this->assertSame('0.00', $this->creditLeft());
    }

    /**
     * A customer opted out of automatic credit: the grouped invoice is charged whole, the credit kept. Regression
     * coverage.
     */
    public function test_opted_out_credit_is_kept_and_the_whole_invoice_charged(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $this->user->client()->update(['credit_auto_apply' => false]);
        $this->agreement(); // the account's method (decision 6, PR C2)
        $this->service();
        $this->service();
        $this->credit(500);

        $this->cron();
        $this->onTheDueDate(); // charged on its due date (PR H)

        $this->assertSame([[(int) $this->renewalInvoices()->sole()->id, '20.00']], RenewalGroupingFakePay::$charges);
        $this->assertSame('5.00', $this->creditLeft());
    }

    /** A partial payment of the grouped invoice renews none of its services; the rest renews all. Regression coverage. */
    public function test_a_partial_payment_renews_nothing_the_rest_renews_all(): void
    {
        $one = $this->service();
        $two = $this->service();
        $this->cron();
        $invoice = $this->renewalInvoices()->sole();

        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-part');
        $this->assertSame('2026-10-25', $one->fresh()->expires_at->toDateString());
        $this->assertSame('2026-10-25', $two->fresh()->expires_at->toDateString());

        (new RecordPaymentService)->handle($invoice->fresh(), null, 10.0, transactionId: 'wire-rest');
        $this->assertSame('2026-11-25', $one->fresh()->expires_at->toDateString());
        $this->assertSame('2026-11-25', $two->fresh()->expires_at->toDateString());
        $this->assertSame([], self::moneyFindings($invoice));
    }

    /** A second run the same day bills nothing more and mails nothing more. Regression coverage. */
    public function test_a_second_run_the_same_day_changes_nothing(): void
    {
        $this->service();
        $this->service();
        $this->service([], User::factory()->create());
        $this->cron();
        $before = InvoiceItem::query()->orderBy('id')->get(['id', 'invoice_id', 'price'])->toArray();
        $mails = Queue::pushed(CallQueuedListener::class)->count();

        Carbon::setTestNow('2026-10-20 18:00:00');
        $this->cron();

        $this->assertSame($before, InvoiceItem::query()->orderBy('id')->get(['id', 'invoice_id', 'price'])->toArray());
        $this->assertSame($mails, Queue::pushed(CallQueuedListener::class)->count());
    }

    /** A service with no plan in the middle of a group: told to staff, the others still grouped. Regression coverage. */
    public function test_a_service_without_plan_does_not_break_its_group(): void
    {
        $one = $this->service();
        $broken = $this->service();
        $two = $this->service();
        Service::query()->whereKey($broken->id)->update(['plan_id' => null]);

        $this->cron();

        $invoice = $this->renewalInvoices()->sole();
        $this->assertSame([(int) $one->id, (int) $two->id], $invoice->items->pluck('reference_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        $this->assertSame('20.00', self::money($invoice->total));
    }

    /**
     * One service of a shared invoice ends (as the cron's termination does) while credit paid part of it: only its line
     * goes, the credit keeps paying the rest, the money check stays clean, and paying the rest renews the other only.
     * Regression coverage.
     */
    public function test_ending_one_service_with_partial_credit_on_the_shared_invoice(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $one = $this->service();
        $two = $this->service();
        $this->credit(500);
        $this->cron();
        $invoice = $this->renewalInvoices()->sole();

        $one->fresh()->update(['status' => Service::STATUS_TERMINATED]);
        $left = CancelOpenInvoices::handle(CancelOpenInvoices::onServiceEnd($one->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()),
            'terminated', $one->fresh());

        $this->assertSame([], $left);
        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame([(int) $two->id], $invoice->items->pluck('reference_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('5.00', self::money($invoice->remaining));
        $this->assertSame('0.00', $this->creditLeft());
        $this->assertSame([], self::moneyFindings($invoice));

        (new RecordPaymentService)->handle($invoice, null, 5.0, transactionId: 'wire-rest');
        $this->assertSame('2026-11-25', $two->fresh()->expires_at->toDateString());
        $this->assertSame(Service::STATUS_TERMINATED, $one->fresh()->status);
        $this->assertSame([], self::moneyFindings($invoice));
    }

    /**
     * «Keep overdue invoices of ended services» (Δ3a) on a shared invoice: the whole invoice is kept as a debt; paid, it
     * renews the service still running and never revives the ended one; the money check does not flag it. Regression
     * coverage.
     */
    public function test_a_kept_debt_on_a_shared_invoice(): void
    {
        $one = $this->service();
        $two = $this->service();
        $this->cron();
        $invoice = $this->renewalInvoices()->sole();
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        Carbon::setTestNow('2026-10-28 10:00:00');

        $one->fresh()->update(['status' => Service::STATUS_TERMINATED]);
        $open = CancelOpenInvoices::onServiceEnd($one->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get());
        $this->assertCount(0, $open);
        $this->assertNotNull($invoice->fresh()->kept_as_debt_at);

        (new RecordPaymentService)->handle($invoice->fresh(), null, 20.0, transactionId: 'wire-debt');

        $this->assertSame('2026-11-25', $two->fresh()->expires_at->toDateString());
        $this->assertSame(Service::STATUS_TERMINATED, $one->fresh()->status);
        $this->assertSame([], self::moneyFindings($invoice));
    }

    /** Due date of the grouped invoice is the services' expiry (reminders and dunning read it). Regression coverage. */
    public function test_the_grouped_invoice_is_due_on_the_services_expiry(): void
    {
        $this->service(['expires_at' => '2026-10-25 00:00:00']);
        $this->service(['expires_at' => '2026-10-25 00:00:00']);

        $this->cron();

        $this->assertSame('2026-10-25', $this->renewalInvoices()->sole()->due_at->toDateString());
    }

    /** Lines without options carry no trailing newline; hidden options stay out. Regression coverage. */
    public function test_option_text_is_clean(): void
    {
        $plain = $this->service();
        $with = $this->service();
        $hidden = ConfigOption::query()->create(['name' => 'Internal', 'type' => 'select', 'hidden' => true]);
        $hiddenValue = ConfigOption::query()->create(['name' => 'Yes', 'type' => 'select', 'parent_id' => $hidden->id, 'hidden' => false]);
        $with->configs()->create(['config_option_id' => $hidden->id, 'config_value_id' => $hiddenValue->id]);

        $this->cron();

        $lines = $this->renewalInvoices()->sole()->items->keyBy(fn ($line) => (int) $line->reference_id);
        $this->assertSame($lines[$plain->id]->description, trim($lines[$plain->id]->description));
        $this->assertStringNotContainsString("\n", $lines[$with->id]->description);
        $this->assertStringNotContainsString('Internal', $lines[$with->id]->description);
    }

    /** Staff set «Separate invoices» on an existing account. Regression coverage. */
    public function test_staff_edit_separate_invoices(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update'])->create();

        $this->livewireAs($staff)->test(EditClient::class, ['record' => $this->user->client()->getRouteKey()])
            ->assertFormSet(['separate_invoices' => false])
            ->fillForm(['separate_invoices' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->user->client()->fresh()->separate_invoices);
    }

    /** The importer brings WHMCS tblclients.separateinvoices. Regression coverage. */
    public function test_the_importer_brings_separate_invoices(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, email TEXT, companyname TEXT,
            updated_at TEXT, created_at TEXT, credit REAL, currency INT, taxexempt INT, separateinvoices INT)');
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT, email_verified_at TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (auth_user_id INT, client_id INT, owner INT)');
        foreach ([[9101, 1], [9102, 0]] as [$id, $separate]) {
            $whmcs->exec("INSERT INTO tblclients VALUES ({$id}, 'A', 'B', 'c{$id}@example.test', '', '2024-01-02 10:00:00', '2024-01-02 10:00:00', 0, 1, 0, {$separate})");
            $whmcs->exec("INSERT INTO tblusers VALUES ({$id}, 'A', 'B', 'c{$id}@example.test', 'x', NULL)");
            $whmcs->exec("INSERT INTO tblusers_clients VALUES ({$id}, {$id}, 1)");
        }

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);

        $this->assertTrue((bool) Client::query()->find(9101)->separate_invoices);
        $this->assertFalse((bool) Client::query()->find(9102)->separate_invoices);
    }
}

/** A gateway that records what it is asked to charge (aliased as MyCS\Extensions\Gateways\GroupingFakePay\GroupingFakePay). */
class RenewalGroupingFakePay
{
    /** @var list<array{0: int, 1: string}> */
    public static array $charges = [];

    public function __construct($config = []) {}

    public function charge(Invoice $invoice, $total, BillingAgreement $billingAgreement): bool
    {
        self::$charges[] = [(int) $invoice->id, number_format((float) $total, 2, '.', '')];

        return true;
    }
}
