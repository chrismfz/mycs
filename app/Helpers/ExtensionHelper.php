<?php

namespace App\Helpers;

use App\Attributes\ExtensionMeta;
use App\Classes\FilamentInput;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\PaymentGatewayEvent;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\GatewayLog;
use App\Services\Money\MoneyTrail;
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

        $transaction = (new RecordPaymentService)->handle($invoice, $gateway, $amount, $fee, $transactionId === null ? null : (string) $transactionId, $status, (bool) $isCreditTransaction, $currency);
        // Refunds that arrived before their payment was money here are recorded now (PR 2c-2, F3).
        if ($transaction instanceof InvoiceTransaction && $gateway !== null && $transactionId !== null && $transaction->status === InvoiceTransactionStatus::Succeeded) {
            $extension = $gateway->extension;
            DB::afterCommit(function () use ($extension, $transactionId) {
                try {
                    self::applyWaitingRefunds($extension, (string) $transactionId);
                } catch (\Throwable $e) {
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
                } catch (\Throwable $e) { // an Error too: the subscription may still be another gateway's
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
        } catch (\Throwable $e) {
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

        $userId = null;
        $currency = null;
        DB::transaction(function () use (&$transaction, $amount, $cents, $notify, &$userId, &$currency) {
            $invoice = (new RecordPaymentService)->lockInvoice($transaction->invoice_id);
            if ($invoice->user_id === null) {
                throw new \InvalidArgumentException('This invoice has no customer whose credit could take it.');
            }
            if ($invoice->items()->where('reference_type', Credit::class)->exists()) {
                throw new \InvalidArgumentException('A credit purchase is not refunded to credit: refund it back, which takes the credit it bought.');
            }
            [$transaction] = self::prepareRefund($transaction, $amount);

            $transaction->increment('credited_amount', $cents / 100);
            (new CreditLedger)->add((int) $invoice->user_id, $invoice->currency_code, $cents, MoneyTrail::CAUSE_REFUND_TO_CREDIT,
                MoneyTrail::sourceFor($transaction->gateway, (bool) $transaction->is_credit_transaction), $invoice, $transaction);
            self::afterRefund($transaction, $cents, 'Refund to account credit for transaction #' . $transaction->id);
            [$userId, $currency] = [(int) $invoice->user_id, (string) $invoice->currency_code];
            if ($notify) {
                self::notifyRefund($transaction, $cents, toCredit: true, byGateway: false);
            }
        });

        // The credit pays the customer's other open invoices (decision 5, MT-1e), as an overpayment's does.
        DB::afterCommit(function () use ($userId, $currency) {
            try {
                (new CreditAutoApply)->forCustomer($userId, $currency);
            } catch (\Throwable $e) {
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
            $recorded = DB::transaction(fn () => self::recordGatewayRefund($payment, $gateway, $refundReference, $cents, $currency, $log, $staff));
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
            ->where('origin', GatewayRefund::ORIGIN_WAITING)->orderBy('id')->get()
            ->each(function (GatewayRefund $waiting) use ($gateway, $paymentReference) {
                try {
                    self::refundedAtGateway($gateway, $paymentReference, $waiting->reference, (float) $waiting->amount, $waiting->currency_code !== '' ? $waiting->currency_code : null);
                } catch (\Throwable $e) {
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
            ->where('source', MoneyTrail::SOURCE_LOCAL)->get()->sum(fn (MoneyEvent $event) => RecordPaymentService::toCents($event->amount));
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
            $credit = Credit::query()->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
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

    /** The customer is told of a refund, after the commit; a failing notification never undoes the refund. */
    private static function notifyRefund(InvoiceTransaction $transaction, int $cents, bool $toCredit, bool $byGateway): void
    {
        $invoice = $transaction->invoice;
        if ($invoice === null || $invoice->user === null) {
            return;
        }
        DB::afterCommit(function () use ($invoice, $cents, $toCredit, $byGateway) {
            try {
                NotificationHelper::invoiceRefundedNotification($invoice->user, $invoice->fresh(), $cents, $toCredit, $byGateway);
            } catch (\Throwable $e) {
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
            (new CreditLedger)->remove((int) $credit->user_id, $credit->currency_code, $creditCents, MoneyTrail::CAUSE_PURCHASE_REFUNDED,
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
     * Runs a server action. An adapter fails by throwing; `false` (the old cPanel convention) is turned into an
     * exception too, so no caller mistakes a refused action for a done one.
     */
    protected static function serverAction(Service $service, string $action, string $audit)
    {
        $server = self::checkServer($service, $action);

        self::recordAudit($service, 'extension_action', [], ['action' => $audit]);

        $result = self::getExtension('server', $server->extension, $server->settings)->$action($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));

        if ($result === false) {
            throw new Exception('Server action ' . $action . ' failed for service #' . $service->id . ' (' . $server->extension . ')');
        }

        return $result;
    }

    /**
     * Create server
     */
    public static function createServer(Service $service)
    {
        return self::serverAction($service, 'createServer', 'create_server');
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
