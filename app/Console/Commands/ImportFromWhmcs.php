<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Extension;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Providers\SettingsProvider;
use App\Services\Import\WhmcsMassPay;
use App\Services\Import\WhmcsTicketImport;
use App\Services\Licensing\WhmcsLicensingImport;
use App\Services\Money\MoneyTrail;
use Brick\Math\BigDecimal;
use Closure;
use DB;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Log;
use MyCS\Pricing\LinePricer;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class ImportFromWhmcs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-from-whmcs {dbname} {username?} {host?} {port?} {--ticket-attachments= : A copy of WHMCS\'s attachments folder, for the tickets\' files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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

        $dbname = $this->argument('dbname');
        $host = $this->askOrUseENV(argument: 'host', env: 'DB_HOST', question: 'Enter the host:', placeholder: 'localhost');
        $port = $this->askOrUseENV(argument: 'port', env: 'DB_PORT', question: 'Enter the port:', placeholder: '3306');
        $username = $this->askOrUseENV(argument: 'username', env: 'DB_USERNAME', question: 'Enter the username:', placeholder: 'mycs');
        $password = password("Enter the password for user '$username':", required: true);

        try {
            $this->pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->info('Connected to WHMCS database, Starting migration...');

            $this->prepareDatabase();

            DB::statement('SET foreign_key_checks=0');

            // Remove default currencies
            DB::table('currencies')->truncate();
            // Import currencies
            $this->importCurrencies();
            $this->importUsers();
            $this->importAdmins();
            $this->importCategories();
            $this->importConfigOptions();
            $this->importProducts();
            $this->importPromotions();
            $this->importTickets();
            $this->importOrders();
            $this->importServices();
            $this->importLicensing();
            $this->importCancellations();
            $this->importInvoices();
            $this->importInvoiceItems();
            $this->importPayments();
            $this->importMassPay();

            DB::statement('SET foreign_key_checks=1');

            SettingsProvider::flushCache();
        } catch (PDOException $e) {
            $this->fail('Connection failed: ' . $e->getMessage());
        }
    }

    private function prepareDatabase()
    {
        // Rerun migrations
        $this->call('migrate:fresh', ['--force' => true]);
        // Seed default data
        $this->call('db:seed', ['--force' => true]);
    }

    protected function askOrUseENV(string $argument, string $env, string $question, string $placeholder): string
    {
        $arg_value = $this->argument($argument);
        if ($arg_value) {
            return $arg_value;
        }

        $env_value = env($env);
        if (!is_null($env_value) && $env_value !== '') {
            return $env_value;
        }

        return text($question, required: true, placeholder: $placeholder);
    }

    protected function validateDate($date): ?string
    {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
            if (!$dateTime) {
                $dateTime = \DateTime::createFromFormat('Y-m-d', $date);
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

    private function importUsers()
    {
        $this->info('Importing users... (' . $this->count('tblclients') . ' records)');

        $this->migrateInBatch('tblclients', 'SELECT * FROM tblclients LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            $clients = [];
            $contacts = [];
            $credits = [];

            foreach ($records as $record) {
                // Get client (join tblusers_clients on tblusers.id = tblusers_clients.auth_user_id and tblusers_clients.owner = 1)
                $stmt = $this->pdo->prepare('SELECT * FROM tblusers WHERE id = (SELECT auth_user_id FROM tblusers_clients WHERE client_id = :client_id AND owner = 1 LIMIT 1)');
                $stmt->bindValue(':client_id', $record['id'], PDO::PARAM_INT);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    continue;
                }

                $data[] = [
                    'id' => $record['id'],
                    'first_name' => $record['firstname'],
                    'last_name' => $record['lastname'],
                    'email' => $record['email'],
                    'password' => $user['password'],
                    'email_verified_at' => $user['email_verified_at'] ? $user['email_verified_at'] : null,
                    'updated_at' => $record['updated_at'] == '0000-00-00 00:00:00' ? now() : $record['updated_at'],
                    'created_at' => $record['created_at'] == '0000-00-00 00:00:00' ? now() : $record['created_at'],
                ];
                // The account keeps the WHMCS client id; its owner is the login (docs/accounts.md, PR 2a).
                $clients[] = ['id' => $record['id'], 'created_at' => end($data)['created_at'], 'updated_at' => end($data)['updated_at'],
                    'tax_exempt' => (bool) ($record['taxexempt'] ?? false), 'separate_invoices' => (bool) ($record['separateinvoices'] ?? false),
                    'auto_charge' => !($record['disableautocc'] ?? false)];

                // The account's details are its primary contact, field for field (docs/accounts.md, PR 3a).
                $value = fn (string $key) => isset($record[$key]) && trim((string) $record[$key]) !== '' ? trim((string) $record[$key]) : null;
                $contacts[] = [
                    'client_id' => $record['id'], 'primary_of_client_id' => $record['id'],
                    'type' => $value('companyname') !== null ? 'business' : 'individual',
                    'first_name' => $value('firstname'), 'last_name' => $value('lastname'), 'company_name' => $value('companyname'),
                    'tax_id' => $value('tax_id'), 'email' => $value('email'), 'phone' => $value('phonenumber'),
                    'address1' => $value('address1'), 'address2' => $value('address2'), 'city' => $value('city'),
                    'state' => $value('state'), 'postcode' => $value('postcode'),
                    'country' => $value('country') !== null ? strtoupper(substr($value('country'), 0, 2)) : null,
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

            DB::table('users')->insert($data);
            DB::table('clients')->insert($clients);
            DB::table('client_user')->insert(array_map(fn (array $client) => [
                'client_id' => $client['id'], 'user_id' => $client['id'], 'owner' => true, 'owner_of_client_id' => $client['id'],
                'granted_at' => $client['created_at'], 'created_at' => now(), 'updated_at' => now(),
            ], $clients));
            if (count($contacts) > 0) {
                DB::table('contacts')->insert($contacts);
            }
            if (count($credits) > 0) {
                DB::table('credits')->insert($credits);
                // The journal opens with the imported balance, so credits = Σ events (docs/money-trail.md §2).
                DB::table('money_events')->insert(array_map(fn (array $credit) => [
                    'kind' => 'credit_in',
                    'amount' => $credit['amount'],
                    'currency_code' => $credit['currency_code'],
                    'client_id' => $credit['client_id'],
                    'credit_balance_after' => $credit['amount'],
                    'source' => MoneyTrail::SOURCE_IMPORT,
                    'cause' => MoneyTrail::CAUSE_OPENING_BALANCE,
                    'occurred_at' => now(),
                ], $credits));
            }
        });
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

        $this->migrateInBatch('tblhosting', 'SELECT * FROM tblhosting LIMIT :limit OFFSET :offset', function ($records) {
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

                $data[] = [
                    'id' => $record['id'],
                    'order_id' => $record['orderid'],
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
                    // WHMCS's own suspension for non-payment: a payment lifts it here too. Any other reason
                    // (or none) was staff's: a manual hold (docs/billing-cursor.md).
                    'dunning_suspended_at' => $record['domainstatus'] === 'Suspended'
                        && strcasecmp(trim((string) ($record['suspendreason'] ?? '')), 'Overdue on Payment') === 0 ? now() : null,
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
        });
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
                    'status' => match ($record['status']) {
                        'Draft' => 'draft',
                        'Paid' => 'paid',
                        'Unpaid' => 'unpaid',
                        'Cancelled' => 'cancelled',
                        'Refunded' => 'refunded',
                        default => 'unpaid',
                    },
                    'currency_code' => $user['code'],
                    // When it was paid (WHMCS datepaid): the revenue reports count by it (#275).
                    'paid_at' => $record['status'] === 'Paid' ? $this->validateDate($record['datepaid'] ?? null) : null,
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['date']),
                ];
            }

            DB::table('invoices')->insert($data);
        });

        // Set invoice number in settings to highest imported invoice number + 1. A Mass Pay parent (not imported, PR F3)
        // keeps its id too: a new invoice never takes the number a customer quotes for one.
        $highestInvoiceNumber = max((int) DB::table('invoices')->max('id'), (int) $this->pdo->query('SELECT MAX(id) FROM tblinvoices')->fetchColumn());
        // DDL commits an open transaction (MySQL/MariaDB): only outside one, as the importer runs.
        if ((DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') && DB::transactionLevel() === 0) {
            DB::statement('ALTER TABLE invoices AUTO_INCREMENT = ' . ($highestInvoiceNumber + 1));
        }
        Setting::updateOrCreate(
            ['key' => 'invoice_number'],
            ['value' => $highestInvoiceNumber + 1]
        );
    }

    private function importInvoiceItems()
    {
        $this->info('Importing invoice items... (' . $this->count('tblinvoiceitems') . ' records)');

        // Each line with the tax it was issued with (Δ1): WHMCS keeps amounts net, «taxed» per line and the rate on the
        // invoice (TaxPerLineItem: rounded per line, as here). The second tax (taxrate2) is not used by either company.
        // Ordered, so the batches are stable.
        $this->migrateInBatch('tblinvoiceitems', 'SELECT ii.*, i.taxrate AS invoice_taxrate FROM tblinvoiceitems ii LEFT JOIN tblinvoices i ON i.id = ii.invoiceid ORDER BY ii.id LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            foreach ($records as $record) {
                if (isset($this->massPayParents()[(int) $record['invoiceid']])) {
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
                    'created_at' => now(),
                    'updated_at' => now(),
                    'reference_type' => match ($record['type']) {
                        'Hosting' => Service::class,
                        // A credit purchase (add funds), as MyCS's own: never income (the credit is, when it pays; #275).
                        'AddFunds' => Credit::class,
                        'Domain' => null,
                        'Addon' => null,
                        'Upgrade' => null,
                        default => null,
                    },
                    'reference_id' => $record['type'] === 'AddFunds' ? null : $record['relid'],
                ];
            }

            DB::table('invoice_items')->insert($data);
        });
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
        $this->importRefunds();
        $this->reportUnmappedGateways();
    }

    /**
     * WHMCS's refunds (a tblaccounts row with `amountout`, its payment in `refundid`) on the payment they refunded:
     * `refunded_amount`, so what was given back is never given back again (adversarial ADV-1), up to its amount.
     */
    private function importRefunds(): void
    {
        try {
            $refunds = $this->pdo->query('SELECT refundid, amountout FROM tblaccounts WHERE refundid > 0 AND amountout > 0')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return; // a WHMCS without refunds' column
        }
        $byPayment = [];
        foreach ($refunds as $refund) {
            $byPayment[(int) $refund['refundid']] = BigDecimal::of($byPayment[(int) $refund['refundid']] ?? '0')->plus(number_format((float) $refund['amountout'], 2, '.', ''));
        }
        foreach ($byPayment as $paymentId => $refunded) {
            $payment = DB::table('invoice_transactions')->where('id', $paymentId)->first(['amount', 'refunded_amount']);
            if ($payment === null) {
                continue;
            }
            $total = BigDecimal::of((string) ($payment->refunded_amount ?? '0'))->plus($refunded);
            $cap = BigDecimal::of((string) $payment->amount);
            DB::table('invoice_transactions')->where('id', $paymentId)->update(['refunded_amount' => (string) ($total->isGreaterThan($cap) ? $cap : $total)->toScale(2)]);
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

    /** @return array<int, true> */
    private function massPayParents(): array
    {
        return $this->massPayParents ??= array_fill_keys(array_map('intval', $this->pdo->query(
            "SELECT DISTINCT ii.invoiceid FROM tblinvoiceitems ii JOIN tblinvoices c ON c.id = ii.relid JOIN tblinvoices p ON p.id = ii.invoiceid
            WHERE ii.type = 'Invoice' AND c.userid = p.userid
            AND NOT EXISTS (SELECT 1 FROM tblinvoiceitems n WHERE n.invoiceid = c.id AND n.type = 'Invoice')
            AND NOT EXISTS (SELECT 1 FROM tblinvoiceitems o WHERE o.invoiceid = p.id AND o.type <> 'Invoice')
            AND (p.status = 'Paid' OR NOT EXISTS (SELECT 1 FROM tblaccounts a WHERE a.invoiceid = p.id AND a.amountin > 0))"
        )->fetchAll(PDO::FETCH_COLUMN)), true);
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
        $childCredit = [];
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
            foreach ($payments as $payment) {
                if ((float) $payment['amountout'] > 0) {
                    $this->warn("Mass Pay #{$parentId}: refund {$payment['id']} on it was not imported. Check it by hand.");
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
                return [
                    'id' => $nextId++,
                    'invoice_id' => $allocation['invoice_id'],
                    'amount' => $allocation['amount'] / 100,
                    'is_credit_transaction' => true,
                    'source_transaction_id' => $allocation['source_id'],
                    'created_at' => $allocation['date'] ?? $this->validateDate($parent['date']),
                    'updated_at' => $allocation['date'] ?? $this->validateDate($parent['date']),
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
