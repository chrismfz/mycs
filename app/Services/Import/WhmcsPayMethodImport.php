<?php

namespace App\Services\Import;

use App\Models\Client;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Throwable;

/**
 * WHMCS's saved payment methods (tblpaymethods) as billing agreements here (docs/importer.md «Αποθηκευμένες μέθοδοι
 * πληρωμής»), so automatic charges go on after the cut-over without customers saving their cards again. The same
 * Stripe and PayPal accounts as WHMCS's (each company keeps its own: S0-6), so their references stay valid.
 *
 * - The reference itself (Stripe's customer and payment method, PayPal's vault token) is in tblcreditcards.card_data,
 *   encrypted with WHMCS's key (not in the database): read through WHMCS's own API (GetPayMethods' remote_token), never
 *   by reading its key. Without the API (WhmcsApi::fromEnvironment) nothing is imported, and it says so.
 * - Name, type and expiry as MyCS's gateways write them («Visa **** 4242», the brand, the month's last day).
 * - Stripe: its customer becomes the client's stripe_id (as Stripe::createOrGetStripeCustomer keeps it), the payment
 *   method the agreement's reference; a token without both is listed, never guessed.
 * - The client's default: its first by WHMCS's order (order_preference), as WHMCS charges.
 * - Deleted methods, other gateways, customers not here: skipped, counted. A token is never printed or logged.
 */
final class WhmcsPayMethodImport
{
    /** @var array<string, int> */
    private array $counts = ['methods' => 0, 'imported' => 0, 'defaults' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param  Closure(string): ?int  $gateway  WHMCS's gateway module → the MyCS gateway extension's id (the importer's)
     */
    public function __construct(private readonly PDO $whmcs, private readonly ?WhmcsApi $api, private readonly Closure $gateway) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $methods = $this->methods();
        $this->counts['methods'] = count($methods);
        if ($methods === []) {
            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        if ($this->api === null) {
            $this->counts['skipped'] = count($methods);
            $this->notes[] = count($methods) . ' saved payment method(s) not imported: WHMCS\'s API is needed for their references '
                . '(--whmcs-api-url, WHMCS_API_IDENTIFIER, WHMCS_API_SECRET in the environment).';

            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        $clients = DB::table('clients')->pluck('id')->flip()->all();
        $byClient = [];
        foreach ($methods as $method) {
            $byClient[(int) $method['userid']][] = $method;
        }
        foreach ($byClient as $clientId => $own) {
            if (!isset($clients[$clientId])) {
                $this->skip(count($own), "customer #{$clientId} not here");

                continue;
            }
            try {
                $tokens = $this->tokens($clientId);
            } catch (Throwable $e) {
                $this->skip(count($own), "customer #{$clientId}: WHMCS's API failed ({$e->getMessage()})");

                continue;
            }
            foreach ($own as $method) {
                $this->import($clientId, $method, $tokens[(int) $method['id']] ?? null);
            }
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** @return list<array<string, mixed>> the methods not deleted, by customer and WHMCS's order */
    private function methods(): array
    {
        try {
            return $this->whmcs->query("SELECT p.id, p.userid, p.gateway_name, p.payment_type, p.order_preference, c.card_type, c.last_four, c.expiry_date
                FROM tblpaymethods p LEFT JOIN tblcreditcards c ON c.pay_method_id = p.id AND c.deleted_at IS NULL
                WHERE p.deleted_at IS NULL AND p.payment_type IN ('RemoteCreditCard', 'RemoteBankAccount')
                ORDER BY p.userid, p.order_preference, p.id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return []; // a WHMCS without them (before 7.10, a fixture)
        }
    }

    /** @return array<int, array{token: string, expiry: string, last_four: string, type: string}> paymethod id => what the API says */
    private function tokens(int $clientId): array
    {
        $answer = $this->api?->call('GetPayMethods', ['clientid' => $clientId]) ?? [];
        $tokens = [];
        foreach ((array) ($answer['paymethods'] ?? []) as $method) {
            if (is_array($method) && isset($method['id'])) {
                $tokens[(int) $method['id']] = ['token' => (string) ($method['remote_token'] ?? ''), 'expiry' => (string) ($method['expiry_date'] ?? ''),
                    'last_four' => (string) ($method['card_last_four'] ?? ''), 'type' => (string) ($method['card_type'] ?? '')];
            }
        }

        return $tokens;
    }

    /** @param array<string, mixed> $method @param array{token: string, expiry: string, last_four: string, type: string}|null $api */
    private function import(int $clientId, array $method, ?array $api): void
    {
        $module = strtolower(trim((string) $method['gateway_name']));
        $gatewayId = ($this->gateway)($module);
        $extension = $gatewayId !== null ? (string) DB::table('extensions')->where('id', $gatewayId)->value('extension') : '';
        if (!in_array($extension, ['Stripe', 'PayPal'], true)) {
            $this->skip(1, "a «{$module}» method of customer #{$clientId}: no gateway here that charges it");

            return;
        }
        if ($api === null || trim($api['token']) === '') {
            $this->skip(1, "customer #{$clientId}'s {$extension} method #{$method['id']}: WHMCS's API gave no reference");

            return;
        }
        $reference = $extension === 'Stripe' ? self::stripe($api['token']) : self::paypal($api['token']);
        if ($reference === null) {
            $this->skip(1, "customer #{$clientId}'s {$extension} method #{$method['id']}: its reference is not one MyCS can charge (customer and payment method)");

            return;
        }
        $lastFour = trim($api['last_four'] !== '' ? $api['last_four'] : (string) ($method['last_four'] ?? ''));
        $cardType = trim($api['type'] !== '' ? $api['type'] : (string) ($method['card_type'] ?? ''));
        [$name, $type] = self::describe($extension, $module, $cardType, $lastFour);
        DB::transaction(function () use ($clientId, $gatewayId, $name, $type, $reference, $api, $method) {
            if (isset($reference['customer'])) {
                $this->stripeCustomer($clientId, $reference['customer']);
            }
            $id = DB::table('billing_agreements')->insertGetId(['ulid' => (string) Str::ulid(), 'client_id' => $clientId, 'gateway_id' => $gatewayId,
                'name' => mb_substr($name, 0, 255), 'type' => $type, 'expiry' => self::expiry($api['expiry'] !== '' ? $api['expiry'] : (string) ($method['expiry_date'] ?? '')),
                'external_reference' => $reference['method'], 'created_at' => now(), 'updated_at' => now()]);
            // The first by WHMCS's order is its default, as WHMCS charges it (ClientObserver: only its own).
            if (DB::table('clients')->where('id', $clientId)->whereNull('default_billing_agreement_id')->update(['default_billing_agreement_id' => $id]) > 0) {
                $this->counts['defaults']++;
            }
        });
        $this->counts['imported']++;
    }

    /** The client's Stripe customer (stripe_id), as the Stripe gateway keeps it; one already set stays, another listed. */
    private function stripeCustomer(int $clientId, string $customer): void
    {
        $where = ['model_type' => Client::class, 'model_id' => $clientId, 'key' => 'stripe_id'];
        $current = DB::table('properties')->where($where)->value('value');
        if ($current === null) {
            DB::table('properties')->insert($where + ['name' => 'Stripe customer', 'value' => $customer, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($current !== $customer) {
            $this->notes[] = "Customer #{$clientId}: methods of two Stripe customers in WHMCS; the first kept, a method of the other one may not charge.";
        }
    }

    /**
     * WHMCS's Stripe token: its JSON {customer, method} (the module's), or «cus_…|pm_…» pairs; a bare one is not enough
     * (a payment method is charged with its customer).
     *
     * @return array{customer: string, method: string}|null
     */
    private static function stripe(string $token): ?array
    {
        $decoded = json_decode($token, true);
        $customer = is_array($decoded) ? (string) ($decoded['customer'] ?? $decoded['customer_id'] ?? '') : '';
        $method = is_array($decoded) ? (string) ($decoded['method'] ?? $decoded['payment_method'] ?? $decoded['paymentMethod'] ?? '') : '';
        if (!is_array($decoded)) {
            preg_match('/\bcus_[A-Za-z0-9]+/', $token, $c);
            preg_match('/\b(?:pm|card|src|ba)_[A-Za-z0-9]+/', $token, $m);
            [$customer, $method] = [$c[0] ?? '', $m[0] ?? ''];
        }

        return str_starts_with($customer, 'cus_') && $method !== '' ? ['customer' => $customer, 'method' => $method] : null;
    }

    /** WHMCS's PayPal (Commerce Platform) vault token: plain, or in its JSON. @return array{method: string}|null */
    private static function paypal(string $token): ?array
    {
        $decoded = json_decode($token, true);
        $method = is_array($decoded) ? (string) ($decoded['vault_id'] ?? $decoded['payment_token'] ?? $decoded['token'] ?? $decoded['id'] ?? '') : trim($token);

        return $method !== '' && !str_contains($method, ' ') ? ['method' => $method] : null;
    }

    /** @return array{0: string, 1: string} its name and type as MyCS's gateways write them */
    private static function describe(string $extension, string $module, string $cardType, string $lastFour): array
    {
        if ($extension === 'PayPal') {
            return [trim('PayPal ' . (str_contains($lastFour, '@') ? $lastFour : '')), 'paypal'];
        }
        if (str_contains($module, 'sepa')) {
            return ['SEPA Direct Debit **** ' . $lastFour, 'sepa_debit'];
        }
        $brand = strtolower(str_replace(' ', '', $cardType));
        $name = match ($brand) {
            'americanexpress', 'amex' => 'American Express',
            'mastercard' => 'Mastercard',
            'visa' => 'Visa',
            'dinersclub', 'diners' => 'Diners Club',
            'discover' => 'Discover',
            'jcb' => 'JCB',
            '' => 'Card',
            default => ucfirst($cardType),
        };
        $type = match ($brand) {
            'americanexpress' => 'amex',
            'dinersclub' => 'diners',
            '' => 'card',
            default => $brand,
        };

        return [$name . ($lastFour !== '' ? ' **** ' . $lastFour : ''), $type];
    }

    /** «06/27» or a date: its month's last day, as the Stripe gateway keeps it; null when not read. */
    private static function expiry(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^(\d{1,2})\s*/\s*(\d{2}|\d{4})$#', $value, $m)) {
            $year = strlen($m[2]) === 2 ? 2000 + (int) $m[2] : (int) $m[2];

            return (int) $m[1] >= 1 && (int) $m[1] <= 12 ? Carbon::create($year, (int) $m[1], 1)->endOfMonth()->toDateString() : null;
        }
        try {
            return $value !== '' && !str_starts_with($value, '0000') ? Carbon::parse($value)->endOfMonth()->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function skip(int $count, string $why): void
    {
        $this->counts['skipped'] += $count;
        $this->notes[] = "Saved method(s) not imported ({$count}): {$why}.";
    }
}
