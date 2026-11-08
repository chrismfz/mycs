<?php

namespace App\Console\Commands;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Credit;
use App\Models\CronStat;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Providers\SettingsProvider;
use App\Services\Billing\ClientGroupPricing;
use App\Services\Import\WhmcsAddonImport;
use App\Services\Import\WhmcsAnnouncementImport;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsContactImport;
use App\Services\Import\WhmcsCredentialImport;
use App\Services\Import\WhmcsCustomFieldImport;
use App\Services\Import\WhmcsDomainImport;
use App\Services\Import\WhmcsEkdosiContactImport;
use App\Services\Import\WhmcsEmailHistoryImport;
use App\Services\Import\WhmcsKnowledgebaseImport;
use App\Services\Import\WhmcsMassPay;
use App\Services\Import\WhmcsMoneyJournal;
use App\Services\Import\WhmcsNoteImport;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Import\WhmcsProxmoxImport;
use App\Services\Import\WhmcsSecondFactor;
use App\Services\Import\WhmcsServiceLabelImport;
use App\Services\Import\WhmcsServicePasswordImport;
use App\Services\Import\WhmcsSpamFilterImport;
use App\Services\Import\WhmcsTaxImport;
use App\Services\Import\WhmcsTicketImport;
use App\Services\Import\WhmcsWelcomeSnippetImport;
use App\Services\Invoice\PaymentReminders;
use App\Services\Licensing\WhmcsLicensingImport;
use App\Services\Tax\EuVat;
use App\Services\Whmcs\CatalogImport;
use App\Services\Whmcs\RolePermissions;
use App\Support\Locales;
use Closure;
use DB;
use Illuminate\Console\Command;
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
        {--ticket-attachments=* : A copy of a WHMCS folder with ticket files, once per folder (its storage folder and attachments/)}
        {--whmcs-api-url= : WHMCS\'s URL, for what only its API reads (saved payment methods, service passwords); its credentials in WHMCS_API_IDENTIFIER, WHMCS_API_SECRET}
        {--with-logins : also bring the logins of the servers, registrars and gateways (a database holding them must not run a cron or queue by accident)}';

    /** WHMCS's SSL modules with no extension here → their provider: kept on an Offline SSL server, by hand. */
    public const OFFLINE_SSL_MODULES = ['thesslstorefullv2' => 'TheSSLStore', 'thesslstore' => 'TheSSLStore'];

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

        // A Mass Pay whose debit is still on its way: refused, as the other pre-dump conditions (cutover-runbook.md), never
        // placed over its children by a rule of the importer's own (owner's decision after review 2 of #697, «never bill
        // wrongly»). Checked on a dry run too, so a rehearsal names them.
        if (($pending = $this->pendingMassPay()) !== []) {
            $this->error('WHMCS Mass Pay invoice(s) in «Payment Pending»: ' . implode('; ', array_map(fn (int $parent, array $children) => "#{$parent}"
                . ($children === [] ? ' (no child)' : ' (children #' . implode(', #', $children) . ')'), array_keys($pending), $pending))
                . '. Their debit is still on its way: wait for it to clear (or fail) in WHMCS and dump again. Nothing done.');

            return self::FAILURE;
        }
        // An old unpaid order (owner's decision 2026-11-08, sweep 4 C-FIRST-RUN-1): the first cron would pay it with the
        // customer's credit and set the service up or buy the name at the registrar. Cancelled in WHMCS before the dump.
        if (($orders = $this->abandonedOrders()) !== []) {
            $this->error('WHMCS unpaid order invoice(s) older than ' . self::ABANDONED_ORDER_DAYS . ' days: #' . implode(', #', $orders)
                . '. Credit would pay them on the first cron and set them up: cancel them in WHMCS and dump again. Nothing done.');

            return self::FAILURE;
        }
        // A renewal WHMCS invoiced and staff cancelled, its nextinvoicedate moved past it (owner's decision 2026-11-08, sweep 5,
        // replacing sweep 4's C-FIRST-RUN-2 rule): settled in WHMCS before the dump, never guessed here (cutover-runbook.md).
        if (($cancelled = $this->cancelledRenewals()) !== []) {
            $this->error('WHMCS live service(s) whose renewal invoice was cancelled and next invoice date moved past it: #' . implode(', #', $cancelled)
                . '. Bill that period again (next invoice date back to the due date) or give it away (due date to the next invoice date) in WHMCS'
                . ' (cutover-runbook.md), and dump again. Nothing done.');

            return self::FAILURE;
        }

        // An addon always shares its parent's due date here (docs/addons.md, owner 2026-11-08): a live recurring one off its
        // service's billing day is refused, as the other pre-dump conditions; staff align it in WHMCS (cutover-runbook.md) and dump again.
        if (($apart = $this->addonsApart()) !== []) {
            $this->error('WHMCS addon(s) off their service\'s billing day: #' . implode(', #', $apart)
                . '. An addon shares its service\'s due date here: set its next due date in WHMCS to the service\'s, or for a shorter cycle to the service\'s day within its cycle (cutover-runbook.md), and dump again. Nothing done.');

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
        // A folder of ticket files that is not there would leave every file of it «missing» (review of #668).
        foreach ((array) $this->option('ticket-attachments') as $folder) {
            if (is_string($folder) && $folder !== '' && !is_dir($folder)) {
                $this->error("No folder {$folder} (--ticket-attachments).");

                return self::FAILURE;
            }
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
            $this->importMarketingConsent();
            $this->importAdmins();
            $this->importServers();
            // After the servers: each product takes its group and server from them (WG-2).
            $this->importCatalog();
            $this->importPromotions();
            $this->importTickets();
            $this->importKnowledgebase();
            $this->importOrders();
            $this->importServices();
            // After the services and the catalog's options (WG-11).
            $this->importServiceOptions();
            // After the services and the catalog's reseller products (WG-16).
            $this->markCpanelResellers();
            // After the services (their parents) and the orders, before the notes and the invoice lines (docs/addons.md, step 4).
            $this->importAddons();
            $this->importOfflineSsl();
            $this->importOfflineDedicated();
            $this->importDomains();
            $this->importEkdosiContacts();
            $this->importContacts();
            $this->importLicensing();
            // After the licensing: a licensing product's fields are its import's (ADV-544-6).
            $this->importCustomFields();
            $this->importNotes();
            $this->importWelcomeSnippets();
            $this->importEmailHistory();
            $this->importAnnouncements();
            $this->importTax();
            $this->importServiceLabels();
            $this->importSpamFilters();
            $this->importProxmoxVms();
            $this->importCancellations();
            $this->importInvoices();
            $this->importReminderState();
            $this->importInvoiceItems();
            $this->importPayments();
            $this->importMassPay();
            $this->importPaymentWays();
            $this->importInvoicePreferences();
            $this->importItemPaymentMethods();
            $this->journalMoney();
            // Last: over the network; a method that fails is skipped, never the money above (R5 of #538).
            $this->importPayMethods();
            $this->importServicePasswords();
            $this->importCredentials();

            DB::statement('SET foreign_key_checks=1');

            // Done: the cron may run again (its first daily run at its time; the catch-up never starts one over a database
            // with no run of its own: CronJob::catchUp).
            DB::table('settings')->where('key', self::RUNNING)->where('settingable_type', CronStat::class)->delete();
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

    /**
     * A WHMCS invoice's status here; parity maps it the same way (anything else, e.g. «Payment Pending», is unpaid: its
     * payment on its way is a processing transaction, importPendingPayments).
     */
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

    /** WHMCS's service statuses (tblhosting.domainstatus) here; parity maps them the same way (WhmcsRecordParity). */
    public const SERVICE_STATUSES = ['Active' => 'active', 'Suspended' => 'suspended', 'Terminated' => 'terminated', 'Cancelled' => 'cancelled',
        'Fraud' => 'cancelled', 'Pending' => 'pending'];

    /** A WHMCS service's status here: one it does not name is pending (parity reports it). */
    public static function serviceStatus(string $whmcs): string
    {
        return self::SERVICE_STATUSES[$whmcs] ?? 'pending';
    }

    /**
     * The plan a WHMCS «Free Account» goes on when its product has no Free plan: the product's first (its order here: sort,
     * then id). Null: a product with no plan at all (parity reports the service).
     */
    public static function freeAccountPlan(int $productId): ?int
    {
        $id = DB::table('plans')->where('priceable_id', $productId)->where('priceable_type', Product::class)->orderBy('sort')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** The plan a WHMCS billing cycle (tblhosting.billingcycle) is, by name (the catalog's); parity maps it the same way. */
    public static function planName(string $cycle): string
    {
        return match ($cycle) {
            'Monthly' => 'Monthly',
            'Quarterly' => 'Quarterly',
            'Semi-Annually' => 'Semiannually',
            'Annually' => 'Annually',
            'Biennially' => 'Biennially',
            'Triennially' => 'Triennially',
            'One Time' => 'One-Time',
            'Free Account' => 'Free',
            default => 'Monthly',
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

    /**
     * This instance's own settings, as mycs:install stored them (Install::saveSettings): kept over the import's reset. The
     * seed would put them back to the code's defaults (UTC, http://localhost), which then override .env (SettingsProvider:
     * app.timezone, app.url, date_default_timezone_set): sweep F-CUTOVER-DAY-2. What the import brings of them (the default
     * currency) is written after, and wins.
     */
    public const INSTANCE_SETTINGS = ['company_name', 'app_url', 'app_language', 'allowed_languages', 'admin_language', 'timezone', 'default_currency'];

    /**
     * While an import runs (from its reset to its end), and after one that failed: the cron does nothing over a database
     * half imported (CronJob, sweep F-CUTOVER-DAY-1). A CronStat setting, in the database the import fills: written right
     * after its reset, removed when it ends.
     */
    public const RUNNING = 'whmcs_import_running_at';

    /** When the import that is running (or failed) started; null when none. */
    public static function running(): ?string
    {
        try {
            $value = DB::table('settings')->where('key', self::RUNNING)->where('settingable_type', CronStat::class)->value('value');
        } catch (Throwable) {
            return null; // not migrated yet
        }

        return $value !== null ? (string) $value : null;
    }

    private function prepareDatabase()
    {
        $kept = Schema::hasTable('settings')
            ? DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->whereIn('key', self::INSTANCE_SETTINGS)
                ->get(['key', 'value', 'type', 'encrypted'])->all()
            : [];
        // Rerun migrations
        $this->call('migrate:fresh', ['--force' => true]);
        // Seed default data
        $this->call('db:seed', ['--force' => true]);
        foreach ($kept as $setting) {
            DB::table('settings')->updateOrInsert(['key' => $setting->key, 'settingable_type' => null, 'settingable_id' => null],
                ['value' => $setting->value, 'type' => $setting->type, 'encrypted' => $setting->encrypted, 'updated_at' => now()]);
        }
        DB::table('settings')->updateOrInsert(['key' => self::RUNNING, 'settingable_type' => CronStat::class, 'settingable_id' => null],
            ['value' => now()->toDateTimeString(), 'type' => 'string', 'created_at' => now(), 'updated_at' => now()]);
        SettingsProvider::flushCache();
    }

    /** A service's recurring amount, never below zero (ADV-551-3: a negative one renewed free); staff are told. */
    private function recurringAmount(array $record): string
    {
        $amount = number_format((float) $record['amount'], 2, '.', '');
        if ((float) $amount < 0) {
            $this->warn("WHMCS service #{$record['id']}: its recurring amount {$amount} is below zero; imported as 0.00, set its price.");

            return '0.00';
        }

        return $amount;
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
                $this->failBatch($table, $th);
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
                    $this->failBatch($table, $th);
                }

                $bar->advance();
            }

            $bar->finish();
        }

        $this->info('Done.');
    }

    /**
     * A batch that failed stops the import. A query's message is never printed: it is the whole statement with its values
     * (a users batch's emails and password hashes, a clients batch's contacts and tax ids), only its SQLSTATE, as handle()
     * does (sweep IMPORT-3). The full one goes to the application log.
     */
    private function failBatch(string $table, Throwable $th): void
    {
        Log::error($th);
        if ($th instanceof PDOException) { // QueryException is one
            $this->fail("Import of {$table} failed (SQLSTATE " . $th->getCode() . '): see the application log.');
        }
        $this->fail($th->getMessage());
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

        $this->migrateInBatch('tblcurrencies', 'SELECT * FROM tblcurrencies ORDER BY id LIMIT :limit OFFSET :offset', function ($records) {
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
            . " 'CreateInvoiceDaysBeforeTriennially', 'AddFundsEnabled', 'CreditOnDowngrade', 'AddFundsMinimum', 'AddFundsMaximum',"
            . " 'AddFundsMaximumBalance', 'OrderDaysGrace', 'EnableTOSAccept', 'TermsOfService', 'InvoicePayTo')")->fetchAll(PDO::FETCH_KEY_PAIR);
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
        // An amount as WHMCS keeps it («5000.00»), to two decimals, without float. 0 is none, kept as MyCS's own: a maximum
        // of 0 would refuse every deposit, a minimum of 0 let one of nothing through (ADV-648-3).
        $money = function (string $key) use ($config): ?string {
            $value = trim((string) ($config[$key] ?? ''));
            if (!preg_match('/^\d{1,9}(\.\d+)?$/', $value)) {
                return null;
            }
            $value = bcadd($value, '0', 2);

            return bccomp($value, '0', 2) <= 0 ? null : $value;
        };
        $deposits = ['credits_minimum_deposit' => $money('AddFundsMinimum'), 'credits_maximum_deposit' => $money('AddFundsMaximum'),
            'credits_maximum_credit' => $money('AddFundsMaximumBalance')];
        // Only as a whole that makes sense: a minimum above the maximum would refuse every deposit (WG-3). The pair as it
        // would be, one not imported being MyCS's own (review of #648: WHMCS's minimum 200 beside MyCS's maximum 100).
        $minimum = $deposits['credits_minimum_deposit'] ?? (string) config('settings.credits_minimum_deposit', 5);
        $maximum = $deposits['credits_maximum_deposit'] ?? (string) config('settings.credits_maximum_deposit', 100);
        if (($deposits['credits_minimum_deposit'] !== null || $deposits['credits_maximum_deposit'] !== null)
            && is_numeric($minimum) && is_numeric($maximum) && bccomp($minimum, $maximum, 2) > 0) {
            $this->warn("The minimum deposit {$minimum} would be above the maximum {$maximum}: both kept as MyCS's own.");
            $deposits['credits_minimum_deposit'] = $deposits['credits_maximum_deposit'] = null;
        }
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
        // The terms the cart and the sign-up ask to accept (WG-7: both companies have it on, with a URL): a web page only,
        // as the Settings page takes it (review of #330); WHMCS's off is none here.
        $tosUrl = trim((string) ($config['TermsOfService'] ?? ''));
        $tosValid = strlen($tosUrl) <= 255 && filter_var($tosUrl, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($tosUrl, PHP_URL_SCHEME)), ['http', 'https'], true);
        $tos = !$has('EnableTOSAccept') ? null : (!$on('EnableTOSAccept') ? ['string', ''] : ($tosValid ? ['string', $tosUrl] : null));
        if ($on('EnableTOSAccept') && !$tosValid) {
            $this->warn("Terms of Service: WHMCS asks for them, but «{$tosUrl}» is no web address: set the tos setting by hand.");
        }
        $invoiceDays = $days('CreateInvoiceDaysBefore');
        // A monthly plan's own (WG-14, owner 2026-11-05): WHMCS's «Monthly» when it has one above 0 (myip 10), else none
        // (as Invoice Generation; nixpal).
        $monthlyDays = ($m = $days('CreateInvoiceDaysBeforeMonthly')) !== null && (int) $m > 0 ? $m : null;
        $settings = [
            'cronjob_suspend_enabled' => $has('AutoSuspension') ? ['boolean', $on('AutoSuspension') ? '1' : '0'] : null,
            'cronjob_order_suspend' => ($v = $days('AutoSuspensionDays')) !== null ? ['string', $v] : null,
            'cronjob_terminate_enabled' => $has('AutoTermination') ? ['boolean', $on('AutoTermination') ? '1' : '0'] : null,
            'cronjob_order_terminate' => ($v = $days('AutoTerminationDays')) !== null ? ['string', $v] : null,
            // WHMCS's general one for every cycle but a monthly plan's (WG-14, owner 2026-11-05: myip 14, its quarterly 15
            // and longer cycles' 20 not kept; nixpal 7).
            'cronjob_invoice' => ($v = $invoiceDays) !== null ? ['string', $v] : null,
            // None in WHMCS (0 or empty) clears it, so a re-run never keeps an older value (R660-4).
            // An unreadable one keeps MyCS's own, as the general does (review of #660).
            'cronjob_invoice_monthly' => $monthlyDays !== null ? ['string', $monthlyDays]
                : (in_array(trim((string) ($config['CreateInvoiceDaysBeforeMonthly'] ?? 'x')), ['', '0'], true) ? ['string', ''] : null),
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
            // «Credit on Downgrade»: the rest of the period's difference as account credit, or nothing (2026-11-03: nixpal on,
            // myip off, MyCS's default on: myip's downgrades would have given credit WHMCS never gave).
            'credits_on_downgrade' => $has('CreditOnDowngrade') ? ['boolean', $on('CreditOnDowngrade') ? '1' : '0'] : null,
            // «Add Funds» limits (WG-3: myip 1 / 5,000 / 10,000, nixpal 2 / 1,000 / 1,000; MyCS's defaults 5 / 100 / 300
            // refused 13 of myip's last year's deposits).
            ...array_map(fn (?string $value) => $value !== null ? ['string', $value] : null, $deposits),
            // «Order Days Grace»: a new order's invoice is due after it (WG-4: myip 8, nixpal 0, the same day).
            'order_days_grace' => ($v = $days('OrderDaysGrace')) !== null ? ['string', $v] : null,
            'tos' => $tos,
            // WHMCS's «Pay To Text» (WG-17): the company's block on every invoice, Bill To Text here (shown escaped, its lines
            // kept). Decoded first, as WHMCS keeps its texts escaped (review of #664: «&amp;» would show as such); a <br> and
            // the newline after it are one line break; its tags are dropped, never taken as HTML; empty keeps MyCS's own.
            'bill_to_text' => ($payTo = mb_substr(trim(strip_tags((string) preg_replace(['/\r\n?/', '/<br\s*\/?>\n?/i'], "\n",
                html_entity_decode((string) ($config['InvoicePayTo'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')))), 0, 2000)) !== ''
                ? ['string', $payTo] : null,
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
        $perCycle = array_filter(['Quarterly', 'SemiAnnually', 'Annually', 'Biennially', 'Triennially'],
            fn (string $cycle) => $days('CreateInvoiceDaysBefore' . $cycle) !== null && (int) $days('CreateInvoiceDaysBefore' . $cycle) > 0);
        if ($perCycle !== []) {
            $this->warn('WHMCS invoices per billing cycle (' . implode(', ', array_map(fn (string $c) => $c . ' ' . $days('CreateInvoiceDaysBefore' . $c), $perCycle))
                . '); MyCS has a monthly plan\'s own and one for every other cycle, cronjob_invoice = ' . ($invoiceDays ?? 'unchanged')
                . ($invoiceDays !== null ? ' (WHMCS\'s general, WG-14): those cycles are invoiced that many days before their due date.' : '.'));
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

        $this->migrateInBatch('tblusers', 'SELECT * FROM tblusers ORDER BY id LIMIT :limit OFFSET :offset', function ($records) use ($secondFactor, &$withTwoFactor, &$missed) {
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

    /**
     * Who said no to marketing mail in WHMCS (WG-9): «news» off for the account's owner (its login), as «Stop these
     * emails» sets it (AnnouncementUnsubscribeController), so the first news mail never reaches them. WHMCS's state is
     * `tblclients.emailoptout` and, from WHMCS 8, `marketing_emails_opt_in` (tblmarketing_consent is its history): either
     * one saying no is no (myip 345/346, nixpal 76/77). Important announcements are not news: still sent.
     */
    private function importMarketingConsent(): void
    {
        $template = NotificationTemplate::query()->where('key', 'announcement_news')->first();
        if ($template === null) {
            $this->warn('Marketing consent: no «announcement_news» template here, nothing set.');

            return;
        }
        try {
            $clients = $this->pdo->query('SELECT id FROM tblclients WHERE emailoptout = 1 OR marketing_emails_opt_in = 0')->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            $clients = $this->pdo->query('SELECT id FROM tblclients WHERE emailoptout = 1')->fetchAll(PDO::FETCH_COLUMN); // before WHMCS 8
        }
        $users = DB::table('client_user')->where('owner', true)->whereIn('client_id', array_map('intval', $clients))->distinct()->pluck('user_id');
        $now = now();
        foreach ($users->chunk(500) as $chunk) {
            // No unique key on the pair: one row each, the earlier ones (a re-run) replaced.
            DB::table('notification_preferences')->where('notification_template_id', $template->id)->whereIn('user_id', $chunk->all())->delete();
            DB::table('notification_preferences')->insert($chunk->map(fn ($user) => ['user_id' => $user, 'notification_template_id' => $template->id,
                'mail_enabled' => false, 'in_app_enabled' => false, 'created_at' => $now, 'updated_at' => $now])->values()->all());
        }
        $this->info('Marketing consent: news off for ' . $users->count() . ' login(s) of ' . count($clients) . ' account(s) that said no in WHMCS.');
    }

    private function importUsers()
    {
        $this->importLogins();
        $groups = $this->importClientGroups();
        $this->info('Importing accounts... (' . $this->count('tblclients') . ' records)');

        $this->migrateInBatch('tblclients', 'SELECT * FROM tblclients ORDER BY id LIMIT :limit OFFSET :offset', function ($records) use ($groups) {
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
                    $this->ownerless[] = (int) $record['id'];

                    continue;
                }

                // Its signup: WHMCS's created_at, empty on accounts older than it (430 on myip): datecreated then.
                $created = $record['created_at'] ?? null;
                if ($created === null || str_starts_with((string) $created, '0000')) {
                    $created = filled($record['datecreated'] ?? null) && !str_starts_with((string) $record['datecreated'], '0000') ? $record['datecreated'] . ' 00:00:00' : now();
                }
                $data[] = [
                    'updated_at' => $record['updated_at'] == '0000-00-00 00:00:00' ? now() : $record['updated_at'],
                    'created_at' => $created,
                ];
                // The account keeps the WHMCS client id; its owner is the login (docs/accounts.md, PR 2a).
                $clients[] = ['id' => $record['id'], 'created_at' => end($data)['created_at'], 'updated_at' => end($data)['updated_at'],
                    'tax_exempt' => (bool) ($record['taxexempt'] ?? false), 'separate_invoices' => (bool) ($record['separateinvoices'] ?? false),
                    'auto_charge' => !($record['disableautocc'] ?? false),
                    // «Disable overdue notices», and its group (sweep of myip, 2026-10-25).
                    'no_overdue_reminders' => (bool) ($record['overideduenotices'] ?? false),
                    // WHMCS «Closed» (WG-8: nixpal 2): no sign-in, no orders. «Inactive» is what its services say.
                    'closed_at' => ($record['status'] ?? null) === 'Closed' ? now() : null,
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
                    // A foreign EU VAT number there (nixpal's EU businesses) is the VIES one too, which vat:recheck and the
                    // reverse charge read (sweep 2, F-TAX-DOCUMENTS-4); the ΑΦΜ field keeps it as WHMCS had it.
                    'vat_number' => WhmcsCustomFieldImport::foreignVatNumber($value('tax_id')),
                    'address1' => $value('address1'), 'address2' => $value('address2'), 'city' => $value('city'),
                    'state' => $value('state'), 'postcode' => $value('postcode'),
                    // A country the app knows (EuVat::iso: a code, a name, EL → GR), else none: never the first two letters of
                    // a name («Germany» → GE, Georgia). The ones left without are listed (reportCountries).
                    'country' => $this->country($record),
                    // The mails it takes (WHMCS's email_preferences: {"general":1,…}); none set: every kind, as WHMCS.
                    'email_preferences' => self::emailPreferences($record['email_preferences'] ?? null),
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
        if ($this->ownerless !== []) {
            // WHMCS gives each account its owner (docs/accounts.md): one without is not imported, nor its orders, services
            // and invoices (clientCurrency). Named, so it is fixed in WHMCS (an owner login) before the final import.
            $this->warn(count($this->ownerless) . ' WHMCS account(s) without an owner login, not imported with their orders, services and invoices (#'
                . implode(', #', $this->ownerless) . '). Give each an owner in WHMCS before the final import.');
        }
        $this->importedClients = null; // read again, now that they are in
        $this->reportCountries();
    }

    /** WHMCS's email_preferences as the contact's list of kinds it takes; none set (or unreadable): null, every kind. */
    private static function emailPreferences(mixed $json): ?string
    {
        $preferences = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($preferences)) {
            return null;
        }
        $kinds = array_values(array_filter(Contact::EMAIL_PREFERENCES, fn (string $kind) => (bool) (int) ($preferences[$kind] ?? 1)));

        return json_encode($kinds);
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

        $this->migrateInBatch('tbladmins', 'SELECT * FROM tbladmins ORDER BY id LIMIT :limit OFFSET :offset', function ($records) {
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
            $name = trim((string) $stmt->fetchColumn()) ?: 'WHMCS role ' . $whmcsRoleId;
            if (mb_strtolower($name) === 'admin') {
                $name .= ' (WHMCS)'; // never the Full Administrator's `*` by its name (a case-insensitive collation)
            }
            $perms = $this->pdo->prepare('SELECT permid FROM tbladminperms WHERE roleid = :id');
            $perms->bindValue(':id', $whmcsRoleId, PDO::PARAM_INT);
            $perms->execute();
            $mapped = RolePermissions::map($perms->fetchAll(PDO::FETCH_COLUMN));
            // Two WHMCS roles named alike (by case or spaces, as the collation sees them) stay two (review of #649).
            $same = Role::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($same !== null && $same->permissions !== $mapped['permissions']) {
                $name .= " (WHMCS #{$whmcsRoleId})";
            }
            $role = Role::query()->firstOrCreate(['name' => $name], ['permissions' => $mapped['permissions']]);
            if ($role->wasRecentlyCreated && $mapped['unmapped'] !== []) {
                $this->warn("Role «{$name}»: " . count($mapped['unmapped']) . ' WHMCS permission(s) MyCS has nothing for: '
                    . implode(', ', $mapped['unmapped']) . '.');
            }
        }

        return $this->staffRoles[$whmcsRoleId] = $role->id;
    }

    /** The tickets (docs/tickets.md §5α, PR Δ); also on its own: app:import-whmcs-tickets. */
    private function importTickets()
    {
        $this->info('Importing tickets... (' . $this->count('tbltickets') . ' records)');
        try {
            $folders = array_values(array_filter((array) $this->option('ticket-attachments'), fn ($folder) => is_string($folder) && $folder !== ''));
        } catch (Throwable) {
            $folders = []; // called without the command's input (the tests' reflection)
        }
        if (ImportWhmcsTickets::report($this, (new WhmcsTicketImport($this->pdo, $folders))->run()) !== self::SUCCESS) {
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

        $this->migrateInBatch('tblorders', 'SELECT * FROM tblorders ORDER BY id LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                // Get currency from user
                $user = $this->clientCurrency((int) $record['userid']);
                if ($user === null) {
                    continue;
                }

                $data[] = [
                    'id' => $record['id'],
                    'client_id' => $record['userid'],
                    'currency_code' => $user['code'],
                    // WHMCS «Order Notes» (the customer's at checkout, or staff's).
                    'notes' => WhmcsNoteImport::text($record['notes'] ?? null), // unescaped, as every note (#546)
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

    /**
     * A service's options as WHMCS keeps them (tblhostingconfigoptions, WG-11), by the catalog's `whmcs_ref`: a dropdown
     * or radio its value, a checkbox its value when ticked (qty > 0), a quantity its number as a property (as an order
     * writes one; PlaceOrderService). Its price is not touched: WHMCS's amount, with the options in it, stays the
     * service's (undiscounted_price); a price computed from the catalog (an upgrade, the price report) now counts them.
     * A quantity option still has no price here (CART-9, WG-15). One not brought (an option or value WHMCS deleted, an
     * option of a group its product does not offer) is said.
     */
    private function importServiceOptions(): void
    {
        $this->info('Importing service options... (' . $this->count('tblhostingconfigoptions') . ' records)');
        $refs = DB::table('config_options')->whereNotNull('whmcs_ref')->get(['id', 'parent_id', 'type', 'name', 'env_variable', 'whmcs_ref'])->keyBy('whmcs_ref');
        $services = DB::table('services')->whereNull('whmcs_addon_id')->pluck('product_id', 'id');
        // Only an option its product offers: WHMCS prices a service by its product's option groups (tblproductconfiglinks),
        // and an option of another group is in neither its amount nor its recalculation (review of #653: myip's 4 Dedicated
        // IPs, 7.00 and 62.00 with none in them, would have priced at 9.00 and 86.00 on a change of cycle).
        $linked = DB::table('config_option_products')->get(['product_id', 'config_option_id'])
            ->mapWithKeys(fn ($link) => [$link->product_id . '|' . $link->config_option_id => true]);
        $unlinked = [];
        $morph = (new Service)->getMorphClass();
        $configs = [];
        $properties = [];
        $missing = [];
        $now = now();
        foreach ($this->pdo->query('SELECT hc.relid, hc.configid, hc.optionid, hc.qty, o.optiontype FROM tblhostingconfigoptions hc
            LEFT JOIN tblproductconfigoptions o ON o.id = hc.configid ORDER BY hc.id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $service = (int) $row['relid'];
            if (!$services->has($service)) {
                continue; // a service not brought
            }
            $option = $refs->get('option:' . (int) $row['configid']);
            if ($option === null && (int) ($row['optiontype'] ?? 0) === 4 && (int) $row['qty'] <= 0) {
                continue; // a quantity of 0 of an option no service took: not brought (WG-15), nothing chosen
            }
            if ($option === null) {
                $missing[] = "service #{$service}, option #{$row['configid']}";

                continue;
            }
            if (!isset($linked[$services[$service] . '|' . $option->id])) {
                if ($option->type === 'select' || $option->type === 'radio' || (int) $row['qty'] > 0) {
                    $unlinked[] = "service #{$service}, «{$option->name}»";
                }

                continue;
            }
            $quantity = (int) $row['qty'];
            if ($option->type === 'number') {
                if ($quantity > 0) {
                    // One row per service and key: a second (a duplicate in WHMCS) replaces the first.
                    $properties[$service . '|' . ($option->env_variable ?: $option->name)] = ['model_type' => Service::class, 'model_id' => $service,
                        'key' => $option->env_variable ?: $option->name, 'name' => $option->name, 'value' => (string) $quantity,
                        'created_at' => $now, 'updated_at' => $now];
                }

                continue;
            }
            if ($option->type === 'checkbox' && $quantity <= 0) {
                continue; // not ticked
            }
            $value = $refs->get('value:' . (int) $row['optionid']);
            if ($value === null || (int) $value->parent_id !== (int) $option->id) {
                $missing[] = "service #{$service}, option «{$option->name}» value #{$row['optionid']}";

                continue;
            }
            $configs[$service . '|' . $option->id] = ['configurable_type' => $morph, 'configurable_id' => $service, 'config_option_id' => $option->id,
                'config_value_id' => $value->id, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk(array_values($configs), 500) as $chunk) {
            DB::table('service_configs')->insert($chunk);
        }
        foreach (array_chunk(array_values($properties), 500) as $chunk) {
            DB::table('properties')->insertOrIgnore($chunk); // one another step wrote under the same key stays
        }
        $this->info('Service options: ' . count($configs) . ' chosen, ' . count($properties) . ' quantities.');
        if ($missing !== []) {
            $this->warn(count($missing) . ' service option(s) whose option or value WHMCS no longer has, left out: ' . implode('; ', array_slice($missing, 0, 20))
                . (count($missing) > 20 ? '; …' : '') . '.');
        }
        if ($unlinked !== []) {
            $this->warn(count($unlinked) . ' service option(s) of a group its product does not offer, left out (WHMCS never charged them): '
                . implode('; ', array_slice($unlinked, 0, 20)) . (count($unlinked) > 20 ? '; …' : '') . '.');
        }
    }

    /**
     * A cPanel reseller product's services are WHM resellers (WG-16): marked as the extension marks one it made
     * (CPanel::RESELLER, «cpanel_reseller»), so a suspension takes their customers' accounts with it (suspendreseller, when
     * WHM lists the user as a reseller), as WHMCS did, and WHM SSO and a downgrade's unmake know it, whatever the product
     * becomes later. A termination is not changed by it: it always reads the owned accounts from WHM and needs staff's
     * typed confirmation (review of #663).
     */
    private function markCpanelResellers(): void
    {
        $products = Setting::query()->where('settingable_type', (new Product)->getMorphClass())->where('key', 'reseller')->where('value', '1')->pluck('settingable_id');
        $services = DB::table('services')->whereIn('product_id', $products)->whereNull('whmcs_addon_id')->pluck('id');
        $now = now();
        foreach ($services->chunk(500) as $chunk) {
            DB::table('properties')->insertOrIgnore($chunk->map(fn ($id) => ['model_type' => Service::class, 'model_id' => $id, 'key' => 'cpanel_reseller',
                'name' => 'WHM reseller', 'value' => '1', 'created_at' => $now, 'updated_at' => $now])->values()->all());
        }
        if ($services->isNotEmpty()) {
            $this->info('cPanel resellers: ' . $services->count() . ' service(s) of ' . $products->count() . ' reseller product(s) marked (WG-16).');
        }
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
        // A cluster's other nodes (myip's Pegasus beside Vega): one server here, its WHMCS id the first node's (CatalogImport).
        $byId = Server::query()->whereKey(array_values($this->whmcsServers))->get(['id', 'whmcs_id', 'extension'])->keyBy('id');
        foreach ($this->whmcsServers as $whmcsId => $serverId) {
            if (!$servers->has($whmcsId) && $byId->has($serverId)) {
                $servers->put($whmcsId, $byId->get($serverId));
            }
        }
        $productServers = DB::table('products')->join('extensions', 'extensions.id', '=', 'products.server_id')->pluck('extensions.extension', 'products.id');
        $withoutServer = [];
        $freeOnPlan = [];
        $offCycle = [];
        $unpriced = []; // plan id => it has no prices
        $marked = [];
        // Each account's group discount, as its services' prices hold it (ClientGroupPricing).
        $groupPercents = DB::table('clients')->join('client_groups', 'client_groups.id', '=', 'clients.client_group_id')
            ->pluck('client_groups.discount_percent', 'clients.id')->map(fn ($percent) => number_format((float) $percent, 2, '.', ''))->all();
        $this->migrateInBatch('tblhosting', 'SELECT * FROM tblhosting ORDER BY id LIMIT :limit OFFSET :offset', function ($records) use ($suspendDays, $servers, $productServers, &$withoutServer, &$marked, &$freeOnPlan, &$offCycle, &$unpriced, $groupPercents) {
            $data = [];
            $marks = [];
            foreach ($records as $record) {
                // Get currency from user
                $user = $this->clientCurrency((int) $record['userid']);
                if ($user === null) {
                    continue;
                }

                $planId = DB::table('plans')
                    ->where('priceable_id', $record['packageid'])
                    ->where('priceable_type', Product::class)
                    ->where('name', self::planName((string) $record['billingcycle']))
                    ->first()?->id;
                // A «Free Account» of a product sold on its prices (staff gave this one free, nixpal's licenses): no Free plan
                // is made for it, which customers could order free (owner 2026-11-03), but its first plan at 0.00: it renews
                // as it is, never invoiced, as WHMCS left it.
                $freeAccount = (string) $record['billingcycle'] === 'Free Account';
                if ($planId === null && $freeAccount) {
                    $planId = self::freeAccountPlan((int) $record['packageid']);
                    if ($planId !== null) {
                        $freeOnPlan[] = (int) $record['id'];
                    }
                }
                // Billed on a cycle its product does not offer (WHMCS bills the service's own amount): a plan of that cycle
                // without prices, never sold, so it renews here at its price (myip rehearsal 2026-11-04: 13 live ones). A
                // live one only: an ended one renews no more, and its plan would only crowd its product's (review of #628).
                $live = in_array($record['domainstatus'], ['Active', 'Suspended', 'Pending'], true);
                if ($planId === null && !$freeAccount && $live && ($product = Product::query()->find((int) $record['packageid'])) !== null) {
                    $planId = CatalogImport::unpricedPlan($product, (string) $record['billingcycle']);
                }
                // Listed for staff: each live one on a plan with no price in its currency (made here, a cycle WHMCS no longer
                // sells, or one priced in another currency only): «Recalculate price» would make it 0.00 (review of #628).
                $currency = (string) ($user['code'] ?? '');
                if ($planId !== null && !$freeAccount && $live
                    && ($unpriced["{$planId}|{$currency}"] ??= !DB::table('prices')->where('plan_id', $planId)->where('currency_code', $currency)->exists())) {
                    $offCycle[] = [(int) $record['id'], (string) $record['billingcycle'], (string) DB::table('products')->where('id', $record['packageid'])->value('name')];
                }

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

                // WHMCS's amount, without its account's group discount; none for a «Free Account» (below).
                $amount = $freeAccount ? null : $this->recurringAmount($record);
                $data[] = [
                    'id' => $record['id'],
                    'order_id' => $record['orderid'],
                    'server_id' => $server?->id,
                    'whmcs_server_id' => $whmcsServer,
                    'product_id' => $record['packageid'],
                    'status' => self::serviceStatus((string) $record['domainstatus']),
                    // Never below zero (ADV-551-3): it would renew free; staff are told. A «Free Account» is free whatever
                    // amount WHMCS left on it: WHMCS never invoiced it. WHMCS keeps the amount without its account's group
                    // discount and bills the discount on each renewal, a `GroupDiscount` line per service (nixpal: 46.40,
                    // −4.64, 41.76 each month); here the price holds it (WG-1).
                    'price' => $amount === null ? '0.00' : ClientGroupPricing::discounted($amount, $groupPercents[(int) $record['userid']] ?? '0.00'),
                    'quantity' => $record['qty'],
                    'client_id' => $record['userid'],
                    'plan_id' => $planId,
                    'currency_code' => $user['code'],
                    // A «Free Account» has no due date here, whatever WHMCS kept: WHMCS never bills nor suspends one, and a
                    // past date would be suspended and terminated here (adversarial ADV-594-1).
                    'expires_at' => $freeAccount ? null : $this->validateDate($record['nextduedate']),
                    // Its 0.00 kept («Keep this price»): no reconcile of client group prices ever makes it the catalog's
                    // (ADV-594-3), and the price report reads it as kept.
                    'price_locked' => $freeAccount,
                    // The discount its price now holds: the cron's reconcile of group prices leaves it as it is.
                    'group_percent' => $groupPercents[(int) $record['userid']] ?? '0.00',
                    // WHMCS's amount itself: a later change of group reprices from it as WHMCS would bill it, never by
                    // ratio (ADV-641-1: a 100% group's 0.00; ADV-641-2: 1.66 back to 1.85, not 1.84).
                    'undiscounted_price' => $amount,
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
                    'notes' => self::text(implode("\n\n", array_filter([$this->promotionStillRunning($record), (string) WhmcsNoteImport::text($record['notes'] ?? null)]))),
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
        $this->moveServicesCounter();
        if ($freeOnPlan !== []) {
            $this->info(count($freeOnPlan) . ' «Free Account» service(s) of a product with no Free plan: on its first plan at 0.00 (renews free, never invoiced, as in WHMCS): '
                . implode(', ', array_map(fn (int $id) => "#{$id}", $freeOnPlan)) . '.');
        }
        if ($withoutServer !== []) {
            $this->warn(count($withoutServer) . ' live service(s) whose WHMCS server is not here: no server action until staff set one.');
            $this->table(['Service', 'WHMCS server'], $withoutServer);
        }
        if ($offCycle !== []) {
            $this->warn(count($offCycle) . ' live service(s) billed on a cycle their product does not offer: on a plan of that cycle without prices (not sold), renewed at their own price. Check each one\'s price.');
            $this->table(['Service', 'Cycle', 'Product'], $offCycle);
        }
        if ($marked !== []) {
            // Before the cut-over: each one checked disabled (Centova) or locked (mailcow) there, as WHMCS left it.
            $this->info(count($marked) . ' suspended service(s) marked as suspended by us: a payment enables them again.');
            $this->table(['Service', 'Module', 'Account'], $marked);
        }
    }

    /**
     * The services' counter past every id WHMCS gave a service, even one it deleted since (its highest, a fraud order staff
     * removed): its cancellation requests, invoice lines, upgrades and fields still name that id, and a service made here
     * after the cut-over would take them (sweep 4, C-IMPORT-1). As the invoices' (importInvoices); the addons (inserted
     * after, WhmcsAddonImport) take ids above it, and their explicit ids move it on by themselves.
     */
    private function moveServicesCounter(): void
    {
        $next = (new WhmcsAddonImport($this->pdo))->firstFreeServiceId();
        $driver = DB::getDriverName();
        // DDL commits an open transaction (MySQL/MariaDB): only outside one, as the importer runs.
        if (($driver === 'mysql' || $driver === 'mariadb') && DB::transactionLevel() === 0) {
            DB::statement('ALTER TABLE services AUTO_INCREMENT = ' . $next);
        } elseif ($driver === 'sqlite') {
            // AUTOINCREMENT keeps its counter in sqlite_sequence (a row once the table had one); never moved back.
            $updated = DB::update('UPDATE sqlite_sequence SET seq = ? WHERE name = ? AND seq < ?', [$next - 1, 'services', $next - 1]);
            if ($updated === 0 && !DB::table('sqlite_sequence')->where('name', 'services')->exists()) {
                DB::table('sqlite_sequence')->insert(['name' => 'services', 'seq' => $next - 1]);
            }
        }
        $this->info('Services counter: the next service is #' . $next . '.');
    }

    /** WHMCS's product addons (WhmcsAddonImport): each live one a service fastened to its parent. */
    private function importAddons(): void
    {
        if (!$this->sourceHas('tblhostingaddons')) {
            return;
        }
        $suspendDays = $this->sourceHas('tblconfiguration')
            ? (int) ($this->pdo->query("SELECT value FROM tblconfiguration WHERE setting = 'AutoSuspensionDays'")->fetchColumn() ?: 0) : 0;
        $result = (new WhmcsAddonImport($this->pdo, $suspendDays))->run();
        $this->addonsHere = $result['services'];
        $c = $result['counts'];
        $this->info("Product addons: {$c['services']} live addon(s) as services of their parents, {$c['products']} addon product(s); {$c['skipped']} not imported.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** @var array<int, int>|null WHMCS addon (tblhostingaddons.id) => its own service here (WhmcsAddonImport) */
    private ?array $addonsHere = null;

    /** @return array<int, int> */
    private function addonsHere(): array
    {
        return $this->addonsHere ??= DB::table('services')->whereNotNull('whmcs_addon_id')->pluck('id', 'whmcs_addon_id')
            ->mapWithKeys(fn ($id, $addon) => [(int) $addon => (int) $id])->all();
    }

    /** @var array<int, int> WHMCS server id => its server here (a cluster's nodes share one), from importServers */
    private array $whmcsServers = [];

    /** WHMCS's servers and groups, and its products' (the catalog's step): before the services, which come on theirs. */
    private function importServers()
    {
        if (!$this->sourceHas('tblservers')) {
            return;
        }
        $result = $this->catalog()->serversForFullImport();
        $this->whmcsServers = $result['server_ids'];
        $this->info("Servers: {$result['counts']['servers']} servers, {$result['counts']['server_groups']} groups (no passwords or API keys: staff enter them).");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** The catalog's importer, one for the servers and the products: a product takes the servers its step brought. */
    private ?CatalogImport $catalog = null;

    private function catalog(): CatalogImport
    {
        return $this->catalog ??= new CatalogImport($this->pdo, makeDefaultCurrency: false, keepWhmcsIds: true);
    }

    /**
     * WHMCS's product groups, config options and products, as the catalog's own import brings them (WG-2): the cPanel and
     * ngm packages, the hidden groups, the retired products, the free prices, the order and the config options' links.
     * Products and groups keep their WHMCS ids, which the services, orders and licenses point at.
     */
    private function importCatalog(): void
    {
        $result = $this->catalog()->productsForFullImport();
        $this->info("Catalog: {$result['counts']['categories']} groups, {$result['counts']['products']} products, {$result['counts']['config_options']} config options, "
            . "{$result['counts']['plans']} plans, {$result['counts']['prices']} prices.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /**
     * The products with no module in WHMCS that a live service is on (myip: dedicated servers, colocation, managed ones, a
     * backup MX, a few by hand), and those services: on one Offline Dedicated server, so every action on them (a suspension
     * for non-payment too) is a task for staff to see, never a status changed and nothing done (owner 2026-11-04). Only one
     * with no server in WHMCS: one on its own WHMCS server keeps it, and one whose WHMCS server is not here (deleted, a module
     * with no extension) stays listed for staff to set (review of #628). An ended service keeps none of its own; staff
     * acting on it by hand from its page get a task too. Listed for staff, who move one that is not a machine (a
     * certificate, a license) where it belongs. Redfish (iDRAC, BMC) is the roadmap's, later (ROADMAP).
     */
    private function importOfflineDedicated(): void
    {
        if (!$this->sourceHas('tblproducts') || !$this->sourceHas('tblhosting')) {
            return;
        }
        $live = "('Active', 'Suspended', 'Pending')";
        $ids = array_map('intval', $this->pdo->query("SELECT p.id FROM tblproducts p WHERE COALESCE(p.servertype, '') = ''
            AND EXISTS (SELECT 1 FROM tblhosting h WHERE h.packageid = p.id AND h.domainstatus IN {$live}) ORDER BY p.id")->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return;
        }
        $server = Server::query()->firstOrCreate(['extension' => 'OfflineDedicated', 'name' => 'Manual (offline)'], ['type' => 'server', 'enabled' => true]);
        DB::table('products')->whereIn('id', $ids)->whereNull('server_id')->update(['server_id' => $server->id]);
        $services = DB::table('services')->whereIn('product_id', $ids)->whereNull('server_id')
            ->where(fn ($none) => $none->whereNull('whmcs_server_id')->orWhere('whmcs_server_id', 0))
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING])->orderBy('id')->get(['id', 'product_id', 'domain']);
        DB::table('services')->whereIn('id', $services->pluck('id'))->update(['server_id' => $server->id]);
        $this->info(count($services) . ' live service(s) of ' . count($ids) . " product(s) with no module in WHMCS on «{$server->name}» (Offline Dedicated): every action on them is a task for staff.");
        if ($services->isNotEmpty()) {
            $names = DB::table('products')->whereIn('id', $ids)->pluck('name', 'id');
            $this->table(['Service', 'Product', 'Domain'], $services->map(fn ($service) => [$service->id, $names[$service->product_id] ?? '', $service->domain])->all());
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
        $this->info("Domains: {$c['domains']} ({$c['skipped']} skipped, {$c['at_tld_price']} at their TLD's price), {$c['tlds']} TLDs, {$c['connections']} registrar connection(s); "
            . "{$c['handles']} handle(s) linked, {$c['handles_kept']} kept for staff; {$c['reminders']} notice(s) already sent.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /**
     * WHMCS's modules that are a gateway here for an invoice's or item's preferred method, beside the payment ones
     * (GATEWAYS): bank transfer, and myip's Eurobank vPOS (eurobanklib: a redirect to the bank, nothing saved, paid from
     * the invoice; owner 2026-10-29). Not for its payments: those stay manual, never refunded through a gateway.
     */
    private const PREFERRED_GATEWAYS = ['banktransfer' => 'BankTransfer', 'eurobanklib' => 'Cardlink'];

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
     * WHMCS's ways of paying, each a gateway here, installed DISABLED (staff set it up and enable it) before the invoices',
     * items' and saved methods' ways are set: on the cut-over's empty database a bank transfer or Eurobank one was no way
     * at all, and a customer paying some items so and others by card stopped being charged (owner 2026-11-06; myip's
     * rehearsal: 31 customers, 101 items). The ways WHMCS shows customers («visible»), and any a live service or domain
     * still pays by (a way hidden since: review of #679). A disabled gateway is offered to no one (the concern of #539).
     * One it installs gets the name WHMCS shows (bank transfer, the Eurobank vPOS); bank transfer's accounts come from its
     * «Invoice Created» template (bankAccounts), also on a re-run while it has none: WHMCS keeps its own text encrypted in
     * a scheme nothing outside it reads.
     */
    private function importPaymentWays(): void
    {
        try {
            $rows = $this->pdo->query("SELECT gateway, setting, value FROM tblpaymentgateways WHERE setting IN ('visible', 'name')")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return;
        }
        $ways = [];
        foreach ($rows as $row) {
            $ways[strtolower(trim((string) $row['gateway']))][(string) $row['setting']] = trim((string) $row['value']);
        }
        $used = [];
        foreach ([["SELECT DISTINCT paymentmethod FROM tblhosting WHERE domainstatus IN ('Active', 'Suspended')", 'tblhosting'],
            ["SELECT DISTINCT paymentmethod FROM tbldomains WHERE status IN ('Active', 'Grace', 'Redemption', 'Pending')", 'tbldomains']] as [$sql, $table]) {
            if ($this->sourceHas($table)) {
                foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $module) {
                    $used[strtolower(trim((string) $module))] = true;
                }
            }
        }
        foreach ($ways as $module => $settings) {
            if (($settings['visible'] ?? '') !== 'on' && !isset($used[$module])) {
                continue;
            }
            $extension = self::extensionFor($module, preferred: true);
            if ($extension === null) {
                $this->warn("Payment way «{$module}» («" . ($settings['name'] ?? '') . '»): MyCS has no gateway for it; its invoices and items keep none.');

                continue;
            }
            $existed = DB::table('extensions')->where('type', 'gateway')->where('extension', $extension)->whereNull('deleted_at')->exists();
            $id = $this->installedGateway($extension);
            unset($this->itemGateways[$module]); // read again by the steps after
            $name = mb_substr($settings['name'] ?? '', 0, 255);
            // Its own name only on one it installed, and only for a way of its own (paypal and paypal_ppcpv share PayPal).
            if (!$existed && $name !== '' && isset(self::PREFERRED_GATEWAYS[$module])) {
                DB::table('extensions')->where('id', $id)->update(['name' => $name]);
            }
            // Its accounts once: never over what staff wrote, and again on a re-run that has none yet.
            $gateway = Gateway::query()->find($id);
            if ($extension === 'BankTransfer' && $gateway !== null && trim((string) $gateway->settings()->where('key', 'accounts')->value('value')) === '') {
                $this->bankAccounts($gateway, $name);
            }
        }
    }

    /**
     * Bank transfer's accounts (its required «Bank accounts»), from WHMCS's «Invoice Created» template: the block it shows
     * for this way ({if $invoice_payment_method eq "<its name>"} … its own {elseif}/{else}/{/if}, an {if} inside it kept
     * whole), as text: no comments, styles, scripts or hidden elements (old or preheader text, review of #679), cells and
     * paragraphs apart; a line that is Smarty («deposit {$invoice_total} …») left out, unless it holds an IBAN (then only its
     * Smarty goes). None found: said, staff write them.
     */
    private function bankAccounts(Gateway $gateway, string $name): void
    {
        $opening = '{if $invoice_payment_method eq "' . $name . '"}';
        try {
            $templates = $name === '' ? [] : $this->pdo->query("SELECT message FROM tblemailtemplates WHERE name = 'Invoice Created' ORDER BY language = '' DESC, id")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            $templates = [];
        }
        $iban = '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]){10,30}\b/';
        $text = null;
        foreach ($templates as $message) {
            $start = mb_strpos((string) $message, $opening);
            if ($start === false) {
                continue;
            }
            $block = self::smartyBranch(mb_substr((string) $message, $start + mb_strlen($opening)));
            // What a reader never sees.
            $block = (string) preg_replace(['/<!--.*?-->/s', '#<(style|script|head|title)\b[^>]*>.*?</\1\s*>#is'], '', $block);
            $block = (string) preg_replace('#<(\w+)\b[^>]*(?:display\s*:\s*none|visibility\s*:\s*hidden|\bhidden\b)[^>]*>.*?</\1\s*>#is', '', $block);
            // A paragraph, heading, row or box ends with a blank line; a <br>, an item or a cell with a new line.
            $block = (string) preg_replace('#<\s*(/p|/div|/tr|/table|/h[1-6])\b[^>]*>#i', "\n\n", $block);
            $block = (string) preg_replace('#<\s*(br|/li|/td|/th)\b[^>]*>#i', "\n", $block);
            // The tags, also those split over lines, out of the whole block; a non-breaking space (&nbsp;) as a plain one.
            $block = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $lines = [];
            foreach (explode("\n", $block) as $line) {
                $line = trim($line);
                if (str_contains($line, '{')) {
                    if (preg_match($iban, $line) !== 1) {
                        continue; // a Smarty line, meaningless out of its mail
                    }
                    $line = trim((string) preg_replace('/\s*\{[^}]*\}\s*/', ' ', $line));
                }
                $lines[] = $line;
            }
            // One blank line between its parts (the field's «a blank line between them»).
            $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
            if ($text !== '' && preg_match($iban, $text) === 1) {
                $text = mb_substr($text, 0, 5000);
                break;
            }
            $text = null;
        }
        if ($text === null) {
            $this->warn('Gateway «Bank transfer»: its accounts were not found in WHMCS\'s «Invoice Created» template: write them in its settings before enabling it.');

            return;
        }
        $gateway->settings()->updateOrCreate(['key' => 'accounts'], ['value' => $text, 'type' => 'string', 'encrypted' => false]);
        $this->info('Gateway «Bank transfer»: its accounts brought from WHMCS\'s «Invoice Created» template (' . count(explode("\n", $text)) . ' lines): check them before enabling it'
            . ' (WHMCS\'s own bank transfer text is encrypted: paste it instead if it should be that one).');
    }

    /** A Smarty {if} branch's own text: up to its {elseif}/{else}/{/if}, an {if} … {/if} inside it kept whole. */
    private static function smartyBranch(string $rest): string
    {
        $depth = 0;
        $offset = 0;
        while (preg_match('/\{(\/?if\b|elseif\b|else\b)[^}]*\}/', $rest, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$tag, $at] = $m[0];
            $kind = strtolower($m[1][0]);
            if (str_starts_with($kind, 'if')) {
                $depth++;
            } elseif ($depth === 0) {
                return substr($rest, 0, $at);
            } elseif ($kind === '/if') {
                $depth--;
            }
            $offset = $at + strlen($tag);
        }

        return $rest;
    }

    /**
     * The gateway here of a WHMCS module, for a preferred payment method: only one here by now (its payments', set up, or
     * installed disabled by importPaymentWays), never one installed for a label alone, enabled for everyone (#539).
     */
    private function existingGateway(string $module): ?int
    {
        // Once per module and run: what the preferences and the items got is what the saved methods' step reads, though
        // it installs gateways after (ADV-541-8).
        if (array_key_exists($module, $this->itemGateways)) {
            return $this->itemGateways[$module];
        }
        $extension = self::extensionFor($module, preferred: true);
        $id = $extension === null ? null : DB::table('extensions')->where('type', 'gateway')->where('extension', $extension)->whereNull('deleted_at')->value('id');

        return $this->itemGateways[$module] = $id === null ? null : (int) $id;
    }

    /** @var array<string, int|null> WHMCS module => the gateway here its preferences and items got (existingGateway) */
    private array $itemGateways = [];

    /** WHMCS's Proxmox VPS (WhmcsProxmoxImport): each one's VMID, for the cluster's first read here to map its guest. */
    private function importProxmoxVms(): void
    {
        $result = (new WhmcsProxmoxImport($this->pdo))->run();
        $c = $result['counts'];
        if ($c['vms'] > 0 || $c['unmapped'] > 0) {
            $this->info("Proxmox: {$c['kept']} VPS's VMID kept for the cluster's first read to map them; {$c['unmapped']} live one(s) for staff to map.");
        }
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** Staff's notes no other step brings (WhmcsNoteImport): the account's, an invoice's, an addon's. */
    private function importNotes(): void
    {
        $result = (new WhmcsNoteImport($this->pdo))->run();
        $c = $result['counts'];
        $this->info("Notes: {$c['notes']} staff note(s), {$c['account_notes']} account note(s), {$c['invoice_notes']} invoice note(s) on their accounts, "
            . "{$c['addon_notes']} addon note(s) on their services; {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** Tax Rates and the tax settings (WhmcsTaxImport): what the last invoice in WHMCS charged, the seed updating the stale. */
    private function importTax(): void
    {
        $result = (new WhmcsTaxImport($this->pdo))->run();
        $c = $result['counts'];
        $this->info("Tax: {$c['rates']} rate(s) (`mycs:vat-rates:seed --update-imported` brings the out-of-date ones to the checked list), {$c['settings']} setting(s); {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** The names customers gave their services (WhmcsServiceLabelImport). */
    private function importServiceLabels(): void
    {
        $c = (new WhmcsServiceLabelImport($this->pdo))->run()['counts'];
        $this->info("Service labels: {$c['labels']} imported, {$c['skipped']} skipped.");
    }

    /** WHMCS's ticket spam filters as blocked senders (WhmcsSpamFilterImport). */
    private function importSpamFilters(): void
    {
        $result = (new WhmcsSpamFilterImport($this->pdo))->run();
        $c = $result['counts'];
        $this->info("Blocked senders: {$c['senders']} imported, {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** A product's own welcome email in WHMCS as its snippet in the «service activated» email (WhmcsWelcomeSnippetImport). */
    private function importWelcomeSnippets(): void
    {
        $result = (new WhmcsWelcomeSnippetImport($this->pdo))->run();
        $c = $result['counts'];
        $this->info("Welcome emails: {$c['snippets']} of {$c['products']} product(s) with their own template given a snippet; {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** What WHMCS mailed its customers in the last year, as the mail log (WhmcsEmailHistoryImport). */
    private function importEmailHistory(): void
    {
        $c = (new WhmcsEmailHistoryImport($this->pdo))->run()['counts'];
        $this->info("Email history: {$c['mails']} mail(s) of the last year ({$c['scrubbed']} with a password or a key masked); {$c['older']} older not brought, {$c['pending']} pending skipped.");
    }

    /** The published announcements (WhmcsAnnouncementImport). */
    private function importAnnouncements(): void
    {
        $result = (new WhmcsAnnouncementImport($this->pdo))->run();
        $this->info("Announcements: {$result['counts']['announcements']} imported, {$result['counts']['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** WHMCS's custom fields into MyCS's own fields (WhmcsCustomFieldImport): the account's fiscal details, IPs, Centova's. */
    private function importCustomFields(): void
    {
        $result = (new WhmcsCustomFieldImport($this->pdo))->run();
        $c = $result['counts'];
        $this->info("Custom fields: {$c['accounts']} account(s) given their fiscal details, {$c['notes']} note(s), {$c['ips']} IP(s), "
            . "{$c['centova']} Centova field(s), {$c['service_notes']} service(s) with the rest in their notes; {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** WHMCS's contacts (WhmcsContactImport) as the account's, after Ekdosi's: one that is one of those is not made twice. */
    private function importContacts(): void
    {
        $result = (new WhmcsContactImport($this->pdo))->run();
        $c = $result['counts'];
        $this->info("Contacts: {$c['contacts']} imported, {$c['merged']} completing an Ekdosi contact they are, {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /** E4 of the Ekdosi bridge (WhmcsEkdosiContactImport): who each service's and domain's fiscal document is issued to. */
    private function importEkdosiContacts(): void
    {
        $result = (new WhmcsEkdosiContactImport($this->pdo))->run();
        $c = $result['counts'];
        $this->info("Ekdosi contacts: {$c['contacts']} imported, assigned to {$c['services']} service(s) and {$c['domains']} domain(s); {$c['skipped']} assignment(s) skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /**
     * Each service's and domain's own payment method (tblhosting/tbldomains.paymentmethod, owner 2026-10-29): its renewals
     * are invoiced apart with it, charged by the account's saved method of that gateway and never by one of another, as in
     * WHMCS (#568). A module with no gateway here: none (the account's); the saved methods' step then keeps «Auto-charge»
     * off for such a customer; an item another module's method would charge here gets «Do not charge automatically»
     * (ADV-568-1).
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
        // An addon's own (WhmcsAddonImport): its service here by its WHMCS id.
        foreach ($this->sourceHas('tblhostingaddons') ? $this->pdo->query('SELECT id, paymentmethod FROM tblhostingaddons')->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $module = strtolower(trim((string) ($row['paymentmethod'] ?? '')));
            if ($module !== '' && isset($this->addonsHere()[(int) $row['id']]) && ($gateway = $this->existingGateway($module)) !== null) {
                $set += DB::table('services')->where('id', $this->addonsHere()[(int) $row['id']])->update(['payment_gateway_id' => $gateway]);
            }
        }
        $this->info("Services' and domains' payment method: {$set} set from WHMCS.");
    }

    /** The live services' passwords (WhmcsServicePasswordImport): through WHMCS's API (DecryptPassword), never its key. */
    private function importServicePasswords(): void
    {
        try {
            $api = WhmcsApi::fromEnvironment(rescue(fn () => $this->option('whmcs-api-url'), null, false));
        } catch (\RuntimeException $e) {
            $this->warn("Service passwords not imported: {$e->getMessage()}");

            return;
        }
        $result = (new WhmcsServicePasswordImport($this->pdo, $api))->run();
        $c = $result['counts'];
        $this->info("Service passwords: {$c['imported']} of {$c['services']} live service(s) imported, {$c['empty']} empty in WHMCS, {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
    }

    /**
     * The logins of what staff connect (WhmcsCredentialImport): a cPanel server's API token, the registrars' accounts and
     * the gateways' keys. Last, with the other steps over the network; everything stays off until staff switch it on.
     */
    private function importCredentials(): void
    {
        // Opt-in: a database holding live logins (a WHM token on an enabled server) must never be one a cron or queue runs on
        // by accident (ADV-561-4).
        if (!rescue(fn () => (bool) $this->option('with-logins'), false, false)) {
            $this->line('Logins of servers, registrars and gateways not imported (--with-logins brings them).');

            return;
        }
        try {
            $api = WhmcsApi::fromEnvironment(rescue(fn () => $this->option('whmcs-api-url'), null, false));
        } catch (\RuntimeException $e) {
            $this->warn("Credentials not imported: {$e->getMessage()}");

            return;
        }
        $result = (new WhmcsCredentialImport($this->pdo, $api, fn (string $module) => $this->gatewayId($module)))->run();
        $c = $result['counts'];
        $this->info("Credentials: {$c['imported']} of {$c['found']} login(s) of servers, registrars and gateways imported, {$c['skipped']} skipped.");
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
        if ($c['imported'] > 0) {
            $this->warn('This database now holds live logins (an enabled server with its WHM token, registrar and gateway accounts): '
                . 'never run its scheduler or queue until they are meant to act.');
        }
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
            // What the item step wrote (ADV-541-8: a gateway installed since is not on its items).
            fn (string $module) => $this->existingGateway($module)))->run();
        $c = $result['counts'];
        $this->info("Saved payment methods: {$c['imported']} of {$c['methods']} imported ({$c['defaults']} customers' default; {$c['not_charged']} with «Auto-charge» off, "
            . "their items paid another way with no payment method here, those with some paid by a saved method listed; {$c['items_kept']} service(s) "
            . "and domain(s) set «Do not charge automatically», listed), {$c['skipped']} skipped.");
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

    /** WHMCS cancellation requests of services not imported, left out */
    private int $cancellationsDropped = 0;

    private function importCancellations()
    {
        $this->info('Importing cancellations... (' . $this->count('tblcancelrequests') . ' records)');

        // One request per service (DATA-11; WHMCS has no such key): the first one is kept, the later ones ignored.
        $this->migrateInBatch('tblcancelrequests', 'SELECT * FROM tblcancelrequests ORDER BY id LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                // A deleted service's request (or one of an account not imported) is no one's: a service made here later
                // with its id would be never billed and cancelled at its first expiry (sweep 4, C-IMPORT-1).
                if (!isset($this->importedServices()[(int) $record['relid']])) {
                    $this->cancellationsDropped++;

                    continue;
                }
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
        if ($this->cancellationsDropped > 0) {
            $this->info("{$this->cancellationsDropped} cancellation request(s) of services not imported (deleted in WHMCS): left out.");
        }
        $this->cancellationsToAddons();
    }

    /**
     * A parent's end-of-period request on each of its live addons that has none of its own, as one made here
     * (AddonCascade::requestWithParent, `with_parent`): never renewed again, ended with it (code review 1 of #647). Rows
     * written directly, as the requests: no mail to the customer, who asked once.
     */
    private function cancellationsToAddons(): void
    {
        $copies = [];
        foreach (DB::table('service_cancellations')->where('type', 'end_of_period')->where('with_parent', false)->get(['service_id', 'reason', 'created_at']) as $request) {
            $addons = DB::table('services')->where('parent_service_id', $request->service_id)->whereIn('status', Service::PAYING_STATUSES)
                ->whereNotExists(fn ($own) => $own->from('service_cancellations')->whereColumn('service_cancellations.service_id', 'services.id'))->orderBy('id')->pluck('id');
            foreach ($addons as $addon) {
                $copies[] = ['service_id' => (int) $addon, 'reason' => $request->reason, 'type' => 'end_of_period', 'with_parent' => true,
                    'created_at' => $request->created_at, 'updated_at' => $request->created_at];
            }
        }
        DB::table('service_cancellations')->insertOrIgnore($copies);
        if ($copies !== []) {
            $this->info(count($copies) . ' addon(s) of services with an end-of-period cancellation: cancelled with them.');
        }
    }

    private function importInvoices()
    {
        $this->info('Importing invoices... (' . $this->count('tblinvoices') . ' records)');
        // WHMCS's «Collections» (myip's auto-cancellation hook writes them on terminate) come unpaid: reminders and
        // auto-charge would chase them. The owner's call (WG-18): they are cancelled in WHMCS before the final import.
        // Only those imported below: not a Mass Pay parent, nor one whose customer has no currency (review of #665).
        $collections = array_keys(array_filter(array_map('intval', $this->pdo->query("SELECT i.id, i.userid FROM tblinvoices i
            WHERE i.status = 'Collections' ORDER BY i.id")->fetchAll(PDO::FETCH_KEY_PAIR)),
            fn (int $client, int $id) => !isset($this->massPayParents()[$id]) && $this->clientCurrency($client) !== null, ARRAY_FILTER_USE_BOTH));
        if ($collections !== []) {
            $this->warn(count($collections) . ' WHMCS invoice(s) in Collections (#' . implode(', #', $collections) . '): imported as unpaid, so reminders and'
                . ' auto-charge apply to them. Cancel them in WHMCS before the final import (cutover-runbook, WG-18).');
        }

        $this->migrateInBatch('tblinvoices', 'SELECT * FROM tblinvoices ORDER BY id LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            $existingNumbers = DB::table('invoices')->whereNotNull('number')->pluck('number')->mapWithKeys(fn ($number) => [self::numberKey((string) $number) => true])->all();

            foreach ($records as $record) {
                // A Mass Pay parent is not an invoice here (importMassPay).
                if (isset($this->massPayParents()[(int) $record['id']])) {
                    continue;
                }
                // Get currency from user
                $user = $this->clientCurrency((int) $record['userid']);
                if ($user === null) {
                    continue;
                }

                $invoiceNumber = !empty($record['invoicenum']) ? $record['invoicenum'] : null;

                // A number another invoice has already is set to null (the unique key), and named. As the key compares them
                // (MariaDB's case-insensitive PAD SPACE collation): «2025-0412 » is «2025-0412» there (sweep IMPORT-5).
                if ($invoiceNumber && isset($existingNumbers[self::numberKey((string) $invoiceNumber)])) {
                    $invoiceNumber = null;
                    $this->numbersDropped[] = (int) $record['id'];
                } elseif ($invoiceNumber) {
                    $existingNumbers[self::numberKey((string) $invoiceNumber)] = true;
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
                    // A cancelled one's notes are why (nixpal's «Automatic Cancellation after N days overdue», myip's
                    // «Transferred Away»): its cancellation reason too, as staff see it (code review 3 of #546); the whole
                    // text is a note on its account (WhmcsNoteImport).
                    'cancellation_reason' => $record['status'] === 'Cancelled' && ($why = WhmcsNoteImport::text($record['notes'] ?? null)) !== null
                        && mb_strlen($why) <= 255 ? $why : null,
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['date']),
                ];
            }

            DB::table('invoices')->insert($data);
        });
        if ($this->numbersDropped !== []) {
            $this->warn(count($this->numbersDropped) . ' WHMCS invoice(s) imported without a number, theirs being an earlier invoice\'s too (#'
                . implode(', #', $this->numbersDropped) . '). Give each its own in WHMCS before the final import.');
        }

        // The counter holds the last number given (InvoiceNumberListener: +1, then used): the highest imported id, so the
        // next is one past it, none skipped (adversarial ADV-2 of #477). A Mass Pay parent (not imported, PR F3) keeps its id
        // too: a new invoice never takes the number a customer quotes for one. Nor one WHMCS deleted at the top of its ids:
        // its counter (tblinvoices' AUTO_INCREMENT) and the invoice ids lines, payments and orders still name, as
        // firstFreeServiceId does for services (sweep 2, F-TAX-DOCUMENTS-2).
        $highestInvoiceNumber = (int) DB::table('invoices')->max('id');
        foreach (['SELECT MAX(id) FROM tblinvoices', 'SELECT MAX(invoiceid) FROM tblinvoiceitems', 'SELECT MAX(invoiceid) FROM tblaccounts',
            'SELECT MAX(invoiceid) FROM tblorders',
            "SELECT AUTO_INCREMENT - 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinvoices'"] as $sql) {
            try {
                $highestInvoiceNumber = max($highestInvoiceNumber, (int) $this->pdo->query($sql)->fetchColumn());
            } catch (PDOException) {
                // Not in this source (a partial copy, SQLite in tests): nothing it names.
            }
        }
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
     * overdue day up to WHMCS's last daily run counts as sent, and «due soon» where that run was within WHMCS's reminder
     * days of its due date. The days after it are the cron's own.
     */
    private function importReminderState(): void
    {
        $before = 0;
        if ($this->sourceHas('tblconfiguration')) {
            $value = trim((string) $this->pdo->query("SELECT value FROM tblconfiguration WHERE setting = 'SendInvoiceReminderDays'")->fetchColumn());
            $before = ctype_digit($value) && (int) $value <= PaymentReminders::MAX_DAYS ? (int) $value : 0;
        }
        $today = Carbon::today();
        $sentUpTo = $this->whmcsLastDailyRun($today);
        $rows = [];
        foreach (DB::table('invoices')->where('status', Invoice::STATUS_UNPAID)->whereNotNull('due_at')->get(['id', 'due_at']) as $invoice) {
            $due = Carbon::parse($invoice->due_at)->startOfDay();
            $row = ['invoice_id' => $invoice->id, 'due_on' => $due->toDateString(), 'sent_at' => now()];
            if ($due->greaterThan($today)) {
                if ($due->lessThanOrEqualTo($sentUpTo->copy()->addDays($before))) {
                    $rows[] = ['kind' => 'due', 'days' => 0] + $row;
                }

                continue;
            }
            // Only within the overdue days the cron still sends on (and catches up): an older one is never reminded again.
            if ((int) $due->diffInDays($today) > PaymentReminders::MAX_DAYS + PaymentReminders::CATCH_UP_DAYS) {
                continue;
            }
            // Late on WHMCS's last run: due after it, none of its notices went yet (0 days).
            $late = $due->lessThan($sentUpTo) ? (int) $due->diffInDays($sentUpTo) : 0;
            for ($day = 1; $day <= min($late, PaymentReminders::MAX_DAYS); $day++) {
                $rows[] = ['kind' => 'overdue', 'days' => $day] + $row;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('invoice_reminders')->insertOrIgnore($chunk);
        }
        $this->info('Reminders WHMCS sent already, kept as sent: ' . count($rows) . " (up to its daily run of {$sentUpTo->toDateString()}).");
    }

    /**
     * The day of WHMCS's last finished daily run (its reminders go in it): a dump taken before the day's run has not sent
     * the day's notices, and WHMCS's cron is off from the cut-over (replay of nixpal, 2026-11-03: four «Second Overdue
     * Notice» of 09:00 counted as sent by an import of 02:21). Never after today; today when WHMCS does not say.
     */
    private function whmcsLastDailyRun(Carbon $today): Carbon
    {
        if (!$this->sourceHas('tblconfiguration')) {
            return $today;
        }
        $at = [];
        foreach ($this->pdo->query("SELECT setting, value FROM tblconfiguration WHERE setting IN ('lastDailyCronEndTime', 'lastDailyCronInvocationTime')") as $row) {
            $at[$row['setting']] = trim((string) $row['value']);
        }
        $value = ($at['lastDailyCronEndTime'] ?? '') !== '' ? $at['lastDailyCronEndTime'] : ($at['lastDailyCronInvocationTime'] ?? '');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) !== 1 || $m[1] === '0000-00-00') {
            return $today;
        }
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $m[1]);
        } catch (Throwable) {
            return $today;
        }

        return $day === null || $day->greaterThan($today) ? $today : $day;
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
        if ($this->unpaidUpgrades !== []) {
            // Never charged by MyCS by itself (no_auto_charge): paid here, the upgrade would not apply (ADV-A1-1, R1).
            DB::table('invoices')->whereIn('id', array_unique(array_column($this->unpaidUpgrades, 'invoice')))->update(['no_auto_charge' => true]);
            $this->warn(count($this->unpaidUpgrades) . ' unpaid WHMCS upgrade(s) imported as a charge of their service, their invoice not charged'
                . ' automatically: paying it here does not apply the upgrade. Settle or cancel each in WHMCS before the final dump'
                . ' (cutover-runbook, ADV-A1-1): ' . implode(', ', array_map(fn (array $u) => "invoice #{$u['invoice']} service #{$u['service']}"
                . " WHMCS upgrade #{$u['upgrade']} ({$u['type']})", $this->unpaidUpgrades)) . '.');
        }
    }

    /** Lines of an invoice WHMCS deleted, skipped. */
    private int $orphanLines = 0;

    /**
     * What a WHMCS invoice line is for (tblinvoiceitems.type, relid), so income goes to its product (#504):
     * - Hosting: the service's renewal (services keep their WHMCS ids), as before: no kind, its period given when paid.
     * - Addon (relid = tblhostingaddons): the addon's own service when it was imported (a live one, WhmcsAddonImport), as a
     *   Hosting line; an ended one's on its parent, as below.
     * - Setup, PromoHosting (relid = the service), Addon of an ended addon (on its parent) and Upgrade (relid =
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
        // An upgrade WHMCS has not applied yet, on an open invoice: a charge as any, named and its invoice never charged
        // by MyCS by itself (ADV-A1-1). Not a pending upgrade here: the cron would reprice it, and its price is WHMCS's (R1).
        if ($type === 'Upgrade' && ($upgrade = $this->whmcsPendingUpgrades()[$relid] ?? null) !== null
            && self::invoiceStatus((string) ($record['invoice_status'] ?? '')) === Invoice::STATUS_UNPAID) {
            $this->unpaidUpgrades[] = ['invoice' => (int) $record['invoiceid'], 'service' => (int) $upgrade['relid'], 'upgrade' => $relid,
                'type' => (string) $upgrade['type']];
        }
        $service = match ($type) {
            'Setup', 'PromoHosting' => $relid,
            'Addon' => $this->addonServices()[$relid] ?? null,
            'Upgrade' => $this->upgradeServices()[$relid] ?? null,
            default => null,
        };

        return match (true) {
            // Never an addon's service (its id is no WHMCS service's, ADV-647-3), nor a deleted one's (WG-27): a service made
            // here later with its id would take it as its renewal, billed or paid (sweep 4, C-IMPORT-1).
            $type === 'Hosting' && isset($this->importedServices()[$relid]) && !in_array($relid, $this->addonsHere(), true) => ['reference_type' => Service::class, 'reference_id' => $record['relid'], 'kind' => null],
            // An addon imported as a service of its own (WhmcsAddonImport): its renewal, as a service's Hosting line (paid, it
            // renews it; unpaid, it is the renewal billed already).
            $type === 'Addon' && isset($this->addonsHere()[$relid]) => ['reference_type' => Service::class, 'reference_id' => $this->addonsHere()[$relid], 'kind' => null],
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
    /**
     * The currency of an account imported here (its tblcurrencies row), or null: an account WHMCS gives no currency, or
     * one skipped (no owner login, importUsers). Its orders, services and invoices are not imported then: never a row of
     * an account that is not here (sweep IMPORT-2: an overdue invoice of none stopped the reminders' run).
     *
     * @return array<string, mixed>|null
     */
    private function clientCurrency(int $clientId): ?array
    {
        $this->importedClients ??= array_fill_keys(DB::table('clients')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        if (!isset($this->importedClients[$clientId])) {
            return null;
        }
        if (!array_key_exists($clientId, $this->clientCurrencies)) {
            $stmt = $this->pdo->prepare('SELECT * FROM tblcurrencies WHERE id = (SELECT currency FROM tblclients WHERE id = :client_id LIMIT 1) LIMIT 1');
            $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
            $stmt->execute();
            $this->clientCurrencies[$clientId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        return $this->clientCurrencies[$clientId];
    }

    /** @var list<int> WHMCS invoices imported without their number (another invoice's) */
    private array $numbersDropped = [];

    /**
     * An invoice number as the unique key compares it: MariaDB's utf8mb4_uca1400_ai_ci is case- and accent-insensitive and
     * ignores trailing spaces, so «ΤΊΜ-0412» is «ΤΙΜ-0412» and «NX-ÉTÉ-1» is «NX-ETE-1» to it (review of the sweep). The
     * accents go by their decomposition; a final sigma is a sigma to the collation too.
     */
    private static function numberKey(string $number): string
    {
        $lower = mb_strtolower(rtrim($number, ' '));
        $decomposed = \Normalizer::normalize($lower, \Normalizer::FORM_D);
        $bare = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $lower : $decomposed) ?? $lower;

        return str_replace('ς', 'σ', $bare);
    }

    /** @var array<int, true>|null the accounts imported here, read once after importUsers */
    private ?array $importedClients = null;

    /** @var array<int, array<string, mixed>|null> */
    private array $clientCurrencies = [];

    /** @var list<int> WHMCS accounts with no owner login, skipped by importUsers */
    private array $ownerless = [];

    private function importedServices(): array
    {
        // WHMCS's services only: an addon's service (WhmcsAddonImport) is no WHMCS service id.
        return $this->importedServices ??= array_fill_keys(DB::table('services')->whereNull('whmcs_addon_id')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
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

    /** @var array<int, array<string, mixed>>|null WHMCS upgrades not applied yet (tblupgrades Pending, not paid), by id */
    private ?array $whmcsPendingUpgrades = null;

    /** @var list<array{invoice: int, service: int, upgrade: int, type: string}> their lines on open invoices (ADV-A1-1) */
    private array $unpaidUpgrades = [];

    /** @return array<int, array<string, mixed>> */
    private function whmcsPendingUpgrades(): array
    {
        if ($this->whmcsPendingUpgrades !== null) {
            return $this->whmcsPendingUpgrades;
        }
        try {
            $rows = $this->pdo->query("SELECT id, relid, type, paid FROM tblupgrades WHERE status = 'Pending' AND type IN ('package', 'configoptions')")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $rows = []; // an old WHMCS, a fixture without these columns
        }

        return $this->whmcsPendingUpgrades = array_column(array_filter($rows, fn (array $row) => strtoupper((string) $row['paid']) !== 'Y'), null, 'id');
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

        $this->migrateInBatch('tblaccounts', 'SELECT * FROM tblaccounts ORDER BY id LIMIT :limit OFFSET :offset', function ($records) {
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
        $this->importPendingPayments();
        $this->reportUnmappedGateways();
        if ($this->paymentsWithoutInvoice !== []) {
            $this->warn('Payments on no imported invoice, not imported (record them by hand if they are money still owed): WHMCS tblaccounts #'
                . implode(', #', $this->paymentsWithoutInvoice) . '.');
        }
    }

    /**
     * WHMCS's «Payment Pending» (a SEPA/ACH debit or a PayPal capture started, not cleared yet) comes `unpaid` with a
     * `processing` payment of what WHMCS still has it owe (its total, net of credit, less the money in on it): the money is
     * on its way (SavedMethodCharge::onItsWay), so the cron neither charges the saved method again nor reminds or
     * suspends for it (ADV-A1-3, ADV-A4-1). A Mass Pay parent in it never gets here: the import refuses to run on one
     * (handle, pendingMassPay; owner's decision after review 2 of #697). Dated by the invoice's day, before the
     * cut-over: never taken for MyCS's own (R1-6); its updated_at is the import's time, from which the cron counts a week
     * before telling staff of one still processing (R1-5, R1b-3). Ids above every WHMCS payment; its transaction id is one
     * staff type to complete it when the debit clears (RecordPaymentService: a processing payment of that id), or they
     * delete it if it failed. Never journaled, never in parity (only succeeded money is). Named, so staff follow them up.
     */
    private function importPendingPayments(): void
    {
        $pending = [];
        $nextId = max((int) $this->pdo->query('SELECT MAX(id) FROM tblaccounts')->fetchColumn(), (int) DB::table('invoice_transactions')->max('id')) + 1;
        foreach ($this->pdo->query("SELECT * FROM tblinvoices WHERE status = 'Payment Pending' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $id = (int) $record['id'];
            // A Mass Pay parent is no invoice here (and refused before the import: pendingMassPay).
            if (!isset($this->importedInvoices()[$id])) {
                continue;
            }
            // What WHMCS has it still owe: its total (net of credit) less the money in on it.
            $cents = WhmcsMassPay::cents($record['total'])
                - WhmcsMassPay::cents($this->row('SELECT COALESCE(SUM(amountin), 0) AS paid FROM tblaccounts WHERE invoiceid = ? AND amountin > 0', [$id])['paid'] ?? 0);
            if ($cents <= 0) {
                continue;
            }
            $at = $this->validateDate($record['date'] ?? null) ?? now();
            DB::table('invoice_transactions')->insert([
                'id' => $nextId++,
                'invoice_id' => $id,
                'gateway_id' => $this->gatewayId((string) ($record['paymentmethod'] ?? '')),
                'amount' => $cents / 100,
                'transaction_id' => "whmcs-pending-{$id}",
                'status' => InvoiceTransactionStatus::Processing->value,
                'created_at' => $at,
                'updated_at' => now(), // when it was imported (the cron's week, R1b-3)
            ]);
            $pending[] = $id;
        }
        if ($pending !== []) {
            $this->warn(count($pending) . ' WHMCS invoice(s) in Payment Pending (#' . implode(', #', $pending) . '): imported unpaid with a processing'
                . ' payment of their balance, so the cron neither charges nor reminds them. When the debit clears, record it with transaction id'
                . ' «whmcs-pending-<invoice>», through the same gateway or none; if it failed, delete that payment (ADV-A1-3).');
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
                ? DB::table('invoice_transactions')->where('id', (int) $refund['refundid'])->first(['id', 'invoice_id', 'amount', 'refunded_amount', 'credited_amount', 'transaction_id'])
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
            // The gateway's id of a cash refund (tblaccounts.transid: PayPal's refund transaction, Stripe's balance
            // transaction txn_..., which its event carries as balance_transaction): its late event after the cut-over (a
            // `refund.updated`) is then a replay, never booked a second time (ADV-A4-2, review P1).
            if (!$credit) {
                $this->keepGatewayRefund($refund, $payment, $cents, max(0, $take));
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

    /** @var array<int, array{gateway: string, transid: string}>|null WHMCS refund rows (tblaccounts) and the gateway they went through */
    private ?array $refundGateways = null;

    /** @var array<int, string> invoice id => its currency, for the refunds' gateway rows */
    private array $refundCurrencies = [];

    /**
     * A WHMCS refund at a gateway MyCS has an extension for, kept by its id there: its late event is a replay (ADV-A4-2).
     * A refund WHMCS has no refundid for (an old version, typed by hand) is on no payment here, so it gets no row either.
     */
    private function keepGatewayRefund(array $refund, object $payment, int $cents, int $recorded): void
    {
        $this->refundGateways ??= collect($this->sourceRows('SELECT id, gateway, transid FROM tblaccounts WHERE amountout > 0') ?? [])
            ->mapWithKeys(fn (array $row) => [(int) $row['id'] => ['gateway' => (string) ($row['gateway'] ?? ''), 'transid' => trim((string) ($row['transid'] ?? ''))]])->all();
        $row = $this->refundGateways[(int) $refund['id']] ?? null;
        $module = strtolower(trim($row['gateway'] ?? ''));
        $gateway = self::extensionFor($module);
        if ($gateway === null || $row['transid'] === '') {
            return;
        }
        // Once per id (WHMCS may write a refund's id twice): the first is the one recorded.
        // The payment's id read with it, its invoice's currency once per invoice (review of #694, F9).
        $currency = $this->refundCurrencies[(int) $payment->invoice_id] ??= (string) DB::table('invoices')->where('id', $payment->invoice_id)->value('currency_code');
        DB::table('gateway_refunds')->insertOrIgnore(['invoice_transaction_id' => $payment->id, 'gateway' => $gateway, 'reference' => $row['transid'],
            'payment_reference' => $payment->transaction_id, 'amount' => $cents / 100, 'currency_code' => $currency, 'recorded_amount' => $recorded / 100,
            'origin' => GatewayRefund::ORIGIN_IMPORT, 'created_at' => $this->validateDate($refund['date']), 'updated_at' => now()]);
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

    /**
     * The MyCS gateway extension of a WHMCS module (lower case): its payments' (GATEWAYS, any mollie*), and with $preferred
     * also a way only a preference or a label names (PREFERRED_GATEWAYS). One place (review of #694, F6).
     */
    private static function extensionFor(string $module, bool $preferred = false): ?string
    {
        return self::GATEWAYS[$module] ?? ($preferred ? self::PREFERRED_GATEWAYS[$module] ?? null : null) ?? (str_starts_with($module, 'mollie') ? 'Mollie' : null);
    }

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
            $extension = self::extensionFor($module);
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
        // Never a deleted one (staff removed it): a new one, as the admin's «Install» would (review of #679).
        $id = DB::table('extensions')->where('type', 'gateway')->where('extension', $extension)->whereNull('deleted_at')->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $gateway = Extension::create(['name' => $extension, 'type' => 'gateway', 'extension' => $extension]);
        ExtensionHelper::call($gateway, 'installed', mayFail: true);
        $this->warn("Gateway «{$extension}» installed, disabled: set it up and enable it.");

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
     * WHMCS promotions (tblpromotions) as coupons, with their ids (tblhosting.promoid), and their rules as MyCS has them
     * since #274 (WG-6): a price override, its billing cycles, new or existing clients, once per client, «requires» (held
     * already, with «Requires Existing»), recurring for N times (N discounted invoices, the order's among them: review of
     * #650), «Lifetime Promotion» (kept_on_product_change: ADV-650-1). One MyCS still cannot express as WHMCS applies it
     * (once per order, for upgrades, a fixed amount in more than one currency, a negative value, addons or domains) is
     * imported closed for new orders (expired now) and reported: staff recreate it. The services ordered with it keep it,
     * for the record (serviceCoupon), also those WHMCS no longer marks (ADV-650-2: «once per client» remembers them).
     */
    /** WHMCS's billing cycles as a coupon lists them (Coupon::cycleKey); its domains' «1Years»… are no product's. */
    private const PROMOTION_CYCLES = ['Monthly' => 'month:1', 'Quarterly' => 'month:3', 'Semi-Annually' => 'month:6',
        'Annually' => 'year:1', 'Biennially' => 'year:2', 'Triennially' => 'year:3', 'One Time' => 'one_time'];

    /** @var array<int, int> WHMCS orders placed with a promotion's code (tblorders.promocode) => its id: serviceCoupon */
    private array $orderPromotions = [];

    /** @var array<int, array{recurring: int, recurfor: int, code: string}> the promotions imported, by id: serviceCoupon */
    private array $promotions = [];

    private function importPromotions(): void
    {
        $this->info('Importing promotions... (' . $this->count('tblpromotions') . ' records)');
        $now = now()->toDateTimeString();
        $products = DB::table('products')->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $currencies = (int) $this->pdo->query('SELECT COUNT(*) FROM tblcurrencies')->fetchColumn();
        $closed = now()->subSecond()->toDateTimeString(); // past already: closed from the import on

        $promotionCodes = $this->pdo->query('SELECT code, id FROM tblpromotions ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        try {
            foreach ($this->pdo->query("SELECT id, promocode FROM tblorders WHERE promocode <> ''")->fetchAll(PDO::FETCH_KEY_PAIR) as $order => $code) {
                if (isset($promotionCodes[$code])) {
                    $this->orderPromotions[(int) $order] = (int) $promotionCodes[$code];
                }
            }
        } catch (PDOException) {
            // a source without tblorders (a test's): none
        }

        foreach ($this->pdo->query('SELECT * FROM tblpromotions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $promo) {
            $why = [];
            [$type, $value, $appliesTo] = match ($promo['type']) {
                'Percentage' => ['percentage', $promo['value'], 'all'],
                'Fixed Amount' => ['fixed', $promo['value'], 'all'],
                'Free Setup' => ['percentage', '100.00', 'setup_fee'],
                'Price Override' => ['override', $promo['value'], 'all'],
                default => ['fixed', '0.00', 'all'],
            };
            if (!in_array($promo['type'], ['Percentage', 'Fixed Amount', 'Free Setup', 'Price Override'], true)) {
                $why[] = "type «{$promo['type']}»";
            }
            // A surcharge or a price below zero (WHMCS takes one; MyCS's discount refuses it, the cart would fail: ADV-650-3),
            // or more than the whole.
            if (!is_numeric($value) || $value < 0 || ($type === 'percentage' && $value > 100)) {
                $why[] = "value {$promo['value']}";
            }
            // The first payment only (null), every one (0), or N invoices, the order's among them: WHMCS's promocount is 1
            // after the order and the promotion stops at recurfor (review of #650: FREETRIAL, 2, never more than 2
            // discounted invoices; divramis50, 1, the first renewal at full price). MyCS counts cycles the same way.
            $recurring = (int) $promo['recurring'] === 0 ? null : max(0, (int) $promo['recurfor']);
            if ($recurring !== null && $recurring > 1000) {
                $why[] = "recurring for {$promo['recurfor']} times";
            }
            $cycles = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $promo['cycles'])))));
            $billingCycles = null; // every cycle: none listed, or all of them
            if ($cycles !== []) {
                // A free account has nothing to discount: known, no cycle (review of #650: it is no one-time plan).
                $unknown = array_filter($cycles, fn (string $cycle) => !isset(self::PROMOTION_CYCLES[$cycle]) && $cycle !== 'Free Account'
                    && preg_match('/^\d+Years$/', $cycle) !== 1);
                $keys = array_values(array_unique(array_map(fn (string $cycle) => self::PROMOTION_CYCLES[$cycle],
                    array_filter($cycles, fn (string $cycle) => isset(self::PROMOTION_CYCLES[$cycle])))));
                if ($unknown !== []) {
                    $why[] = 'billing cycles unknown here (' . implode(', ', $unknown) . ')';
                } elseif ($keys === []) {
                    $why[] = 'billing cycles of domains or free accounts only (' . implode(', ', $cycles) . ')';
                } elseif (array_diff(array_unique(self::PROMOTION_CYCLES), $keys) !== []) {
                    $billingCycles = $keys;
                }
            }
            // A MyCS coupon has no currency: a fixed amount means another sum in each one (WHMCS converts it).
            if ($promo['type'] === 'Fixed Amount' && $currencies > 1) {
                $why[] = 'a fixed amount with more than one currency';
            }
            foreach (['applyonce' => 'once per order', 'upgrades' => 'for upgrades'] as $column => $label) {
                if ((int) ($promo[$column] ?? 0) === 1) {
                    $why[] = $label;
                }
            }
            $customers = 'all';
            if ((int) ($promo['newsignups'] ?? 0) === 1 && (int) ($promo['existingclient'] ?? 0) === 1) {
                $why[] = 'new and existing clients only'; // no one, as WHMCS reads it
            } elseif ((int) ($promo['newsignups'] ?? 0) === 1) {
                $customers = 'new';
            } elseif ((int) ($promo['existingclient'] ?? 0) === 1) {
                $customers = 'existing';
            }
            $requires = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($promo['requires'] ?? ''))))));
            $requiredIds = array_values(array_unique(array_filter(array_map('intval', array_filter($requires, 'ctype_digit')), fn (int $id) => $products->has($id))));
            if (count($requiredIds) !== count($requires)) {
                $why[] = 'requires addons, domains or products not imported';
            }
            $applies = array_filter(array_map('trim', explode(',', (string) $promo['appliesto'])));
            $productIds = array_values(array_unique(array_filter(array_map('intval', array_filter($applies, 'ctype_digit')), fn (int $id) => $products->has($id))));
            if (count($productIds) !== count(array_unique($applies))) {
                $why[] = 'addons, domains or products not imported';
            }
            // No products in MyCS means every product (ADV-3): one WHMCS applies to nothing stays closed.
            if ($applies === []) {
                $why[] = 'applies to no product';
            }
            // An override is for the products it names, in the default currency (Coupon::covers): said where WHMCS has more.
            if ($type === 'override' && $currencies > 1 && $why === []) {
                $this->warn("Promotion #{$promo['id']} «{$promo['code']}»: a price override, applied here in the default currency only.");
            }
            // Its uses: MyCS counts the services with it, WHMCS counted more (deleted services, orders). Used up there:
            // closed; else the uses left there on top of those brought here (ADV-2).
            $maxUses = null;
            if ((int) $promo['maxuses'] > 0) {
                $left = (int) $promo['maxuses'] - (int) ($promo['uses'] ?? 0);
                if ($left <= 0) {
                    $why[] = "used up ({$promo['uses']} of {$promo['maxuses']})";
                }
                // The services that come with it (serviceCoupon): those with its promoid, and those of orders with its code.
                $orders = array_keys(array_filter($this->orderPromotions, fn (int $id) => $id === (int) $promo['id']));
                $statement = $this->pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM tblhosting WHERE promoid = ?'
                    . ($orders === [] ? '' : ' OR (COALESCE(promoid, 0) = 0 AND orderid IN (' . implode(',', array_map('intval', $orders)) . '))'));
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
                'max_uses_per_user' => null,
                'once_per_client' => (int) $promo['onceperclient'] === 1,
                // WHMCS drops one that is not lifetime on an upgrade (ADV-650-1).
                'kept_on_product_change' => (int) ($promo['lifetimepromo'] ?? 0) === 1,
                'customers' => $customers,
                'requires_existing' => (int) ($promo['requiresexisting'] ?? 0) === 1 && $requiredIds !== [],
                'billing_cycles' => $billingCycles === null ? null : json_encode($billingCycles),
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
            if ($requiredIds !== []) {
                DB::table('coupon_required_products')->insert(array_map(fn (int $id) => ['coupon_id' => $promo['id'], 'product_id' => $id], $requiredIds));
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
        // WHMCS sets promoid back to 0 when a recurring promotion ends: its order's code still says it (ADV-650-2).
        $promo = (int) ($record['promoid'] ?? 0) ?: ($this->orderPromotions[(int) ($record['orderid'] ?? 0)] ?? 0);
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
     * Every WHMCS invoice in «Payment Pending» with an «Invoice» line (a Mass Pay, whatever massPayParents makes of it:
     * one that took some money, has a child of another customer or another kind of line would otherwise come as an
     * ordinary invoice billing its children again, held, while the children are dunned: review of 142fbee1), each with
     * its children: the import refuses to run on them (handle). The runbook's pre-dump query, the same set. A source
     * without the invoice tables (an old WHMCS, a test's): none.
     *
     * @return array<int, list<int>> invoice => its children (its «Invoice» lines), by id
     */
    private function pendingMassPay(): array
    {
        if (!$this->sourceHas('tblinvoices') || !$this->sourceHas('tblinvoiceitems')) {
            return [];
        }
        $pending = [];
        foreach ($this->pdo->query("SELECT p.id, ii.relid FROM tblinvoices p JOIN tblinvoiceitems ii ON ii.invoiceid = p.id AND ii.type = 'Invoice'"
            . " WHERE p.status = 'Payment Pending' ORDER BY p.id, ii.relid")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pending[(int) $row['id']] ??= [];
            if (!in_array((int) $row['relid'], $pending[(int) $row['id']], true)) {
                $pending[(int) $row['id']][] = (int) $row['relid'];
            }
        }

        return $pending;
    }

    /** Days after which an unpaid WHMCS order invoice refuses the import (abandonedOrders). */
    public const ABANDONED_ORDER_DAYS = 7;

    /**
     * Every WHMCS invoice imported unpaid (invoiceStatus, ADV-531-1: Collections too) dated more than ABANDONED_ORDER_DAYS
     * ago that orders something: a Hosting line of a Pending service, an Addon line of a Pending addon (imported as a
     * pending service, REV2-FR-2), or a DomainRegister/DomainTransfer line of a Pending domain (one WHMCS has Active, set up
     * before payment, is a debt: paying it sets nothing up; sweep 5 G-MONEY-SERVICE-3). «Payment Pending» is left out: it comes with a
     * processing payment, which credit does not pay over. The import refuses to run on them (handle): credit would pay
     * them on the first cron and set them up. The runbook's pre-dump query, the same set. A source without these tables
     * (a test's): none.
     *
     * @return list<int>
     */
    private function abandonedOrders(): array
    {
        if (!$this->sourceHas('tblinvoices') || !$this->sourceHas('tblinvoiceitems') || !$this->sourceHas('tblhosting')) {
            return [];
        }
        $addons = $this->sourceHas('tblhostingaddons');
        $domains = $this->sourceHas('tbldomains');
        $query = $this->pdo->prepare("SELECT DISTINCT i.id FROM tblinvoices i JOIN tblinvoiceitems ii ON ii.invoiceid = i.id
            LEFT JOIN tblhosting h ON ii.type = 'Hosting' AND h.id = ii.relid"
            . ($addons ? " LEFT JOIN tblhostingaddons a ON ii.type = 'Addon' AND a.id = ii.relid" : '')
            . ($domains ? " LEFT JOIN tbldomains d ON ii.type IN ('DomainRegister', 'DomainTransfer') AND d.id = ii.relid" : '')
            . " WHERE i.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded', 'Payment Pending') AND i.date < ?
            AND (h.domainstatus = 'Pending'"
            . ($addons ? " OR a.status = 'Pending'" : '')
            // Only a plain «Pending» is sent from here; one WHMCS sent (Pending Registration/Transfer) never is again.
            . ($domains ? " OR d.status = 'Pending'" : '') . ')
            ORDER BY i.id');
        $query->execute([now()->subDays(self::ABANDONED_ORDER_DAYS)->toDateString()]);

        return array_values(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Live WHMCS services (Active, Suspended) whose nextinvoicedate is past their nextduedate and whose only renewal
     * invoices due on that nextduedate are Cancelled: WHMCS invoiced that period, staff cancelled it, and WHMCS never
     * bills it again. Here the period would be billed again from nextduedate. The import refuses to run on them (handle;
     * owner's decision 2026-11-08, sweep 5, in place of sweep 4's C-FIRST-RUN-2 rule): staff settle each in WHMCS before
     * the dump. One with a cancellation request is left out: never billed here (RenewalBilling), it ends on its date as
     * asked (G-MONEY-SERVICE-1). The runbook's pre-dump query, the same set. A source without these tables or
     * nextinvoicedate (a test's): none.
     *
     * @return list<int> tblhosting ids
     */
    private function cancelledRenewals(): array
    {
        if (!$this->sourceHas('tblhosting') || !$this->sourceHas('tblinvoiceitems') || !$this->sourceHas('tblinvoices')) {
            return [];
        }
        try {
            return array_values(array_map('intval', $this->pdo->query("SELECT h.id FROM tblhosting h
                JOIN tblinvoiceitems ii ON ii.type = 'Hosting' AND ii.relid = h.id JOIN tblinvoices i ON i.id = ii.invoiceid AND i.duedate = h.nextduedate
                WHERE h.domainstatus IN ('Active', 'Suspended') AND h.nextduedate > '0000-00-00' AND h.nextinvoicedate > h.nextduedate"
                . ($this->sourceHas('tblcancelrequests') ? ' AND NOT EXISTS (SELECT 1 FROM tblcancelrequests c WHERE c.relid = h.id)' : '')
                . " GROUP BY h.id HAVING SUM(CASE WHEN i.status = 'Cancelled' THEN 0 ELSE 1 END) = 0 ORDER BY h.id")->fetchAll(PDO::FETCH_COLUMN)));
        } catch (PDOException) {
            return []; // no nextinvoicedate in the source
        }
    }

    /**
     * Live recurring WHMCS addons (WhmcsAddonImport::LIVE) off their service's billing day (not its date, nor k of their own
     * cycles back from it: WhmcsAddonImport::onServiceDay, REV-KISS-2): the import refuses to run on them (handle). The
     * runbook's pre-dump check. A source without addons: none.
     *
     * @return list<int> tblhostingaddons ids
     */
    private function addonsApart(): array
    {
        if (!$this->sourceHas('tblhostingaddons')) {
            return [];
        }
        $live = implode(', ', array_map(fn (string $status) => $this->pdo->quote($status), WhmcsAddonImport::LIVE));

        $rows = $this->pdo->query('SELECT a.id, a.billingcycle, a.nextduedate, h.nextduedate AS service_due FROM tblhostingaddons a'
            . " JOIN tblhosting h ON h.id = a.hostingid WHERE a.status IN ({$live}) AND a.billingcycle NOT IN ('Free Account', 'One Time', 'Free')"
            . ' AND a.nextduedate <> h.nextduedate ORDER BY a.id')->fetchAll(PDO::FETCH_ASSOC);

        // On its service's billing day by its own cycle (a monthly addon of a yearly service, a month before it) is fine.
        return array_values(array_map(fn (array $row) => (int) $row['id'], array_filter($rows,
            fn (array $row) => !WhmcsAddonImport::onServiceDay((string) $row['billingcycle'], (string) $row['nextduedate'], (string) $row['service_due']))));
    }

    /**
     * WHMCS Mass Pay parents (PR F3, WhmcsMassPay): not invoices here. A paid one's payment lands on its child due first,
     * the rest pays the other children as allocations of it, and what it took from account credit pays what is left; it
     * stays a paid payment group with its WHMCS id. A cancelled one is dropped (its children are invoices on their own);
     * credit WHMCS applied to one never paid goes back to the balance (WhmcsMoneyJournal::unfinishedMassPay, ADV-A1-2).
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
        foreach ($journal->unfinishedMassPay() as $parent) {
            $this->warn(sprintf('Mass Pay #%d was never paid in WHMCS: the %s %s of credit applied to it is back on customer #%d\'s balance (ADV-A1-2).',
                $parent['id'], number_format($parent['cents'] / 100, 2, '.', ''), $parent['currency_code'], $parent['client_id']));
        }
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
