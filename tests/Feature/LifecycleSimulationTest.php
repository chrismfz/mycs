<?php

namespace MyCS\Extensions\Servers\SimServer {

    use App\Classes\Extension\Server;
    use App\Models\Service;
    use RuntimeException;

    /** A server that keeps each account's state, and can be down for a while. */
    class SimServer extends Server
    {
        /** @var array<int, string> service id => active|suspended|terminated */
        public static array $state = [];

        public static bool $down = false;

        /** @var list<string> */
        public static array $calls = [];

        public function getConfig($values = []): array
        {
            return [];
        }

        private function act(Service $service, string $what, string $to): bool
        {
            self::$calls[] = "{$what} #{$service->id}";
            if (self::$down) {
                throw new RuntimeException('Server unreachable');
            }
            self::$state[$service->id] = $to;

            return true;
        }

        public function createServer(Service $service, $settings, $properties)
        {
            return $this->act($service, 'create', 'active');
        }

        public function suspendServer(Service $service, $settings, $properties)
        {
            return $this->act($service, 'suspend', 'suspended');
        }

        public function unsuspendServer(Service $service, $settings, $properties)
        {
            return $this->act($service, 'unsuspend', 'active');
        }

        public function terminateServer(Service $service, $settings, $properties)
        {
            return $this->act($service, 'terminate', 'terminated');
        }
    }
}

namespace MyCS\Extensions\Gateways\SimPay {

    use App\Exceptions\DisplayException;
    use App\Helpers\ExtensionHelper;
    use Illuminate\Support\Carbon;

    /** A card gateway: declines a customer's card until a day, then takes the money and records it (as a real one). */
    class SimPay
    {
        public $explicitConfig;

        /** @var array<int, string> client id => first day (Y-m-d) the card is accepted; absent: always */
        public static array $acceptFrom = [];

        /** @var list<array{invoice: int, amount: float, at: string, ok: bool}> */
        public static array $charges = [];

        public function __construct($config = []) {}

        public function supportsBillingAgreements()
        {
            return true;
        }

        public function cancelBillingAgreement($agreement): bool
        {
            return true;
        }

        public function charge($invoice, $amount, $agreement)
        {
            $from = self::$acceptFrom[(int) $invoice->client_id] ?? null;
            $ok = $from === null || Carbon::now()->toDateString() >= $from;
            self::$charges[] = ['invoice' => (int) $invoice->id, 'amount' => (float) $amount, 'at' => (string) Carbon::now(), 'ok' => $ok];
            if (!$ok) {
                throw new DisplayException('Card declined: insufficient_funds');
            }
            ExtensionHelper::addPayment($invoice->id, 'SimPay', $amount, null, 'ch_' . count(self::$charges), currency: 'USD');

            return true;
        }
    }
}

namespace Tests\Feature {

    use App\Enums\DomainStatus;
    use App\Enums\InvoiceTransactionStatus;
    use App\Exceptions\DisplayException;
    use App\Helpers\ExtensionHelper;
    use App\Helpers\NotificationHelper;
    use App\Models\BillingAgreement;
    use App\Models\Client;
    use App\Models\Credit;
    use App\Models\Domain;
    use App\Models\DomainRegistrarConnection;
    use App\Models\DomainTld;
    use App\Models\DomainTldPrice;
    use App\Models\EmailLog;
    use App\Models\Gateway;
    use App\Models\Invoice;
    use App\Models\InvoiceItem;
    use App\Models\InvoiceTransaction;
    use App\Models\ModuleAction;
    use App\Models\Plan;
    use App\Models\Server;
    use App\Models\Service;
    use App\Models\ServiceCancellation;
    use App\Models\User;
    use App\Services\Invoice\RecordPaymentService;
    use App\Services\Money\CreditLedger;
    use App\Services\Money\MoneyCheck;
    use App\Services\Money\MoneyTrail;
    use App\Support\DunningWindow;
    use Carbon\CarbonImmutable;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Http\Client\Request;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Mail as MailFacade;
    use MyCS\Extensions\Gateways\SimPay\SimPay;
    use MyCS\Extensions\Servers\SimServer\SimServer;
    use PHPUnit\Framework\Attributes\DataProvider;
    use Tests\TestCase;
    use Throwable;

    /**
     * Seeded simulation of ~20 customers living 75 days: the daily cron at its hour, the hourly dunning run, a queue
     * worker, a server that keeps state, a card gateway, and random customer and staff events. Invariants after each
     * cron run and each day; every violation is collected with the client's history (SIM_SEEDS="1-10" for more).
     */
    class LifecycleSimulationTest extends TestCase
    {
        use RefreshDatabase;

        private const DAYS = 75;

        private const SUSPEND = 5;

        private const TERMINATE = 30;

        private const MIN_SUSPENDED = 3;

        /** @var array<int, array{persona: string, client: Client, user: User}> */
        private array $clients = [];

        /** @var list<string> */
        private array $log = [];

        /** @var array<string, string> violation key => message (one per key) */
        private array $violations = [];

        /** @var array<int, string> service id => events (for the readable failure) */
        private array $history = [];

        /** @var array<int, true> services whose dates staff moved, or a period given up on (gaps allowed) */
        private array $gapsAllowed = [];

        /** @var array<int, list<array{at: string, kind: string}>> service id => staff silent unsuspensions */
        private array $silent = [];

        /** @var array<int, array{status: string, dunning: ?string, expires: ?string}> */
        private array $snapshot = [];

        private int $lastMailId = 0;

        /** @var array<int, array{on: string, invoice: int, at: string}> service id => the suspension day its last notice announced */
        private array $announced = [];

        /** @var array<int, string> service id => the last day staff unsuspended it */
        private array $staffUnsuspendedOn = [];

        /** @var array<string, true> days the daily cron did not run */
        private array $missedDays = [];

        /** @var array<int, string> services with an invoice staff cancelled «auto» or «give_up» */
        private array $gaveUp = [];

        /** @var array<string, string> what is by design: reported, not failed */
        private array $design = [];

        /** @var array<int, string> service id => last customer mail kind (suspended/unsuspended) */
        private array $mailState = [];

        private int $tx = 0;

        /** @var array<string, array<string, mixed>> registrar id => the registrar's record */
        private array $registrar = [];

        /** @var array<string, int> registrar id => renewals charged */
        private array $renewCharges = [];

        private bool $windowed;

        private string $cronAt;

        protected function setUp(): void
        {
            parent::setUp();
            MailFacade::fake();
            SimServer::$state = [];
            SimServer::$down = false;
            SimServer::$calls = [];
            SimPay::$acceptFrom = [];
            SimPay::$charges = [];
            config(['queue.default' => 'database']);
        }

        protected function tearDown(): void
        {
            Carbon::setTestNow();
            parent::tearDown();
        }

        public static function seeds(): array
        {
            $env = getenv('SIM_SEEDS') ?: '1-4';
            $list = preg_match('/^(\d+)-(\d+)$/', $env, $m) ? range((int) $m[1], (int) $m[2]) : array_map('intval', explode(',', $env));
            $out = [];
            foreach ($list as $seed) {
                if (getenv('SIM_STRICT') !== 'only') {
                    $out["seed {$seed} always"] = [$seed, false, false];
                    $out["seed {$seed} window"] = [$seed, true, false];
                }
                if (getenv('SIM_STRICT')) {
                    $out["seed {$seed} strict"] = [$seed, $seed % 2 === 1, true];
                }
            }

            return $out;
        }

        #[DataProvider('seeds')]
        public function test_lifecycle_simulation(int $seed, bool $windowed, bool $strict = false): void
        {
            // About a minute a data set: run on demand, not in every suite run (SIM_SEEDS=1-8 bin/test --serial this file).
            if (getenv('SIM_SEEDS') === false) {
                $this->markTestSkipped('The lifecycle simulation runs on demand: SIM_SEEDS=1-4 bin/test --serial tests/Feature/LifecycleSimulationTest.php');
            }
            mt_srand($seed);
            if ($strict) {
                // The other settings: suspend only with an overdue invoice, keep overdue invoices of ended services as a debt,
                // card retries on days 1, 3, 5 and stopped after 3 declines.
                config(['settings.cronjob_suspend_requires_invoice' => true, 'settings.cronjob_end_keeps_overdue' => true,
                    'settings.cronjob_end_keeps_overdue_days' => 10]);
            }
            $this->windowed = $windowed;
            $this->cronAt = ['00:05', '03:00', '14:00', '22:00'][mt_rand(0, 3)];
            config([
                'settings.cronjob_order_suspend' => self::SUSPEND, 'settings.cronjob_order_terminate' => self::TERMINATE,
                'settings.cronjob_terminate_min_suspended_days' => self::MIN_SUSPENDED, 'settings.cronjob_invoice' => 7,
                'settings.cronjob_payment_reminder' => 3, 'settings.cronjob_overdue_reminders' => '1,4',
                'settings.credits_enabled' => true, 'settings.credits_auto_use' => true,
                'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => $strict ? '1,3,5' : '', 'settings.autocharge_max_failures' => $strict ? 3 : 0,
                'settings.cronjob_order_cancel' => 0, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
                'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
                'settings.cronjob_dunning_days' => $windowed ? [1, 2, 3, 4, 5] : DunningWindow::ALL_DAYS,
                'settings.cronjob_dunning_from' => $windowed ? '10:00' : '00:00',
            ]);
            $start = CarbonImmutable::parse('2026-11-02 00:00:00'); // a Monday
            Carbon::setTestNow($start);
            $this->note("seed={$seed} window=" . ($windowed ? 'Mon-Fri 10:00' : 'always') . " daily cron at {$this->cronAt}");
            $this->build($start);

            for ($d = 0; $d < (int) (getenv('SIM_DAYS') ?: self::DAYS); $d++) {
                $this->day($start->addDays($d));
            }

            fwrite(STDERR, sprintf("\n[seed %d %s] invoices %d (paid %d, cancelled %d), payments %d, charges %d (ok %d), server calls %d, suspended now %d, terminated %d, cancelled %d, mails %s, jobs left %d, failed jobs %d\n",
                $seed, $windowed ? 'window' : 'always', Invoice::count(), Invoice::where('status', 'paid')->count(), Invoice::where('status', 'cancelled')->count(),
                InvoiceTransaction::count(), count(SimPay::$charges), collect(SimPay::$charges)->where('ok', true)->count(), count(SimServer::$calls),
                Service::where('status', 'suspended')->count(), Service::where('status', 'terminated')->count(), Service::where('status', 'cancelled')->count(),
                json_encode(EmailLog::query()->selectRaw('template_key, count(*) n')->groupBy('template_key')->pluck('n', 'template_key')),
                DB::table('jobs')->count(), DB::table('failed_jobs')->count()));
            $report = [];
            foreach ($this->violations as $key => $message) {
                $report[] = $message;
            }
            if ($this->design !== []) {
                fwrite(STDERR, "\n--- by design (seed {$seed}):\n" . implode("\n", $this->design) . "\n");
            }
            if ($report !== []) {
                fwrite(STDERR, "\n=== seed {$seed} " . ($windowed ? 'window' : 'always') . ': ' . count($report) . " violation(s)\n" . implode("\n\n", $report) . "\n");
            }
            $this->assertSame([], array_keys($this->violations), implode("\n\n", array_slice($report, 0, 5)));
        }

        // ============================================================================================ the world

        private function build(CarbonImmutable $start): void
        {
            $server = Server::query()->create(['name' => 'Sim', 'extension' => 'SimServer', 'type' => 'server', 'enabled' => true]);
            $gateway = Gateway::query()->firstOrCreate(['extension' => 'SimPay'], ['name' => 'SimPay', 'type' => 'gateway']);
            $monthly = $this->createProduct();
            $monthly->product->update(['server_id' => $server->id]);
            $yearly = $this->createProduct();
            $yearly->product->update(['server_id' => $server->id]);
            Plan::query()->whereKey($yearly->plan->id)->update(['billing_unit' => 'year', 'billing_period' => 1]);

            $personas = ['card_ok', 'card_ok', 'card_decline', 'card_decline', 'card_never', 'bank_prompt', 'bank_prompt', 'bank_prompt',
                'bank_late', 'bank_late', 'bank_late', 'bank_never', 'bank_never', 'partial', 'partial', 'double', 'double', 'credit', 'credit', 'bank_late'];
            foreach ($personas as $i => $persona) {
                $user = User::factory()->create();
                $client = $user->client();
                if (str_starts_with($persona, 'card')) {
                    BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa', 'external_reference' => "pm_{$i}", 'type' => 'card']);
                    $client->refresh();
                    $client->forceFill(['auto_charge' => true])->save();
                    if ($persona === 'card_never') {
                        SimPay::$acceptFrom[$client->id] = '2099-01-01';
                    }
                }
                if ($persona === 'credit') {
                    (new CreditLedger)->add($client->id, 'USD', mt_rand(1500, 4000), MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'opening');
                }
                $this->clients[$client->id] = ['persona' => $persona, 'client' => $client, 'user' => $user];
                $n = mt_rand(1, 10) <= 7 ? 1 : 2;
                for ($k = 0; $k < $n; $k++) {
                    $year = mt_rand(1, 6) === 1;
                    $product = $year ? $yearly : $monthly;
                    $expires = $start->addDays(mt_rand(-2, 35))->toDateString();
                    $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                        'server_id' => $server->id, 'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD',
                        'price' => $year ? 100.00 : [10.00, 15.00, 7.50][mt_rand(0, 2)], 'quantity' => 1]);
                    SimServer::$state[$service->id] = 'active';
                    $this->event($service->id, "created ({$persona}, " . ($year ? 'yearly' : 'monthly') . " {$service->price}, expires {$expires})");
                }
            }
            $this->buildDomains($start);
            $this->snapshotAll();
        }

        /** A few .com domains at a fake Openprovider that keeps their expiry and counts the renewals charged. */
        private function buildDomains(CarbonImmutable $start): void
        {
            Http::fake(function (Request $request) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                if (str_ends_with($path, '/auth/login')) {
                    return Http::response(['data' => ['token' => 'tok']]);
                }
                if (preg_match('#/domains/(\d+)(/renew)?$#', $path, $m) === 1 && isset($this->registrar[$m[1]])) {
                    if (($m[2] ?? '') === '/renew' && $request->method() === 'POST') {
                        $this->renewCharges[$m[1]] = ($this->renewCharges[$m[1]] ?? 0) + 1;
                        $this->registrar[$m[1]]['expiration_date'] = Carbon::parse($this->registrar[$m[1]]['expiration_date'])->addYears((int) $request->data()['period'])->format('Y-m-d H:i:s');

                        return Http::response(['code' => 0, 'data' => ['success' => true]]);
                    }

                    return Http::response(['data' => $this->registrar[$m[1]]]);
                }

                return Http::response(['code' => 399, 'desc' => 'not simulated: ' . $request->method() . ' ' . $path], 400);
            });
            $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
            $op->storeCredentials(['username' => 'u', 'password' => 'Sim-Secret-Pa55'], ['password']);
            $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
            foreach ([1 => '12.00', 2 => '24.00'] as $years => $price) {
                DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'USD'], ['price' => $price]);
            }
            $ids = array_keys($this->clients);
            for ($i = 0; $i < 7; $i++) {
                $clientId = $ids[mt_rand(0, count($ids) - 1)];
                // Due the same day as one of its services half the time: one invoice for both (PR C).
                $same = Service::query()->where('client_id', $clientId)->first();
                $expires = mt_rand(0, 1) === 1 && $same ? CarbonImmutable::parse($same->expires_at) : $start->addDays(mt_rand(5, 40));
                $rid = (string) (900000 + $i);
                $sld = "sim{$i}x{$clientId}";
                $this->registrar[$rid] = ['id' => (int) $rid, 'status' => 'ACT', 'expiration_date' => $expires->format('Y-m-d') . ' 10:00:00',
                    'domain' => ['name' => $sld, 'extension' => 'com'], 'name_servers' => [['name' => 'ns1.example.com'], ['name' => 'ns2.example.com']]];
                $domain = Domain::factory()->create(['client_id' => $clientId, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
                    'domain_tld_id' => $tld->id, 'registrar_connection_id' => $op->id, 'registrar_domain_id' => $rid, 'status' => DomainStatus::Active,
                    'expires_at' => $expires->toDateString(), 'currency_code' => 'USD', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
                foreach (Service::query()->where('client_id', $clientId)->pluck('id') as $sid) {
                    $this->event((int) $sid, "client has domain #{$domain->id} expiring {$expires->toDateString()}");
                }
            }
        }

        private function day(CarbonImmutable $day): void
        {
            $missed = mt_rand(1, 100) <= 4;
            $twice = !$missed && mt_rand(1, 100) <= 4;
            SimServer::$down = mt_rand(1, 100) <= 3;
            if ($missed) {
                $this->missedDays[$day->toDateString()] = true;
                $this->note("{$day->toDateString()} the daily cron does not run (server down)");
            }
            if (SimServer::$down) {
                $this->note("{$day->toDateString()} the hosting server is unreachable all day");
            }
            [$ch, $cm] = array_map('intval', explode(':', $this->cronAt));
            // Customer and staff events at their own hours.
            $events = [];
            foreach ($this->clients as $id => $c) {
                $events[] = [mt_rand(8, 21), mt_rand(0, 59), 'customer', $id];
            }
            foreach (['staff_unsuspend', 'staff_move', 'cancel_invoice', 'delete_invoice', 'refund', 'credit_add', 'cancel_request', 'cancel_request'] as $kind) {
                $events[] = [mt_rand(9, 18), mt_rand(0, 59), $kind, null];
            }
            for ($h = 0; $h < 24; $h++) {
                foreach ($events as [$eh, $em, $kind, $who]) {
                    if ($eh === $h && $em < 5) {
                        $this->at($day->setTime($h, $em), fn () => $this->doEvent($kind, $who));
                    }
                }
                if ($h === $ch && !$missed) {
                    $this->cron($day->setTime($ch, $cm), false);
                    if ($twice) {
                        $this->cron($day->setTime($ch, $cm + 20), false);
                    }
                }
                $this->cron($day->setTime($h, 5), true);
                foreach ($events as [$eh, $em, $kind, $who]) {
                    if ($eh === $h && $em >= 5) {
                        $this->at($day->setTime($h, $em), fn () => $this->doEvent($kind, $who));
                    }
                }
                // The worker: mostly on time; now and then an hour late.
                if (mt_rand(1, 100) > 10) {
                    $this->at($day->setTime($h, 59), fn () => $this->work());
                }
            }
            $this->at($day->setTime(23, 59, 30), function () {
                $this->work();
                $this->checkDay();
            });
        }

        private function at(CarbonImmutable $when, \Closure $what): void
        {
            Carbon::setTestNow($when);
            $what();
        }

        private function cron(CarbonImmutable $when, bool $hourly): void
        {
            Carbon::setTestNow($when);
            $before = $this->statuses();
            try {
                Artisan::call('app:cron-job', $hourly ? ['--dunning-only' => true] : []);
            } catch (Throwable $e) {
                $this->note("{$when} cron " . ($hourly ? 'hourly' : 'daily') . ' threw: ' . $e->getMessage());
                $this->violation('cron-threw:' . md5($e->getMessage()), "cron run at {$when} threw " . get_class($e) . ': ' . $e->getMessage());
            }
            $this->transitions($before, $when, $hourly ? 'hourly cron' : 'daily cron');
        }

        private function work(): void
        {
            try {
                Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);
            } catch (Throwable $e) {
                $this->note(now() . ' worker threw: ' . $e->getMessage());
            }
        }

        // ============================================================================================ events

        private function doEvent(string $kind, ?int $clientId): void
        {
            $before = $this->statuses();
            try {
                $kind === 'customer' ? $this->customer($clientId) : $this->staff($kind);
            } catch (Throwable $e) {
                $this->note(now() . " {$kind} threw " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
                if (!$e instanceof DisplayException) {
                    $this->violation('event-threw:' . $kind . ':' . md5($e->getMessage()), now() . " event {$kind} threw " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                }
            }
            $this->transitions($before, CarbonImmutable::now(), $kind);
        }

        /** What each persona does today with its oldest open invoice. */
        private function customer(int $clientId): void
        {
            $persona = $this->clients[$clientId]['persona'];
            if (str_starts_with($persona, 'card')) {
                if ($persona === 'card_decline' && !isset(SimPay::$acceptFrom[$clientId])) {
                    // Declined from now for 3..12 days.
                    $open = $this->openInvoices($clientId)->first();
                    if ($open !== null) {
                        SimPay::$acceptFrom[$clientId] = CarbonImmutable::today()->addDays(mt_rand(3, 12))->toDateString();
                        $this->clientEvent($clientId, 'card declines until ' . SimPay::$acceptFrom[$clientId]);
                    }
                } elseif ($persona === 'card_decline' && SimPay::$acceptFrom[$clientId] < CarbonImmutable::today()->subDays(20)->toDateString()) {
                    unset(SimPay::$acceptFrom[$clientId]); // a new run of declines later
                }
                // card_never: staff take a transfer after the suspension, now and then.
                if ($persona === 'card_never' && mt_rand(1, 100) <= 4) {
                    $this->payOldest($clientId, 'transfer (staff Add Payment)', staff: true, overpay: false);
                }

                return;
            }
            $invoice = $this->openInvoices($clientId)->first();
            if ($invoice === null) {
                return;
            }
            $late = (int) CarbonImmutable::parse($invoice->due_at)->startOfDay()->diffInDays(CarbonImmutable::today(), false);
            switch ($persona) {
                case 'bank_prompt':
                    if ($late >= mt_rand(-3, 1)) {
                        $this->payInvoice($invoice, $invoice->remaining, mt_rand(0, 1) === 1 ? 'gateway' : 'staff_mark', 'on time');
                    }
                    break;
                case 'bank_late':
                    if ($late >= mt_rand(6, 25) && mt_rand(1, 3) === 1) {
                        $this->payInvoice($invoice, $invoice->remaining, ['gateway', 'staff_mark', 'staff_add'][mt_rand(0, 2)], "late {$late}d");
                    }
                    break;
                case 'partial':
                    if ($late >= 0 && $invoice->remaining >= $invoice->total && mt_rand(1, 2) === 1) {
                        $this->payInvoice($invoice, round($invoice->remaining / 2, 2), 'staff_mark', 'half');
                    } elseif ($late >= 7 && mt_rand(1, 3) === 1) {
                        $this->payInvoice($invoice, $invoice->remaining, 'gateway', "rest, {$late}d late");
                    }
                    break;
                case 'double':
                    if ($late >= -1 && mt_rand(1, 2) === 1) {
                        $amount = $invoice->remaining;
                        $this->payInvoice($invoice, $amount, 'gateway', 'first of two');
                        // Pays it again: a second transfer staff record, or the same webhook replayed.
                        mt_rand(0, 1) === 1
                            ? $this->payInvoice($invoice->fresh(), $amount, 'staff_add', 'second (overpayment)')
                            : $this->replay($invoice);
                    }
                    break;
                case 'credit':
                    if ($late >= 10 && mt_rand(1, 4) === 1) {
                        $this->payInvoice($invoice, $invoice->remaining, 'gateway', "late {$late}d");
                    }
                    break;
                case 'bank_never':
                    break;
            }
        }

        private function payOldest(int $clientId, string $why, bool $staff, bool $overpay): void
        {
            $invoice = $this->openInvoices($clientId)->first();
            if ($invoice !== null) {
                $this->payInvoice($invoice, $invoice->remaining, $staff ? 'staff_add' : 'gateway', $why);
            }
        }

        private string $lastTx = '';

        private function payInvoice(Invoice $invoice, float $amount, string $how, string $why): void
        {
            if ($amount <= 0) {
                return;
            }
            $id = 'sim-' . ++$this->tx;
            $this->lastTx = $id;
            match ($how) {
                'gateway' => ExtensionHelper::addPayment($invoice->id, 'SimPay', $amount, null, $id, currency: 'USD'),
                'staff_mark' => (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, $amount, $id, upToRemaining: true),
                'staff_add' => (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, $amount, $id),
            };
            $this->invoiceEvent($invoice, "paid {$amount} by {$how} ({$why}) -> invoice " . $invoice->fresh()->status);
        }

        private function replay(Invoice $invoice): void
        {
            $payment = $invoice->transactions()->where('is_credit_transaction', false)->latest('id')->first();
            if ($payment !== null) {
                ExtensionHelper::addPayment($invoice->id, 'SimPay', $payment->amount, null, $payment->transaction_id, currency: 'USD');
                $this->invoiceEvent($invoice, "webhook replayed ({$payment->transaction_id}) -> " . $invoice->fresh()->status);
            }
        }

        private function staff(string $kind): void
        {
            $roll = mt_rand(1, 100);
            switch ($kind) {
                case 'staff_unsuspend': // the morning digest: a good customer's service brought back
                    if ($roll > 35) {
                        return;
                    }
                    $service = $this->pickOne0(Service::query()->where('status', 'suspended')->get());
                    if ($service === null) {
                        return;
                    }
                    $hold = mt_rand(0, 1) === 1 ? CarbonImmutable::today()->addDays(mt_rand(1, 10))->toDateString() : null;
                    $notify = mt_rand(0, 1) === 1;
                    // EditService «Unsuspend server», then (with a hold) the form's «Do not suspend until».
                    try {
                        ExtensionHelper::unsuspendServer($service);
                    } catch (\RuntimeException) {
                        $this->event($service->id, now() . ' staff unsuspend failed: server unreachable');

                        return; // EditService shows the error; nothing changes
                    }
                    if ($service->status === Service::STATUS_SUSPENDED) {
                        $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
                        if ($notify && $service->toldOfSuspension()) { // as EditService: only of a suspension told
                            NotificationHelper::serverUnsuspendedNotification($service->client, $service, []);
                        } else {
                            $this->silent[$service->id][] = (string) now(); // staff chose not to tell
                        }
                    }
                    if ($hold !== null) {
                        $service->fresh()->applyStaffChanges(['suspend_hold_until' => $hold]);
                    }
                    $this->staffUnsuspendedOn[$service->id] = CarbonImmutable::today()->toDateString();
                    $this->event($service->id, now() . ' staff unsuspend' . ($hold ? " hold until {$hold}" : '') . ($notify ? ' (mailed)' : ' (no mail)'));
                    break;
                case 'staff_move':
                    if ($roll > 6) {
                        return;
                    }
                    $service = $this->pickOne0(Service::query()->where('status', 'active')->get());
                    if ($service === null) {
                        return;
                    }
                    $delta = mt_rand(1, 10) * (mt_rand(0, 2) === 0 ? -1 : 1);
                    $to = CarbonImmutable::parse($service->expires_at)->addDays($delta)->toDateString();
                    $service->applyStaffChanges(['expires_at' => $to]);
                    $this->gapsAllowed[$service->id] = true;
                    $this->staffUnsuspendedOn[$service->id] = CarbonImmutable::today()->toDateString(); // changed by staff today
                    $this->event($service->id, now() . " staff move due date {$delta}d to {$to} (stored " . $service->fresh()->expires_at?->toDateString() . ')');
                    break;
                case 'cancel_invoice':
                    if ($roll > 5) {
                        return;
                    }
                    $invoice = $this->pickOne0(Invoice::query()->where('status', 'unpaid')->get());
                    if ($invoice === null) {
                        return;
                    }
                    $mode = ['auto', 'keep', 'give_up'][mt_rand(0, 2)];
                    $invoice->keep_periods_billable = match ($mode) {
                        'keep' => true, 'give_up' => false, default => null,
                    };
                    $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => "sim {$mode}"]);
                    foreach ($this->servicesOf($invoice) as $sid) {
                        if ($mode !== 'keep') {
                            $this->gapsAllowed[$sid] = true;
                            $this->gaveUp[$sid] = $mode;
                        }
                    }
                    $this->invoiceEvent($invoice, "staff cancel invoice ({$mode}) -> " . $invoice->fresh()->status);
                    break;
                case 'delete_invoice':
                    if ($roll > 2) {
                        return;
                    }
                    $invoice = $this->pickOne0(Invoice::query()->where('status', 'unpaid')->whereDoesntHave('transactions')->get());
                    if ($invoice === null) {
                        return;
                    }
                    $services = $this->servicesOf($invoice);
                    $invoice->delete();
                    foreach ($services as $sid) {
                        $this->event($sid, now() . " staff delete invoice #{$invoice->id}");
                    }
                    break;
                case 'refund':
                    if ($roll > 4) {
                        return;
                    }
                    $payment = $this->pickOne0(InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded->value)
                        ->where('is_credit_transaction', false)->whereHas('invoice', fn ($q) => $q->where('status', 'paid'))->get()
                        ->filter(fn ($p) => (float) $p->refundable_amount > 0)->values());
                    if ($payment === null) {
                        return;
                    }
                    $amount = mt_rand(0, 1) === 1 ? (float) $payment->refundable_amount : round((float) $payment->refundable_amount / 2, 2);
                    $toCredit = mt_rand(0, 1) === 1;
                    $toCredit ? ExtensionHelper::refundToCredit($payment, $amount) : ExtensionHelper::refundLocally($payment, $amount);
                    $invoice = $payment->invoice->fresh();
                    foreach ($this->servicesOf($invoice) as $sid) {
                        $this->gapsAllowed[$sid] = true;
                        if ($invoice->status === Invoice::STATUS_REFUNDED) {
                            $this->gaveUp[$sid] = 'refunded in full';
                        }
                    }
                    $this->invoiceEvent($invoice, 'staff refund ' . ($toCredit ? 'to credit' : 'by hand') . " {$amount} of payment #{$payment->id} -> invoice {$invoice->status}");
                    break;
                case 'credit_add':
                    if ($roll > 4) {
                        return;
                    }
                    $clientId = array_keys($this->clients)[mt_rand(0, count($this->clients) - 1)];
                    $cents = mt_rand(300, 3000);
                    (new CreditLedger)->add($clientId, 'USD', $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'sim');
                    $this->clientEvent($clientId, now() . ' staff add credit ' . ($cents / 100));
                    break;
                case 'cancel_request':
                    if ($roll > 2) {
                        return;
                    }
                    $service = $this->pickOne0(Service::query()->whereIn('status', ['active', 'suspended'])->whereDoesntHave('cancellation')->get());
                    if ($service === null) {
                        return;
                    }
                    $type = mt_rand(0, 2) === 0 ? 'immediate' : 'end_of_period';
                    ServiceCancellation::create(['service_id' => $service->id, 'type' => $type, 'reason' => 'sim']);
                    $this->event($service->id, now() . " customer asks to cancel ({$type})");
                    break;
            }
        }

        // ============================================================================================ invariants

        /** @return array<int, array{status: string, dunning: ?string, expires: ?string, hold: ?string}> */
        private function statuses(): array
        {
            $out = [];
            foreach (DB::table('services')->get(['id', 'status', 'dunning_suspended_at', 'expires_at', 'suspend_hold_until']) as $row) {
                $out[(int) $row->id] = ['status' => $row->status, 'dunning' => $row->dunning_suspended_at, 'expires' => $row->expires_at ? substr($row->expires_at, 0, 10) : null,
                    'hold' => $row->suspend_hold_until ? substr($row->suspend_hold_until, 0, 10) : null];
            }

            return $out;
        }

        private function snapshotAll(): void
        {
            $this->snapshot = $this->statuses();
        }

        /** What changed in one step: checked at the moment it happened. */
        private function transitions(array $before, CarbonImmutable $when, string $by): void
        {
            $after = $this->statuses();
            foreach ($after as $id => $now) {
                $was = $before[$id] ?? null;
                if ($was === null || ($was['status'] === $now['status'] && $was['expires'] === $now['expires'])) {
                    continue;
                }
                $this->event($id, "{$when} {$by}: {$was['status']} -> {$now['status']}, expires {$was['expires']} -> {$now['expires']}");
                if ($was['status'] === $now['status']) {
                    continue;
                }
                $service = Service::query()->find($id);
                if ($now['status'] === 'suspended' && $now['dunning'] !== null && $was['status'] === 'active') {
                    // V-window: only inside the window.
                    if (!DunningWindow::isOpen(Carbon::parse($when))) {
                        $this->violation("window:{$id}:{$when->toDateString()}", "V-WINDOW service #{$id} suspended at {$when} outside the window (by {$by})", $id);
                    }
                    // V-paid-up: suspended though not Suspend Days past due.
                    $cutoff = $when->startOfDay()->subDays(self::SUSPEND)->toDateString();
                    if ($now['expires'] === null || $now['expires'] >= $cutoff) {
                        $this->violation("paidup-suspended:{$id}:{$when->toDateString()}", "V-PAIDUP service #{$id} suspended at {$when} with expires {$now['expires']} (cutoff {$cutoff})", $id);
                    }
                    // V-ANNOUNCED: not before the day its last notice gave the customer.
                    if (isset($this->announced[$id]) && $when->toDateString() < $this->announced[$id]['on']) {
                        $this->violation("announced-early:{$id}:{$this->announced[$id]['on']}", "V-ANNOUNCED service #{$id} suspended at {$when}, its last notice (invoice #{$this->announced[$id]['invoice']}, {$this->announced[$id]['at']}) said {$this->announced[$id]['on']}", $id);
                    }
                    unset($this->announced[$id]);
                    // V-nothing-to-pay: suspended with no open invoice of its own left to pay.
                    $payable = Invoice::query()->where('status', 'unpaid')->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $id))
                        ->get()->filter(fn (Invoice $i) => $i->remaining > 0)->isNotEmpty();
                    if (!$payable && config('settings.cronjob_suspend_requires_invoice')) {
                        $this->violation("nothing-to-pay-strict:{$id}", "V-NOTHING-TO-PAY (suspend only with an overdue invoice ON) service #{$id} suspended at {$when} by {$by}", $id);
                    } elseif (!$payable && isset($this->gaveUp[$id])) {
                        $this->design["nothing-to-pay:{$id}"] = "DESIGN service #{$id} suspended at {$when} with nothing to pay after a cancel «{$this->gaveUp[$id]}»";
                    } elseif (!$payable) {
                        $this->violation("nothing-to-pay:{$id}", "V-NOTHING-TO-PAY service #{$id} suspended at {$when} (by {$by}) with no open invoice to pay", $id);
                    }
                }
                if ($now['status'] === 'terminated' && $was['status'] !== 'terminated') {
                    if ($was['status'] !== 'suspended' || $was['dunning'] === null) {
                        $this->violation("term-not-suspended:{$id}", "V-TERMINATE service #{$id} terminated at {$when} while {$was['status']} (dunning " . ($was['dunning'] ?? 'null') . ')', $id);
                    } elseif (CarbonImmutable::parse($was['dunning'])->startOfDay()->gt($when->startOfDay()->subDays(self::MIN_SUSPENDED))) {
                        $this->violation("term-min:{$id}", "V-TERMINATE service #{$id} terminated at {$when}, suspended only since {$was['dunning']}", $id);
                    }
                    if (!DunningWindow::isOpen(Carbon::parse($when))) {
                        $this->violation("term-window:{$id}", "V-WINDOW service #{$id} terminated at {$when} outside the window", $id);
                    }
                    $open = Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)->whereNull('kept_as_debt_at')->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $id))->pluck('id');
                    if ($open->isNotEmpty()) {
                        $this->violation("term-open:{$id}", "V-TERMINATE service #{$id} terminated at {$when} with open invoice(s) #" . $open->implode(', #') . ' left', $id);
                    }
                }
                if (in_array($was['status'], Service::ENDED_STATUSES, true) && $now['status'] !== $was['status']) {
                    $this->violation("revived:{$id}", "V-REVIVED service #{$id} {$was['status']} -> {$now['status']} at {$when} by {$by}", $id);
                }
            }
        }

        private function checkDay(): void
        {
            $today = CarbonImmutable::today();
            $services = Service::query()->with('plan')->get();
            $queueEmpty = DB::table('jobs')->count() === 0;
            $openActions = ModuleAction::query()->open()->pluck('service_id')->map(fn ($id) => (int) $id)->all();

            foreach ($services as $service) {
                $id = (int) $service->id;
                // V-PAIDUP-LASTING: suspended for non-payment, not past Suspend Days any more, no hold.
                if ($service->status === 'suspended' && $service->dunning_suspended_at !== null && $service->expires_at !== null
                    && $service->expires_at->toDateString() >= $today->subDays(self::SUSPEND)->toDateString()) {
                    $this->violation("paidup-lasting:{$id}", "V-PAIDUP service #{$id} still suspended on {$today->toDateString()} though paid until {$service->expires_at->toDateString()}", $id);
                }
                // V-NOT-SUSPENDED: due for suspension today, nothing holding it, yet still active (the day's runs all ran).
                if ($service->status === 'active' && $service->expires_at !== null && $service->plan_id !== null
                    && $service->expires_at->toDateString() < $today->subDays(self::SUSPEND)->toDateString()
                    && ($service->suspend_hold_until === null || $service->suspend_hold_until->toDateString() < $today->toDateString())
                    && !isset($this->missedDays[$today->toDateString()]) && !isset($this->missedDays[$today->subDay()->toDateString()])
                    && DunningWindow::isOpen(Carbon::parse($today->setTime(23, 0)))
                    && ($this->staffUnsuspendedOn[$id] ?? '') !== $today->toDateString()
                    && !$service->cancellation()->exists()) {
                    $this->violation("not-suspended:{$id}:{$today->toDateString()}", "V-NOT-SUSPENDED service #{$id} active at the end of {$today->toDateString()}, paid until {$service->expires_at->toDateString()}, hold " . ($service->suspend_hold_until?->toDateString() ?? 'none'), $id);
                }
                if (isset($this->announced[$id]) && $service->status === 'active' && $this->announced[$id]['on'] < $today->toDateString()
                    && $service->expires_at !== null && $service->expires_at->toDateString() < $today->subDays(self::SUSPEND)->toDateString()
                    && ($service->suspend_hold_until === null || $service->suspend_hold_until->toDateString() < $this->announced[$id]['on'])
                    && !isset($this->missedDays[$this->announced[$id]['on']])) {
                    $this->violation("announced-late:{$id}:{$this->announced[$id]['on']}", "V-ANNOUNCED service #{$id} not suspended on {$this->announced[$id]['on']} as its last notice said (still active on {$today->toDateString()})", $id);
                }
                // V-SERVER: the server agrees with MyCS once the worker is done.
                if ($queueEmpty && !SimServer::$down && !in_array($id, $openActions, true)) {
                    $want = match ($service->status) {
                        'active' => 'active', 'suspended' => 'suspended', 'terminated', 'cancelled' => 'terminated', default => null,
                    };
                    $has = SimServer::$state[$id] ?? 'none';
                    if ($want !== null && $has !== $want) {
                        $this->violation("server:{$id}:{$want}:{$has}", "V-SERVER service #{$id} is {$service->status} in MyCS but {$has} on the server on {$today->toDateString()}", $id);
                    }
                }
                // V-OVERLAP / V-TWO-OPEN / V-GAP on its lines.
                $lines = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $id)->with('invoice')->get()
                    ->filter(fn ($l) => $l->invoice !== null && $l->period_start !== null);
                $live = $lines->filter(fn ($l) => in_array($l->invoice->status, ['unpaid', 'paid'], true))
                    ->reject(fn ($l) => $l->invoice->status === 'paid' && $this->refundedFully($l->invoice))->values();
                foreach ($live as $a) {
                    foreach ($live as $b) {
                        if ($a->id < $b->id && $a->period_start < $b->period_end && $b->period_start < $a->period_end) {
                            $this->violation("overlap:{$a->id}:{$b->id}", "V-OVERLAP service #{$id}: line #{$a->id} [{$a->period_start->toDateString()},{$a->period_end->toDateString()}) inv #{$a->invoice_id} {$a->invoice->status} overlaps line #{$b->id} [{$b->period_start->toDateString()},{$b->period_end->toDateString()}) inv #{$b->invoice_id} {$b->invoice->status}", $id);
                        }
                    }
                }
                $open = $lines->filter(fn ($l) => $l->invoice->status === 'unpaid')->pluck('invoice_id')->unique();
                if ($open->count() > 1) {
                    $this->violation("two-open:{$id}", "V-TWO-OPEN service #{$id} has open invoices #" . $open->implode(', #') . " on {$today->toDateString()}", $id);
                }
                if (!isset($this->gapsAllowed[$id])) {
                    $paid = $live->filter(fn ($l) => $l->invoice->status === 'paid')->sortBy('period_start')->values();
                    for ($i = 1; $i < $paid->count(); $i++) {
                        if (!$paid[$i]->period_start->equalTo($paid[$i - 1]->period_end)) {
                            $this->violation("gap:{$id}:{$paid[$i]->id}", "V-GAP service #{$id}: paid line #{$paid[$i]->id} starts {$paid[$i]->period_start->toDateString()}, previous paid ends {$paid[$i - 1]->period_end->toDateString()}", $id);
                        }
                    }
                    if ($paid->isNotEmpty() && !in_array($service->status, Service::ENDED_STATUSES, true)
                        && $service->expires_at !== null && $paid->last()->period_end->gt($service->expires_at)) {
                        $this->violation("paid-ahead:{$id}", "V-PAID-NOT-APPLIED service #{$id} paid until {$paid->last()->period_end->toDateString()} but expires_at {$service->expires_at->toDateString()} on {$today->toDateString()}", $id);
                    }
                }
            }

            // V-DOMAIN: renewed at the registrar once per paid period, never unpaid; MyCS and the registrar agree.
            foreach (Domain::query()->get() as $domain) {
                $rid = (string) $domain->registrar_domain_id;
                $paid = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')
                    ->whereHas('invoice', fn ($q) => $q->whereIn('status', ['paid', 'refunded']))->count();
                $charged = $this->renewCharges[$rid] ?? 0;
                if ($charged > $paid) {
                    $this->violation("domain-charged:{$domain->id}:{$charged}", "V-DOMAIN domain #{$domain->id} renewed {$charged} times at the registrar for {$paid} paid renewal line(s) on {$today->toDateString()}");
                }
                if ($queueEmpty && $charged < $paid && !ModuleAction::query()->open()->where('domain_id', $domain->id)->exists()) {
                    $this->violation("domain-not-renewed:{$domain->id}:{$paid}", "V-DOMAIN domain #{$domain->id} has {$paid} paid renewal(s) but {$charged} done at the registrar on {$today->toDateString()}");
                }
                $theirs = substr((string) ($this->registrar[$rid]['expiration_date'] ?? ''), 0, 10);
                if ($queueEmpty && $domain->expires_at?->toDateString() !== $theirs) {
                    $this->violation("domain-expiry:{$domain->id}:{$theirs}", "V-DOMAIN domain #{$domain->id} expires " . $domain->expires_at?->toDateString() . " in MyCS, {$theirs} at the registrar on {$today->toDateString()}");
                }
                $open = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)
                    ->whereHas('invoice', fn ($q) => $q->where('status', 'unpaid'))->distinct()->count('invoice_id');
                if ($open > 1) {
                    $this->violation("domain-two-open:{$domain->id}", "V-DOMAIN domain #{$domain->id} has {$open} open invoices on {$today->toDateString()}");
                }
            }

            // V-MAIL: per service, suspended / unsuspended alternate; per invoice, a reminder once a day.
            $mails = EmailLog::query()->where('id', '>', $this->lastMailId)->orderBy('id')->get();
            $stream = [];
            foreach ($mails as $mail) {
                $this->lastMailId = max($this->lastMailId, (int) $mail->id);
                if ($mail->template_key === 'invoice_overdue' && preg_match('#suspended on (?:\*\*|<strong>)?(\d{2})/(\d{2})/(\d{4})#', (string) $mail->body, $m) === 1) {
                    $inv = Invoice::query()->find($mail->related_id);
                    foreach ($inv ? $this->servicesOf($inv) : [] as $sid) {
                        $this->announced[$sid] = ['on' => "{$m[3]}-{$m[2]}-{$m[1]}", 'invoice' => (int) $mail->related_id, 'at' => (string) $mail->created_at];
                        $this->event($sid, "{$mail->created_at} last notice: suspended on {$m[3]}-{$m[2]}-{$m[1]}");
                    }
                }
                $sid = $mail->related_type === (new Service)->getMorphClass() ? (int) $mail->related_id : null;
                if ($sid !== null && in_array($mail->template_key, ['server_suspended', 'server_unsuspended', 'server_terminated', 'service_cancelled'], true)) {
                    $stream[] = [(string) $mail->created_at, (int) $mail->id, $sid, $mail->template_key];
                }
            }
            foreach ($this->silent as $sid => $times) {
                foreach ($times as $t) {
                    $stream[] = [$t, 0, $sid, 'silent_unsuspend'];
                }
            }
            $this->silent = [];
            usort($stream, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            // A server action given up on (the server was down): the customer was not told; staff were. Any next mail is fine.
            foreach ($openActions as $sid) {
                $this->mailState[$sid] = 'unknown';
            }
            foreach ($stream as [$at, $mailId, $sid, $key]) {
                if (in_array($key, ['server_terminated', 'service_cancelled'], true)) {
                    $this->event($sid, "{$at} mail {$key}");

                    continue;
                }
                $kind = $key === 'server_suspended' ? 'suspended' : 'unsuspended';
                $prev = $this->mailState[$sid] ?? 'unsuspended';
                if ($prev === $kind && $key !== 'silent_unsuspend') {
                    $this->violation("mail:{$sid}:{$kind}:{$mailId}", "V-MAIL service #{$sid} got «{$kind}» at {$at} after «{$prev}» with nothing between", $sid);
                }
                $this->mailState[$sid] = $kind;
                if ($key !== 'silent_unsuspend') {
                    $this->event($sid, "{$at} mail {$key}");
                }
            }
            $dupes = EmailLog::query()->whereIn('template_key', ['invoice_payment_reminder', 'card_payment_due', 'invoice_overdue'])
                ->whereDate('created_at', $today)->get()->groupBy(fn ($m) => $m->template_key . '|' . $m->related_id)->filter(fn ($g) => $g->count() > 1);
            foreach ($dupes as $key => $group) {
                $this->violation("reminder-dupe:{$key}:{$today->toDateString()}", "V-REMINDER {$key} sent {$group->count()} times on {$today->toDateString()}");
            }
            // V-CHARGE: a card charged at most once a day per invoice, never a paid one.
            $byDay = collect(SimPay::$charges)->filter(fn ($c) => str_starts_with($c['at'], $today->toDateString()))->groupBy('invoice');
            foreach ($byDay as $invoiceId => $charges) {
                if ($charges->count() > 1) {
                    $this->violation("charge-twice:{$invoiceId}:{$today->toDateString()}", "V-CHARGE invoice #{$invoiceId} charged {$charges->count()} times on {$today->toDateString()}");
                }
            }
            // V-MONEY: the daily money check, but the rules about time.
            foreach ((new MoneyCheck)->run(array_values(array_diff(MoneyCheck::RULES, ['paid_not_applied', 'gateway_received']))) as $finding) {
                $this->violation("money:{$finding['rule']}:{$finding['subject']}", "V-MONEY on {$today->toDateString()}: {$finding['rule']} {$finding['subject']}: {$finding['detail']}");
            }
            // Credit never negative.
            foreach (Credit::query()->where('amount', '<', 0)->get() as $credit) {
                $this->violation("credit-neg:{$credit->client_id}", "V-CREDIT client #{$credit->client_id} credit {$credit->amount} on {$today->toDateString()}");
            }
        }

        private function refundedFully(Invoice $invoice): bool
        {
            return $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
                ->sum(fn ($p) => RecordPaymentService::toCents($p->amount) - RecordPaymentService::toCents($p->refunded_amount) - RecordPaymentService::toCents($p->credited_amount)) <= 0;
        }

        // ============================================================================================ helpers

        private function openInvoices(int $clientId)
        {
            return Invoice::query()->where('client_id', $clientId)->where('status', 'unpaid')->whereNull('finish_pending_at')
                ->orderBy('due_at')->orderBy('id')->get()->filter(fn (Invoice $i) => $i->remaining > 0)->values();
        }

        /** @return list<int> */
        private function servicesOf(Invoice $invoice): array
        {
            return InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', Service::class)->pluck('reference_id')->map(fn ($id) => (int) $id)->all();
        }

        private function pickOne0($items): mixed
        {
            return $items->isEmpty() ? null : $this->pickOne($items);
        }

        private function note(string $line): void
        {
            $this->log[] = $line;
        }

        private function event(int $serviceId, string $line): void
        {
            $this->history[$serviceId] = ($this->history[$serviceId] ?? '') . "\n      {$line}";
        }

        private function clientEvent(int $clientId, string $line): void
        {
            foreach (Service::query()->where('client_id', $clientId)->pluck('id') as $sid) {
                $this->event((int) $sid, $line);
            }
        }

        private function invoiceEvent(Invoice $invoice, string $line): void
        {
            foreach ($this->servicesOf($invoice) as $sid) {
                $this->event($sid, now() . " invoice #{$invoice->id}: {$line}");
            }
        }

        private function violation(string $key, string $message, ?int $serviceId = null): void
        {
            if (isset($this->violations[$key])) {
                return;
            }
            $context = '';
            if ($serviceId !== null) {
                $service = Service::query()->find($serviceId);
                $persona = $service !== null ? ($this->clients[$service->client_id]['persona'] ?? '?') : '?';
                $context = "\n    client #" . ($service?->client_id ?? '?') . " ({$persona}), service #{$serviceId} history:" . ($this->history[$serviceId] ?? '');
            }
            $this->violations[$key] = $message . $context;
        }
    }
}
