<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Providers\SettingsProvider;
use App\Services\Import\WhmcsMassPay;
use App\Services\Licensing\WhmcsLicensingImport;
use App\Services\Money\MoneyTrail;
use Closure;
use DB;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Batch;
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
    protected $signature = 'app:import-from-whmcs {dbname} {username?} {host?} {port?}';

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

    /** The account of a login: the one it owns, or a new one (a guest of a WHMCS ticket). */
    private function clientIdOfUser(int $userId): int
    {
        $owned = DB::table('client_user')->where('user_id', $userId)->whereNotNull('owner_of_client_id')->value('client_id');
        if ($owned !== null) {
            return (int) $owned;
        }
        $clientId = DB::table('clients')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        DB::table('client_user')->insert(['client_id' => $clientId, 'user_id' => $userId, 'owner' => true, 'owner_of_client_id' => $clientId,
            'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        // Every account has its primary contact (its details, PR 3a): from the login here.
        $user = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'email']);
        DB::table('contacts')->insert(['client_id' => $clientId, 'primary_of_client_id' => $clientId, 'type' => 'individual',
            'first_name' => $user?->first_name, 'last_name' => $user?->last_name, 'email' => $user?->email,
            'created_at' => now(), 'updated_at' => now()]);

        return $clientId;
    }

    private function getUserIdTicket($message, &$userId)
    {
        if (!$userId) {
            if (DB::table('users')->where('email', $message['email'])->exists()) {
                $userRecord = DB::table('users')->where('email', $message['email'])->first();
                $userId = $userRecord->id;
            }
            if (!$userId) {
                // Create a user with the email
                $newUserId = DB::table('users')->insertGetId([
                    'first_name' => $message['name'],
                    'last_name' => '',
                    'email' => $message['email'],
                    'password' => bcrypt(\Str::random(16)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $userId = $newUserId;
            }
        }

        return $userId;
    }

    /**
     * A reply by a WHMCS admin (its `admin` column, a name) → the staff member of that name; one no longer there → a
     * disabled member of that name, so the reply keeps its author and is never taken for the customer's.
     */
    private function staffIdOfReply(array $message): ?int
    {
        $name = trim((string) ($message['admin'] ?? ''));
        if ($name === '') {
            return null;
        }
        $staff = DB::table('staff')->where(DB::raw("CONCAT(first_name, ' ', last_name)"), $name)
            ->orWhere('first_name', $name)->first();
        if ($staff) {
            return $staff->id;
        }

        return DB::table('staff')->insertGetId([
            'first_name' => $name,
            'last_name' => '',
            'email' => 'whmcs-admin-' . \Str::slug($name) . '-' . \Str::random(6) . '@invalid.example',
            'password' => bcrypt(\Str::random(32)),
            'active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @var array<int, int|null> WHMCS department id => ours */
    private array $ticketDepartments = [];

    /** The department of a WHMCS ticket, made once from tblticketdepartments (its name, order and email). */
    private function ticketDepartmentId(int $whmcsId): ?int
    {
        if (array_key_exists($whmcsId, $this->ticketDepartments)) {
            return $this->ticketDepartments[$whmcsId];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM tblticketdepartments WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $whmcsId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->ticketDepartments[$whmcsId] = null;
        }
        $department = TicketDepartment::query()->firstOrNew(['whmcs_id' => $whmcsId]);
        $department->forceFill([
            'name' => (string) $row['name'],
            'sort' => max(0, (int) ($row['order'] ?? 0)),
            'hidden' => (bool) ($row['hidden'] ?? false),
            'clients_only' => (bool) ($row['clientsonly'] ?? false),
            'email' => ($row['email'] ?? '') !== '' ? (string) $row['email'] : null,
        ])->save();

        return $this->ticketDepartments[$whmcsId] = $department->id;
    }

    private function importTickets()
    {

        $this->info('Importing tickets... (' . $this->count('tbltickets') . ' records)');

        $this->migrateInBatch('tbltickets', 'SELECT * FROM tbltickets LIMIT :limit OFFSET :offset', function ($records) {
            $data = [];
            $messages = [];
            foreach ($records as $record) {
                // Get user
                $user = $this->getUserIdTicket($record, $record['userid']);

                if (!$user) {
                    continue;
                }

                $departmentId = $this->ticketDepartmentId((int) $record['did']);

                $data[] = [
                    'id' => $record['id'],
                    'client_id' => $this->clientIdOfUser($user),
                    // The number the customer knows (docs/tickets.md §5α); the full importer is PR Δ.
                    'reference' => (string) ($record['tid'] ?? $record['id']),
                    'subject' => $record['title'],
                    'status' => match ($record['status']) {
                        'Answered' => Ticket::STATUS_ANSWERED,
                        'Customer-Reply' => Ticket::STATUS_CUSTOMER_REPLY,
                        'On Hold', 'In Progress' => Ticket::STATUS_ON_HOLD,
                        'Closed' => Ticket::STATUS_CLOSED,
                        default => Ticket::STATUS_OPEN,
                    },
                    'priority' => match ($record['urgency']) {
                        'Low' => 'low',
                        'Medium' => 'medium',
                        'High' => 'high',
                        default => 'medium',
                    },
                    'department_id' => $departmentId,
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['lastreply']),
                ];

                // Get ticket messages
                $stmt = $this->pdo->prepare('SELECT * FROM tblticketreplies WHERE tid = :tid ORDER BY date ASC');
                $stmt->bindValue(':tid', $record['id'], PDO::PARAM_INT);
                $stmt->execute();
                $messageRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($messageRecords as $message) {
                    // A reply by staff or by the customer (docs/accounts.md, PR 1).
                    $staffId = $this->staffIdOfReply($message);
                    $messages[] = [
                        'ticket_id' => $record['id'],
                        'user_id' => $staffId === null ? $this->getUserIdTicket($message, $message['userid']) : null,
                        'staff_id' => $staffId,
                        'message' => $message['message'],
                        'created_at' => $this->validateDate($message['date']),
                        'updated_at' => $this->validateDate($message['date']),
                    ];
                }
            }

            DB::table('tickets')->insert($data);
            if (count($messages) > 0) {
                DB::table('ticket_messages')->insert($messages);
            }
        });
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
                    'notes' => self::text($record['notes'] ?? null),
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
                        'Domain' => null,
                        'Addon' => null,
                        'Upgrade' => null,
                        default => null,
                    },
                    'reference_id' => $record['relid'],
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
                    'amount' => $record['amountin'],
                    'transaction_id' => $record['transid'],
                    'created_at' => $this->validateDate($record['date']),
                    'updated_at' => $this->validateDate($record['date']),
                ];
            }

            DB::table('invoice_transactions')->insert($data);
        });
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
