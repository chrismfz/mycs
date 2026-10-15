<?php

namespace App\Helpers;

use App\Attributes\ExtensionMeta;
use App\Classes\FilamentInput;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\CreateSkipped;
use App\Exceptions\DisplayException;
use App\Exceptions\NothingToDoOnServer;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\ModuleAction;
use App\Models\MoneyEvent;
use App\Models\PaymentGatewayEvent;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\GatewayLog;
use App\Services\Money\MoneyTrail;
use App\Services\Service\ServerSelector;
use Exception;
use Filament\Forms\Components\Placeholder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OwenIt\Auditing\Events\AuditCustom;
use ReflectionClass;
use Throwable;

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
        $extension = '\\MyCS\\Extensions\\' . ucfirst($type) . 's\\' . $extension . '\\' . $extension;

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
     * The keys of the secrets stored on an extension (encrypted settings with a value).
     *
     * @return list<string>
     */
    public static function storedSecretKeys(Extension $record): array
    {
        return $record->settings()->where('encrypted', true)->get()
            ->filter(fn (Setting $setting) => $setting->value !== null && $setting->value !== '')->pluck('key')->values()->all();
    }

    /**
     * A form's settings with every secret left empty filled from what is stored (the form never had it). Not when the form
     * now points somewhere else (a host, an IP, a port, a URL changed: review R6): a stored root key is never sent to a
     * host typed by someone who could not read it. Then it must be typed again.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException naming each secret to type again
     */
    public static function withStoredSecrets(?Extension $record, array $values): array
    {
        if ($record === null) {
            return $values;
        }
        $stored = $record->settings->pluck('value', 'key');
        $moved = collect($values)->keys()->filter(fn ($key) => preg_match('/host|^ip$|_ip$|port|url|endpoint|hostname|server/i', (string) $key)
            && $stored->has($key) && (string) $stored[$key] !== (string) ($values[$key] ?? ''))->isNotEmpty();
        $missing = [];
        foreach ($record->settings->where('encrypted', true) as $setting) {
            if (($values[$setting->key] ?? null) !== null && $values[$setting->key] !== '') {
                continue;
            }
            if ($moved && $setting->value !== null && $setting->value !== '') {
                $missing['data.settings.' . $setting->key] = __('The address changed: enter this secret again.');

                continue;
            }
            $values[$setting->key] = $setting->value;
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        return $values;
    }

    /** A form's settings, a stored secret left out (sweep §6 #4): the page never gets it. */
    public static function settingsForForm(Extension $record): array
    {
        $values = [];
        foreach ($record->settings as $setting) {
            $values[$setting->key] = $setting->encrypted ? null : $setting->value;
        }

        return $values;
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
            if (strpos($class, 'MyCS\\Extensions\\') !== 0) {
                continue;
            }

            // Example: MyCS\Extensions\Whatevers\SomeExtension\SomeExtension
            $parts = explode('\\', $class);

            // Must have at least: MyCS, Extensions, <Type>s, <Name>, <Class>
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
                if (class_exists('\\MyCS\\Extensions\\' . ucfirst($type) . 's\\' . $name . '\\' . $name)) {

                    $extensions[] = [
                        'name' => $name,
                        'type' => $type,
                        'meta' => self::getMeta('\\MyCS\\Extensions\\' . ucfirst($type) . 's\\' . $name . '\\' . $name),
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
        $server = $service->provisioningServer();

        if (!$server) {
            if ($mayFail) {
                throw new NothingToDoOnServer('No server assigned to this product');
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
    public static function getConfigAsInputs(string $type, ?string $name, $config = [], ?Extension $record = null)
    {
        if (!$name) {
            return [];
        }

        $settings = [];
        $stored = $record === null ? [] : self::storedSecretKeys($record);

        try {
            foreach (self::getConfig($type, $name, rescue(fn () => self::withStoredSecrets($record, (array) $config), (array) $config, false)) as $key => $config) {
                // A secret already stored is not sent to the page (sweep §6 #4): left empty, it is kept.
                if (($config['encrypted'] ?? false) && in_array($config['name'], $stored, true)) {
                    $config['required'] = false;
                    $config['placeholder'] = __('Stored: leave empty to keep it');
                }
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
        // Only the invoice's own account's payment method pays it (docs/accounts.md, PR 2a).
        if ($invoice->client_id === null || (int) $billingAgreement->client_id !== (int) $invoice->client_id) {
            throw new DisplayException(__('This payment method belongs to another account.'));
        }

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
     * @param  Client  $client  the account the payment method is saved on
     * @param  Gateway  $gateway
     * @return mixed
     */
    public static function createBillingAgreement(Client $client, $gateway)
    {
        return self::getExtension('gateway', $gateway->extension, $gateway->settings)->createBillingAgreement($client);
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

    public static function makeBillingAgreement(Client $client, $gateway, $name, $externalReference, $type = null, $expiry = null)
    {
        $gateway = Gateway::where('extension', $gateway)->firstOrFail();

        $billingAgreement = BillingAgreement::updateOrCreate([
            'external_reference' => $externalReference,
            'client_id' => $client->id,
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

        $transaction = (new RecordPaymentService)->handle($invoice, $gateway, $amount, $fee, $transactionId === null ? null : (string) $transactionId, $status, (bool) $isCreditTransaction, $currency);
        // Refunds that arrived before their payment was money here are recorded now (PR 2c-2, F3).
        if ($transaction instanceof InvoiceTransaction && $gateway !== null && $transactionId !== null && $transaction->status === InvoiceTransactionStatus::Succeeded) {
            $extension = $gateway->extension;
            DB::afterCommit(function () use ($extension, $transactionId) {
                try {
                    self::applyWaitingRefunds($extension, (string) $transactionId);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $transaction;
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
    /**
     * Asks each gateway in turn; one that fails does not stop the others (the subscription may be another's). True
     * when one cancelled it; the first failure is rethrown when none did; false when none took it.
     */
    public static function cancelSubscription(Service $service)
    {
        $failure = null;
        foreach (Gateway::all() as $gateway) {
            if (self::hasFunction($gateway, 'cancelSubscription')) {
                try {
                    if (self::getExtension('gateway', $gateway->extension, $gateway->settings)->cancelSubscription($service)) {
                        return true;
                    }
                } catch (Throwable $e) { // an Error too: the subscription may still be another gateway's
                    $failure ??= $e;
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return false;
    }

    /**
     * Refund a transaction via the payment gateway.
     *
     * @throws \InvalidArgumentException If amount is invalid.
     * @throws \RuntimeException If the gateway does not support refunds or the refund fails.
     */
    public static function refund(InvoiceTransaction $transaction, float $amount, bool $notify = true): bool
    {
        if (RecordPaymentService::toCents($amount) <= 0) { // at least a cent: checked before the gateway is called
            throw new \InvalidArgumentException('Refund amount must be greater than 0.');
        }

        if ($transaction->is_credit_transaction) {
            throw new \InvalidArgumentException('A payment made with account credit goes back to account credit.');
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
        $reference = null;
        try {
            return DB::transaction(function () use ($transaction, $gatewayInstance, $amount, $notify, &$refundedAtGateway, &$reference) {
                // Every lock and check happens BEFORE the gateway call: nothing after the money left can wait
                // for a lock, deadlock, or be refused.
                [$transaction, $credit] = self::prepareRefund($transaction, $amount);

                // The rounded amount, the same the transaction, the note and the journal record.
                $success = $gatewayInstance->refund($transaction, RecordPaymentService::toCents($amount) / 100);

                if ($success === false || $success === null || $success === '') {
                    throw new \RuntimeException('Gateway refund failed for transaction #' . $transaction->id);
                }
                $refundedAtGateway = true;
                $reference = is_string($success) ? $success : null;
                // The gateway answered with a refund its webhook recorded already (a retry after a lost answer, whose
                // idempotency key gave the first refund back): nothing more to record.
                if ($reference !== null && GatewayRefund::query()->where('gateway', $transaction->gateway->extension)->where('reference', $reference)
                    ->whereIn('origin', [GatewayRefund::ORIGIN_GATEWAY, GatewayRefund::ORIGIN_MYCS, GatewayRefund::ORIGIN_HAND])->lockForUpdate()->exists()) {
                    return true;
                }
                GatewayRefund::query()->where('gateway', $transaction->gateway->extension)->where('reference', (string) $reference)
                    ->whereIn('origin', [GatewayRefund::ORIGIN_WAITING, GatewayRefund::ORIGIN_FAILED])->delete();

                self::recordRefund($transaction, $amount, $credit, MoneyTrail::sourceFor($transaction->gateway));
                // The gateway's own id for it (PR 2c-2): its webhook telling the same refund is not recorded twice.
                // The webhook waits on the locks held here and reads this row with a locking read.
                if (!is_string($success)) {
                    Log::warning('Gateway refund without the gateway\'s id: its webhook cannot tell it from a new refund.', [
                        'gateway' => $transaction->gateway->extension, 'transaction_id' => $transaction->id,
                    ]);
                } else {
                    GatewayRefund::create([
                        'invoice_transaction_id' => $transaction->id, 'gateway' => $transaction->gateway->extension,
                        'reference' => $success, 'payment_reference' => $transaction->transaction_id, 'amount' => RecordPaymentService::toCents($amount) / 100,
                        'currency_code' => $transaction->invoice->currency_code,
                        'recorded_amount' => RecordPaymentService::toCents($amount) / 100, 'origin' => GatewayRefund::ORIGIN_MYCS,
                    ]);
                }
                if ($notify) {
                    self::notifyRefund($transaction, RecordPaymentService::toCents($amount), toCredit: false, byGateway: true);
                }

                return true;
            });
        } catch (Throwable $e) {
            if ($refundedAtGateway) { // recording or the commit failed after the money left
                // With the gateway's id, its webhook records it (PR 2c-2): recording it by hand as well would count it twice.
                Log::critical($reference !== null
                    ? 'Gateway refund succeeded but was NOT recorded here: the gateway\'s webhook records it (refund ' . $reference . '). Check the gateway log / gateway_refunds before recording anything by hand.'
                    : 'Gateway refund succeeded but was NOT recorded locally: record it by hand.', [
                        'transaction_id' => $transaction->id, 'gateway_transaction_id' => $transaction->transaction_id, 'refund' => $reference,
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
        // All its payments, before the credit row: afterRefund reads them to tell a full refund (code review of 2c).
        $invoice->transactions()->lockForUpdate()->get(['id']);
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
        $credit = Credit::query()->where('client_id', $invoice->client_id)->where('currency_code', $invoice->currency_code)
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
    public static function refundLocally(InvoiceTransaction $transaction, float $amount, bool $notify = true): void
    {
        if (RecordPaymentService::toCents($amount) <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than 0.');
        }

        if ($transaction->is_credit_transaction) {
            throw new \InvalidArgumentException('A payment made with account credit goes back to account credit.');
        }

        DB::transaction(function () use ($transaction, $amount, $notify) {
            [$transaction, $credit] = self::prepareRefund($transaction, $amount);

            self::recordRefund($transaction, $amount, $credit, MoneyTrail::SOURCE_LOCAL);
            if ($notify) {
                self::notifyRefund($transaction, RecordPaymentService::toCents($amount), toCredit: false, byGateway: false);
            }
        });
    }

    /**
     * Refund a payment to the customer's account credit instead of back to them («Add to Client's Credit Balance», as
     * WHMCS; PR 2c): the amount leaves the invoice as credited_amount and becomes credit (cause refund_to_credit), then
     * pays the customer's other open invoices (decision 5). The invoice's note and status follow as for any refund.
     * Not a credit purchase (credit back for credit bought: take the credit back with a refund instead), and not an
     * invoice without a customer.
     *
     * @throws \InvalidArgumentException If amount is invalid or the invoice cannot take it.
     */
    public static function refundToCredit(InvoiceTransaction $transaction, float $amount, bool $notify = true): void
    {
        $cents = RecordPaymentService::toCents($amount);
        if ($cents <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than 0.');
        }

        $clientId = null;
        $currency = null;
        DB::transaction(function () use (&$transaction, $amount, $cents, $notify, &$clientId, &$currency) {
            $invoice = (new RecordPaymentService)->lockInvoice($transaction->invoice_id);
            if ($invoice->client_id === null) {
                throw new \InvalidArgumentException('This invoice has no customer whose credit could take it.');
            }
            if ($invoice->items()->where('reference_type', Credit::class)->exists()) {
                throw new \InvalidArgumentException('A credit purchase is not refunded to credit: refund it back, which takes the credit it bought.');
            }
            [$transaction] = self::prepareRefund($transaction, $amount);

            $transaction->increment('credited_amount', $cents / 100);
            (new CreditLedger)->add((int) $invoice->client_id, $invoice->currency_code, $cents, MoneyTrail::CAUSE_REFUND_TO_CREDIT,
                MoneyTrail::sourceFor($transaction->gateway, (bool) $transaction->is_credit_transaction), $invoice, $transaction);
            self::afterRefund($transaction, $cents, 'Refund to account credit for transaction #' . $transaction->id);
            [$clientId, $currency] = [(int) $invoice->client_id, (string) $invoice->currency_code];
            if ($notify) {
                self::notifyRefund($transaction, $cents, toCredit: true, byGateway: false);
            }
        });

        // The credit pays the customer's other open invoices (decision 5, MT-1e), as an overpayment's does.
        DB::afterCommit(function () use ($clientId, $currency) {
            try {
                (new CreditAutoApply)->forCustomer($clientId, $currency);
            } catch (Throwable $e) {
                report($e); // the refund stands; the daily pass tries again
            }
        });
    }

    /**
     * After a refund (back or to credit) is recorded: a paid invoice stays settled, so a note takes the refund off its
     * total; an open one is still to pay in full, its balance grows back (MT-1c); a cancelled one is settled by its
     * cancellation note already (the whole total), and reopened it is to pay in full again (adversarial review of 2c,
     * R4). A paid invoice whose payments all went back is refunded (PR 2c, as WHMCS); its services stay as they are.
     * The rows are locked already (prepareRefund): nothing here waits for a lock after a gateway refund.
     */
    private static function afterRefund(InvoiceTransaction $transaction, int $cents, string $description): void
    {
        $invoice = $transaction->invoice;
        if ($invoice->status === Invoice::STATUS_PAID) {
            $invoice->adjustmentNotes()->create([
                'type' => AdjustmentNoteType::Credit->value,
                'amount' => -1 * $cents / 100,
                'description' => $description,
                'is_refund' => true, // follows refunded_amount / credited_amount: never voided by hand (MT-1c)
            ]);
        }
        if ($invoice->status === Invoice::STATUS_PAID) {
            $kept = (int) $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded->value)->lockForUpdate()->get()
                ->sum(fn (InvoiceTransaction $payment) => RecordPaymentService::toCents($payment->amount)
                    - RecordPaymentService::toCents($payment->refunded_amount) - RecordPaymentService::toCents($payment->credited_amount));
            if ($kept === 0) {
                $invoice->refresh()->update(['status' => Invoice::STATUS_REFUNDED]);
            }
        }
    }

    /**
     * A refund the gateway made on its own (its dashboard; PR 2c-2, PAY-5), told by its webhook: the money already left,
     * so it is recorded, never refused. Once per refund id: MyCS's own refund (written when the gateway answered) and a
     * retried webhook change nothing. Recorded up to what is left on the payment; more than that (part became account
     * credit, or was refunded by hand already) is staff's to settle, and they are told. A credit purchase gives back the
     * credit it bought, as much as is left of it. The customer is told, as for any refund.
     *
     * - A refund recorded here «by hand» first (as staff did in WHMCS) is matched, not recorded again (adversarial review
     *   of 2c-2a, F1): staff are told to check.
     * - A refund for a payment not recorded here yet (its webhook late or retried), or not succeeded yet, waits and is
     *   applied when the payment is (F3).
     * - A refund the gateway already reported as failed is not recorded by a late «created» (F2).
     *
     * @return bool whether this call recorded it on a payment
     */
    public static function refundedAtGateway(string $gateway, string $paymentReference, string $refundReference, float $amount, ?string $currency = null): bool
    {
        $cents = RecordPaymentService::toCents($amount);
        $log = fn (string $outcome, string $reason, ?InvoiceTransaction $on = null) => GatewayLog::current()->result($outcome, $reason, [
            'amount' => $cents / 100, 'currency_code' => $currency !== null ? strtoupper($currency) : null, 'external_id' => $refundReference,
            'invoice_id' => $on?->invoice_id, 'invoice_transaction_id' => $on?->id,
        ]);
        if ($cents <= 0 || $refundReference === '' || $paymentReference === '') {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'refund_unknown_payment');

            return false;
        }
        $known = GatewayRefund::query()->where('gateway', $gateway)->where('reference', $refundReference)
            ->where('origin', '!=', GatewayRefund::ORIGIN_WAITING)->first();
        $payment = self::gatewayPayment($gateway, $paymentReference);
        if ($known !== null) {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, $known->origin === GatewayRefund::ORIGIN_FAILED ? 'refund_failed' : 'replay', $payment);

            return false;
        }
        if ($payment === null || $payment->status !== InvoiceTransactionStatus::Succeeded) {
            // Not recorded here yet (or not money yet): kept, and applied when the payment is (applyWaitingRefunds).
            // A refund of another app on the same gateway account waits for ever, recording nothing.
            GatewayRefund::query()->updateOrCreate(['gateway' => $gateway, 'reference' => $refundReference], [
                'invoice_transaction_id' => $payment?->id, 'payment_reference' => $paymentReference, 'amount' => $cents / 100,
                'currency_code' => strtoupper((string) ($currency ?? '')), 'recorded_amount' => 0, 'origin' => GatewayRefund::ORIGIN_WAITING,
            ]);
            Log::info('Gateway refund for a payment not recorded (or not succeeded) here yet: waiting for it.', ['gateway' => $gateway, 'payment' => $paymentReference, 'refund' => $refundReference]);
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, $payment === null ? 'refund_unknown_payment' : 'refund_payment_not_succeeded', $payment);

            return false;
        }

        $staff = null;
        try {
            // A closure by reference: an arrow function would fill a copy of $staff.
            $recorded = DB::transaction(function () use ($payment, $gateway, $refundReference, $cents, $currency, $log, &$staff) {
                return self::recordGatewayRefund($payment, $gateway, $refundReference, $cents, $currency, $log, $staff);
            });
        } catch (UniqueConstraintViolationException) { // the same refund, recorded by another request in the meantime
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay', $payment);

            return false;
        }

        if ($staff !== null) {
            Log::warning($staff);
            DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Gateway refund needs staff attention', e($staff)));
        }

        return $recorded > 0;
    }

    /**
     * The payment a gateway's refund is for: its row for that gateway, or one staff entered by hand (no gateway) with the
     * provider's id (as RecordPaymentService matches payments; adversarial review of 2c-2a, F5). Never a credit row.
     */
    private static function gatewayPayment(string $gateway, string $paymentReference): ?InvoiceTransaction
    {
        $rows = InvoiceTransaction::query()->where('transaction_id', $paymentReference)->where('is_credit_transaction', false)
            ->where(fn ($query) => $query->whereNull('gateway_id')->orWhereHas('gateway', fn ($gw) => $gw->where('extension', $gateway)))
            ->with('gateway')->orderBy('id')->get();

        return $rows->first(fn (InvoiceTransaction $row) => $row->gateway?->extension === $gateway && $row->status === InvoiceTransactionStatus::Succeeded)
            ?? $rows->first(fn (InvoiceTransaction $row) => $row->status === InvoiceTransactionStatus::Succeeded)
            ?? $rows->first();
    }

    /** A payment was recorded (succeeded): the refunds that waited for it are recorded now (F3). */
    public static function applyWaitingRefunds(string $gateway, string $paymentReference): void
    {
        GatewayRefund::query()->where('gateway', $gateway)->where('payment_reference', $paymentReference)
            ->whereIn('origin', [GatewayRefund::ORIGIN_WAITING, GatewayRefund::ORIGIN_CHARGEBACK_WAITING])->orderBy('id')->get()
            ->each(function (GatewayRefund $waiting) use ($gateway, $paymentReference) {
                try {
                    $currency = $waiting->currency_code !== '' ? $waiting->currency_code : null;
                    $waiting->origin === GatewayRefund::ORIGIN_CHARGEBACK_WAITING
                        ? self::chargebackAtGateway($gateway, $paymentReference, $waiting->reference, (float) $waiting->amount, $currency)
                        : self::refundedAtGateway($gateway, $paymentReference, $waiting->reference, (float) $waiting->amount, $currency);
                } catch (Throwable $e) {
                    report($e); // it keeps waiting: the next payment event or the gateway's retry applies it
                }
            });
    }

    /**
     * refundedAtGateway's work under the payments' locks: what it recorded, in cents.
     *
     * @param  callable(string, string, ?InvoiceTransaction): void  $log
     */
    private static function recordGatewayRefund(InvoiceTransaction $payment, string $gateway, string $refundReference, int $cents, ?string $currency, callable $log, ?string &$staff): int
    {
        $invoice = (new RecordPaymentService)->lockInvoice($payment->invoice_id);
        $invoice->transactions()->lockForUpdate()->get(['id']);
        $payment = InvoiceTransaction::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        // MyCS's own refund, committed while this waited for the locks: a locking read sees it (a plain read keeps
        // the snapshot this transaction took before it waited, on MariaDB's repeatable read).
        $row = GatewayRefund::query()->where('gateway', $gateway)->where('reference', $refundReference)->lockForUpdate()->first();
        if ($row !== null && $row->origin !== GatewayRefund::ORIGIN_WAITING) {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay', $payment);

            return 0;
        }
        $row?->delete(); // it waited for this payment: recorded now
        $write = fn (int $recorded, string $origin, ?string $code = null) => GatewayRefund::create([
            'invoice_transaction_id' => $payment->id, 'gateway' => $gateway, 'reference' => $refundReference, 'payment_reference' => $payment->transaction_id,
            'amount' => $cents / 100, 'currency_code' => $code ?? $invoice->currency_code, 'recorded_amount' => $recorded / 100, 'origin' => $origin,
        ]);

        if ($currency !== null && strtoupper($currency) !== strtoupper((string) $invoice->currency_code)) {
            $staff = "{$gateway} refund {$refundReference} is in {$currency}, the invoice #{$invoice->id} in {$invoice->currency_code}: not recorded, record it by hand.";
            $write(0, GatewayRefund::ORIGIN_GATEWAY, strtoupper($currency));
            $log(PaymentGatewayEvent::OUTCOME_REJECTED, 'currency_mismatch', $payment);

            return 0;
        }

        // Refunded by hand here first (WHMCS's habit: refund in the dashboard, then record it): the same money, matched to
        // it rather than recorded twice. Only a whole match; staff check it either way (F1).
        $byHand = (int) MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->where('invoice_transaction_id', $payment->id)
            ->where('source', MoneyTrail::SOURCE_LOCAL)->where(fn ($query) => $query->whereNull('cause')->orWhere('cause', '!=', MoneyTrail::CAUSE_CHARGEBACK))
            ->get()->sum(fn (MoneyEvent $event) => RecordPaymentService::toCents($event->amount));
        $matched = (int) GatewayRefund::query()->where('invoice_transaction_id', $payment->id)->where('origin', GatewayRefund::ORIGIN_HAND)
            ->get()->sum(fn (GatewayRefund $refund) => RecordPaymentService::toCents($refund->amount));
        if ($byHand - $matched >= $cents) {
            $write(0, GatewayRefund::ORIGIN_HAND);
            $staff = "{$gateway} refund {$refundReference} (" . self::cents($cents) . " {$invoice->currency_code}) on payment #{$payment->id} of invoice #{$invoice->id}"
                . ' was taken as the refund recorded by hand on it, not recorded again. If that was a different refund (e.g. a bank transfer), record this one by hand.';
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'refund_matched_by_hand', $payment);

            return 0;
        }

        $left = max(0, RecordPaymentService::toCents($payment->refundable_amount));
        $record = min($cents, $left);
        $credit = null;
        $creditCents = 0;
        try {
            $boughtCredit = $record > 0 && (new RecordPaymentService)->lockBoughtCredit($invoice);
        } catch (DisplayException $e) { // credit bought together with other items: staff decide what to take back
            $boughtCredit = false;
            $staff = "{$gateway} refunded {$refundReference} on invoice #{$invoice->id}: " . $e->getMessage();
        }
        if ($boughtCredit) {
            $credit = Credit::query()->where('client_id', $invoice->client_id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
            $creditCents = min($record, max(0, RecordPaymentService::toCents($credit === null ? 0 : $credit->amount)));
            if ($creditCents < $record) {
                $staff = "{$gateway} refunded {$refundReference} for a credit purchase whose credit was spent: " . self::cents($record - $creditCents)
                    . " {$invoice->currency_code} of it could not be taken back from the customer's credit (invoice #{$invoice->id}).";
            }
        }
        if ($record > 0) {
            self::recordRefund($payment, $record / 100, $credit, MoneyTrail::sourceFor($payment->gateway), $creditCents);
        }
        if ($record < $cents) {
            $staff = trim(($staff ?? '') . " {$gateway} refunded " . self::cents($cents) . " {$invoice->currency_code} ({$refundReference}) on payment #{$payment->id}"
                . " of invoice #{$invoice->id}, but only " . self::cents($left) . ' was left on it (the rest became account credit, was refunded by hand, or paid other invoices):'
                . ' ' . self::cents($cents - $record) . ' recorded nowhere, settle it by hand.');
        } elseif ($byHand > $matched) {
            $staff = "{$gateway} refund {$refundReference} recorded on payment #{$payment->id} of invoice #{$invoice->id}, which also has a refund recorded by hand:"
                . ' check the two are not the same money.';
        }
        $write($record, GatewayRefund::ORIGIN_GATEWAY);
        if ($record > 0) {
            self::notifyRefund($payment, $record, toCredit: false, byGateway: true);
        }
        $log($record > 0 ? PaymentGatewayEvent::OUTCOME_RECORDED : PaymentGatewayEvent::OUTCOME_IGNORED, $record > 0 ? 'refund' : 'refund_nothing_left', $payment);

        return $record;
    }

    /**
     * A refund the gateway reports as failed: if MyCS recorded it, staff check it (the money may not have left), once.
     * Not recorded yet (the failure came first, F2): kept as failed, so a late «created» does not record it.
     */
    public static function refundFailedAtGateway(string $gateway, string $refundReference, ?string $paymentReference = null, ?float $amount = null, ?string $currency = null): void
    {
        $refund = GatewayRefund::query()->where('gateway', $gateway)->where('reference', $refundReference)->first();
        GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, 'refund_failed', [
            'external_id' => $refundReference, 'invoice_transaction_id' => $refund?->invoice_transaction_id,
        ]);
        if ($refund === null || $refund->origin === GatewayRefund::ORIGIN_WAITING) {
            $payment = $paymentReference !== null ? self::gatewayPayment($gateway, $paymentReference) : null;
            try {
                GatewayRefund::query()->updateOrCreate(['gateway' => $gateway, 'reference' => $refundReference], [
                    'invoice_transaction_id' => $payment?->id, 'payment_reference' => $paymentReference,
                    'amount' => $amount ?? 0, 'currency_code' => strtoupper((string) ($currency ?? '')), 'recorded_amount' => 0,
                    'origin' => GatewayRefund::ORIGIN_FAILED,
                ]);
            } catch (UniqueConstraintViolationException) {
                // recorded by a «created» at this very moment: the next failure event (Stripe sends two) tells staff
            }

            return;
        }
        // Stripe tells a failure twice (refund.updated and refund.failed): staff are told once.
        if ((float) $refund->recorded_amount <= 0 || !Cache::add('gateway-refund-failed:' . $gateway . ':' . $refundReference, true, now()->addDays(30))) {
            return;
        }
        $message = "{$gateway} reports refund {$refundReference} ({$refund->amount} {$refund->currency_code}, payment #{$refund->invoice_transaction_id}) as failed,"
            . ' but it is recorded as refunded: check it at the gateway and correct it by hand.';
        Log::warning($message);
        NotificationHelper::sendSystemEmailNotification('Gateway refund failed after it was recorded', e($message));
    }

    /**
     * A dispute opened at the gateway (PR 2c-2b): nothing moves yet (the money goes when the gateway takes it, and is
     * recorded then); staff are told once, to answer the dispute at the gateway.
     */
    public static function chargebackOpened(string $gateway, ?string $paymentReference, string $disputeReference, float $amount, ?string $currency = null): void
    {
        $payment = $paymentReference !== null && $paymentReference !== '' ? self::gatewayPayment($gateway, $paymentReference) : null;
        GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, 'dispute_opened', [
            'amount' => $amount, 'currency_code' => $currency !== null ? strtoupper($currency) : null, 'external_id' => $disputeReference,
            'invoice_id' => $payment?->invoice_id, 'invoice_transaction_id' => $payment?->id,
        ]);
        if ($payment === null) {
            return;
        }
        // Kept (reference «opened:<id>»): told once, and a won PayPal dispute knows how many disputes the payment had.
        try {
            GatewayRefund::create(['invoice_transaction_id' => $payment->id, 'gateway' => $gateway, 'reference' => 'opened:' . $disputeReference,
                'payment_reference' => $payment->transaction_id, 'amount' => RecordPaymentService::toCents($amount) / 100,
                'currency_code' => strtoupper((string) ($currency ?? '')), 'recorded_amount' => 0, 'origin' => GatewayRefund::ORIGIN_DISPUTE]);
        } catch (UniqueConstraintViolationException) {
            return;
        }
        NotificationHelper::sendSystemEmailNotification('Payment disputed at the gateway', e(
            "{$gateway} dispute {$disputeReference}: " . self::cents(RecordPaymentService::toCents($amount)) . ' ' . strtoupper((string) $currency)
            . " on payment #{$payment->id} of invoice #{$payment->invoice_id}. Answer it at {$gateway}; if the money is taken back, the invoice becomes unpaid here."
        ));
    }

    /**
     * The gateway took a payment back for a dispute (a chargeback; PR 2c-2b, decision 2026-10-14): recorded on the
     * payment (out of it, journal «refund» with cause chargeback), once per id. The invoice keeps its total and is owed
     * again: a paid one becomes unpaid, and the cron treats it as any unpaid invoice (dunning as set). Services are not
     * touched; staff are told and decide (a hold on the service waits for the dispute). A credit purchase gives back the
     * credit it bought, as much as is left of it.
     *
     * @return bool whether this call recorded it
     */
    public static function chargebackAtGateway(string $gateway, string $paymentReference, string $chargebackReference, float $amount, ?string $currency = null): bool
    {
        $cents = RecordPaymentService::toCents($amount);
        $payment = $paymentReference !== '' ? self::gatewayPayment($gateway, $paymentReference) : null;
        $log = fn (string $outcome, string $reason) => GatewayLog::current()->result($outcome, $reason, [
            'amount' => $cents / 100, 'currency_code' => $currency !== null ? strtoupper($currency) : null, 'external_id' => $chargebackReference,
            'invoice_id' => $payment?->invoice_id, 'invoice_transaction_id' => $payment?->id,
        ]);
        if ($cents <= 0 || $chargebackReference === '') {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'chargeback_unknown_payment');

            return false;
        }
        if (GatewayRefund::query()->where('gateway', $gateway)->where('reference', $chargebackReference)
            ->where('origin', '!=', GatewayRefund::ORIGIN_CHARGEBACK_WAITING)->exists()) {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay');

            return false;
        }
        if ($payment === null || $payment->status !== InvoiceTransactionStatus::Succeeded) {
            // Its payment not money here yet (late webhook, processing): kept, recorded when it is (F7, as refunds).
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'chargeback_unknown_payment');
            GatewayRefund::query()->updateOrCreate(['gateway' => $gateway, 'reference' => $chargebackReference], [
                'invoice_transaction_id' => $payment?->id, 'payment_reference' => $paymentReference, 'amount' => $cents / 100,
                'currency_code' => strtoupper((string) ($currency ?? '')), 'recorded_amount' => 0, 'origin' => GatewayRefund::ORIGIN_CHARGEBACK_WAITING,
            ]);

            return false;
        }

        $staff = null;
        try {
            $recorded = DB::transaction(function () use ($payment, $gateway, $chargebackReference, $cents, $currency, $log, &$staff) {
                $invoice = (new RecordPaymentService)->lockInvoice($payment->invoice_id);
                $invoice->transactions()->lockForUpdate()->get(['id']);
                $payment = InvoiceTransaction::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $known = GatewayRefund::query()->where('gateway', $gateway)->where('reference', $chargebackReference)->lockForUpdate()->first();
                if ($known !== null && $known->origin !== GatewayRefund::ORIGIN_CHARGEBACK_WAITING) {
                    $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay');

                    return 0;
                }
                $known?->delete(); // it waited for this payment: recorded now
                // Won before its money was taken (PayPal told the win first): this chargeback is already undone.
                $early = GatewayRefund::query()->where('gateway', $gateway)->where('invoice_transaction_id', $payment->id)
                    ->where('origin', GatewayRefund::ORIGIN_DISPUTE_WON_EARLY)->where('amount', $cents / 100)->orderBy('id')->lockForUpdate()->first();
                if ($early !== null) {
                    $early->update(['origin' => GatewayRefund::ORIGIN_DISPUTE_RESOLVED]);
                    GatewayRefund::create(['invoice_transaction_id' => $payment->id, 'gateway' => $gateway, 'reference' => $chargebackReference,
                        'payment_reference' => $payment->transaction_id, 'amount' => $cents / 100, 'currency_code' => $invoice->currency_code,
                        'recorded_amount' => 0, 'origin' => GatewayRefund::ORIGIN_CHARGEBACK_WON]);
                    $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'chargeback_already_won');

                    return 0;
                }
                $row = ['invoice_transaction_id' => $payment->id, 'gateway' => $gateway, 'reference' => $chargebackReference,
                    'payment_reference' => $payment->transaction_id, 'amount' => $cents / 100, 'currency_code' => $invoice->currency_code,
                    'origin' => GatewayRefund::ORIGIN_CHARGEBACK];
                if ($currency !== null && strtoupper($currency) !== strtoupper((string) $invoice->currency_code)) {
                    GatewayRefund::create(['recorded_amount' => 0, 'currency_code' => strtoupper($currency)] + $row);
                    $staff = "{$gateway} chargeback {$chargebackReference} is in {$currency}, invoice #{$invoice->id} in {$invoice->currency_code}: not recorded, record it by hand.";
                    $log(PaymentGatewayEvent::OUTCOME_REJECTED, 'currency_mismatch');

                    return 0;
                }

                $left = max(0, RecordPaymentService::toCents($payment->refundable_amount));
                $record = min($cents, $left);
                $source = MoneyTrail::sourceFor($payment->gateway);
                $event = null;
                if ($record > 0) {
                    try {
                        $boughtCredit = (new RecordPaymentService)->lockBoughtCredit($invoice);
                    } catch (DisplayException $e) {
                        $boughtCredit = false;
                        $staff = $e->getMessage() . ' ';
                    }
                    if ($boughtCredit) {
                        $credit = Credit::query()->where('client_id', $invoice->client_id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
                        $take = min($record, max(0, RecordPaymentService::toCents($credit === null ? 0 : $credit->amount)));
                        if ($take > 0) {
                            (new CreditLedger)->remove((int) $invoice->client_id, $invoice->currency_code, $take, MoneyTrail::CAUSE_CHARGEBACK, $source, $invoice, $payment);
                        }
                        // All the credit it bought taken back: not bought any more, so paying the invoice again (or the
                        // dispute won) buys it again (code review H1 / adversarial F6). Part of it: staff.
                        $bought = (int) $invoice->items()->where('reference_type', Credit::class)->get()
                            ->sum(fn (InvoiceItem $line) => $line->totalCents());
                        if ($take === $record && $take === $bought) {
                            $invoice->items()->where('reference_type', Credit::class)->update(['applied_at' => null]);
                        }
                        if ($take < $record) {
                            $staff = ($staff ?? '') . 'The credit this payment bought was spent: ' . self::cents($record - $take) . " {$invoice->currency_code} of it could not be taken back. ";
                        }
                    }
                    $payment->increment('refunded_amount', $record / 100);
                    $event = (new MoneyTrail)->refund($payment, $invoice, $record, $source, MoneyTrail::CAUSE_CHARGEBACK);
                    // Owed again: a paid invoice keeps its total and becomes unpaid (no refund note: it is not a refund).
                    // Read under the locks: refresh() would read this transaction's older snapshot (code review M4).
                    $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail()->withLockedBalance();
                    if ($invoice->status === Invoice::STATUS_PAID && $invoice->remaining > 0) {
                        $invoice->update(['status' => Invoice::STATUS_UNPAID]);
                    }
                }
                GatewayRefund::create(['recorded_amount' => $record / 100, 'money_event_id' => $event?->id] + $row);
                $staff = "{$gateway} took back " . self::cents($cents) . " {$invoice->currency_code} for a dispute ({$chargebackReference}) on payment #{$payment->id}"
                    . " of invoice #{$invoice->id}: recorded " . self::cents($record) . ", the invoice is {$invoice->status}" . ($record > 0 ? ' (owed again).' : ', unchanged.')
                    . ' Its services are as they were; the cron treats the invoice as any unpaid one (put the service on hold to wait for the dispute). '
                    . ($record < $cents ? self::cents($cents - $record) . ' of it was no longer on the payment (credit or refunds): settle it by hand. ' : '')
                    . ($staff ?? '');
                $log($record > 0 ? PaymentGatewayEvent::OUTCOME_RECORDED : PaymentGatewayEvent::OUTCOME_IGNORED, 'chargeback');

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay');

            return false;
        }

        if ($staff !== null) {
            $message = trim($staff);
            Log::warning($message);
            NotificationHelper::sendSystemEmailNotification('Chargeback needs staff attention', e($message));
        }

        return $recorded > 0;
    }

    /**
     * The dispute was won and the gateway gave the money back (PR 2c-2b): the chargeback is undone (its journal event
     * reversed, the payment holds the money again), and the invoice is settled again under the same locks:
     * - covered again: paid (a credit purchase whose credit the chargeback took gives it again, as on any payment);
     * - paid again in the meantime: the part over becomes the customer's credit;
     * - cancelled or refunded in the meantime (e.g. the service was terminated while the dispute ran): the money back
     *   becomes the customer's credit (code review of 2c-2b, M3).
     * Once per chargeback; $resolutionReference (PayPal's dispute id) is kept so a replay changes nothing (M1).
     * $chargebackReference names the chargeback (Stripe: the dispute); without one (PayPal) the payment's only standing
     * chargeback, else the one of that amount; none: staff are told (M2). A win told before its chargeback (Stripe, out of
     * order) is kept, so the late chargeback is not recorded (M5).
     */
    public static function chargebackReversedAtGateway(string $gateway, string $paymentReference, ?string $chargebackReference, float $amount, ?string $currency = null, ?string $resolutionReference = null): bool
    {
        $cents = RecordPaymentService::toCents($amount);
        $payment = $paymentReference !== '' ? self::gatewayPayment($gateway, $paymentReference) : null;
        $log = fn (string $outcome, string $reason, ?GatewayRefund $row = null) => GatewayLog::current()->result($outcome, $reason, [
            'amount' => $cents / 100, 'currency_code' => $currency !== null ? strtoupper($currency) : null,
            'external_id' => $resolutionReference ?? $chargebackReference ?? $row?->reference,
            'invoice_id' => $payment?->invoice_id, 'invoice_transaction_id' => $row !== null ? $row->invoice_transaction_id : $payment?->id,
        ]);
        if ($resolutionReference !== null && GatewayRefund::query()->where('gateway', $gateway)->where('reference', $resolutionReference)->exists()) {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay');

            return false;
        }

        $row = null;
        if ($chargebackReference !== null) {
            $row = GatewayRefund::query()->where('gateway', $gateway)->where('reference', $chargebackReference)->first();
        } elseif ($payment !== null) {
            // PayPal does not name the reversal a dispute took: undone only when it can be no other (one dispute ever
            // opened on the payment, one chargeback standing); otherwise staff decide (adversarial F3: a won dispute
            // must never undo another dispute's lost chargeback).
            $standing = GatewayRefund::query()->where('gateway', $gateway)->where('invoice_transaction_id', $payment->id)
                ->where('origin', GatewayRefund::ORIGIN_CHARGEBACK)->orderBy('id')->get();
            $otherDisputes = GatewayRefund::query()->where('gateway', $gateway)->where('invoice_transaction_id', $payment->id)
                ->whereIn('origin', [GatewayRefund::ORIGIN_DISPUTE, GatewayRefund::ORIGIN_DISPUTE_RESOLVED, GatewayRefund::ORIGIN_DISPUTE_WON_EARLY])
                ->whereNotIn('reference', ['opened:' . $resolutionReference, (string) $resolutionReference])->count();
            $row = $standing->count() === 1 && $otherDisputes === 0 ? $standing->first() : null;
        }

        if ($row === null) {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'chargeback_unknown');
            if ($payment === null) {
                return false;
            }
            // Kept: a late chargeback (Stripe) is then a replay, a replayed win (PayPal) changes nothing.
            try {
                GatewayRefund::create(['invoice_transaction_id' => $payment->id, 'gateway' => $gateway,
                    'reference' => $resolutionReference ?? (string) $chargebackReference, 'payment_reference' => $payment->transaction_id,
                    'amount' => $cents / 100, 'currency_code' => strtoupper((string) ($currency ?? '')), 'recorded_amount' => 0,
                    // PayPal, no chargeback at all yet: won early, so one arriving later records nothing; chargebacks standing
                    // but none surely this dispute's: kept as resolved, staff decide. Stripe: the dispute's own id.
                    'origin' => $resolutionReference === null ? GatewayRefund::ORIGIN_CHARGEBACK_WON
                        : (GatewayRefund::query()->where('gateway', $gateway)->where('invoice_transaction_id', $payment->id)
                            ->where('origin', GatewayRefund::ORIGIN_CHARGEBACK)->exists() ? GatewayRefund::ORIGIN_DISPUTE_RESOLVED : GatewayRefund::ORIGIN_DISPUTE_WON_EARLY)]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }
            NotificationHelper::sendSystemEmailNotification('Chargeback needs staff attention', e(
                "{$gateway} reports the dispute " . ($resolutionReference ?? $chargebackReference) . ' on payment #' . $payment->id . " of invoice #{$payment->invoice_id}"
                . ' won (' . self::cents($cents) . '), but no chargeback of it is recorded here: nothing changed. If money was taken back and recorded by hand, undo it by hand.'
            ));

            return false;
        }

        $staff = null;
        try {
            $reversed = DB::transaction(function () use ($row, $gateway, $resolutionReference, $log, &$staff) {
                // The chargeback's own payment (not whatever the gateway's reference finds now: L2 of the code review).
                $owner = InvoiceTransaction::query()->find($row->invoice_transaction_id);
                if ($owner === null) {
                    return 0;
                }
                $invoice = (new RecordPaymentService)->lockInvoice($owner->invoice_id);
                $invoice->transactions()->lockForUpdate()->get(['id']);
                $payment = InvoiceTransaction::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $row = GatewayRefund::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($row === null || $row->origin !== GatewayRefund::ORIGIN_CHARGEBACK) {
                    $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay', $row);

                    return 0;
                }
                if ($resolutionReference !== null) { // once per dispute, under the same locks (M1)
                    GatewayRefund::create(['invoice_transaction_id' => $payment->id, 'gateway' => $gateway, 'reference' => $resolutionReference,
                        'payment_reference' => $payment->transaction_id, 'amount' => $row->amount, 'currency_code' => $row->currency_code,
                        'recorded_amount' => 0, 'origin' => GatewayRefund::ORIGIN_DISPUTE_RESOLVED]);
                }
                $row->update(['origin' => GatewayRefund::ORIGIN_CHARGEBACK_WON]);
                $recorded = RecordPaymentService::toCents($row->recorded_amount);
                $event = $row->money_event_id !== null ? MoneyEvent::query()->find($row->money_event_id) : null;
                if ($recorded <= 0 || $event === null) {
                    $staff = "{$gateway} reports the dispute {$row->reference} on invoice #{$invoice->id} won, but its chargeback recorded nothing here"
                        . ' (another currency, or nothing was left on the payment): anything entered by hand for it is to undo by hand.';
                    $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'chargeback_won_nothing_recorded', $row);

                    return 0;
                }

                $payment->decrement('refunded_amount', $recorded / 100);
                (new MoneyTrail)->reverse($event, MoneyTrail::CAUSE_CHARGEBACK_WON);
                $source = MoneyTrail::sourceFor($payment->gateway);
                // The invoice as it is now, read under the locks (a plain refresh() reads this transaction's older
                // snapshot on MariaDB's repeatable read: M4 of the code review).
                $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail()->withLockedBalance();
                $remaining = RecordPaymentService::toCents($invoice->remaining);
                $toCredit = in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true) ? $recorded : min($recorded, max(0, -$remaining));
                if ($toCredit > 0 && $invoice->client_id !== null) {
                    $payment->increment('credited_amount', $toCredit / 100);
                    (new CreditLedger)->add((int) $invoice->client_id, $invoice->currency_code, $toCredit, MoneyTrail::CAUSE_CHARGEBACK_WON, $source, $invoice, $payment);
                    $staff = self::cents($toCredit) . " {$invoice->currency_code} of it became the customer's account credit (the invoice is {$invoice->status}"
                        . ($invoice->status === Invoice::STATUS_PAID ? ', paid again in the meantime' : '') . ').';
                }
                if ($invoice->status === Invoice::STATUS_UNPAID) {
                    $invoice->unsetRelations();
                    if (RecordPaymentService::toCents($invoice->withLockedBalance()->remaining) <= 0) {
                        // Covered again: paid (InvoiceObserver gives a credit purchase its credit again).
                        $invoice->update(['status' => Invoice::STATUS_PAID]);
                    }
                }
                $log(PaymentGatewayEvent::OUTCOME_RECORDED, 'chargeback_won', $row);
                $staff = "{$gateway} gave back " . self::cents($recorded) . " for the dispute {$row->reference} on invoice #{$invoice->id}: the chargeback is undone,"
                    . " the invoice is {$invoice->fresh()->status}. " . ($staff ?? '');

                return $recorded;
            });
        } catch (UniqueConstraintViolationException) {
            $log(PaymentGatewayEvent::OUTCOME_IGNORED, 'replay', $row);

            return false;
        }

        if ($staff !== null) {
            NotificationHelper::sendSystemEmailNotification($reversed > 0 ? 'Chargeback reversed (dispute won)' : 'Chargeback needs staff attention', e(trim($staff)));
        }

        return $reversed > 0;
    }

    /**
     * A dispute closed in the customer's favour (PayPal; PR 2c-2b): nothing moves (its chargeback stays), but it is kept,
     * once, so a later win of another dispute on the same payment does not undo this one's chargeback.
     */
    public static function disputeLostAtGateway(string $gateway, string $paymentReference, string $resolutionReference, float $amount, ?string $currency = null): void
    {
        $payment = $paymentReference !== '' ? self::gatewayPayment($gateway, $paymentReference) : null;
        GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, 'dispute_lost', [
            'amount' => $amount, 'currency_code' => $currency !== null ? strtoupper($currency) : null, 'external_id' => $resolutionReference,
            'invoice_id' => $payment?->invoice_id, 'invoice_transaction_id' => $payment?->id,
        ]);
        if ($payment === null) {
            return;
        }
        GatewayRefund::query()->firstOrCreate(['gateway' => $gateway, 'reference' => $resolutionReference], [
            'invoice_transaction_id' => $payment->id, 'payment_reference' => $payment->transaction_id, 'amount' => RecordPaymentService::toCents($amount) / 100,
            'currency_code' => strtoupper((string) ($currency ?? '')), 'recorded_amount' => 0, 'origin' => GatewayRefund::ORIGIN_DISPUTE_RESOLVED,
        ]);
    }

    /** The customer is told of a refund, after the commit; a failing notification never undoes the refund. */
    private static function notifyRefund(InvoiceTransaction $transaction, int $cents, bool $toCredit, bool $byGateway): void
    {
        $invoice = $transaction->invoice;
        if ($invoice === null || $invoice->client === null) {
            return;
        }
        DB::afterCommit(function () use ($invoice, $cents, $toCredit, $byGateway) {
            try {
                NotificationHelper::invoiceRefundedNotification($invoice->client, $invoice->fresh(), $cents, $toCredit, $byGateway);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private static function cents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Record a refund by updating the transaction and creating an adjustment note; a credit purchase also
     * gives back the credit it bought. Both are journaled (docs/money-trail.md §1).
     */
    private static function recordRefund(InvoiceTransaction $transaction, float $amount, ?Credit $credit, string $source, ?int $creditCents = null): bool
    {
        $cents = RecordPaymentService::toCents($amount);
        $creditCents ??= $cents;
        $trail = new MoneyTrail;
        if ($credit !== null && $creditCents > 0) { // checked in prepareRefund (or refundedAtGateway), under the same locks
            (new CreditLedger)->remove((int) $credit->client_id, $credit->currency_code, $creditCents, MoneyTrail::CAUSE_PURCHASE_REFUNDED,
                $source, $transaction->invoice, $transaction);
        }
        // One rounded amount for the transaction, the note and the journal.
        $transaction->increment('refunded_amount', $cents / 100);
        $trail->refund($transaction, $transaction->invoice, $cents, $source);
        self::afterRefund($transaction, $cents, 'Refund for transaction #' . $transaction->id . ' (' . ($transaction->transaction_id ?? 'manual') . ')');

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
        // The service's own fields (WHMCS's tblhosting) for every module: a property set by its module or the checkout
        // wins. cpanel_username: an account imported from WHMCS has only the native username.
        // A product setting of the same name wins too (review R3: Pterodactyl's «dedicated_ip» is a yes/no setting); a
        // password the current key cannot read is left out rather than failing every server action (review R4).
        $settings = $service->product ? self::settingsToArray($service->product->settings) : [];
        $password = rescue(fn () => $service->password, null); // reported
        foreach (['domain' => $service->domain, 'username' => $service->username, 'password' => $password,
            'dedicated_ip' => $service->dedicated_ip, 'assigned_ips' => $service->assigned_ips, 'cpanel_username' => $service->username] as $key => $value) {
            if (!isset($properties[$key]) && !array_key_exists($key, $settings) && $value !== null && $value !== '') {
                $properties[$key] = $value;
            }
        }

        return $properties;
    }

    protected static function checkServer(Service $service, $action)
    {
        // The service's own server (WHMCS's tblhosting.server); chosen on its first creation (the product's group, or
        // the product's server), then kept.
        $server = $action === 'createServer' ? ServerSelector::assign($service) : $service->provisioningServer();

        if (!$server && $service->server_id !== null) {
            // Its server was deleted: never quietly «nothing to do» (the jobs skip «no server»), nor another server.
            throw new Exception("The service's server #{$service->server_id} no longer exists: set its server first");
        }
        if (!$server) {
            throw new NothingToDoOnServer('No server assigned to this product');
        }

        // Does server support this action?
        if (!self::hasFunction($server, $action)) {
            throw new NothingToDoOnServer('Server does not support the action: ' . $action);
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
     * Runs one server action and records it (Σ1, module_actions): a failure stays open for the Pending Module Actions,
     * a success closes it. An adapter fails by throwing; `false` (the old cPanel convention) is turned into an exception
     * too, so no caller mistakes a refused action for a done one. Nothing to do on the server (no module, or a module
     * without the action: NothingToDoOnServer) is not a failure. A create that succeeds starts a pending service:
     * active only once the account exists (WHMCS).
     */
    protected static function serverAction(Service $service, string $action, string $audit)
    {
        $recorded = ModuleAction::ACTIONS[$action];
        try {
            $server = self::checkServer($service, $action);
        } catch (NothingToDoOnServer $e) {
            throw $e;
        } catch (Exception $e) {
            ModuleAction::recordFailure($service, $recorded, $service->server?->extension, $e);
            throw $e;
        }

        self::recordAudit($service, 'extension_action', [], ['action' => $audit]);

        try {
            $result = self::getExtension('server', $server->extension, $server->settings)->$action($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));

            if ($result === false) {
                throw new Exception('Server action ' . $action . ' failed for service #' . $service->id . ' (' . $server->extension . ')');
            }
        } catch (Throwable $e) {
            ModuleAction::recordFailure($service, $recorded, $server->extension, $e);
            throw $e;
        }

        ModuleAction::recordSuccess($service, $recorded, $server->extension);
        if ($action === 'createServer') {
            self::startCreated($service);
        }

        return $result;
    }

    /**
     * The account exists (made, made by hand, or nothing to make): a pending service starts (an ended one stays as it
     * is).
     */
    public static function startCreated(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            if ($locked->status === Service::STATUS_PENDING) {
                $locked->status = Service::STATUS_ACTIVE; // its dates follow (ServiceObserver: Service::datesOnStart)
                $locked->save();
            }
            $service->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Create server
     */
    public static function createServer(Service $service, bool $whilePending = false)
    {
        // One create of a service at a time, whoever asks (the job, its retry, staff): two would make two accounts
        // (adversarial A5). $whilePending: the job's check, read again under the lock.
        $lock = Cache::lock("service-create:{$service->id}", 600);
        if (!$lock->get()) {
            throw new CreateSkipped("A create of service #{$service->id} is already running.");
        }
        try {
            if ($whilePending && Service::query()->whereKey($service->id)->value('status') !== Service::STATUS_PENDING) {
                throw new CreateSkipped("Service #{$service->id} is no longer pending.");
            }

            return self::serverAction($service, 'createServer', 'create_server');
        } finally {
            $lock->release();
        }
    }

    /**
     * Suspend server
     */
    public static function suspendServer(Service $service)
    {
        return self::serverAction($service, 'suspendServer', 'suspend_server');
    }

    /**
     * Unsuspend server
     */
    public static function unsuspendServer(Service $service)
    {
        return self::serverAction($service, 'unsuspendServer', 'unsuspend_server');
    }

    /**
     * Terminate server
     */
    public static function terminateServer(Service $service)
    {
        return self::serverAction($service, 'terminateServer', 'terminate_server');
    }

    /**
     * Upgrade server
     */
    public static function upgradeServer(Service $service)
    {
        return self::serverAction($service, 'upgradeServer', 'upgrade_server');
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
