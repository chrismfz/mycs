<?php

namespace App\Services\Import;

use App\Models\Client;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
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
 * - The client's default, as the billing cursor's migration rule (docs/billing-cursor.md, decision 6): the method of the
 *   gateway most of its active or suspended services and domains pay with (WHMCS charges a saved method only for an
 *   invoice of its gateway), the newer one on a tie. None of them on a saved method's gateway: no default and «Auto-charge»
 *   off, as WHMCS never charged it (reviews of #538: a customer paying by bank transfer with an old card saved).
 * - A method of a second Stripe customer (the charge goes with the client's one stripe_id), the same reference twice,
 *   PayPal's card vault (acdc, checkout: not a wallet): skipped, listed. Two accounts on one Stripe customer: listed.
 * - Deleted methods, other gateways, customers not here: skipped, counted. One that fails is skipped, never the whole
 *   import. A token is never printed or logged.
 */
final class WhmcsPayMethodImport
{
    /** @var array<string, int> */
    private array $counts = ['methods' => 0, 'imported' => 0, 'defaults' => 0, 'not_charged' => 0, 'skipped' => 0];

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
            $imported = [];
            foreach ($own as $method) {
                try {
                    $agreement = $this->import($clientId, $method, $tokens[(int) $method['id']] ?? null);
                } catch (Throwable $e) {
                    report(new RuntimeException("Saved method #{$method['id']} of customer #{$clientId} not imported: " . class_basename($e)));
                    $this->skip(1, "customer #{$clientId}'s method #{$method['id']}: it could not be written");
                    $agreement = null;
                }
                if ($agreement !== null) {
                    $imported[] = $agreement;
                }
            }
            $this->chooseDefault($clientId, $imported);
        }
        $this->sharedCustomers();

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** @var array<string, list<int>> Stripe customer => the clients given it */
    private array $stripeCustomers = [];

    /** Two accounts on one Stripe customer: listed (receipts and new cards go to that customer). */
    private function sharedCustomers(): void
    {
        foreach ($this->stripeCustomers as $clients) {
            $clients = array_values(array_unique($clients));
            if (count($clients) > 1) {
                $this->notes[] = 'Customers #' . implode(', #', $clients) . ': one Stripe customer in WHMCS for all of them; check them (its receipts and new cards are shared).';
            }
        }
    }

    /** @var array<int, array<string, int>>|null client => WHMCS gateway module => its active or suspended items */
    private ?array $usage = null;

    /**
     * The default as WHMCS charged (decision 6): the gateway most of the client's live services, addons and domains pay
     * with, of all of them, not only those it saved a method for (R2-1 of #538: one service by card and three by bank
     * transfer is a bank-transfer customer). That gateway has a saved method: its first by WHMCS's order. It has none, or
     * it ties with one that has none: no default, «Auto-charge» off (as WHMCS charged it, never more). Two saved gateways
     * tied: the one whose first method is the newer, still that gateway's first (R2-2).
     *
     * @param  list<array{id: int, module: string, whmcs_id: int}>  $imported
     */
    private function chooseDefault(int $clientId, array $imported): void
    {
        if ($imported === []) {
            return;
        }
        $usage = $this->usage()[$clientId] ?? [];
        $first = []; // gateway module => its first imported method (they come in WHMCS's order)
        foreach ($imported as $agreement) {
            $first[$agreement['module']] ??= $agreement;
        }
        $most = $usage === [] ? 0 : max($usage);
        $leaders = $most > 0 ? array_keys($usage, $most, true) : [];
        $saved = array_values(array_filter($leaders, fn (string $module) => isset($first[$module])));
        if ($saved === [] || count($saved) < count($leaders)) {
            DB::table('clients')->where('id', $clientId)->update(['auto_charge' => false]);
            $this->counts['not_charged']++;

            return;
        }
        usort($saved, fn (string $a, string $b) => $first[$b]['whmcs_id'] <=> $first[$a]['whmcs_id']);
        if (DB::table('clients')->where('id', $clientId)->whereNull('default_billing_agreement_id')->update(['default_billing_agreement_id' => $first[$saved[0]]['id']]) > 0) {
            $this->counts['defaults']++;
        }
    }

    /** @return array<int, array<string, int>> */
    private function usage(): array
    {
        if ($this->usage !== null) {
            return $this->usage;
        }
        $this->usage = [];
        // What WHMCS invoices: live services and their addons, domains active or in grace (never one long expired: R2-3).
        foreach (["SELECT userid, paymentmethod FROM tblhosting WHERE domainstatus IN ('Active', 'Suspended')",
            "SELECT h.userid, a.paymentmethod FROM tblhostingaddons a JOIN tblhosting h ON h.id = a.hostingid WHERE a.status IN ('Active', 'Suspended')",
            "SELECT userid, paymentmethod FROM tbldomains WHERE status IN ('Active', 'Grace')"] as $sql) {
            try {
                foreach ($this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $module = strtolower(trim((string) $row['paymentmethod']));
                    $this->usage[(int) $row['userid']][$module] = ($this->usage[(int) $row['userid']][$module] ?? 0) + 1;
                }
            } catch (PDOException) {
                // no such table here (a fixture): no item on it
            }
        }

        return $this->usage;
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

    /** PayPal's modules whose vault is a PayPal wallet (the gateway charges payment_source.paypal.vault_id); not a card vault. */
    private const PAYPAL_WALLETS = ['paypal_ppcpv'];

    /**
     * @param  array<string, mixed>  $method
     * @param  array{token: string, expiry: string, last_four: string, type: string}|null  $api
     * @return array{id: int, module: string, whmcs_id: int}|null the agreement made
     */
    private function import(int $clientId, array $method, ?array $api): ?array
    {
        $module = strtolower(trim((string) $method['gateway_name']));
        $gatewayId = ($this->gateway)($module);
        $extension = $gatewayId !== null ? (string) DB::table('extensions')->where('id', $gatewayId)->value('extension') : '';
        if (!in_array($extension, ['Stripe', 'PayPal'], true) || ($extension === 'PayPal' && !in_array($module, self::PAYPAL_WALLETS, true))) {
            $this->skip(1, "a «{$module}» method of customer #{$clientId}: no gateway here that charges it");

            return null;
        }
        if ($api === null || trim($api['token']) === '') {
            $this->skip(1, "customer #{$clientId}'s {$extension} method #{$method['id']}: WHMCS's API gave no reference");

            return null;
        }
        $reference = $extension === 'Stripe' ? self::stripe($api['token']) : self::paypal($api['token']);
        if ($reference === null) {
            $this->skip(1, "customer #{$clientId}'s {$extension} method #{$method['id']}: its reference is not one MyCS can charge (customer and payment method)");

            return null;
        }
        // The charge goes with the client's one Stripe customer: a method of another one would never charge (R3 of #538).
        $current = isset($reference['customer']) ? $this->clientStripeCustomer($clientId) : null;
        if ($current !== null && $current !== $reference['customer']) {
            $this->skip(1, "customer #{$clientId}'s Stripe method #{$method['id']}: on a second Stripe customer in WHMCS, it would never charge here (ask for it again)");

            return null;
        }
        if (isset($reference['customer'])) {
            $this->stripeCustomers[$reference['customer']][] = $clientId; // listed if shared, a duplicate too (R2-4)
        }
        if (DB::table('billing_agreements')->where('external_reference', $reference['method'])->exists()) {
            $this->skip(1, "customer #{$clientId}'s {$extension} method #{$method['id']}: the same reference as one imported already");

            return null;
        }
        $lastFour = trim($api['last_four'] !== '' ? $api['last_four'] : (string) ($method['last_four'] ?? ''));
        $cardType = trim($api['type'] !== '' ? $api['type'] : (string) ($method['card_type'] ?? ''));
        [$name, $type] = self::describe($extension, $module, $cardType, $lastFour);
        // PayPal's vault has no expiry (WHMCS writes one 30 years on): none, as the PayPal gateway keeps it.
        $expiry = $extension === 'PayPal' ? null : self::expiry($api['expiry'] !== '' ? $api['expiry'] : (string) ($method['expiry_date'] ?? ''));
        $id = DB::transaction(function () use ($clientId, $gatewayId, $name, $type, $reference, $expiry) {
            if (isset($reference['customer']) && $this->clientStripeCustomer($clientId) === null) {
                DB::table('properties')->insert(['model_type' => Client::class, 'model_id' => $clientId, 'key' => 'stripe_id', 'name' => 'Stripe customer',
                    'value' => $reference['customer'], 'created_at' => now(), 'updated_at' => now()]);
            }

            return (int) DB::table('billing_agreements')->insertGetId(['ulid' => (string) Str::ulid(), 'client_id' => $clientId, 'gateway_id' => $gatewayId,
                'name' => mb_substr($name, 0, 255), 'type' => $type, 'expiry' => $expiry, 'external_reference' => $reference['method'],
                'created_at' => now(), 'updated_at' => now()]);
        });
        $this->counts['imported']++;

        return ['id' => $id, 'module' => $module, 'whmcs_id' => (int) $method['id']];
    }

    /** The client's Stripe customer here (stripe_id, as the Stripe gateway keeps it), or null. */
    private function clientStripeCustomer(int $clientId): ?string
    {
        $value = DB::table('properties')->where(['model_type' => Client::class, 'model_id' => $clientId, 'key' => 'stripe_id'])->value('value');

        return $value !== null ? (string) $value : null;
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
        $text = fn (mixed $value) => is_string($value) ? $value : ''; // only strings: never an array cast (R5 of #538)
        $customer = is_array($decoded) ? $text($decoded['customer'] ?? $decoded['customer_id'] ?? null) : '';
        $method = is_array($decoded) ? $text($decoded['method'] ?? $decoded['payment_method'] ?? $decoded['paymentMethod'] ?? null) : '';
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
        // WHMCS's paypal_ppcpv: {"vaultId", "customerId", "transactionIdentifier"} (read on nixpal's WHMCS, 2026-10-29).
        $value = is_array($decoded) ? ($decoded['vaultId'] ?? $decoded['vault_id'] ?? $decoded['payment_token'] ?? $decoded['token'] ?? $decoded['id'] ?? null) : trim($token);
        $method = is_string($value) ? $value : '';

        return $method !== '' && !str_contains($method, ' ') ? ['method' => $method] : null;
    }

    /** @return array{0: string, 1: string} its name and type as MyCS's gateways write them */
    private static function describe(string $extension, string $module, string $cardType, string $lastFour): array
    {
        if ($extension === 'PayPal') {
            return [trim('PayPal ' . (str_contains($lastFour, '@') ? $lastFour : '')), 'paypal'];
        }
        if (str_contains($module, 'sepa')) {
            return ['SEPA Direct Debit' . ($lastFour !== '' ? ' **** ' . $lastFour : ''), 'sepa_debit'];
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
        if (preg_match('#^(\d{2})(\d{2})$#', $value, $mmyy)) {
            $value = "{$mmyy[1]}/{$mmyy[2]}"; // «1227»: MMYY, never a time of day
        }
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
