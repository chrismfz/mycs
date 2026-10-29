<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Providers\SettingsProvider;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsDomainImport;
use App\Services\Import\WhmcsKnowledgebaseImport;
use App\Services\Import\WhmcsMassPay;
use App\Services\Import\WhmcsMoneyJournal;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Import\WhmcsSecondFactor;
use App\Services\Import\WhmcsTicketImport;
use App\Services\Invoice\PaymentReminders;
use App\Services\Licensing\WhmcsLicensingImport;
use App\Services\Tax\EuVat;
use App\Services\Whmcs\CatalogImport;
use App\Support\Locales;
use Closure;
use DB;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Log;
use MyCS\Extensions\Servers\CentovaCast\CentovaCast;
use MyCS\Extensions\Servers\Mailcow\Mailcow;
use MyCS\Pricing\LinePricer;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use function Laravel\Prompts\password;

class ImportFromWhmcs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-from-whmcs {dbname : the WHMCS database (a copy, never the live one)}
        {username? : its user (or --source-user)} {host? : its host (or --source-host)} {port? : its port (or --source-port)}
        {--source-user= : the WHMCS database\'s user}
        {--source-host= : the WHMCS database\'s host}
        {--source-port=3306 : the WHMCS database\'s port}
        {--source-socket= : the WHMCS database\'s unix socket, instead of host and port}
        {--dry-run : connect to both, count what would come, write nothing}
        {--confirm-database= : this instance\'s database name, typed: the import empties it first (migrate:fresh)}
        {--ticket-attachments= : A copy of WHMCS\'s attachments folder, for the tickets\' files}
        {--whmcs-api-url= : WHMCS\'s URL, for what only its API reads (saved payment methods); its credentials in WHMCS_API_IDENTIFIER, WHMCS_API_SECRET}';

    /** WHMCS's SSL modules with no extension here → their provider: kept on an Offline SSL server, by hand. */
    private const OFFLINE_SSL_MODULES = ['thesslstorefullv2' => 'TheSSLStore', 'thesslstore' => 'TheSSLStore'];

    /** A WHMCS service never suspended automatically («Override Auto-Suspend» without a date): a hold this far off. */
    public const NEVER_SUSPEND = '9999-12-31';

    /** @var (Closure(string, string, string): PDO)|null how the source is opened (tests: a fixture database) */
    public static ?Closure $connect = null;

    /** Read from the source and counted by --dry-run. */
    private const DRY_RUN_TABLES = ['tblclients', 'tblusers', 'tblcontacts', 'tblhosting', 'tbldomains', 'tblinvoices', 'tblinvoiceitems',
        'tblaccounts', 'tblcredit', 'tblorders', 'tbltickets', 'tblproducts', 'tblpromotions', 'tbladmins'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a WHMCS database (a copy) into this instance: empties this instance\'s database first';

    /**
     * The PDO connection to WHMCS database
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * @var int
     */
    protected $batchSize = 500;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Set max memory to 1GB
        ini_set('memory_limit', '1024M');

        // The source only from what is given here, never from this instance's DB_* (they are MyCS's own database: the
        // import would read it, and its migrate:fresh empty it; review of the importer, 2026-10-29).
        $dbname = (string) $this->argument('dbname');
        $username = (string) ($this->option('source-user') ?: $this->argument('username') ?: '');
        $host = (string) ($this->option('source-host') ?: $this->argument('host') ?: '');
        $port = (string) ($this->argument('port') ?: $this->option('source-port'));
        $socket = (string) $this->option('source-socket');
        if ($username === '' || ($host === '' && $socket === '')) {
            $this->error('The WHMCS database\'s user and host (or socket) are needed: --source-user and --source-host or --source-socket.');

            return self::FAILURE;
        }
        // From the environment for a run without a terminal (WHMCS_SOURCE_PASSWORD), else asked; never an argument (the
        // process list would show it).
        $password = (string) (getenv('WHMCS_SOURCE_PASSWORD') ?: password("Enter the password for user '$username':", required: true));

        $dsn = $socket !== '' ? "mysql:unix_socket=$socket;dbname=$dbname;charset=utf8mb4" : "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        try {
            $this->pdo = self::$connect !== null ? (self::$connect)($dsn, $username, $password) : new PDO($dsn, $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error('Connection to WHMCS failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        if ($this->isOwnDatabase($dbname, $host, $port, $socket)) {
            $this->error('The source is this instance\'s own database: nothing to import from, and it would be emptied.');

            return self::FAILURE;
        }

        // A WHMCS database, by its own tables, before anything is emptied (review of #515: a wrong source, confirmed,
        // would have emptied the target and only then failed).
        $missing = array_values(array_filter(['tblclients', 'tblconfiguration', 'tblcurrencies'], fn (string $table) => !$this->sourceHas($table)));
        if ($missing !== []) {
            $this->error('The source is not a WHMCS database (no ' . implode(', ', $missing) . '): nothing done.');

            return self::FAILURE;
        }

        $target = (string) DB::connection()->getDatabaseName();
        if ($target === '') {
            $this->error('This instance has no database name to confirm: nothing done.');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->dryRun($target);

            return self::SUCCESS;
        }
        // The import empties this instance's database (migrate:fresh): only one named here, by its name.
        if ($this->option('confirm-database') !== $target) {
            $this->error("The import empties this instance's database «{$target}» first. Run it again with --confirm-database={$target} if that is meant"
                . ' (a test copy, or the new instance before the cut-over); --dry-run writes nothing.');

            return self::FAILURE;
        }

        $this->info('Connected to WHMCS database, Starting migration...');
        try {
            $this->prepareDatabase();

            DB::statement('SET foreign_key_checks=0');

            // Remove default currencies
            DB::table('currencies')->truncate();
            // Import currencies
            $this->importCurrencies();
            $this->importAutomationSettings();
            $this->importUsers();
            $this->importAdmins();
            $this->importCategories();
            $this->importConfigOptions();
            $this->importProducts();
            $this->importServers();
            $this->importPromotions();
            $this->importTickets();
            $this->importKnowledgebase();
            $this->importOrders();
            $this->importServices();
            $this->importOfflineSsl();
            $this->importDomains();
            $this->importLicensing();
            $this->importCancellations();
            $this->importInvoices();
            $this->importReminderState();
            $this->importInvoiceItems();
            $this->importPayments();
            $this->importMassPay();
            $this->importInvoicePreferences();
            $this->importItemPaymentMethods();
            $this->journalMoney();
            // Last: over the network; a method that fails is skipped, never the money above (R5 of #538).
            $this->importPayMethods();

            DB::statement('SET foreign_key_checks=1');

            SettingsProvider::flushCache();
        } catch (PDOException $e) {
            // The SQL state only: a query's message carries its values (emails, hashes); the whole in the log.
            Log::error($e);
            $this->fail('Import failed (SQLSTATE ' . $e->getCode() . '): see the application log.');
        }

        return self::SUCCESS;
    }

    /** A service WHMCS suspended for non-payment: on its due date plus the Suspend Days, or now if that is still ahead. */
    private static function suspendedOn(?string $due, int $suspendDays): Carbon
    {
        if ($due === null) {
            return now();
        }
        $on = Carbon::parse($due)->addDays($suspendDays);

        return $on->lessThan(now()) ? $on : now();
    }

    private function sourceHas(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** A WHMCS invoice's status here; parity maps it the same way (anything else, e.g. «Payment Pending», is unpaid). */
    public static function invoiceStatus(string $whmcs): string
    {
        return match ($whmcs) {
            'Draft' => 'draft',
            'Paid' => 'paid',
            'Unpaid' => 'unpaid',
            'Cancelled' => 'cancelled',
            'Refunded' => 'refunded',
            default => 'unpaid',
        };
    }

    /**
     * Whether the source named is this instance's own database: the same name, on a local host or the same host and
     * port. A first, cheap check only: a copy under another name, or the server reached by its own hostname, is not
     * seen (the WHMCS tables' check catches those).
     */
    private function isOwnDatabase(string $dbname, string $host, string $port, string $socket): bool
    {
        $own = DB::connection()->getConfig();
        if ($dbname !== (string) ($own['database'] ?? '')) {
            return false;
        }
        $local = fn (string $h) => in_array($h, ['', 'localhost', '127.0.0.1', '::1'], true);
        if ($socket !== '' || ($own['unix_socket'] ?? '') !== '') {
            return $local((string) ($own['host'] ?? '')) || $local($host);
        }

        return ($local($host) && $local((string) ($own['host'] ?? ''))) || ($host === (string) ($own['host'] ?? '') && $port === (string) ($own['port'] ?? '3306'));
    }

    /** What would come, from the source, and where it would go: nothing written anywhere. */
    private function dryRun(string $target): void
    {
        $rows = [];
        foreach (self::DRY_RUN_TABLES as $table) {
            try {
                $rows[] = [$table, $this->count($table)];
            } catch (PDOException) {
                $rows[] = [$table, 'missing'];
            }
        }
        $this->table(['WHMCS table', 'Rows'], $rows);
        // A new instance, not migrated yet (the cut-over's case), has none of these tables: told, not a crash (review of #515).
        $existing = collect(['clients', 'staff', 'services', 'invoices'])->mapWithKeys(fn (string $t) => [$t => Schema::hasTable($t) ? DB::table($t)->count() : 'not migrated']);
        $this->line("Target: this instance's database «{$target}» (" . $existing->map(fn ($n, $t) => "{$t} {$n}")->implode(', ') . ').');
        $this->line("Dry run: nothing written. To import: --confirm-database={$target} (it empties «{$target}» first).");
    }

    private function prepareDatabase()
    {
        // Rerun migrations
        $this->call('migrate:fresh', ['--force' => true]);
        // Seed default data
        $this->call('db:seed', ['--force' => true]);
    }

    protected function validateDate($date): ?string
    {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
            if (!$dateTime) {
                // Midnight: without «!» the missing time is the clock's now (an invoice at the time of the run; a run at
                // 03:00 on a day the clocks went forward stopped the import of myip, 2026-10-29).
                $dateTime = \DateTime::createFromFormat('!Y-m-d', $date);
            }
            if (!$dateTime) {
                return null;
            }

            // Validate year is reasonable (between 1900 and 2100)
            $year = (int) $dateTime->format('Y');
            if ($year < 1900 || $year > 2100) {
                return null;
            }

            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function migrateInBatch(string $table, string $query, Closure $processor)
    {
        /**
         * @var PDOStatement $stmt
         */
        $stmt = $this->pdo->prepare($query);

        $offset = 0;
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();

        $nRows = $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();

        if ($nRows <= $this->batchSize) {
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            try {
                $processor($records);
            } catch (Throwable $th) {
                Log::error($th);
                $this->fail($th->getMessage());
            }
        } else {
            $bar = $this->output->createProgressBar(round($nRows / $this->batchSize));
            $bar->setFormat("Batch: %current%/%max% [%bar%] %percent:3s%%\n");
            $bar->start();
            while ($records = $stmt->fetchAll(PDO::FETCH_ASSOC)) {
                $offset += $this->batchSize;
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                try {
                    $processor($records);
                } catch (Throwable $th) {
                    Log::error($th);
                    $this->fail($th->getMessage());
                }

                $bar->advance();
            }

            $bar->finish();
        }

        $this->info('Done.');
    }

    private function count(string $table): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) as count FROM ' . $table);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['count'] ?? 0);
    }

    private function importCurrencies()
    {
        $this->info('Importing currencies... (' . $this->count('tblcurrencies') . ' records)');

        $this->migrateInBatch('tblcurrencies', 'SELECT * FROM tblcurrencies LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                $format = match ($record['format']) {
                    1 => '1 000.00', // 1234.56
                    2 => '1,000.00', // 1,234.56
                    4 => '1 000,00', // 1,234
                    default => '1.000,00', // 1.234,56
                };
                $data[] = [
                    'name' => $record['code'],
                    'code' => $record['code'],
                    'prefix' => $record['prefix'],
                    'suffix' => $record['suffix'],
                    'format' => $format,
                ];
                if ($record['default'] == 1) {
                    // Set default currency
                    Setting::updateOrCreate(
                        ['key' => 'default_currency'],
                        ['value' => $record['code']]
                    );
                }
            }

            DB::table('currencies')->insert($data);
        });
    }

    /** How the logins' 2FA is read (WHMCS_CC_ENCRYPTION_HASH, WhmcsSecondFactor); the tests set their own. */
    public ?WhmcsSecondFactor $secondFactor = null;

    /**
     * The company's automation, from its tblconfiguration (docs/settings-map.md, «Αυτοματισμοί»): left at MyCS's defaults,
     * the cron would end services earlier than WHMCS did (myip terminates at 120 days, the default is 60: pre-launch audit,
     * flow 1, 2026-10-29). Only the settings that are the same thing on both sides. A value WHMCS lacks, or one out of
     * range, keeps MyCS's own, and is said. What MyCS cannot hold yet (invoice days per billing cycle) is said too.
     */
    private function importAutomationSettings(): void
    {
        $this->info('Importing automation settings...');
        $config = $this->pdo->query("SELECT setting, value FROM tblconfiguration WHERE setting IN ('AutoSuspension', 'AutoSuspensionDays',"
            . " 'AutoTermination', 'AutoTerminationDays', 'CreateInvoiceDaysBefore', 'DailyCronExecutionHour', 'CloseInactiveTickets',"
            . " 'SendReminder', 'SendInvoiceReminderDays', 'SendFirstOverdueInvoiceReminder', 'SendSecondOverdueInvoiceReminder',"
            . " 'SendThirdOverdueInvoiceReminder', 'NoAutoApplyCredit', 'CreateInvoiceDaysBeforeMonthly', 'CreateInvoiceDaysBeforeQuarterly',"
            . " 'CreateInvoiceDaysBeforeSemiAnnually', 'CreateInvoiceDaysBeforeAnnually', 'CreateInvoiceDaysBeforeBiennially',"
            . " 'CreateInvoiceDaysBeforeTriennially', 'AddFundsEnabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
        // Customers with a balance: their credit is spendable here only with credit on (round 3 of #518).
        try {
            $balances = (int) ($this->pdo->query('SELECT COUNT(*) FROM tblclients WHERE credit > 0')->fetchColumn() ?: 0);
        } catch (PDOException) {
            $balances = 0; // a source without tblclients.credit (a test's): none
        }
        $has = fn (string $key) => array_key_exists($key, $config);
        // WHMCS writes «on»; anything else that reads as yes is yes too (review of #518).
        $on = fn (string $key) => in_array(strtolower(trim((string) ($config[$key] ?? ''))), ['on', '1', 'yes', 'true'], true);
        // Whole days up to ten years: a «never» typed as a huge number is kept as MyCS's own, never an overflow that makes
        // every suspended service terminable (review of #518).
        $days = function (string $key) use ($config): ?string {
            $value = trim((string) ($config[$key] ?? ''));

            return ctype_digit($value) && strlen($value) <= 4 && (int) $value <= 3650 ? (string) (int) $value : null;
        };
        $hour = isset($config['DailyCronExecutionHour']) && ctype_digit(trim((string) $config['DailyCronExecutionHour']))
            ? (int) $config['DailyCronExecutionHour'] : null;
        // WHMCS counts hours, MyCS days: a part of a day is a whole one (48 → 2, 120 → 5); 0 or empty is never, on both sides.
        // Hours, so their own limit (ten years of them), not the days' (review of #518, R2-1: 8760 hours, a year, was kept as 5 days).
        $hours = trim((string) ($config['CloseInactiveTickets'] ?? ''));
        $closeHours = $has('CloseInactiveTickets') && $hours === '' ? '0'
            : (ctype_digit($hours) && strlen($hours) <= 6 && (int) $hours <= 3650 * 24 ? (string) (int) $hours : null);
        // Only days the cron sends on (PaymentReminders::MAX_DAYS; the Settings page takes at most three digits): any other,
        // or one that is no number, is left out and said (review of #518, R2-2 and round 3).
        $overdue = [];
        $dropped = [];
        foreach (['SendFirstOverdueInvoiceReminder', 'SendSecondOverdueInvoiceReminder', 'SendThirdOverdueInvoiceReminder'] as $key) {
            $value = trim((string) ($config[$key] ?? ''));
            if ($value === '' || $value === '0') {
                continue;
            }
            if (ctype_digit($value) && (int) $value <= PaymentReminders::MAX_DAYS) {
                $overdue[] = (int) $value;
            } else {
                $dropped[] = $value;
            }
        }
        $overdue = array_values(array_unique($overdue));
        sort($overdue);
        $settings = [
            'cronjob_suspend_enabled' => $has('AutoSuspension') ? ['boolean', $on('AutoSuspension') ? '1' : '0'] : null,
            'cronjob_order_suspend' => ($v = $days('AutoSuspensionDays')) !== null ? ['string', $v] : null,
            'cronjob_terminate_enabled' => $has('AutoTermination') ? ['boolean', $on('AutoTermination') ? '1' : '0'] : null,
            'cronjob_order_terminate' => ($v = $days('AutoTerminationDays')) !== null ? ['string', $v] : null,
            'cronjob_invoice' => ($v = $days('CreateInvoiceDaysBefore')) !== null ? ['string', $v] : null,
            // Never 03:xx here (the clock changes at 03:00, BIL-16): kept as MyCS's own then.
            'cronjob_time' => $hour !== null && $hour < 24 && $hour !== 3 ? ['string', sprintf('%02d:00', $hour)] : null,
            'cronjob_close_ticket' => $closeHours !== null ? ['string', (string) (int) ceil((int) $closeHours / 24)] : null,
            // The unpaid reminder, days before the due date; its switch off is 0 (review of #518: myip 1, the default 6).
            'cronjob_payment_reminder' => $has('SendReminder') && !$on('SendReminder') ? ['string', '0']
                : (($v = $days('SendInvoiceReminderDays')) !== null ? ['string', $v] : null),
            // The overdue notices, days after it, in order (myip 1,3,5; nixpal's owner decision, 1,3,7, is set at its cut-over);
            // none with «Payment Reminder Emails» off, which in WHMCS stops these too (round 3 of #518).
            'cronjob_overdue_reminders' => $has('SendReminder') && !$on('SendReminder') ? ['string', '']
                : ($has('SendFirstOverdueInvoiceReminder') ? ['string', implode(',', $overdue)] : null),
            // Credit itself: on where WHMCS lets customers add funds, or any customer has a balance. Off, the auto-apply
            // below is a dead letter and imported balances cannot be spent (round 3 of #518: nixpal's renewals,
            // paid from credit in WHMCS, would have gone unpaid to reminders and suspension).
            'credits_enabled' => $has('AddFundsEnabled') || $balances > 0 ? ['boolean', $on('AddFundsEnabled') || $balances > 0 ? '1' : '0'] : null,
            // WHMCS's «Disable Credit Auto-Apply» is MyCS's credit auto-apply, inverted (review of #518: myip has it on).
            'credits_auto_use' => $has('NoAutoApplyCredit') ? ['boolean', $on('NoAutoApplyCredit') ? '0' : '1'] : null,
        ];
        $rows = [];
        foreach ($settings as $key => $setting) {
            if ($setting === null) {
                $rows[] = [$key, $key === 'cronjob_time' && $hour === 3 ? 'kept: never 03:xx, the clock changes then (BIL-16)' : 'kept (WHMCS has none, or one out of range)'];

                continue;
            }
            [$type, $value] = $setting;
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value, 'type' => $type]);
            $rows[] = [$key, $value];
        }
        $this->table(['Setting', 'Value'], $rows);
        if ($dropped !== []) {
            $this->warn('Overdue reminders after ' . PaymentReminders::MAX_DAYS . ' days are not sent by MyCS: left out (' . implode(', ', $dropped) . ').');
        }
        // Invoice days per billing cycle: MyCS holds one value (ROADMAP: per cycle, «Ν»): said, for staff to choose.
        $perCycle = array_filter(['Monthly', 'Quarterly', 'SemiAnnually', 'Annually', 'Biennially', 'Triennially'],
            fn (string $cycle) => $days('CreateInvoiceDaysBefore' . $cycle) !== null && (int) $days('CreateInvoiceDaysBefore' . $cycle) > 0);
        if ($perCycle !== []) {
            $this->warn('WHMCS invoices per billing cycle (' . implode(', ', array_map(fn (string $c) => $c . ' ' . $days('CreateInvoiceDaysBefore' . $c), $perCycle))
                . '); MyCS has one value, cronjob_invoice = ' . ($days('CreateInvoiceDaysBefore') ?? 'unchanged') . ': check it before the first cron run.');
        }
    }

    /**
     * WHMCS logins (`tblusers`) → users, with their own ids, emails, passwords and 2FA (docs/accounts.md, PR 4;
     * security audit AUTHN-11): those of no account too (108 on myip), so no login is lost. A ticket reply's user is
     * then the same id (WhmcsTicketImport::userOf).
     */
    private function importLogins(): void
    {
        $this->info('Importing logins... (' . $this->count('tblusers') . ' records)');
        $secondFactor = $this->secondFactor ?? WhmcsSecondFactor::fromEnv();
        $withTwoFactor = 0;
        $missed = [];

        $this->migrateInBatch('tblusers', 'SELECT * FROM tblusers LIMIT :limit OFFSET :offset', function ($records) use ($secondFactor, &$withTwoFactor, &$missed) {
            $data = [];
            foreach ($records as $record) {
                $module = trim((string) ($record['second_factor'] ?? ''));
                $secret = $module === '' ? null : $secondFactor->secret($module, $record['second_factor_config'] ?? null);
                if ($module !== '') {
                    $secret !== null ? $withTwoFactor++ : $missed[] = (int) $record['id'];
                }
                $date = fn ($value) => $value === null || $value === '' || str_starts_with((string) $value, '0000') ? now() : $value;
                $data[] = [
                    'id' => $record['id'],
                    'first_name' => (string) $record['first_name'],
                    'last_name' => (string) $record['last_name'],
                    'email' => $record['email'],
                    'password' => $record['password'],
                    'tfa_secret' => $secret !== null ? Crypt::encryptString($secret) : null,
                    'language' => Locales::fromWhmcs($record['language'] ?? null),
                    'email_verified_at' => str_starts_with((string) ($record['email_verified_at'] ?? ''), '0000') ? null : (($record['email_verified_at'] ?? null) ?: null),
                    'created_at' => $date($record['created_at'] ?? null),
                    'updated_at' => $date($record['updated_at'] ?? null),
                ];
            }
            DB::table('users')->insert($data);
        });

        $this->info("Logins with 2FA carried over: {$withTwoFactor}.");
        if ($missed !== []) {
            $this->warn(count($missed) . ' login(s) had 2FA in WHMCS that did not come over' . ($secondFactor->available() ? ' (not readable)' : ' (set WHMCS_CC_ENCRYPTION_HASH, from WHMCS\'s configuration.php, to bring it)')
                . ': user ids ' . implode(', ', $missed) . '. They log in without it until they set it up again.');
        }
    }

    /**
     * WHMCS's client groups, by their ids: name, colour, discount, «Exempt from suspend & terminate» and «Separate
     * invoices» (sweep of myip, 2026-10-25). Returns the ids there are; none when WHMCS has no such table.
     *
     * @return array<int, true>
     */
    private function importClientGroups(): array
    {
        try {
            $groups = $this->pdo->query('SELECT * FROM tblclientgroups')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $ids = [];
        foreach ($groups as $group) {
            $on = fn (string $key) => in_array(strtolower(trim((string) ($group[$key] ?? ''))), ['on', '1', 'yes'], true);
            DB::table('client_groups')->updateOrInsert(['id' => (int) $group['id']], [
                'name' => mb_substr(trim((string) ($group['groupname'] ?? '')) ?: 'Group ' . $group['id'], 0, 255),
                'colour' => ($group['groupcolour'] ?? '') !== '' ? $group['groupcolour'] : null,
                'discount_percent' => number_format(max(0, min(100, (float) ($group['discountpercent'] ?? 0))), 2, '.', ''),
                'dunning_exempt' => $on('susptermexempt'), 'separate_invoices' => $on('separateinvoices'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $ids[(int) $group['id']] = true;
        }
        $this->info('Client groups: ' . count($ids) . '.');

        return $ids;
    }

    private function importUsers()
    {
        $this->importLogins();
        $groups = $this->importClientGroups();
        $this->info('Importing accounts... (' . $this->count('tblclients') . ' records)');

        $this->migrateInBatch('tblclients', 'SELECT * FROM tblclients LIMIT :limit OFFSET :offset', function ($records) use ($groups) {
            $data = [];
            $clients = [];
            $contacts = [];
            $credits = [];

            foreach ($records as $record) {
                // An account always has its owner in WHMCS (docs/accounts.md: exactly one each): one without is skipped.
                $stmt = $this->pdo->prepare('SELECT auth_user_id FROM tblusers_clients WHERE client_id = :client_id AND owner = 1 AND auth_user_id IN (SELECT id FROM tblusers) LIMIT 1');
                $stmt->bindValue(':client_id', $record['id'], PDO::PARAM_INT);
                $stmt->execute();
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    continue;
                }

                $data[] = [
                    'updated_at' => $record['updated_at'] == '0000-00-00 00:00:00' ? now() : $record['updated_at'],
                    'created_at' => $record['created_at'] == '0000-00-00 00:00:00' ? now() : $record['created_at'],
                ];
                // The account keeps the WHMCS client id; its owner is the login (docs/accounts.md, PR 2a).
                $clients[] = ['id' => $record['id'], 'created_at' => end($data)['created_at'], 'updated_at' => end($data)['updated_at'],
                    'tax_exempt' => (bool) ($record['taxexempt'] ?? false), 'separate_invoices' => (bool) ($record['separateinvoices'] ?? false),
                    'auto_charge' => !($record['disableautocc'] ?? false),
                    // «Disable overdue notices», and its group (sweep of myip, 2026-10-25).
                    'no_overdue_reminders' => (bool) ($record['overideduenotices'] ?? false),
                    'client_group_id' => isset($groups[(int) ($record['groupid'] ?? 0)]) ? (int) $record['groupid'] : null,
                    // Its mails' language (WHMCS «greek», «english», …; empty: the instance's).
                    'language' => Locales::fromWhmcs($record['language'] ?? null)];

                // The account's details are its primary contact, field for field (docs/accounts.md, PR 3a).
                $value = fn (string $key) => isset($record[$key]) && trim((string) $record[$key]) !== '' ? trim((string) $record[$key]) : null;
                $contacts[] = [
                    'client_id' => $record['id'], 'primary_of_client_id' => $record['id'],
                    'type' => $value('companyname') !== null ? 'business' : 'individual',
                    'first_name' => $value('firstname'), 'last_name' => $value('lastname'), 'company_name' => $value('companyname'),
                    'tax_id' => $value('tax_id'), 'email' => $value('email'), 'phone' => $value('phonenumber'),
                    'address1' => $value('address1'), 'address2' => $value('address2'), 'city' => $value('city'),
                    'state' => $value('state'), 'postcode' => $value('postcode'),
                    // A country the app knows (EuVat::iso: a code, a name, EL → GR), else none: never the first two letters of
                    // a name («Germany» → GE, Georgia). The ones left without are listed (reportCountries).
                    'country' => $this->country($record),
                    'created_at' => end($data)['created_at'], 'updated_at' => now(),
                ];

                // Credits
                if ($record['credit'] > 0) {
                    $currency = $this->pdo->prepare('SELECT * FROM tblcurrencies WHERE id = :id LIMIT 1');
                    $currency->bindValue(':id', $record['currency'], PDO::PARAM_INT);
                    $currency->execute();
                    $currency = $currency->fetch(PDO::FETCH_ASSOC);
                    array_push($credits, [
                        'client_id' => $record['id'],
                        'amount' => $record['credit'],
                        'currency_code' => $currency['code'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('clients')->insert($clients);
            $this->importGrants(array_column($clients, 'created_at', 'id'));
            if (count($contacts) > 0) {
                DB::table('contacts')->insert($contacts);
            }
            if (count($credits) > 0) {
                // Its journal, from WHMCS's history, once the invoices are in (journalMoney).
                DB::table('credits')->insert($credits);
            }
        });
        $this->reportCountries();
    }

    /** @var array<int, string> customers imported without a country the app knows => what WHMCS had */
    private array $withoutCountry = [];

    /** @param  array<string, mixed>  $record  a tblclients row */
    private function country(array $record): ?string
    {
        $iso = EuVat::iso($record['country'] ?? null);
        if ($iso === null) {
            $this->withoutCountry[(int) $record['id']] = trim((string) ($record['country'] ?? ''));
        }

        return $iso;
    }

    /**
     * The customers left without a country, for staff (#524): they cannot order until they set one, and their invoices
     * take the rate of «All», else the company's country. With their active services, the ones billed first.
     */
    private function reportCountries(): void
    {
        if ($this->withoutCountry === []) {
            return;
        }
        $active = [];
        foreach ($this->sourceRows("SELECT userid, COUNT(*) n FROM tblhosting WHERE domainstatus IN ('Active', 'Suspended') GROUP BY userid") ?? [] as $row) {
            $active[(int) $row['userid']] = (int) $row['n'];
        }
        $rows = array_map(fn (int $id, string $had) => [$id, $had === '' ? '(empty)' : $had, $active[$id] ?? 0],
            array_keys($this->withoutCountry), $this->withoutCountry);
        usort($rows, fn (array $a, array $b) => [$b[2], $a[0]] <=> [$a[2], $b[0]]);
        $this->warn(count($rows) . ' customer(s) without a country the app knows: they cannot order until they set one, and their invoices'
            . ' take the «All» rate, else the company\'s country. Ask them to set it:');
        $this->table(['Customer', 'In WHMCS', 'Active services'], $rows);
    }

    /**
     * Who logs in to each account (`tblusers_clients` → client_user): its owner, and the others with the WHMCS
     * permissions they had (the same names, ClientUser::PERMISSIONS).
     *
     * @param  array<int, mixed>  $accounts  client id => its created_at
     */
    private function importGrants(array $accounts): void
    {
        if ($accounts === []) {
            return;
        }
        $ids = implode(',', array_map('intval', array_keys($accounts)));
        $rows = $this->pdo->query("SELECT * FROM tblusers_clients WHERE client_id IN ({$ids}) ORDER BY owner DESC")->fetchAll(PDO::FETCH_ASSOC);
        $logins = array_flip(DB::table('users')->whereIn('id', array_column($rows, 'auth_user_id'))->pluck('id')->all());
        $grants = [];
        $seen = [];
        foreach ($rows as $row) {
            $client = (int) $row['client_id'];
            $user = (int) $row['auth_user_id'];
            if (!isset($logins[$user]) || isset($seen[$client . ':' . $user])) {
                continue; // a login that is not there, or a second row for the same pair
            }
            $seen[$client . ':' . $user] = true;
            $owner = (bool) $row['owner'] && !isset($seen['owner:' . $client]);
            $seen['owner:' . $client] = $seen['owner:' . $client] ?? $owner;
            $listed = array_values(array_intersect(ClientUser::PERMISSIONS, array_map('trim', explode(',', (string) ($row['permissions'] ?? '')))));
            // WHMCS has no live chat: a login that opens tickets may chat too, as the migration gave it here (review of #475).
            if (in_array('tickets', $listed, true) && !in_array('chat', $listed, true)) {
                $listed[] = 'chat';
            }
            $date = fn ($value) => $value === null || $value === '' || str_starts_with((string) $value, '0000') ? null : $value;
            $grants[] = [
                'client_id' => $client, 'user_id' => $user, 'owner' => $owner, 'owner_of_client_id' => $owner ? $client : null,
                'permissions' => $owner ? null : json_encode($listed),
                'granted_at' => $date($row['created_at'] ?? null) ?? $accounts[$client], 'last_login_at' => $date($row['last_login'] ?? null),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if ($grants !== []) {
            DB::table('client_user')->insert($grants);
        }
    }

    /**
     * WHMCS admins → staff (docs/accounts.md, PR 1), all of them, with their ids: the Full Administrator role as our
     * `admin` (all permissions), any other WHMCS role as a role of the same name with no permissions yet (staff give
     * them their permissions). Disabled admins come disabled.
     */
    private function importAdmins()
    {
        $this->info('Importing admins... (' . $this->count('tbladmins') . ' records)');

        $this->migrateInBatch('tbladmins', 'SELECT * FROM tbladmins LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                // staff.email is unique; WHMCS may hold an old and a new admin with one email. The later one gets a
                // marked address (to correct by hand), not an aborted import.
                $email = $record['email'];
                if (isset($this->staffEmails[strtolower($email)])) {
                    $email = 'duplicate-' . $record['id'] . '+' . $email;
                    $this->warn('Admin #' . $record['id'] . ' shares an email with another admin: imported as ' . $email);
                }
                $this->staffEmails[strtolower($email)] = true;
                $data[] = [
                    'id' => $record['id'],
                    'first_name' => $record['firstname'],
                    'last_name' => $record['lastname'],
                    'email' => $email,
                    // A WHMCS bcrypt hash logs in as it is; anything older needs «Forgot password».
                    'password' => $record['password'],
                    'role_id' => $this->staffRoleId((int) $record['roleid']),
                    'active' => (int) $record['disabled'] !== 1,
                    'created_at' => $this->validateDate($record['created_at'] ?? null) ?? now(),
                    'updated_at' => now(),
                ];
            }
            if ($data !== []) {
                DB::table('staff')->insert($data);
            }
        });
    }

    /** @var array<int, int> */
    private array $staffRoles = [];

    /** @var array<string, true> */
    private array $staffEmails = [];

    private function staffRoleId(int $whmcsRoleId): int
    {
        if (isset($this->staffRoles[$whmcsRoleId])) {
            return $this->staffRoles[$whmcsRoleId];
        }
        if ($whmcsRoleId === 1) {
            $role = Role::query()->firstOrCreate(['name' => 'admin'], ['permissions' => ['*']]);
        } else {
            $stmt = $this->pdo->prepare('SELECT name FROM tbladminroles WHERE id = :id LIMIT 1');
            $stmt->bindValue(':id', $whmcsRoleId, PDO::PARAM_INT);
            $stmt->execute();
            $name = (string) ($stmt->fetchColumn() ?: 'WHMCS role ' . $whmcsRoleId);
            $role = Role::query()->firstOrCreate(['name' => $name], ['permissions' => []]);
        }

        return $this->staffRoles[$whmcsRoleId] = $role->id;
    }

    private function importCategories()
    {
        $this->info('Importing categories... (' . $this->count('tblproductgroups') . ' records)');

        $this->migrateInBatch('tblproductgroups', 'SELECT * FROM tblproductgroups LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                $data[] = [
                    'id' => $record['id'],
                    'name' => $record['name'],
                    'description' => $record['tagline'],
                    'sort' => $record['order'],
                    'slug' => $record['slug'],
                    'created_at' => $record['created_at'],
                    'updated_at' => $record['updated_at'],
                ];
            }

            DB::table('categories')->insert($data);
        });
    }

    private function importConfigOptions()
    {
        $this->info('Importing config options... (' . $this->count('tblproductconfigoptions') . ' records)');

        $this->migrateInBatch('tblproductconfigoptions', 'SELECT * FROM tblproductconfigoptions LIMIT :limit OFFSET :offset', function ($records) {
            $planData = [];
            $priceData = [];

            // First, insert parent config options and track their new IDs
            foreach ($records as $record) {
                if (strpos($record['optionname'], '|') !== false) {
                    $environmentVariable = explode('|', $record['optionname'])[0];
                    $name = explode('|', $record['optionname'])[1] ?? $record['optionname'];
                } else {
                    $environmentVariable = null;
                    $name = $record['optionname'];
                }

                $parentData = [
                    'name' => $name,
                    'env_variable' => $environmentVariable,
                    'type' => match ($record['optiontype']) {
                        1 => 'select',
                        2 => 'radio',
                        3 => 'checkbox',
                        4 => 'number',
                        default => 'select',
                    },
                    'sort' => $record['order'],
                    'hidden' => $record['hidden'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Insert parent and get the new Laravel-generated ID
                $newParentId = DB::table('config_options')->insertGetId($parentData);

                // Get child options for this parent
                $stmt = $this->pdo->prepare('SELECT * FROM tblproductconfigoptionssub WHERE configid = :configid');
                $stmt->bindValue(':configid', $record['id'], PDO::PARAM_INT);
                $stmt->execute();
                $optionRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($optionRecords as $option) {
                    if (strpos($option['optionname'], '|') !== false) {
                        $environmentVariable = explode('|', $option['optionname'])[0];
                        $name = explode('|', $option['optionname'])[1] ?? $option['optionname'];
                    } else {
                        $environmentVariable = null;
                        $name = $option['optionname'];
                    }

                    $childData = [
                        'parent_id' => $newParentId, // Use the new Laravel-generated parent ID
                        'name' => $name,
                        'env_variable' => $environmentVariable,
                        'sort' => $option['sortorder'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Insert child and get the new Laravel-generated ID
                    $newChildId = DB::table('config_options')->insertGetId($childData);

                    // Get pricing for the option
                    $stmt2 = $this->pdo->prepare('SELECT * FROM tblpricing WHERE type = "configoptions" AND relid = :relid');
                    $stmt2->bindValue(':relid', $option['id'], PDO::PARAM_INT);
                    $stmt2->execute();
                    $prices = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                    $this->priceMagic($prices, $planData, $priceData, ['id' => $newChildId, 'paytype' => 'recurring'], ConfigOption::class);
                }
            }

            // Insert plans and then prices
            foreach ($planData as $planKey => $plan) {
                $planId = DB::table('plans')->insertGetId($plan);
                if (isset($priceData[$planKey])) {
                    foreach ($priceData[$planKey] as &$price) {
                        $price['plan_id'] = $planId;
                    }
                    DB::table('prices')->insert($priceData[$planKey]);
                }
            }
        });
    }

    private function priceMagic(&$prices, &$planData, &$priceData, $record, $priceableType = Product::class)
    {
        foreach ($prices as $pricing) {
            if ($record['paytype'] === 'onetime') {
                // One-time payment product, create a one-time plan
                $setupFee = $pricing['msetupfee'] ?? 0;

                // Create a unique key to link plan and price
                $planKey = $record['id'] . '_onetime';

                $planData[$planKey] = [
                    'priceable_id' => $record['id'],
                    'priceable_type' => $priceableType,
                    'name' => 'One-Time',
                    'type' => 'one-time',
                    'billing_period' => 0,
                    'billing_unit' => null,
                ];

                $currency = $this->pdo->prepare('SELECT * FROM tblcurrencies WHERE id = :id LIMIT 1');
                $currency->bindValue(':id', $pricing['currency'], PDO::PARAM_INT);
                $currency->execute();
                $currency = $currency->fetch(PDO::FETCH_ASSOC);

                $priceData[$planKey][] = [
                    'currency_code' => $currency['code'],
                    'price' => $pricing['monthly'],
                    'setup_fee' => $setupFee,
                ];

                continue;
            }

            foreach (['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'] as $period) {
                if ($pricing[$period] > 0) {
                    $setupFee = match ($period) {
                        'monthly' => $pricing['msetupfee'],
                        'quarterly' => $pricing['qsetupfee'],
                        'semiannually' => $pricing['ssetupfee'],
                        'annually' => $pricing['asetupfee'],
                        'biennially' => $pricing['bsetupfee'],
                        'triennially' => $pricing['tsetupfee'],
                        default => 0,
                    };

                    // Create a unique key to link plan and price
                    $planKey = $record['id'] . '_' . $period;

                    $planData[$planKey] = [
                        'priceable_id' => $record['id'],
                        'priceable_type' => $priceableType,
                        'name' => ucfirst($period),
                        'type' => 'recurring',
                        'billing_period' => match ($period) {
                            'monthly' => 1,
                            'quarterly' => 3,
                            'semiannually' => 6,
                            'annually' => 1,
                            'biennially' => 2,
                            'triennially' => 3,
                            default => 1,
                        },
                        'billing_unit' => match ($period) {
                            'monthly', 'quarterly', 'semiannually' => 'month',
                            'annually', 'biennially', 'triennially' => 'year',
                            default => 'month',
                        },
                    ];

                    $currency = $this->pdo->prepare('SELECT * FROM tblcurrencies WHERE id = :id LIMIT 1');
                    $currency->bindValue(':id', $pricing['currency'], PDO::PARAM_INT);
                    $currency->execute();
                    $currency = $currency->fetch(PDO::FETCH_ASSOC);

                    $priceData[$planKey][] = [
                        'currency_code' => $currency['code'],
                        'price' => $pricing[$period],
                        'setup_fee' => $setupFee,
                    ];
                }

            }
        }

    }

    private function importProducts()
    {
        $this->info('Importing products... (' . $this->count('tblproducts') . ' records)');

        $this->migrateInBatch('tblproducts', 'SELECT * FROM tblproducts LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            $planData = [];
            $priceData = [];
            $upgrades = [];

            foreach ($records as $record) {
                if (($record['proratabilling'] ?? 0) && (int) ($record['proratadate'] ?? 0) > 28) {
                    // MyCS aligns to a day every month has (PR D): the parity check should show it.
                    $this->warn("Product #{$record['id']}: pro rata day {$record['proratadate']} imported as 28.");
                }
                $data[] = [
                    'id' => $record['id'],
                    'category_id' => $record['gid'],
                    'name' => $record['name'],
                    'description' => $record['description'],
                    'slug' => !empty($record['slug']) ? $record['slug'] : \Str::slug($record['name']),
                    'hidden' => $record['hidden'],
                    // WHMCS «Apply Tax» (Δ1).
                    'taxable' => (bool) ($record['tax'] ?? true),
                    // WHMCS «Prorata Billing» (decision 3, PR D): its day, and «charge next month» (over 31: never).
                    'prorata_day' => ($record['proratabilling'] ?? 0) && (int) ($record['proratadate'] ?? 0) >= 1
                        ? min((int) $record['proratadate'], 28) : null,
                    'prorata_next_after_day' => ($record['proratabilling'] ?? 0) && (int) ($record['proratachargenextmonth'] ?? 0) >= 1
                        && (int) $record['proratachargenextmonth'] <= 31 ? (int) $record['proratachargenextmonth'] : null,
                    // When its service is set up (Σ1-b): '' (never), on (Accept), order, payment.
                    'autosetup' => Product::AUTOSETUP_FROM_WHMCS[$record['autosetup'] ?? 'payment'] ?? Product::AUTOSETUP_PAYMENT,
                    'stock' => $record['stockcontrol'] ? $record['qty'] : null,
                    'allow_quantity' => match ($record['allowqty']) {
                        1 => 'separated',
                        3 => 'combined',
                        default => 'disabled',
                    },
                    'created_at' => $this->validateDate($record['created_at']) ?? now(),
                    'updated_at' => $this->validateDate($record['updated_at']) ?? now(),
                ];

                // Upgrades
                $stmt = $this->pdo->prepare('SELECT * FROM tblproduct_upgrade_products WHERE product_id = :product_id');
                $stmt->bindValue(':product_id', $record['id'], PDO::PARAM_INT);
                $stmt->execute();
                $upgradeRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($upgradeRecords as $upgrade) {
                    $upgrades[] = [
                        'product_id' => $upgrade['product_id'],
                        'upgrade_id' => $upgrade['upgrade_product_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Config options
                $stmt = $this->pdo->prepare('SELECT * FROM tblproductconfiglinks WHERE pid = :pid');
                $stmt->bindValue(':pid', $record['id'], PDO::PARAM_INT);
                $stmt->execute();
                $configOptionRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($configOptionRecords as $configOptionGroupId) {
                    // Get the config option group
                    $configOptionGroup = $this->pdo->prepare('SELECT * FROM tblproductconfiggroups WHERE id = :id LIMIT 1');
                    $configOptionGroup->bindValue(':id', $configOptionGroupId['gid'], PDO::PARAM_INT);
                    $configOptionGroup->execute();
                    $configOptionGroup = $configOptionGroup->fetch(PDO::FETCH_ASSOC);
                    if (!$configOptionGroup) {
                        continue;
                    }
                    // Get config options in the group
                    $configOptions = $this->pdo->prepare('SELECT * FROM tblproductconfigoptions WHERE gid = :gid');
                    $configOptions->bindValue(':gid', $configOptionGroup['id'], PDO::PARAM_INT);
                    $configOptions->execute();
                    $configOptions = $configOptions->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($configOptions as $configOption) {
                        // Link config option to product
                        DB::table('config_option_products')->insert([
                            'product_id' => $record['id'],
                            'config_option_id' => $configOption['id'],
                        ]);
                    }
                }
            }

            // Insert products first
            DB::table('products')->insert($data);

            // Insert upgrades
            if (count($upgrades) > 0) {
                DB::table('product_upgrades')->insert($upgrades);
            }

            // Now process plans for all products in this batch
            foreach ($records as $record) {
                if ($record['paytype'] === 'free') {
                    // Free product, create a free plan
                    $planData[$record['id'] . '_free'] = [
                        'priceable_id' => $record['id'],
                        'priceable_type' => Product::class,
                        'name' => 'Free',
                        'type' => 'free',
                    ];

                    continue;
                }
                $stmt = $this->pdo->prepare('SELECT * FROM tblpricing WHERE type = "product" AND relid = :relid');
                $stmt->bindValue(':relid', $record['id'], PDO::PARAM_INT);
                $stmt->execute();
                $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $this->priceMagic($prices, $planData, $priceData, $record);
            }

            // Insert plans and then prices
            foreach ($planData as $planKey => $plan) {
                $planId = DB::table('plans')->insertGetId($plan);

                if (isset($priceData[$planKey])) {
                    foreach ($priceData[$planKey] as &$price) {
                        $price['plan_id'] = $planId;
                    }
                    DB::table('prices')->insert($priceData[$planKey]);
                }
            }
        });
    }

    /** The tickets (docs/tickets.md §5α, PR Δ); also on its own: app:import-whmcs-tickets. */
    private function importTickets()
    {
        $this->info('Importing tickets... (' . $this->count('tbltickets') . ' records)');
        try {
            $folder = $this->option('ticket-attachments');
        } catch (Throwable) {
            $folder = null; // called without the command's input (the tests' reflection)
        }
        if (ImportWhmcsTickets::report($this, (new WhmcsTicketImport($this->pdo, is_string($folder) && $folder !== '' ? $folder : null))->run()) !== self::SUCCESS) {
            $this->warn('Tickets: see above; app:import-whmcs-tickets can be run again on its own.');
        }
    }

    /** The knowledgebase (docs/knowledgebase.md); also on its own: app:import-whmcs-knowledgebase. */
    private function importKnowledgebase()
    {
        $this->info('Importing the knowledgebase... (' . $this->count('tblknowledgebase') . ' records)');
        if (ImportWhmcsKnowledgebase::report($this, (new WhmcsKnowledgebaseImport($this->pdo))->run()) !== self::SUCCESS) {
            $this->warn('Knowledgebase: see above; app:import-whmcs-knowledgebase can be run again on its own.');
        }
    }

    private function importOrders()
    {
        $this->info('Importing orders... (' . $this->count('tblorders') . ' records)');

        $this->migrateInBatch('tblorders', 'SELECT * FROM tblorders LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                // Get currency from user
                $user = $this->pdo->prepare('SELECT * FROM tblcurrencies WHERE id = (SELECT currency FROM tblclients WHERE id = :client_id LIMIT 1) LIMIT 1');
                $user->bindValue(':client_id', $record['userid'], PDO::PARAM_INT);
                $user->execute();
                $user = $user->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    continue;
                }

                $data[] = [
                    'id' => $record['id'],
                    'client_id' => $record['userid'],
                    'currency_code' => $user['code'],
                    // WHMCS «Order Notes» (the customer's at checkout, or staff's).
                    'notes' => self::text($record['notes'] ?? null),
                    // Where it was placed from (WHMCS `ipaddress`): an address, or none (staff's, or anything else).
                    'ip_address' => filter_var(trim((string) ($record['ipaddress'] ?? '')), FILTER_VALIDATE_IP) ?: null,
                    // Its status as WHMCS has it (Σ1-b2): no observer runs on an import, and ~7.000 orders must not land in
                    // Pending Orders. Its own invoice: what «Cancel & Refund» gives back.
                    'status' => match ($record['status'] ?? 'Active') {
                        'Pending' => Order::STATUS_PENDING,
                        'Cancelled' => Order::STATUS_CANCELLED,
                        'Fraud' => Order::STATUS_FRAUD,
                        default => Order::STATUS_ACTIVE,
                    },
                    'invoice_id' => !empty($record['invoiceid']) ? (int) $record['invoiceid'] : null,
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['date']),
                ];
            }

            DB::table('orders')->insert($data);
        });
    }

    /** A WHMCS text column as stored here: trimmed, null when empty, cut to the column's length. */
    private static function text(mixed $value, ?int $max = null): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : ($max === null ? $value : mb_substr($value, 0, $max));
    }

    private function importServices()
    {
        $this->info('Importing services... (' . $this->count('tblhosting') . ' records)');

        // WHMCS's Suspend Days, for when a service it suspended for non-payment was suspended (it keeps no date of its own).
        $suspendDays = $this->sourceHas('tblconfiguration')
            ? (int) ($this->pdo->query("SELECT value FROM tblconfiguration WHERE setting = 'AutoSuspensionDays'")->fetchColumn() ?: 0) : 0;
        // Each account's own server (tblhosting.server), by the catalog's WHMCS ids; one not here (deleted, or a module
        // with no extension) stays none, never the product's (AUD4-1).
        $servers = Server::query()->whereNotNull('whmcs_id')->get(['id', 'whmcs_id', 'extension'])->keyBy('whmcs_id');
        $productServers = DB::table('products')->join('extensions', 'extensions.id', '=', 'products.server_id')->pluck('extensions.extension', 'products.id');
        $withoutServer = [];
        $marked = [];
        $this->migrateInBatch('tblhosting', 'SELECT * FROM tblhosting LIMIT :limit OFFSET :offset', function ($records) use ($suspendDays, $servers, $productServers, &$withoutServer, &$marked) {
            $data = [];
            $marks = [];
            foreach ($records as $record) {
                // Get currency from user
                $user = $this->pdo->prepare('SELECT * FROM tblcurrencies WHERE id = (SELECT currency FROM tblclients WHERE id = :client_id LIMIT 1) LIMIT 1');
                $user->bindValue(':client_id', $record['userid'], PDO::PARAM_INT);
                $user->execute();
                $user = $user->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    continue;
                }

                $planId = DB::table('plans')
                    ->where('priceable_id', $record['packageid'])
                    ->where('priceable_type', Product::class)
                    ->where('name', match ($record['billingcycle']) {
                        'Monthly' => 'Monthly',
                        'Quarterly' => 'Quarterly',
                        'Semi-Annually' => 'Semiannually',
                        'Annually' => 'Annually',
                        'Biennially' => 'Biennially',
                        'Triennially' => 'Triennially',
                        'One Time' => 'One-Time',
                        'Free Account' => 'Free',
                        default => 'Monthly',
                    })
                    ->first()?->id;

                $whmcsServer = array_key_exists('server', $record) ? (int) $record['server'] : null;
                $server = $whmcsServer ? $servers->get($whmcsServer) : null;
                if ($whmcsServer && $server === null && in_array($record['domainstatus'], ['Active', 'Suspended'], true)) {
                    $withoutServer[] = [(int) $record['id'], $whmcsServer];
                }
                // Suspended by WHMCS's module: its station disabled, its domain admin locked. Our mark, so a payment here
                // enables what WHMCS disabled (AUD4-2); a stream WHMCS stopped is started by the customer, as WHMCS left it.
                $mark = $record['domainstatus'] !== 'Suspended' ? null : match ($server->extension ?? $productServers[$record['packageid']] ?? null) {
                    'CentovaCast' => [CentovaCast::DISABLED, 'Centova account disabled by suspend', 'down'],
                    'Mailcow' => [Mailcow::LOCKED, 'mailcow admin locked by suspend', '1'],
                    default => null,
                };
                if ($mark !== null) {
                    $marks[] = ['model_type' => Service::class, 'model_id' => $record['id'], 'key' => $mark[0], 'name' => $mark[1], 'value' => $mark[2],
                        'created_at' => now(), 'updated_at' => now()];
                    $marked[] = [(int) $record['id'], $mark[0] === Mailcow::LOCKED ? 'mailcow' : 'Centova', (string) ($record['username'] ?: $record['domain'])];
                }

                $data[] = [
                    'id' => $record['id'],
                    'order_id' => $record['orderid'],
                    'server_id' => $server?->id,
                    'whmcs_server_id' => $whmcsServer,
                    'product_id' => $record['packageid'],
                    'status' => match ($record['domainstatus']) {
                        'Active' => 'active',
                        'Suspended' => 'suspended',
                        'Terminated' => 'terminated',
                        'Cancelled' => 'cancelled',
                        'Fraud' => 'cancelled',
                        'Pending' => 'pending',
                        default => 'pending',
                    },
                    'price' => $record['amount'],
                    'quantity' => $record['qty'],
                    'client_id' => $record['userid'],
                    'plan_id' => $planId,
                    'currency_code' => $user['code'],
                    'expires_at' => $this->validateDate($record['nextduedate']),
                    // Started already unless pending: its dates are never moved as a first start's (Σ1-b1).
                    'activated_at' => $record['domainstatus'] === 'Pending' ? null : ($this->validateDate($record['regdate']) ?? now()),
                    // WHMCS's own suspension for non-payment: a payment lifts it here too. Its own cron leaves the reason
                    // empty (myip: none of 123 says «Overdue on Payment», AUD9-3), so an empty one with an unpaid renewal
                    // past its due date is one too. Any other reason, or none with nothing overdue, was staff's: a manual
                    // hold (docs/billing-cursor.md).
                    // When: its due date and the Suspend Days, as WHMCS's own run did; never later than now. The cron counts
                    // the days it has been suspended (review of #518: «now» put a termination due on the first night a day late).
                    'dunning_suspended_at' => $record['domainstatus'] === 'Suspended'
                        && (strcasecmp(trim((string) ($record['suspendreason'] ?? '')), 'Overdue on Payment') === 0
                            || (trim((string) ($record['suspendreason'] ?? '')) === '' && isset($this->overdueServices()[(int) $record['id']])))
                        ? self::suspendedOn($this->validateDate($record['nextduedate']), $suspendDays) : null,
                    // WHMCS's «Override Auto-Suspend» (review of #518, ADV-518-1: on myip 36 active services): suspended
                    // until its date, or never (no date) as a hold far off; the cron neither suspends nor terminates it.
                    'suspend_hold_until' => in_array(strtolower(trim((string) ($record['overideautosuspend'] ?? ''))), ['1', 'on'], true)
                        ? ($this->validateDate($record['overidesuspenduntil'] ?? null) !== null
                            ? substr((string) $this->validateDate($record['overidesuspenduntil']), 0, 10) : self::NEVER_SUSPEND)
                        : null,
                    // tblhosting's own fields (native here). The password is WHMCS-encrypted (its cc_encryption_hash, not in
                    // the database): brought by its own step through WHMCS's DecryptPassword, before the cut-over.
                    'domain' => self::text($record['domain'] ?? null, 255),
                    'username' => self::text($record['username'] ?? null, 255),
                    'dedicated_ip' => self::text($record['dedicatedip'] ?? null, 45),
                    'assigned_ips' => self::text(str_replace("\r\n", "\n", (string) ($record['assignedips'] ?? ''))),
                    'notes' => self::text(implode("\n\n", array_filter([$this->promotionStillRunning($record), trim((string) ($record['notes'] ?? ''))]))),
                    // Its promotion (tblhosting.promoid), for the record: its discount is in `price` already (WHMCS's
                    // recurring amount), so it is used up here and never moves the price (importer: no amount changes).
                    ...$this->serviceCoupon($record),
                    'suspend_reason' => $record['domainstatus'] === 'Suspended' ? self::text($record['suspendreason'] ?? null, 255) : null,
                    'created_at' => $this->validateDate($record['regdate']),
                    'updated_at' => $this->validateDate($record['regdate']),
                ];
            }

            DB::table('services')->insert($data);
            DB::table('properties')->insert($marks);
        });
        if ($withoutServer !== []) {
            $this->warn(count($withoutServer) . ' live service(s) whose WHMCS server is not here: no server action until staff set one.');
            $this->table(['Service', 'WHMCS server'], $withoutServer);
        }
        if ($marked !== []) {
            // Before the cut-over: each one checked disabled (Centova) or locked (mailcow) there, as WHMCS left it.
            $this->info(count($marked) . ' suspended service(s) marked as suspended by us: a payment enables them again.');
            $this->table(['Service', 'Module', 'Account'], $marked);
        }
    }

    /** WHMCS's servers and groups, and its products' (the catalog's step): before the services, which come on theirs. */
    private function importServers()
    {
        if (!$this->sourceHas('tblservers')) {
            return;
        }
        $result = (new CatalogImport($this->pdo))->serversForFullImport();
        $this->info("Servers: {$result['counts']['servers']} servers, {$result['counts']['server_groups']} groups (no passwords or API keys: staff enter them).");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /**
     * The certificates of a WHMCS SSL module with no extension here (TheSSLStore): their products and services on one
     * Offline SSL server per provider, so that every action on them (a paid renewal too) is a task for staff, never
     * «nothing to do»; each one's order (WHMCS's tblsslorders, the module's orders table) kept in its fields. Nothing is
     * asked of the provider. Its products are retired (owner 2026-10-29): new certificates are Openprovider SSL's.
     */
    private function importOfflineSsl()
    {
        $products = [];
        foreach ($this->pdo->query('SELECT id, servertype FROM tblproducts')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $provider = self::OFFLINE_SSL_MODULES[strtolower(trim((string) ($row['servertype'] ?? '')))] ?? null;
            if ($provider !== null) {
                $products[$provider][] = (int) $row['id'];
            }
        }
        $orders = $this->sslOrders();
        foreach ($products as $provider => $ids) {
            $server = Server::query()->firstOrCreate(['extension' => 'OfflineSsl', 'name' => "{$provider} (offline)"], ['type' => 'server', 'enabled' => true]);
            DB::table('products')->whereIn('id', $ids)->update(['server_id' => $server->id, 'hidden' => true, 'retired' => true]);
            // Every one of the module's, on whatever server tblhosting.server left it (WHMCS's module is its product's: a
            // cPanel server there never ran it), listed when moved (reviews of #532).
            $services = DB::table('services')->whereIn('product_id', $ids)->get(['id', 'domain', 'server_id']);
            $moved = $services->filter(fn ($service) => $service->server_id !== null && (int) $service->server_id !== $server->id);
            if ($moved->isNotEmpty()) {
                $this->warn($moved->count() . " {$provider} service(s) moved from another module's server to «{$server->name}»: " . $moved->pluck('id')->implode(', ') . '.');
            }
            DB::table('services')->whereIn('id', $services->pluck('id'))->update(['server_id' => $server->id]);
            $properties = [];
            foreach ($services as $service) {
                $fields = ['ssl_provider' => $provider] + ($orders[$service->id] ?? []);
                if (strcasecmp((string) ($fields['ssl_common_name'] ?? ''), (string) $service->domain) === 0) {
                    unset($fields['ssl_common_name']); // the service's domain already
                }
                foreach (array_filter($fields, fn ($value) => $value !== null && $value !== '') as $key => $value) {
                    $properties[] = ['model_type' => Service::class, 'model_id' => $service->id, 'key' => $key, 'name' => self::SSL_FIELD_NAMES[$key],
                        'value' => mb_substr((string) $value, 0, 255), 'created_at' => now(), 'updated_at' => now()];
                }
            }
            foreach (array_chunk($properties, 500) as $chunk) {
                DB::table('properties')->insert($chunk);
            }
            $this->info(count($services) . " {$provider} certificate(s) on «{$server->name}» (Offline SSL): every action on them is a task for staff; "
                . count($ids) . ' product(s) retired.');
        }
    }

    private const SSL_FIELD_NAMES = ['ssl_provider' => 'Provider', 'ssl_order_id' => 'Order ID at the provider', 'ssl_vendor_order_id' => 'Order ID at the CA',
        'ssl_product' => 'Certificate type', 'ssl_common_name' => 'Common name', 'ssl_status' => 'Status at the provider', 'ssl_expires_on' => 'Expires on'];

    /**
     * Each service's SSL order, by service id: the module's latest (mod_sslstore_orders_data), whole, never mixed with an
     * older order of the service; WHMCS's own latest of the module (tblsslorders, not another module's or an addon's)
     * where the module has none or it is the same order, filling what the module's row leaves empty. Never their CSR,
     * keys or authentication data (reviews of #532).
     *
     * @return array<int, array<string, string|null>>
     */
    private function sslOrders(): array
    {
        $whmcs = [];
        if ($this->sourceHas('tblsslorders')) {
            foreach ($this->pdo->query('SELECT * FROM tblsslorders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((array_key_exists('module', $row) && !isset(self::OFFLINE_SSL_MODULES[strtolower(trim((string) $row['module']))]))
                    || (int) ($row['addon_id'] ?? 0) !== 0) {
                    continue;
                }
                $whmcs[(int) $row['serviceid']] = [
                    'ssl_order_id' => self::text($row['remoteid'] ?? null, 255),
                    'ssl_product' => self::text($row['certtype'] ?? null, 255),
                    'ssl_status' => self::text($row['status'] ?? null, 255),
                    'ssl_expires_on' => substr((string) $this->validateDate($row['certificate_expiry_date'] ?? null), 0, 10) ?: null,
                ];
            }
        }
        $module = [];
        if ($this->sourceHas('mod_sslstore_orders_data')) {
            foreach ($this->pdo->query('SELECT * FROM mod_sslstore_orders_data ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $module[(int) $row['service_id']] = [
                    'ssl_order_id' => self::text((string) ($row['store_order_id'] ?? '') ?: null, 255),
                    'ssl_vendor_order_id' => self::text($row['vendor_order_id'] ?? null, 255),
                    'ssl_product' => self::text($row['product_code'] ?? null, 255),
                    'ssl_common_name' => self::text($row['common_name'] ?? null, 255),
                    'ssl_status' => self::text($row['ssl_status'] ?? null, 255),
                    'ssl_expires_on' => substr((string) $this->validateDate($row['expiration_date'] ?? null), 0, 10) ?: null,
                ];
            }
        }
        $orders = [];
        foreach (array_unique([...array_keys($whmcs), ...array_keys($module)]) as $serviceId) {
            $own = $module[$serviceId] ?? null;
            $theirs = $whmcs[$serviceId] ?? null;
            $sameOrder = $own === null || $theirs === null || $own['ssl_order_id'] === null || $own['ssl_order_id'] === $theirs['ssl_order_id'];
            $orders[$serviceId] = $own === null ? $theirs : ($sameOrder && $theirs !== null
                ? array_merge($theirs, array_filter($own, fn ($value) => $value !== null)) : $own);
        }

        return $orders;
    }

    /** The domains (D6): their registrar connections and TLDs, each domain, its handles and notices sent (WhmcsDomainImport). */
    private function importDomains()
    {
        $this->info('Importing domains... (' . $this->count('tbldomains') . ' records)');
        $this->domains = new WhmcsDomainImport($this->pdo);
        $result = $this->domains->run();
        $c = $result['counts'];
        $this->info("Domains: {$c['domains']} ({$c['skipped']} skipped), {$c['tlds']} TLDs, {$c['connections']} registrar connection(s); "
            . "{$c['handles']} handle(s) linked, {$c['handles_kept']} kept for staff; {$c['reminders']} notice(s) already sent.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** WHMCS's modules that are a gateway here for an invoice's preferred method, beside the payment ones (GATEWAYS). */
    private const PREFERRED_GATEWAYS = ['banktransfer' => 'BankTransfer'];

    /**
     * Each invoice's preferred payment method (WHMCS's tblinvoices.paymentmethod): its page opens with it chosen, its
     * email names it. After the payments (their gateways installed by then); a module with no gateway here: none.
     */
    private function importInvoicePreferences(): void
    {
        $byModule = [];
        foreach ($this->pdo->query('SELECT id, paymentmethod FROM tblinvoices')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $module = strtolower(trim((string) ($row['paymentmethod'] ?? '')));
            if ($module !== '') {
                $byModule[$module][] = (int) $row['id'];
            }
        }
        $set = 0;
        foreach ($byModule as $module => $ids) {
            $gateway = $this->existingGateway($module);
            if ($gateway === null) {
                continue;
            }
            foreach (array_chunk($ids, 1000) as $chunk) {
                $set += DB::table('invoices')->whereIn('id', $chunk)->update(['preferred_gateway_id' => $gateway]);
            }
        }
        $this->info("Invoices' preferred payment method: {$set} set from WHMCS.");
    }

    /**
     * The gateway here of a WHMCS module, for a preferred payment method: only one already here (its payments', or set
     * up), never installed for a label, it would be offered to everyone unconfigured (reviews of #539).
     */
    private function existingGateway(string $module): ?int
    {
        $extension = self::GATEWAYS[$module] ?? self::PREFERRED_GATEWAYS[$module] ?? (str_starts_with($module, 'mollie') ? 'Mollie' : null);
        $id = $extension === null ? null : DB::table('extensions')->where('type', 'gateway')->where('extension', $extension)->whereNull('deleted_at')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Each service's and domain's own payment method (tblhosting/tbldomains.paymentmethod, owner 2026-10-29): its renewals
     * are invoiced apart with it, and a saved method of another gateway never charges them, as in WHMCS. A module with no
     * gateway here: none (the account's); the saved methods' step then keeps «Auto-charge» off for such a customer.
     */
    private function importItemPaymentMethods(): void
    {
        $set = 0;
        foreach (['tblhosting' => 'services', 'tbldomains' => 'domains'] as $source => $table) {
            $byModule = [];
            foreach ($this->pdo->query("SELECT id, paymentmethod FROM {$source}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $module = strtolower(trim((string) ($row['paymentmethod'] ?? '')));
                if ($module !== '') {
                    $byModule[$module][] = (int) $row['id'];
                }
            }
            foreach ($byModule as $module => $ids) {
                $gateway = $this->existingGateway($module);
                foreach (array_chunk($gateway === null ? [] : $ids, 1000) as $chunk) {
                    $set += DB::table($table)->whereIn('id', $chunk)->update(['payment_gateway_id' => $gateway]);
                }
            }
        }
        $this->info("Services' and domains' payment method: {$set} set from WHMCS.");
    }

    /** The saved payment methods (WhmcsPayMethodImport): their references through WHMCS's API, never its key. */
    private function importPayMethods()
    {
        try {
            $api = WhmcsApi::fromEnvironment(rescue(fn () => $this->option('whmcs-api-url'), null, false)); // none in a step run alone (tests)
        } catch (\RuntimeException $e) {
            $this->warn("Saved payment methods not imported: {$e->getMessage()}");

            return;
        }
        $result = (new WhmcsPayMethodImport($this->pdo, $api, fn (string $module) => $this->gatewayId($module),
            fn (string $module) => $this->existingGateway($module)))->run();
        $c = $result['counts'];
        $this->info("Saved payment methods: {$c['imported']} of {$c['methods']} imported ({$c['defaults']} customers' default; {$c['not_charged']} with «Auto-charge» off, "
            . "their items paid another way with no payment method here, those with some paid by a saved method listed), {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** nixpal's licenses (L3, docs/licensing.md); also on its own: app:import-whmcs-licensing. */
    private function importLicensing()
    {
        $counts = (new WhmcsLicensingImport($this->pdo))->run();
        $this->info("Licensing: {$counts['licenses']} licenses ({$counts['skipped']} without an imported service), {$counts['products']} products, "
            . "{$counts['fields']} custom field values, {$counts['logs']} log rows.");
    }

    private function importCancellations()
    {
        $this->info('Importing cancellations... (' . $this->count('tblcancelrequests') . ' records)');

        // One request per service (DATA-11; WHMCS has no such key): the first one is kept, the later ones ignored.
        $this->migrateInBatch('tblcancelrequests', 'SELECT * FROM tblcancelrequests ORDER BY id LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                $data[] = [
                    'id' => $record['id'],
                    'service_id' => $record['relid'],
                    'reason' => mb_substr($record['reason'], 0, 255),
                    'type' => $record['type'] == 'End of Billing Period' ? 'end_of_period' : 'immediate',
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['date']),
                ];
            }

            DB::table('service_cancellations')->insertOrIgnore($data);
        });
    }

    private function importInvoices()
    {
        $this->info('Importing invoices... (' . $this->count('tblinvoices') . ' records)');

        $this->migrateInBatch('tblinvoices', 'SELECT * FROM tblinvoices LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            $existingNumbers = DB::table('invoices')->whereNotNull('number')->pluck('number')->flip();

            foreach ($records as $record) {
                // A Mass Pay parent is not an invoice here (importMassPay).
                if (isset($this->massPayParents()[(int) $record['id']])) {
                    continue;
                }
                // Get currency from user
                $user = $this->pdo->prepare('SELECT * FROM tblcurrencies WHERE id = (SELECT currency FROM tblclients WHERE id = :client_id LIMIT 1) LIMIT 1');
                $user->bindValue(':client_id', $record['userid'], PDO::PARAM_INT);
                $user->execute();
                $user = $user->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    continue;
                }

                $invoiceNumber = !empty($record['invoicenum']) ? $record['invoicenum'] : null;

                // If invoice number already exists, set it to null to avoid duplicate key error
                if ($invoiceNumber && isset($existingNumbers[$invoiceNumber])) {
                    $invoiceNumber = null;
                } elseif ($invoiceNumber) {
                    $existingNumbers[$invoiceNumber] = true;
                }

                $data[] = [
                    'id' => $record['id'],
                    'number' => $invoiceNumber,
                    'client_id' => $record['userid'],
                    'status' => self::invoiceStatus((string) $record['status']),
                    'currency_code' => $user['code'],
                    // When it was paid (WHMCS datepaid): the revenue reports count by it (#275).
                    'paid_at' => $record['status'] === 'Paid' ? $this->validateDate($record['datepaid'] ?? null) : null,
                    // Its due date (AUD9-2): the overdue reminders and dunning go by it, as WHMCS's did.
                    'due_at' => $this->validateDate($record['duedate'] ?? null),
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['date']),
                ];
            }

            DB::table('invoices')->insert($data);
        });

        // The counter holds the last number given (InvoiceNumberListener: +1, then used): the highest imported id, so the
        // next is one past it, none skipped (adversarial ADV-2 of #477). A Mass Pay parent (not imported, PR F3) keeps its id
        // too: a new invoice never takes the number a customer quotes for one.
        $highestInvoiceNumber = max((int) DB::table('invoices')->max('id'), (int) $this->pdo->query('SELECT MAX(id) FROM tblinvoices')->fetchColumn());
        // DDL commits an open transaction (MySQL/MariaDB): only outside one, as the importer runs.
        if ((DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') && DB::transactionLevel() === 0) {
            DB::statement('ALTER TABLE invoices AUTO_INCREMENT = ' . ($highestInvoiceNumber + 1));
        }
        // WHMCS's sequential paid numbering (nixpal, AUD9-1): its own counter, apart from the ids, holds the next number;
        // here the counter holds the last one given. Its format and «number on payment» come with it. Without it the
        // number is the id (myip), and the counter is not used.
        $numbering = $this->sourceHas('tblconfiguration') ? $this->pdo->query('SELECT setting, value FROM tblconfiguration WHERE setting IN'
            . " ('SequentialInvoiceNumbering', 'SequentialInvoiceNumberFormat', 'SequentialInvoiceNumberValue')")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        $sequential = in_array(strtolower(trim((string) ($numbering['SequentialInvoiceNumbering'] ?? ''))), ['on', '1', 'yes', 'true'], true);
        $next = trim((string) ($numbering['SequentialInvoiceNumberValue'] ?? ''));
        $counter = $sequential && ctype_digit($next) && strlen($next) <= 9 && (int) $next > 0 ? (int) $next - 1 : $highestInvoiceNumber;
        Setting::updateOrCreate(
            ['key' => 'invoice_number', 'settingable_type' => null, 'settingable_id' => null],
            ['value' => $counter]
        );
        if (array_key_exists('SequentialInvoiceNumbering', $numbering)) {
            $format = str_ireplace(['{YEAR}', '{MONTH}', '{DAY}', '{NUMBER}'], ['{year}', '{month}', '{day}', '{number}'],
                trim((string) ($numbering['SequentialInvoiceNumberFormat'] ?? '')));
            $settings = ['invoice_number_is_id' => ['boolean', $sequential ? '0' : '1'], 'invoice_proforma' => ['boolean', $sequential ? '1' : '0']];
            // Only a format MyCS can write: {number} in it, and no placeholder of WHMCS's it has not.
            if ($sequential && str_contains($format, '{number}') && preg_match('/\{(?!year\}|month\}|day\}|number\})/', $format) !== 1) {
                $settings['invoice_number_format'] = ['string', $format];
            } elseif ($sequential) {
                $this->warn("WHMCS's invoice number format ({$format}) is not one MyCS writes: invoice_number_format kept, set it before the first payment.");
            }
            foreach ($settings as $key => [$type, $value]) {
                Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value, 'type' => $type]);
            }
        }
        $this->info("Invoice counter: {$counter} (the next number is one past it" . ($sequential ? ", WHMCS's sequential numbering)." : ').'));
    }

    /**
     * The reminders WHMCS has sent already, as sent here (review of #531): with the due dates in (AUD9-2), the first cron
     * run would otherwise send each open invoice's «due soon» again, and the overdue notice of the last few days. Every
     * overdue day up to today counts as sent, and «due soon» where its due date is within WHMCS's reminder days. The days
     * ahead are the cron's own.
     */
    private function importReminderState(): void
    {
        $before = 0;
        if ($this->sourceHas('tblconfiguration')) {
            $value = trim((string) $this->pdo->query("SELECT value FROM tblconfiguration WHERE setting = 'SendInvoiceReminderDays'")->fetchColumn());
            $before = ctype_digit($value) && (int) $value <= PaymentReminders::MAX_DAYS ? (int) $value : 0;
        }
        $today = Carbon::today();
        $rows = [];
        foreach (DB::table('invoices')->where('status', Invoice::STATUS_UNPAID)->whereNotNull('due_at')->get(['id', 'due_at']) as $invoice) {
            $due = Carbon::parse($invoice->due_at)->startOfDay();
            $row = ['invoice_id' => $invoice->id, 'due_on' => $due->toDateString(), 'sent_at' => now()];
            if ($due->greaterThan($today)) {
                if ($due->lessThanOrEqualTo($today->copy()->addDays($before))) {
                    $rows[] = ['kind' => 'due', 'days' => 0] + $row;
                }

                continue;
            }
            // Only within the overdue days the cron still sends on (and catches up): an older one is never reminded again.
            $late = (int) $due->diffInDays($today);
            if ($late > PaymentReminders::MAX_DAYS + PaymentReminders::CATCH_UP_DAYS) {
                continue;
            }
            for ($day = 1; $day <= min($late, PaymentReminders::MAX_DAYS); $day++) {
                $rows[] = ['kind' => 'overdue', 'days' => $day] + $row;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('invoice_reminders')->insertOrIgnore($chunk);
        }
        $this->info('Reminders WHMCS sent already, kept as sent: ' . count($rows) . '.');
    }

    private function importInvoiceItems()
    {
        $this->info('Importing invoice items... (' . $this->count('tblinvoiceitems') . ' records)');

        // Each line with the tax it was issued with (Δ1): WHMCS keeps amounts net, «taxed» per line and the rate on the
        // invoice (TaxPerLineItem: rounded per line, as here). The second tax (taxrate2) is not used by either company.
        // Ordered, so the batches are stable.
        $this->migrateInBatch('tblinvoiceitems', 'SELECT ii.*, i.taxrate AS invoice_taxrate, i.id AS invoice_found, i.status AS invoice_status, i.datepaid AS invoice_datepaid, i.date AS invoice_date FROM tblinvoiceitems ii LEFT JOIN tblinvoices i ON i.id = ii.invoiceid ORDER BY ii.id LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                if (isset($this->massPayParents()[(int) $record['invoiceid']])) {
                    continue;
                }
                // A line of an invoice WHMCS deleted (it keeps the line), or one the import did not bring (its customer not
                // here): no invoice here to be on, never one without (importer.md: myip 1,344, nixpal 2; reviews of #535).
                if ($record['invoice_found'] === null || !isset($this->importedInvoices()[(int) $record['invoiceid']])) {
                    $this->orphanLines++;

                    continue;
                }
                $taxed = (bool) ($record['taxed'] ?? false) && (float) ($record['invoice_taxrate'] ?? 0) > 0;
                $line = LinePricer::invoiceLine(number_format((float) $record['amount'], 2, '.', ''), 1,
                    $taxed ? (string) $record['invoice_taxrate'] : null);
                $data[] = [
                    'id' => $record['id'],
                    'invoice_id' => $record['invoiceid'],
                    'description' => $record['description'], // text (DATA-12): kept whole
                    'price' => $record['amount'],
                    'taxed' => $taxed,
                    'tax_rate' => $taxed ? $record['invoice_taxrate'] : 0,
                    'tax_amount' => $line['tax'],
                    'line_total' => $line['total'],
                    // Its invoice's date: which fee is which renewal's is told by it (R2-4 of #535), never all «now».
                    'created_at' => $this->validateDate($record['invoice_date'] ?? null) ?? now(),
                    'updated_at' => $this->validateDate($record['invoice_date'] ?? null) ?? now(),
                ] + $this->lineReference($record);
            }

            DB::table('invoice_items')->insert($data);
        });
        $this->domains?->recordDoneYears();
        foreach ($this->domains?->lineNotes() ?? [] as $note) {
            $this->warn($note);
        }
        if ($this->orphanLines > 0) {
            $this->warn("{$this->orphanLines} invoice line(s) of invoices deleted in WHMCS, or not imported: not imported.");
        }
    }

    /** Lines of an invoice WHMCS deleted, skipped. */
    private int $orphanLines = 0;

    /**
     * What a WHMCS invoice line is for (tblinvoiceitems.type, relid), so income goes to its product (#504):
     * - Hosting: the service's renewal (services keep their WHMCS ids), as before: no kind, its period given when paid.
     * - Setup, PromoHosting (relid = the service), Addon (relid = tblhostingaddons, on its service) and Upgrade (relid =
     *   tblupgrades of a package or its options, on its service): the service's, `kind = charge`, never a period: a
     *   payment renews nothing with it, and the billing never takes it for a period (docs/importer.md).
     * - AddFunds: a credit purchase, as MyCS's own: never income (the credit is, when it pays; #275).
     * - A domain's (D6, WhmcsDomainImport::line): its renewal with its period, applied when paid; a registration or a
     *   transfer as such; anything else of it a charge.
     * - Anything else (a late fee, a group discount, by hand): no type, its relid kept as it was.
     * A line whose service or domain is not here (deleted in WHMCS) has no type either: never attached by guess.
     *
     * @param  array<string, mixed>  $record  a tblinvoiceitems row
     * @return array{reference_type: string|null, reference_id: mixed, kind: string|null, period_start: string|null, period_end: string|null, applied_at: string|null, forgone_at: string|null}
     */
    private function lineReference(array $record): array
    {
        $domain = $this->domains?->line($record);
        if ($domain !== null) {
            return $domain;
        }

        return ['period_start' => null, 'period_end' => null, 'applied_at' => null, 'forgone_at' => null] + $this->serviceLineReference($record);
    }

    /** @return array{reference_type: string|null, reference_id: mixed, kind: string|null} */
    private function serviceLineReference(array $record): array
    {
        $type = (string) $record['type'];
        $relid = (int) $record['relid'];
        $service = match ($type) {
            'Setup', 'PromoHosting' => $relid,
            'Addon' => $this->addonServices()[$relid] ?? null,
            'Upgrade' => $this->upgradeServices()[$relid] ?? null,
            default => null,
        };

        return match (true) {
            $type === 'Hosting' => ['reference_type' => Service::class, 'reference_id' => $record['relid'], 'kind' => null],
            $type === 'AddFunds' => ['reference_type' => Credit::class, 'reference_id' => null, 'kind' => null],
            $service !== null && isset($this->importedServices()[$service]) => ['reference_type' => Service::class, 'reference_id' => $service, 'kind' => InvoiceItem::KIND_CHARGE],
            default => ['reference_type' => null, 'reference_id' => $record['relid'], 'kind' => null],
        };
    }

    /** The domain step (D6): what a domain's invoice line is (null before it ran: a test of the lines alone). */
    private ?WhmcsDomainImport $domains = null;

    /** @var array<int, true>|null the services imported (their WHMCS ids) */
    private ?array $importedServices = null;

    /** @return array<int, true> */
    private function importedServices(): array
    {
        return $this->importedServices ??= array_fill_keys(DB::table('services')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /** @var array<int, int>|null WHMCS product addon (tblhostingaddons.id) => its service */
    private ?array $addonServices = null;

    /** @return array<int, int> */
    private function addonServices(): array
    {
        return $this->addonServices ??= $this->idMap('SELECT id, hostingid FROM tblhostingaddons');
    }

    /** @var array<int, int>|null WHMCS upgrade (tblupgrades.id) of a package or its options => its service */
    private ?array $upgradeServices = null;

    /** @return array<int, int> */
    private function upgradeServices(): array
    {
        return $this->upgradeServices ??= $this->idMap("SELECT id, relid FROM tblupgrades WHERE type IN ('package', 'configoptions')");
    }

    /** @return array<int, int> the first column => the second; none on a WHMCS without the table (an old one, a fixture) */
    private function idMap(string $sql): array
    {
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        } catch (PDOException) {
            return [];
        }
        $map = [];
        foreach ($rows as [$id, $service]) {
            if ((int) $service > 0) {
                $map[(int) $id] = (int) $service;
            }
        }

        return $map;
    }

    private function importPayments()
    {
        $this->info('Importing payments... (' . $this->count('tblaccounts') . ' records)');

        $this->migrateInBatch('tblaccounts', 'SELECT * FROM tblaccounts LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                // A Mass Pay parent's payment lands on its children (importMassPay).
                if (isset($this->massPayParents()[(int) $record['invoiceid']])) {
                    continue;
                }
                // Only money in: a refund's own row is on the payment it gave back (importRefunds); a row of nothing (WHMCS
                // writes one when staff mark a free invoice paid) is no payment.
                if ((float) ($record['amountin'] ?? 0) <= 0) {
                    continue;
                }
                // Money on no invoice imported (WHMCS lets staff record one on none): never a payment of invoice #0.
                if (!isset($this->importedInvoices()[(int) $record['invoiceid']])) {
                    $this->paymentsWithoutInvoice[] = (int) $record['id'];

                    continue;
                }
                // Whose it is, by its invoice (WHMCS leaves a payment's own customer 0 at times).
                $data[] = [
                    'id' => $record['id'],
                    'invoice_id' => $record['invoiceid'],
                    // The gateway it came through (tblaccounts.gateway), for «Payment Method» and the money trail. Not on a
                    // payment WHMCS refunded (to credit, by bank: a row with `refundid`), nor on that refund's row: its
                    // refund is not on it here, and a gateway would offer to refund it again through the gateway
                    // (adversarial ADV-1). Those stay manual: staff refund by hand, as before.
                    'gateway_id' => (float) ($record['amountout'] ?? 0) > 0 || isset($this->refundedPayments()[(int) $record['id']]) ? null
                        : $this->gatewayId((string) ($record['gateway'] ?? '')),
                    'amount' => $record['amountin'],
                    'transaction_id' => $record['transid'],
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['date']),
                ];
            }

            DB::table('invoice_transactions')->insert($data);
        });
        $this->reportUnmappedGateways();
        if ($this->paymentsWithoutInvoice !== []) {
            $this->warn('Payments on no imported invoice, not imported (record them by hand if they are money still owed): WHMCS tblaccounts #'
                . implode(', #', $this->paymentsWithoutInvoice) . '.');
        }
    }

    /** @var list<int> WHMCS payments (tblaccounts) on no imported invoice */
    private array $paymentsWithoutInvoice = [];

    /** @var array<int, true>|null */
    private ?array $importedInvoices = null;

    /** @return array<int, true> the invoices imported */
    private function importedInvoices(): array
    {
        return $this->importedInvoices ??= array_fill_keys(DB::table('invoices')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /**
     * WHMCS's refunds (a tblaccounts row with `amountout`, its payment in `refundid`) on the payment they gave back, after
     * the Mass Pay payments are in and the overpayments placed (journalMoney), up to what is left of the payment:
     * - back to the customer: `refunded_amount`, so it is never given back again (adversarial ADV-1);
     * - to their credit balance (WHMCS «Refund ... to Credit Balance», with «Credit from Refund of Invoice ID N» in
     *   tblcredit): `credited_amount`, as ExtensionHelper::refundToCredit records it: no money left (ADV-523-1).
     * Each one is kept for the journal (WhmcsMoneyJournal::refunds); a refund on no payment here is named.
     */
    private function importRefunds(): void
    {
        $refunds = $this->sourceRows('SELECT id, invoiceid, refundid, amountout, date, description FROM tblaccounts WHERE amountout > 0 ORDER BY date, id')
            ?? $this->sourceRows('SELECT id, invoiceid, refundid, amountout, date FROM tblaccounts WHERE amountout > 0 ORDER BY date, id')
            // A WHMCS without refundid (an old version): money out on no payment, named.
            ?? $this->sourceRows('SELECT id, invoiceid, amountout, date FROM tblaccounts WHERE amountout > 0 ORDER BY date, id') ?? [];
        // WHMCS's own record of a refund to credit, by invoice: «Credit from Refund of Invoice ID N».
        $toCredit = [];
        foreach ($this->sourceRows("SELECT id, date, description, amount FROM tblcredit WHERE amount > 0 AND description LIKE 'Credit from Refund of Invoice ID %' ORDER BY date, id") ?? [] as $row) {
            $toCredit[(int) substr((string) $row['description'], strlen('Credit from Refund of Invoice ID '))][] = ['id' => (int) $row['id'],
                'cents' => WhmcsMassPay::cents($row['amount']), 'day' => substr((string) $row['date'], 0, 10)];
        }
        // ... and of taking back the credit an overpayment gave, to refund it in cash: «Removal of Credit from Invoice #N».
        $removed = [];
        foreach ($this->sourceRows("SELECT id, description, amount FROM tblcredit WHERE amount < 0 AND description LIKE 'Removal of Credit from Invoice #%'") ?? [] as $row) {
            $removed[(int) substr((string) $row['description'], strlen('Removal of Credit from Invoice #'))][] = ['id' => (int) $row['id'], 'cents' => -WhmcsMassPay::cents($row['amount'])];
        }

        foreach ($refunds as $refund) {
            $cents = WhmcsMassPay::cents($refund['amountout']);
            $payment = (int) ($refund['refundid'] ?? 0) > 0
                ? DB::table('invoice_transactions')->where('id', (int) $refund['refundid'])->first(['id', 'invoice_id', 'amount', 'refunded_amount', 'credited_amount'])
                : null;
            if ($payment === null) {
                $this->refundsWithoutPayment[] = (int) $refund['id'];

                continue;
            }
            // WHMCS names the invoice the refund was on (a Mass Pay parent's, not the child the payment landed on here).
            $whmcsInvoice = (int) ($refund['invoiceid'] ?? 0) ?: (int) $payment->invoice_id;
            // Its own words first: «... to Credit Balance» is one, any other description a cash refund (round 2 of #523).
            // With none (an older WHMCS), WHMCS's credit row for its invoice and amount, the same day first, tells.
            $description = trim((string) ($refund['description'] ?? ''));
            $match = null;
            if ($description === '' || str_contains($description, 'to Credit Balance')) {
                $day = substr((string) $refund['date'], 0, 10);
                foreach ($toCredit[$whmcsInvoice] ?? [] as $index => $row) {
                    if ($row['cents'] === $cents && ($match === null || $row['day'] === $day)) {
                        $match = $index;
                        if ($row['day'] === $day) {
                            break;
                        }
                    }
                }
            }
            $credit = $match !== null || str_contains($description, 'to Credit Balance');
            if ($match !== null) {
                $this->creditRowsUsed[] = $toCredit[$whmcsInvoice][$match]['id'];
                unset($toCredit[$whmcsInvoice][$match]);
            }

            $refunded = WhmcsMassPay::cents($payment->refunded_amount);
            $credited = WhmcsMassPay::cents($payment->credited_amount);
            $take = min($cents, WhmcsMassPay::cents($payment->amount) - $refunded - $credited);
            // More than the invoice kept, in cash: WHMCS took the rest back from the credit the payment gave, when it
            // says so; as here, where only what is not credit can be refunded.
            $fromCredit = 0;
            if (!$credit) {
                // A cash refund of credit the payment gave (WHMCS: «Removal of Credit from Invoice #N», to refund it):
                // whenever WHMCS says so, even when what the invoice kept could have taken it (round 3 of #523).
                $room = WhmcsMassPay::cents($payment->amount) - $refunded - $credited;
                foreach ($removed[$whmcsInvoice] ?? [] as $index => $row) {
                    if ($row['cents'] <= min($cents, $credited) && $cents - $row['cents'] <= $room) {
                        $fromCredit = $row['cents'];
                        $this->creditRowsUsed[] = $row['id'];
                        unset($removed[$whmcsInvoice][$index]);
                        $credited -= $fromCredit;
                        $take = $cents;
                        break;
                    }
                }
            }
            if ($take < $cents) {
                $this->warn("Refund #{$refund['id']} gives back " . number_format($cents / 100, 2, '.', '') . " of payment #{$payment->id}, more than is left of it: "
                    . number_format(max(0, $take) / 100, 2, '.', '') . ' recorded. Check it by hand.');
            }
            if ($take <= 0) {
                continue;
            }
            DB::table('invoice_transactions')->where('id', $payment->id)->update($credit
                ? ['credited_amount' => ($credited + $take) / 100] : ['refunded_amount' => ($refunded + $take) / 100, 'credited_amount' => $credited / 100]);
            $this->refunds[] = ['transaction_id' => (int) $payment->id, 'cents' => $take, 'to_credit' => $credit, 'from_credit' => $fromCredit,
                'at' => $this->validateDate($refund['date'])];
        }
        if ($this->refundsWithoutPayment !== []) {
            $this->warn('Money out on no payment imported, not imported (a chargeback or a refund typed by hand in WHMCS): WHMCS tblaccounts #'
                . implode(', #', $this->refundsWithoutPayment) . '.');
        }
    }

    /** @var list<array{transaction_id: int, cents: int, to_credit: bool, from_credit: int, at: ?string}> the refunds imported, for the journal */
    private array $refunds = [];

    /** @var list<int> tblcredit rows a refund to credit stands for (not journaled again from the history) */
    private array $creditRowsUsed = [];

    /** @var list<int> WHMCS money out (tblaccounts) on no payment imported */
    private array $refundsWithoutPayment = [];

    /**
     * Rows of a source query, or null when the WHMCS has not the table or column (an older version, a test fixture).
     *
     * @return list<array<string, mixed>>|null
     */
    private function sourceRows(string $sql): ?array
    {
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (WhmcsMoneyJournal::isMissing($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * WHMCS payment gateway modules (tblaccounts.gateway) and the MyCS gateway extension each one's payments belong to.
     * A bank transfer or any other module MyCS has no extension for: none (a manual payment, as staff record one).
     */
    public const GATEWAYS = [
        'stripe' => 'Stripe', 'stripe_sepa' => 'Stripe', 'stripe_ach' => 'Stripe', 'stripe_bacs' => 'Stripe',
        'paypal' => 'PayPal', 'paypal_ppcpv' => 'PayPal', 'paypalcheckout' => 'PayPal', 'paypal_acdc' => 'PayPal',
        'mollie' => 'Mollie',
        'cardlink' => 'Cardlink', 'cardlink_eurobank' => 'Cardlink', 'eurobank' => 'Cardlink',
    ];

    /** @var array<int, true>|null WHMCS payments with a refund row (tblaccounts.refundid) */
    private ?array $refundedPayments = null;

    /** @return array<int, true> */
    private function refundedPayments(): array
    {
        if ($this->refundedPayments === null) {
            try {
                $ids = $this->pdo->query('SELECT DISTINCT refundid FROM tblaccounts WHERE refundid > 0')->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException) {
                $ids = []; // a WHMCS without refunds' column (an old version, a test fixture)
            }
            $this->refundedPayments = array_fill_keys(array_map('intval', $ids), true);
            if ($this->refundedPayments !== []) {
                $this->warn('Payments: ' . count($this->refundedPayments) . ' refunded in WHMCS imported without a gateway (their refunds are not on them here): refund nothing more of them through a gateway.');
            }
        }

        return $this->refundedPayments;
    }

    /** @var array<string, int|null> WHMCS module => MyCS gateway id */
    private array $gatewayIds = [];

    /** @var array<string, int> payments of a module imported without a gateway, by module: the summary */
    private array $unmappedGateways = [];

    private function gatewayId(string $module): ?int
    {
        $module = strtolower(trim($module));
        if (!array_key_exists($module, $this->gatewayIds)) {
            $extension = self::GATEWAYS[$module] ?? (str_starts_with($module, 'mollie') ? 'Mollie' : null);
            $this->gatewayIds[$module] = $extension === null ? null : $this->installedGateway($extension);
        }
        if ($this->gatewayIds[$module] === null && $module !== '') {
            $this->unmappedGateways[$module] = ($this->unmappedGateways[$module] ?? 0) + 1;
        }

        return $this->gatewayIds[$module];
    }

    /**
     * The gateway extension's id, installed as the admin's «Install» does when the fresh database has none (disabled:
     * staff set its keys and enable it). Its payments are its own from the start.
     */
    private function installedGateway(string $extension): int
    {
        $id = DB::table('extensions')->where('type', 'gateway')->where('extension', $extension)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $gateway = Extension::create(['name' => $extension, 'type' => 'gateway', 'extension' => $extension]);
        ExtensionHelper::call($gateway, 'installed', mayFail: true);
        $this->warn("Gateway «{$extension}» installed for the imported payments: set it up and enable it.");

        return (int) $gateway->id;
    }

    /** Payments brought without a gateway: MyCS has no extension for their module (a bank transfer, ...). */
    private function reportUnmappedGateways(): void
    {
        foreach ($this->unmappedGateways as $module => $count) {
            $this->warn("Payments: {$count} of gateway «{$module}» imported without a gateway (MyCS has none for it): «Payment Method» shows them as manual.");
        }
    }

    /**
     * WHMCS promotions (tblpromotions) as coupons, with their ids (tblhosting.promoid). One MyCS cannot express as WHMCS
     * applies it (a price override, some billing cycles only, new or existing clients only, «requires», once per order,
     * recurring for N times) is imported closed for new orders (expired now) and reported: staff recreate it. The
     * services ordered with it keep it, for the record (serviceCoupon).
     */
    /** @var array<int, array{recurring: int, recurfor: int, code: string}> the promotions imported, by id: serviceCoupon */
    private array $promotions = [];

    private function importPromotions(): void
    {
        $this->info('Importing promotions... (' . $this->count('tblpromotions') . ' records)');
        $now = now()->toDateTimeString();
        $products = DB::table('products')->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $currencies = (int) $this->pdo->query('SELECT COUNT(*) FROM tblcurrencies')->fetchColumn();
        $closed = now()->subSecond()->toDateTimeString(); // past already: closed from the import on

        foreach ($this->pdo->query('SELECT * FROM tblpromotions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $promo) {
            $why = [];
            [$type, $value, $appliesTo] = match ($promo['type']) {
                'Percentage' => ['percentage', $promo['value'], 'all'],
                'Fixed Amount' => ['fixed', $promo['value'], 'all'],
                'Free Setup' => ['percentage', '100.00', 'setup_fee'],
                default => ['fixed', '0.00', 'all'],
            };
            if (!in_array($promo['type'], ['Percentage', 'Fixed Amount', 'Free Setup'], true)) {
                $why[] = "type «{$promo['type']}»";
            }
            $recurring = (int) $promo['recurring'] === 0 ? null : 0; // the first payment only, or every one
            if ((int) $promo['recurring'] === 1 && (int) $promo['recurfor'] > 0) {
                $why[] = "recurring for {$promo['recurfor']} times";
            }
            $cycles = array_filter(array_map('trim', explode(',', (string) $promo['cycles'])));
            // MyCS has no cycle limit: one WHMCS limits (a list without every cycle, «One Time» too) is not expressible.
            if ($cycles !== [] && array_diff(['Monthly', 'Quarterly', 'Semi-Annually', 'Annually', 'Biennially', 'Triennially', 'One Time'], $cycles) !== []) {
                $why[] = 'some billing cycles only (' . implode(', ', $cycles) . ')';
            }
            // A MyCS coupon has no currency: a fixed amount means another sum in each one (WHMCS converts it).
            if ($promo['type'] === 'Fixed Amount' && $currencies > 1) {
                $why[] = 'a fixed amount with more than one currency';
            }
            foreach (['newsignups' => 'new clients only', 'existingclient' => 'existing clients only', 'requiresexisting' => 'requires an existing product',
                'applyonce' => 'once per order', 'upgrades' => 'for upgrades', 'lifetimepromo' => 'kept on upgrades'] as $column => $label) {
                if ((int) ($promo[$column] ?? 0) === 1) {
                    $why[] = $label;
                }
            }
            if (trim((string) ($promo['requires'] ?? '')) !== '') {
                $why[] = 'requires other products';
            }
            $applies = array_filter(array_map('trim', explode(',', (string) $promo['appliesto'])));
            $productIds = array_values(array_filter(array_map('intval', array_filter($applies, 'ctype_digit')), fn (int $id) => $products->has($id)));
            if (count($productIds) !== count($applies)) {
                $why[] = 'addons, domains or products not imported';
            }
            // No products in MyCS means every product (ADV-3): one WHMCS applies to nothing stays closed.
            if ($applies === []) {
                $why[] = 'applies to no product';
            }
            // Its uses: MyCS counts the services with it, WHMCS counted more (deleted services, orders). Used up there:
            // closed; else the uses left there on top of those brought here (ADV-2).
            $maxUses = null;
            if ((int) $promo['maxuses'] > 0) {
                $left = (int) $promo['maxuses'] - (int) ($promo['uses'] ?? 0);
                if ($left <= 0) {
                    $why[] = "used up ({$promo['uses']} of {$promo['maxuses']})";
                }
                $statement = $this->pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM tblhosting WHERE promoid = ?');
                $statement->execute([$promo['id']]);
                $maxUses = (int) $statement->fetchColumn() + max(0, $left);
            }

            // WHMCS's expiration date is its last day (ADV-4).
            $expires = ($day = $this->validateDate($promo['expirationdate'] ?? null)) === null ? null
                : Carbon::parse($day)->endOfDay()->toDateTimeString();
            DB::table('coupons')->insert([
                'id' => $promo['id'],
                'code' => $promo['code'],
                'type' => $type,
                'value' => $value,
                'applies_to' => $appliesTo,
                'recurring' => $recurring,
                'max_uses' => $maxUses,
                'max_uses_per_user' => (int) $promo['onceperclient'] === 1 ? 1 : null,
                'starts_at' => $this->validateDate($promo['startdate'] ?? null),
                // Closed for new orders when MyCS would apply it otherwise than WHMCS.
                'expires_at' => $why !== [] && ($expires === null || $expires > $now) ? $closed : $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->promotions[(int) $promo['id']] = ['recurring' => (int) $promo['recurring'], 'recurfor' => (int) $promo['recurfor'], 'code' => (string) $promo['code']];
            if ($productIds !== []) {
                DB::table('coupon_products')->insert(array_map(fn (int $id) => ['coupon_id' => $promo['id'], 'product_id' => $id], $productIds));
            }
            if ($why !== []) {
                $this->warn("Promotion #{$promo['id']} «{$promo['code']}»: imported closed for new orders (" . implode('; ', $why) . '): recreate it by hand if still offered.');
            }
        }
    }

    /**
     * A service's promotion, used up: WHMCS's recurring amount (`price`) has its discount already, and a coupon still
     * running would be taken off again or the price recalculated from the catalog when it ends. One recurring for N
     * times may not have ended yet: reported, staff set the price when it does.
     *
     * @param  array<string, mixed>  $record  a tblhosting row
     * @return array{coupon_id: int|null, coupon_started_at: string|null, coupon_expired_at: string|null}
     */
    private function serviceCoupon(array $record): array
    {
        $promo = (int) ($record['promoid'] ?? 0);
        $row = $this->promotions[$promo] ?? null;
        if ($row === null) {
            return ['coupon_id' => null, 'coupon_started_at' => null, 'coupon_expired_at' => null];
        }

        return ['coupon_id' => $promo, 'coupon_started_at' => $this->validateDate($record['regdate']), 'coupon_expired_at' => now()->toDateTimeString()];
    }

    /**
     * A live service whose promotion recurs for N times and has not (tblhosting.promocount) recurred all of them: WHMCS
     * would charge the full price after them; here its price stays (code review F4). Reported, and written on the
     * service's staff notes, where staff see it.
     *
     * @param  array<string, mixed>  $record  a tblhosting row
     */
    private function promotionStillRunning(array $record): ?string
    {
        $promo = $this->promotions[(int) ($record['promoid'] ?? 0)] ?? null;
        if ($promo === null || $promo['recurring'] !== 1 || $promo['recurfor'] <= 0
            || !in_array($record['domainstatus'], ['Active', 'Suspended', 'Pending'], true)) {
            return null;
        }
        $done = isset($record['promocount']) ? (int) $record['promocount'] : null; // older WHMCS: unknown
        if ($done !== null && $done >= $promo['recurfor']) {
            return null;
        }
        $left = $done === null ? "up to {$promo['recurfor']}" : (string) ($promo['recurfor'] - $done);
        $note = "WHMCS promotion «{$promo['code']}» still running at import ({$left} more discounted renewals): the price {$record['amount']} was kept; set the full price when it ends.";
        $this->warn("Service #{$record['id']}: {$note}");

        return $note;
    }

    /**
     * @var array<int, true>|null WHMCS Mass Pay parents: invoices whose lines are all of type «Invoice», with at least one
     *                            child of the same customer that is an invoice of its own (not a parent itself), paid or
     *                            holding no money. Any other is imported as an invoice like any other, so no payment is lost
     *                            and no line is dropped: its children all deleted, a child of another customer only, nested
     *                            parents, another kind of line on it, or an unpaid or cancelled one that took a payment
     *                            (F3 review CR-3, adversarial F3-ADV-4/5/6).
     */
    private ?array $massPayParents = null;

    private ?array $overdueServices = null;

    /**
     * The services with an unpaid WHMCS invoice line (Hosting, PromoHosting) past its due date: what its cron suspends for
     * (AUD9-3). Unpaid as invoiceStatus() reads it: Collections and Payment Pending too (ADV-531-1). A source without the invoice tables (a test's): none.
     *
     * @return array<int, true>
     */
    private function overdueServices(): array
    {
        if ($this->overdueServices !== null) {
            return $this->overdueServices;
        }
        if (!$this->sourceHas('tblinvoiceitems') || !$this->sourceHas('tblinvoices')) {
            return $this->overdueServices = [];
        }
        $query = $this->pdo->prepare("SELECT DISTINCT ii.relid FROM tblinvoiceitems ii JOIN tblinvoices i ON i.id = ii.invoiceid
            WHERE ii.type IN ('Hosting', 'PromoHosting') AND i.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded') AND i.duedate > '0000-00-00' AND i.duedate < :today");
        $query->execute(['today' => now()->toDateString()]);

        return $this->overdueServices = array_fill_keys(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** @return array<int, true> */
    private function massPayParents(): array
    {
        return $this->massPayParents ??= array_fill_keys(array_map('intval', $this->pdo->query(WhmcsMassPay::PARENTS_SQL)->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * WHMCS Mass Pay parents (PR F3, WhmcsMassPay): not invoices here. A paid one's payment lands on its child due first,
     * the rest pays the other children as allocations of it, and what it took from account credit pays what is left; it
     * stays a paid payment group with its WHMCS id. A cancelled one is dropped (its children are invoices on their own).
     */
    private function importMassPay(): void
    {
        $parentIds = array_keys($this->massPayParents());
        $this->info('Importing Mass Pay parents... (' . count($parentIds) . ' records)');

        // In the order they were paid: a child in two paid parents (a customer chose it again) takes the credit WHMCS
        // applied to it once, from the first (F3 review CR-2); what a later one cannot place stays its payment's credit.
        $parents = array_map(fn (int $id) => $this->row('SELECT id, userid, status, total, credit, date, datepaid FROM tblinvoices WHERE id = ?', [$id]), $parentIds);
        $parents = array_values(array_filter($parents, fn (?array $parent) => $parent !== null && $parent['status'] === 'Paid'));
        usort($parents, fn (array $a, array $b) => [(string) $a['datepaid'], (int) $a['id']] <=> [(string) $b['datepaid'], (int) $b['id']]);
        $childCredit = &$this->childCredit;
        // Allocations get ids above every WHMCS payment: a later parent keeps its payment's id (F3 review CR-1).
        $nextId = max((int) $this->pdo->query('SELECT MAX(id) FROM tblaccounts')->fetchColumn(), (int) DB::table('invoice_transactions')->max('id')) + 1;

        foreach ($parents as $parent) {
            $parentId = (int) $parent['id'];
            $payments = $this->rows('SELECT id, amountin, amountout, transid, date FROM tblaccounts WHERE invoiceid = ? ORDER BY date, id', [$parentId]);
            $currency = $this->row('SELECT code FROM tblcurrencies WHERE id = (SELECT currency FROM tblclients WHERE id = ? LIMIT 1) LIMIT 1', [$parent['userid']]);
            if ($currency === null) {
                continue;
            }

            $lines = array_map(fn (array $line) => ['child' => (int) $line['relid'], 'amount' => WhmcsMassPay::cents($line['amount'])],
                $this->rows("SELECT relid, amount FROM tblinvoiceitems WHERE invoiceid = ? AND type = 'Invoice' ORDER BY id", [$parentId]));
            $children = [];
            foreach ($lines as $line) {
                $child = $this->row('SELECT id, userid, status, duedate, credit FROM tblinvoices WHERE id = ?', [$line['child']]);
                // Only a child of the same customer that was imported takes a share: never another customer's invoice
                // (inconsistent WHMCS data, F3 adversarial F3-ADV-3), never a parent that is not an invoice here.
                if ($child !== null && (int) $child['userid'] === (int) $parent['userid'] && DB::table('invoices')->where('id', $child['id'])->exists()) {
                    $childCredit[(int) $child['id']] ??= WhmcsMassPay::cents($child['credit']);
                    $children[(int) $child['id']] = ['status' => (string) $child['status'],
                        'duedate' => $this->validateDate($child['duedate']), 'credit' => $childCredit[(int) $child['id']]];
                }
            }
            $money = array_values(array_filter($payments, fn (array $payment) => (float) $payment['amountin'] > 0));

            $plan = WhmcsMassPay::plan(
                // WHMCS keeps the total net of the credit applied to it (F3 review CR-4): its lines add up to both.
                ['id' => $parentId, 'total' => WhmcsMassPay::cents($parent['total']) + WhmcsMassPay::cents($parent['credit']),
                    'credit' => WhmcsMassPay::cents($parent['credit'])],
                $lines, $children,
                array_map(fn (array $payment) => ['id' => (int) $payment['id'], 'amount' => WhmcsMassPay::cents($payment['amountin']),
                    'date' => $this->validateDate($payment['date'])], $money),
            );
            foreach ($plan['warnings'] as $warning) {
                $this->warn($warning);
            }
            if ($plan['group']['invoices'] === []) {
                continue;
            }
            // What this parent took of each child's applied credit is not there for another parent.
            $byPayment = array_column($money, 'amountin', 'id');
            foreach ($plan['payments'] as $planned) {
                $childCredit[$planned['invoice_id']] -= WhmcsMassPay::cents($byPayment[$planned['id']]) - $planned['credited'];
            }
            foreach ($plan['allocations'] as $allocation) {
                $childCredit[$allocation['invoice_id']] -= $allocation['amount'];
            }

            $byId = array_column($money, null, 'id');
            DB::table('invoice_transactions')->insert(array_map(fn (array $planned) => [
                'id' => $planned['id'],
                'invoice_id' => $planned['invoice_id'],
                'amount' => WhmcsMassPay::cents($byId[$planned['id']]['amountin']) / 100,
                'credited_amount' => $planned['credited'] / 100,
                'transaction_id' => $byId[$planned['id']]['transid'],
                'created_at' => $this->validateDate($byId[$planned['id']]['date']),
                'updated_at' => $this->validateDate($byId[$planned['id']]['date']),
            ], $plan['payments']));
            DB::table('invoice_transactions')->insert(array_map(function (array $allocation) use (&$nextId, $parent) {
                // Dated by the parent's day alone (no payment, no datepaid): the journal orders it as such (ADV-528-2).
                if ($allocation['date'] === null && $this->validateDate($parent['datepaid']) === null) {
                    $this->dayOnly[] = $nextId;
                }

                return [
                    'id' => $nextId++,
                    'invoice_id' => $allocation['invoice_id'],
                    'amount' => $allocation['amount'] / 100,
                    'is_credit_transaction' => true,
                    'source_transaction_id' => $allocation['source_id'],
                    // The parent's own credit (no payment): when it was paid, else its day (review of #523).
                    'created_at' => $allocation['date'] ?? $this->validateDate($parent['datepaid']) ?? $this->validateDate($parent['date']),
                    'updated_at' => $allocation['date'] ?? $this->validateDate($parent['datepaid']) ?? $this->validateDate($parent['date']),
                ];
            }, $plan['allocations']));

            $groupId = DB::table('payment_groups')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'client_id' => $parent['userid'],
                'currency_code' => $currency['code'],
                'invoice_id' => $plan['group']['invoice_id'],
                'total' => $plan['group']['total'] / 100,
                'status' => PaymentGroup::STATUS_PAID,
                'invoice_transaction_id' => $plan['group']['transaction_id'],
                'whmcs_invoice_id' => $parentId,
                'created_at' => $this->validateDate($parent['date']),
                'updated_at' => $this->validateDate($parent['date']),
            ]);
            DB::table('payment_group_invoice')->insert(array_map(fn (array $invoice, int $position) => [
                'payment_group_id' => $groupId,
                'invoice_id' => $invoice['invoice_id'],
                'position' => $position,
                'amount' => $invoice['amount'] / 100,
            ], $plan['group']['invoices'], array_keys($plan['group']['invoices'])));
        }
    }

    /** @var list<int> transactions dated by a day only (importMassPay), for the journal's order */
    private array $dayOnly = [];

    /** @var array<int, int> Mass Pay child => cents of its WHMCS credit its parents did not pay (importMassPay) */
    private array $childCredit = [];

    /**
     * The money journal (WhmcsMoneyJournal): the credit applied to each invoice as a credit payment, overpayments as
     * credit, then every payment, refund and credit move as an event, so the import passes the money checks. Customers
     * whose WHMCS balance is not what its history adds up to get one adjustment each, listed here.
     */
    private function journalMoney(): void
    {
        $this->info('Writing the money journal...');
        $journal = new WhmcsMoneyJournal($this->pdo);
        $journal->creditApplied($this->massPayParents(), $this->childCredit, $this->importedInvoices(), $this->dayOnly);
        $journal->overpayments();
        // After the overpayments: a refund takes what the invoice kept, never the credit the payment already gave.
        $this->importRefunds();
        $journal->refunds($this->refunds, $this->creditRowsUsed);
        $journal->refundNotes();
        $adjustments = $journal->write();

        $summary = $journal->summary;
        $this->line("Payments {$summary['payments']} (of credit {$summary['credit_payments']}), refunds {$summary['refunds']}, overpayments {$summary['overpayments']},"
            . " credit purchases {$summary['purchases']}, negative invoices {$summary['negative_invoices']}, refund notes {$summary['refund_notes']}, other credit moves from WHMCS's history {$summary['from_history']}.");
        if ($summary['no_invoice'] > 0) {
            $this->warn("Payments without an imported invoice, not journaled: {$summary['no_invoice']}.");
        }
        if ($summary['no_money'] > 0) {
            $this->warn("Payments of no money, not journaled: {$summary['no_money']}.");
        }
        foreach ($summary['unplaced'] as $unplaced) {
            $this->warn("Overpayment not placed: {$unplaced}.");
        }
        if ($adjustments === []) {
            return;
        }
        $this->warn(count($adjustments) . ' customer(s) whose WHMCS credit balance is not what its history adds up to: their balance is kept,'
            . ' with one adjustment each (cause opening_balance). Check them, most recent first:');
        $money = fn (int $cents) => number_format($cents / 100, 2, '.', '');
        $this->table(['Customer', 'Currency', 'Balance', 'History', 'Adjustment', 'Last move'], array_map(fn (array $row) => [
            $row['client_id'], $row['currency_code'], $money($row['balance']), $money($row['history']),
            $money($row['balance'] - $row['history']), $row['last'] ?? '',
        ], $adjustments));
    }

    /** @return array<string, mixed>|null */
    private function row(string $sql, array $bindings): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, array $bindings): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
