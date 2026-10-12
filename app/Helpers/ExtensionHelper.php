<?php

namespace App\Helpers;

use App\Attributes\ExtensionMeta;
use App\Classes\FilamentInput;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use Exception;
use Filament\Forms\Components\Placeholder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OwenIt\Auditing\Events\AuditCustom;
use ReflectionClass;

class ExtensionHelper
{
    /**
     * Used to read all Extensions in app/Extensions with or without type (e.g. 'gateway' or 'server' or 'other' (for non-gateway/server extensions))
     *
     * @param  string|null  $type
     * @return array
     */
    public static function getExtensions($type = null)
    {
        // Check how long this takes
        $extensions = self::getAvailableExtensions();

        if ($type && $type == 'other') {
            // Filter out gateways and servers
            $extensions = array_filter($extensions, fn ($extension) => !in_array($extension['type'], ['gateway', 'server']));

            return $extensions;
        } elseif ($type) {
            $type = strtolower($type);

            return array_filter($extensions, fn ($extension) => $extension['type'] === $type);
        }

        return $extensions;
    }

    /**
     * Get extension and return new instance
     *
     * @param  string  $type
     * @param  string  $extension
     * @return object
     */
    public static function getExtension($type, $extension, $config = [])
    {
        $extension = '\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $extension . '\\' . $extension;

        if (!class_exists($extension)) {
            throw new Exception('Extension "' . $extension . '" not found');
        }

        if (!is_array($config)) {
            $config = self::settingsToArray($config);
        }

        return new $extension($config);
    }

    /**
     * Get available settings
     *
     * @return array
     */
    public static function getConfig($type, $extension, $config = [])
    {
        if (empty($config)) {
            $typeClass = ($type == 'gateway') ? Gateway::class : (($type == 'server') ? Server::class : Extension::class);
            $config = $typeClass::where('extension', $extension)->exists()
                ? $typeClass::where('extension', $extension)->first()->settings->pluck('value', 'key')->toArray()
                : [];
        }

        return self::getExtension($type, $extension)->getConfig($config);
    }

    /**
     * Store an extension's settings from its config: each value gets the type and the
     * `encrypted` flag its option declares, on create and on edit alike. Secrets (API
     * keys, passwords, webhook secrets) are therefore never stored in plain text.
     *
     * @param  array<string, mixed>  $values  form values keyed by option name
     * @param  bool  $skipNull  leave options without a value untouched (create form)
     */
    public static function saveSettings(Extension $record, array $values, bool $skipNull = false): void
    {
        $current = $record->settings->pluck('value', 'key')->toArray();
        // This record's own values: with none, getConfig() would read another record's settings.
        $config = self::getConfig($record->type, $record->extension, array_merge($current, $values) ?: ['_' => null]);

        foreach ($config as $option) {
            $value = $values[$option['name']] ?? null;
            if ($skipNull && $value === null) {
                continue;
            }
            $record->settings()->updateOrCreate([
                'key' => $option['name'],
                'settingable_id' => $record->id,
                'settingable_type' => $record->getMorphClass(),
            ], [
                'type' => $option['database_type'] ?? 'string',
                'value' => is_array($value) ? json_encode($value) : $value,
                'encrypted' => $option['encrypted'] ?? false,
            ]);
        }
    }

    /**
     * Has function
     *
     * @param  object  $extension
     * @param  string  $function
     */
    public static function hasFunction($extension, $function)
    {
        return method_exists(self::getExtension($extension->type, $extension->extension, $extension->settings), $function);
    }

    /**
     * Test connection
     *
     * @return string
     */
    public static function testConfig($extension, $values)
    {
        return self::getExtension($extension->type, $extension->extension, $values)->testConfig();
    }

    /**
     * Get checkout configuration
     *
     * @return array
     */
    public static function getCheckoutConfig(Product $product, $values = [])
    {
        $server = $product->server;
        if (!$server) {
            return [];
        }

        return self::call($server, 'getCheckoutConfig', [$product, $values, self::settingsToArray($product->settings)], mayFail: true) ?? [];
    }

    /**
     * Get all extensions which are not gateways or servers with their settings
     *
     * @return array
     */
    public static function getAvailableExtensions()
    {
        $extensions = [];

        $classmap = require base_path('vendor/composer/autoload_classmap.php');

        // Magic code so we can also support extensions that don't reside in the extensions folder
        foreach ($classmap as $class => $path) {
            if (strpos($class, 'Paymenter\\Extensions\\') !== 0) {
                continue;
            }

            // Example: Paymenter\Extensions\Whatevers\SomeExtension\SomeExtension
            $parts = explode('\\', $class);

            // Must have at least: Paymenter, Extensions, <Type>s, <Name>, <Class>
            if (count($parts) < 5) {
                continue;
            }

            $typePlural = $parts[2];

            $type = strtolower(rtrim($typePlural, 's'));
            $name = $parts[3];

            // Only add the main extension class (class name matches extension folder)
            if ($parts[4] !== $name) {
                continue;
            }

            if (!file_exists($path) || !class_exists($class)) {
                continue;
            }
            $extensions[] = [
                'name' => $name,
                'type' => $type,
                'meta' => self::getMeta($class),
            ];
        }

        // Newly created extensions sometimes don't have a classmap entry, so we also check the filesystem
        $extensionPath = base_path('extensions');
        $typeFolders = glob($extensionPath . '/*', GLOB_ONLYDIR);
        foreach ($typeFolders as $typeFolder) {
            $type = strtolower(rtrim(basename($typeFolder), 's'));
            $extensionDirs = glob($typeFolder . '/*', GLOB_ONLYDIR);

            foreach ($extensionDirs as $extensionDir) {
                $name = basename($extensionDir);

                // CHeck if already added
                if (in_array($name, array_column($extensions, 'name')) && in_array($type, array_column($extensions, 'type'))) {
                    continue;
                }

                // Check if the class exists
                if (class_exists('\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $name . '\\' . $name)) {

                    $extensions[] = [
                        'name' => $name,
                        'type' => $type,
                        'meta' => self::getMeta('\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $name . '\\' . $name),
                    ];
                }
            }
        }

        return $extensions;
    }

    public static function getMeta($class)
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(ExtensionMeta::class);

        return $attributes ? $attributes[0]->newInstance() : null;
    }

    public static function getInstallableExtensions()
    {
        $extensions = self::getExtensions('other');

        // Filter out already installed extensions
        $installedExtensions = Extension::all()->pluck('extension')->toArray();

        return array_filter($extensions, fn ($extension) => !in_array($extension['name'], $installedExtensions));
    }

    public static function call($extension, $function, $args = [], $mayFail = false)
    {
        try {
            if (!self::hasFunction($extension, $function)) {
                throw new Exception('Function not found');
            }

            return self::getExtension($extension->type, $extension->extension, $extension->settings)->$function(...$args);
        } catch (Exception $e) {
            // If mayFail is true, just report the exception instead of throwing it
            if (!$mayFail) {
                throw $e;
            } else {
                // If extension error is Not Found, don't report
                if (\Str::doesntEndWith($e->getMessage(), 'not found')) {
                    report($e);
                }
            }
        }
    }

    public static function callService(Service $service, $function, $args = [], $mayFail = false)
    {
        $server = $service->product->server;

        if (!$server) {
            if ($mayFail) {
                throw new Exception('No server assigned to this product');
            } else {
                return;
            }
        }

        return self::call($server, $function, [$service, self::settingsToArray($service->product->settings), self::getServiceProperties($service), ...$args], $mayFail);
    }

    /**
     * Convert extensions to options
     *
     * @param  Extension  $extension
     * @return object
     */
    public static function getConfigAsInputs(string $type, ?string $name, $config = [])
    {
        if (!$name) {
            return [];
        }

        $settings = [];

        try {
            foreach (self::getConfig($type, $name, $config) as $key => $config) {
                $config['name'] = 'settings.' . $config['name'];
                $settings[] = FilamentInput::convert($config);
            }
        } catch (Exception $e) {
            $settings[] = Placeholder::make('error')->content($e->getMessage());
            // Handle exception
        }

        return $settings;
    }

    /**
     * Get available settings
     *
     * @return array
     */
    public static function getProductConfig($server, $values = [])
    {
        return self::call($server, 'getProductConfig', [$values]);
    }

    /**
     * Get available settings
     *
     * @return array
     */
    public static function getProductConfigOnce($server, $values = [])
    {
        static $config = [];

        $config = Cache::get('product_config', []);

        $key = $server->extension . $server->id . md5(serialize(self::prepareForSerialization($values)));

        if (!isset($config[$key])) {
            $config[$key] = self::getProductConfig($server, $values);
        }

        Cache::put('product_config', $config, 60);

        return $config[$key];
    }

    protected static function prepareForSerialization($values)
    {
        if (is_array($values)) {
            foreach ($values as $key => $value) {
                $values[$key] = self::prepareForSerialization($value);
            }

            return $values;
        }

        if ($values instanceof TemporaryUploadedFile) {
            // Store the file and use the path, or just use the filename if already stored
            return $values->getRealPath() ?: (string) $values;
        }

        return $values;
    }

    /**
     * Convert settings to array
     *
     * @param  mixed  $settings
     * @return array
     */
    public static function settingsToArray($settings)
    {
        $settingsArray = [];

        if ($settings instanceof Collection) {
            // If $settings is a collection of models
            foreach ($settings as $setting) {
                $settingsArray[$setting->key] = $setting->value;
            }
        } elseif ($settings instanceof Model) {
            // If $settings is a single model
            $settingsArray[$settings->name] = $settings->value;
        }

        return $settingsArray ?? $settings;
    }

    /**
     * Register a new middleware.
     *
     * @param  string  $middleware
     * @param  string  $group
     * @return Router
     */
    public static function registerMiddleware($middleware, $group = 'web')
    {
        return app('router')->pushMiddlewareToGroup($group, $middleware);
    }

    /**
     * Get every gateway which allows to checkout with
     *
     * @return array
     */
    public static function getCheckoutGateways($total, $currency, $type, $items = [])
    {
        $gateways = [];

        foreach (Gateway::with('settings')->get() as $gateway) {
            if (self::hasFunction($gateway, 'canUseGateway')) {
                if (self::getExtension('gateway', $gateway->extension, $gateway->settings)->canUseGateway($total, $currency, $type, $items)) {
                    $gateways[] = $gateway;
                }
            } else {
                $gateways[] = $gateway;
            }
        }

        return $gateways;
    }

    /**
     * Get payment url or view
     */
    public static function pay($gateway, $invoice)
    {
        return self::getExtension('gateway', $gateway->extension, $gateway->settings)->pay($invoice, $invoice->remaining);
    }

    public static function charge(Gateway $gateway, Invoice $invoice, BillingAgreement $billingAgreement): bool
    {
        return self::getExtension('gateway', $gateway->extension, $gateway->settings)->charge($invoice, $invoice->remaining, $billingAgreement);
    }

    public static function getBillingAgreementGateways()
    {
        $gateways = [];

        foreach (Gateway::with('settings')->get() as $gateway) {
            if (self::hasFunction($gateway, 'supportsBillingAgreements')) {
                if (self::getExtension('gateway', $gateway->extension, $gateway->settings)->supportsBillingAgreements()) {
                    $gateways[] = $gateway;
                }
            }
        }

        return $gateways;
    }

    /**
     * Create billing agreement
     *
     * @param  User  $user
     * @param  Gateway  $gateway
     * @return mixed
     */
    public static function createBillingAgreement($user, $gateway)
    {
        return self::getExtension('gateway', $gateway->extension, $gateway->settings)->createBillingAgreement($user);
    }

    /**
     * Cancel billing agreement
     *
     * @return bool
     */
    public static function cancelBillingAgreement(BillingAgreement $billingAgreement)
    {
        return self::getExtension('gateway', $billingAgreement->gateway->extension, $billingAgreement->gateway->settings)->cancelBillingAgreement($billingAgreement);
    }

    public static function makeBillingAgreement(User $user, $gateway, $name, $externalReference, $type = null, $expiry = null)
    {
        $gateway = Gateway::where('extension', $gateway)->firstOrFail();

        $billingAgreement = BillingAgreement::updateOrCreate([
            'external_reference' => $externalReference,
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
        ], [
            'name' => $name,
            'type' => $type,
            'expiry' => $expiry,
        ]);

        return $billingAgreement;
    }

    /**
     * Add payment to invoice. Guards, idempotency and over-payment to credit: RecordPaymentService
     * (docs/payments.md). Gateways should pass the currency the provider reports.
     *
     * @param  Invoice|int  $invoice
     */
    public static function addPayment($invoice, $gateway, $amount, $fee = null, $transactionId = null, InvoiceTransactionStatus $status = InvoiceTransactionStatus::Succeeded, $isCreditTransaction = false, ?string $currency = null)
    {
        if (isset($gateway)) {
            $gateway = Gateway::where('extension', $gateway)->first();
        }

        return (new RecordPaymentService)->handle($invoice, $gateway, $amount, $fee, $transactionId === null ? null : (string) $transactionId, $status, (bool) $isCreditTransaction, $currency);
    }

    public static function addProcessingPayment($invoice, $gateway, $amount, $fee = null, $transactionId = null, ?string $currency = null)
    {
        return self::addPayment($invoice, $gateway, $amount, $fee, $transactionId, InvoiceTransactionStatus::Processing, currency: $currency);
    }

    public static function addFailedPayment($invoice, $gateway, $amount, $fee = null, $transactionId = null, ?string $currency = null)
    {
        return self::addPayment($invoice, $gateway, $amount, $fee, $transactionId, InvoiceTransactionStatus::Failed, currency: $currency);
    }

    public static function addPaymentFee($transactionId, $fee)
    {
        $transaction = InvoiceTransaction::where('transaction_id', $transactionId)->firstOrFail();

        $transaction->fee = $fee;
        $transaction->save();

        return $transaction;
    }

    /**
     * Cancel subscription
     */
    public static function cancelSubscription(Service $service)
    {
        foreach (Gateway::all() as $gateway) {
            if (self::hasFunction($gateway, 'cancelSubscription')) {
                if (self::getExtension('gateway', $gateway->extension, $gateway->settings)->cancelSubscription($service)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Refund a transaction via the payment gateway.
     *
     * @throws \InvalidArgumentException If amount is invalid.
     * @throws \RuntimeException If the gateway does not support refunds or the refund fails.
     */
    public static function refund(InvoiceTransaction $transaction, float $amount): bool
    {
        if (RecordPaymentService::toCents($amount) <= 0) { // at least a cent: checked before the gateway is called
            throw new \InvalidArgumentException('Refund amount must be greater than 0.');
        }

        $gateway = $transaction->gateway;

        if (!$gateway || !self::hasFunction($gateway, 'supportsRefunds') || !self::hasFunction($gateway, 'refund')) {
            throw new \RuntimeException('Gateway does not support refunds for transaction #' . $transaction->id);
        }

        $gatewayInstance = self::getExtension('gateway', $gateway->extension, $gateway->settings);

        if (!$gatewayInstance->supportsRefunds()) {
            throw new \RuntimeException('Gateway does not support refunds for transaction #' . $transaction->id);
        }

        $refundedAtGateway = false;
        try {
            return DB::transaction(function () use ($transaction, $gatewayInstance, $amount, &$refundedAtGateway) {
                // Every lock and check happens BEFORE the gateway call: nothing after the money left can wait
                // for a lock, deadlock, or be refused.
                [$transaction, $credit] = self::prepareRefund($transaction, $amount);

                $success = $gatewayInstance->refund($transaction, $amount);

                if (!$success) {
                    throw new \RuntimeException('Gateway refund failed for transaction #' . $transaction->id);
                }
                $refundedAtGateway = true;

                return self::recordRefund($transaction, $amount, $credit, MoneyTrail::sourceFor($transaction->gateway));
            });
        } catch (\Throwable $e) {
            if ($refundedAtGateway) { // recording or the commit failed after the money left
                Log::critical('Gateway refund succeeded but was NOT recorded locally: record it by hand.', [
                    'transaction_id' => $transaction->id, 'gateway_transaction_id' => $transaction->transaction_id,
                    'invoice_id' => $transaction->invoice_id, 'amount' => $amount, 'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Locks (customer, invoice, transaction, lines, then credit: the payments' order) and checks for a refund.
     * A refund of a credit purchase that gave its credit takes that credit back: refused when it was already
     * spent, and for an invoice that mixes it with other lines (RecordPaymentService::lockBoughtCredit).
     *
     * @return array{0: InvoiceTransaction, 1: ?Credit} the locked transaction, and the credit to take back from
     */
    private static function prepareRefund(InvoiceTransaction $transaction, float $amount): array
    {
        $invoice = (new RecordPaymentService)->lockInvoice($transaction->invoice_id);
        $transaction = InvoiceTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
        // Only money that arrived goes back: a failed or processing payment has nothing to refund.
        if ($transaction->status !== InvoiceTransactionStatus::Succeeded) {
            throw new \InvalidArgumentException('Only a succeeded payment can be refunded.');
        }

        $refundable = $transaction->refundable_amount; // net of refunds and of what became account credit
        if (RecordPaymentService::toCents($amount) > RecordPaymentService::toCents($refundable)) {
            throw new \InvalidArgumentException(
                "Refund amount ({$amount}) exceeds refundable amount ({$refundable}) for this transaction."
            );
        }

        // Only credit the invoice gave (once paid) is taken back: a partial payment of an unpaid one bought nothing.
        if (!(new RecordPaymentService)->lockBoughtCredit($invoice)) {
            return [$transaction, null];
        }
        $credit = Credit::query()->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)
            ->lockForUpdate()->first();
        if (RecordPaymentService::toCents($credit === null ? 0 : $credit->amount) < RecordPaymentService::toCents($amount)) {
            throw new \InvalidArgumentException('The account credit this payment bought was already used: it cannot be refunded.');
        }

        return [$transaction, $credit];
    }

    /**
     * Refund a transaction locally without processing through the payment gateway.
     *
     * @throws \InvalidArgumentException If amount is invalid.
     */
    public static function refundLocally(InvoiceTransaction $transaction, float $amount): void
    {
        if (RecordPaymentService::toCents($amount) <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than 0.');
        }

        DB::transaction(function () use ($transaction, $amount) {
            [$transaction, $credit] = self::prepareRefund($transaction, $amount);

            self::recordRefund($transaction, $amount, $credit, MoneyTrail::SOURCE_LOCAL);
        });
    }

    /**
     * Record a refund by updating the transaction and creating an adjustment note; a credit purchase also
     * gives back the credit it bought. Both are journaled (docs/money-trail.md §1).
     */
    private static function recordRefund(InvoiceTransaction $transaction, float $amount, ?Credit $credit, string $source): bool
    {
        $cents = RecordPaymentService::toCents($amount);
        $trail = new MoneyTrail;
        if ($credit !== null) {
            $credit->amount = (RecordPaymentService::toCents($credit->amount) - $cents) / 100;
            $credit->save();
            $trail->creditOut($credit, $cents, MoneyTrail::CAUSE_PURCHASE_REFUNDED, $source, $transaction->invoice, $transaction);
        }
        // One rounded amount for the transaction, the note and the journal.
        $transaction->increment('refunded_amount', $cents / 100);
        $trail->refund($transaction, $transaction->invoice, $cents, $source);

        $transaction->invoice->adjustmentNotes()->create([
            'type' => AdjustmentNoteType::Credit->value,
            'amount' => -1 * $cents / 100,
            'description' => 'Refund for transaction #' . $transaction->id . ' (' . ($transaction->transaction_id ?? 'manual') . ')',
        ]);

        return true;
    }

    /* SERVER RELATED FUNCTIONS */

    /**
     * Get both properties and config options from order product and smash them together
     */
    public static function getServiceProperties(Service $service)
    {
        $properties = [];
        foreach ($service->properties as $property) {
            $properties[$property->key] = $property->value;
        }
        foreach ($service->configs as $config) {
            $properties[$config->configOption->env_variable] = $config->configValue->env_variable ?? $config->configValue->name;
        }

        return $properties;
    }

    protected static function checkServer(Service $service, $action)
    {
        $server = $service->product->server;

        if (!$server) {
            throw new Exception('No server assigned to this product');
        }

        // Does server support this action?
        if (!self::hasFunction($server, $action)) {
            throw new Exception('Server does not support the action: ' . $action);
        }

        return $server;
    }

    protected static function recordAudit(Model $model, string $action, array $oldValues = [], array $newValues = [])
    {
        // Trigger audit log for server creation
        $model->auditEvent = $action;
        $model->isCustomEvent = true;
        $model->auditCustomOld = $oldValues;
        $model->auditCustomNew = $newValues;

        Event::dispatch(new AuditCustom($model));
    }

    /**
     * Create server
     */
    public static function createServer(Service $service)
    {
        $server = self::checkServer($service, 'createServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'create_server']);

        return self::getExtension('server', $server->extension, $server->settings)->createServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Suspend server
     */
    public static function suspendServer(Service $service)
    {
        $server = self::checkServer($service, 'suspendServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'suspend_server']);

        return self::getExtension('server', $server->extension, $server->settings)->suspendServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Unsuspend server
     */
    public static function unsuspendServer(Service $service)
    {
        $server = self::checkServer($service, 'unsuspendServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'unsuspend_server']);

        return self::getExtension('server', $server->extension, $server->settings)->unsuspendServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Terminate server
     */
    public static function terminateServer(Service $service)
    {
        $server = self::checkServer($service, 'terminateServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'terminate_server']);

        return self::getExtension('server', $server->extension, $server->settings)->terminateServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Upgrade server
     */
    public static function upgradeServer(Service $service)
    {
        $server = self::checkServer($service, 'upgradeServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'upgrade_server']);

        return self::getExtension('server', $server->extension, $server->settings)->upgradeServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Get actions for service
     */
    public static function getActions(Service $service)
    {
        $server = self::checkServer($service, 'getActions');

        return self::getExtension('server', $server->extension, $server->settings)->getActions($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Get actions for service
     */
    public static function getView(Service $service, $view)
    {
        $function = isset($view['function']) ? $view['function'] : 'getView';

        $server = self::checkServer($service, $function);

        return self::getExtension('server', $server->extension, $server->settings)->$function($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service), $view['name']);
    }

    /**
     * Revert migrations for a specific extension
     */
    public static function rollbackMigrations($path)
    {
        $migrationFiles = glob(base_path($path . '/*.php'));

        if (empty($migrationFiles)) {
            return;
        }

        // Sort by filename to ensure correct order
        usort($migrationFiles, function ($a, $b) {
            return strcmp(basename($a), basename($b));
        });

        // Reverse the order to rollback in the correct sequence
        $migrationFiles = array_reverse($migrationFiles);

        foreach ($migrationFiles as $file) {
            $migrationName = basename($file, '.php');
            try {
                $migration = require_once $file;
                // return new class extends Migration
                if (method_exists($migration, 'down') && DB::table('migrations')->where('migration', $migrationName)->exists()) {
                    $migration->down();
                    DB::table('migrations')->where('migration', $migrationName)->delete();
                }
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    /**
     * Run migrations for a specific extension
     */
    public static function runMigrations($path)
    {
        $migrator = app(Migrator::class);

        try {
            $ranMigrations = $migrator->run(base_path($path));

            Log::debug('Migrations output: ', $ranMigrations);
        } catch (Exception $e) {
            report($e);
        }
    }
}
