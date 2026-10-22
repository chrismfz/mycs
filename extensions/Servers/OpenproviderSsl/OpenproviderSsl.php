<?php

namespace MyCS\Extensions\Servers\OpenproviderSsl;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Exceptions\ServerRefused;
use App\Models\DomainRegistrarConnection;
use App\Models\Service;
use App\Models\SslOrder;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Exception;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SSL certificates from Openprovider (owner 2026-10-22: myip's 12, instead of TheSSLStore), on the domains' Openprovider
 * connection and its client (no second key). Paid, a service waits for its CSR: nothing is ordered, nothing spent, until
 * the customer sends it with a validation method. The order is marked before it is asked for, so a lost answer adopts the
 * order made, never a second one. Openprovider reissues a paid order's certificate (199 days at most since 2026-03): the
 * hourly sync reads it. Terminate keeps the certificate (it expires); cancelling, which revokes it, is the product's choice.
 */
#[ExtensionMeta(
    name: 'Openprovider SSL',
    description: 'SSL certificates from Openprovider, on the domains\' Openprovider connection',
    version: 'builtin',
    author: 'MyCS',
)]
class OpenproviderSsl extends Server
{
    public const KEEP = 'keep';

    public const CANCEL = 'cancel';

    /** An earlier order request whose answer was lost is looked for among orders made this long before it (clock skew). */
    private const RECOVERY_WINDOW_MINUTES = 10;

    /** A lost answer's order not found after this long is told (sync_error, the sync fails) for staff to check. */
    private const STUCK_MINUTES = 30;

    public function getConfig($values = []): array
    {
        $options = DomainRegistrarConnection::query()->where('registrar', 'openprovider')->orderBy('id')->get()
            ->map(fn (DomainRegistrarConnection $c) => ['value' => (string) $c->id, 'label' => "{$c->label} (#{$c->id}, {$c->mode})"])->values()->all();

        return [
            ['name' => 'connection_id', 'type' => 'select', 'label' => 'Openprovider connection', 'required' => true, 'options' => $options,
                'description' => 'The domains\' Openprovider connection: its credentials, nothing stored twice.'],
        ];
    }

    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'product_id', 'type' => 'number', 'label' => 'Openprovider SSL product ID', 'required' => true],
            ['name' => 'dns_automation', 'type' => 'checkbox', 'label' => 'DNS automation (only for zones at Openprovider)', 'default' => false],
            ['name' => 'terminate_mode', 'type' => 'select', 'label' => 'On terminate', 'default' => self::KEEP, 'options' => [
                ['value' => self::KEEP, 'label' => 'Nothing at Openprovider (the certificate expires on its own)'],
                ['value' => self::CANCEL, 'label' => 'Cancel the order (revokes the certificate)'],
            ]],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            [$registrar, $credentials] = $this->openprovider();
            $registrar->sslCall($credentials, 'GET', '/v1/ssl/products?limit=1');
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return true;
    }

    /**
     * The server's Openprovider connection; an order's own, for an order made (code review 6 of #345: never read on
     * another account when the server's connection changes).
     *
     * @return array{0: OpenproviderRegistrar, 1: DomainRegistrarCredentials, 2: DomainRegistrarConnection}
     */
    private function openprovider(?SslOrder $order = null): array
    {
        $connection = DomainRegistrarConnection::query()->find($order !== null && ($order->order_id !== null || $order->requested_at !== null)
            ? $order->connection_id : (int) $this->config('connection_id'));
        if ($connection === null || $connection->registrar !== 'openprovider' || !$connection->isUsable()) {
            throw new Exception('Openprovider SSL: the server has no usable Openprovider connection');
        }

        return [app(OpenproviderRegistrar::class), DomainRegistrarCredentials::fromConnection($connection), $connection];
    }

    /**
     * Openprovider's refusal (a 4xx), or a call not made (RegistrarNotAsked: a contact with no country), as one the
     * customer may read: nothing done; anything else as it is (code review 7 of #345).
     */
    private static function answered(callable $call): mixed
    {
        try {
            return $call();
        } catch (RegistrarRefused|RegistrarNotAsked $e) {
            throw new ServerRefused(preg_replace('/^Openprovider error \d+: /', 'Openprovider: ', $e->getMessage()), 0, $e);
        }
    }

    private static function order(Service $service): ?SslOrder
    {
        return SslOrder::query()->where('service_id', $service->id)->first();
    }

    /** Years of the service's plan: what Openprovider orders (1 to 3). */
    private static function years(Service $service): int
    {
        $plan = $service->plan;
        $months = match ($plan?->billing_unit) {
            'year' => (int) $plan->billing_period * 12,
            'month' => (int) $plan->billing_period,
            default => 0,
        };
        if ($months % 12 !== 0 || $months < 12 || $months > 36) {
            throw new Exception('Openprovider SSL: a certificate is ordered for 1, 2 or 3 years; this plan is not');
        }

        return intdiv($months, 12);
    }

    /** Paid: the service waits for its CSR. Nothing is ordered (nor spent) until the customer sends it. */
    public function createServer(Service $service, $settings, $properties)
    {
        if ((int) ($settings['product_id'] ?? 0) <= 0) {
            throw new Exception('Openprovider SSL: the product has no Openprovider SSL product ID');
        }
        $years = self::years($service);
        [, , $connection] = $this->openprovider();
        // The product and years paid for, kept: what is ordered later, whatever the product or the plan becomes (ADV-2, ADV-3 of #345).
        SslOrder::query()->firstOrCreate(['service_id' => $service->id], ['connection_id' => $connection->id, 'product_id' => (int) $settings['product_id'], 'period' => $years]);

        return true;
    }

    /** A certificate is not suspended: it stays valid; the service page is what suspension closes. */
    public function suspendServer(Service $service, $settings, $properties)
    {
        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return true;
    }

    /**
     * Nothing at Openprovider by default (the certificate expires); cancelling, which revokes it, only when the product
     * says so. A lost answer's order is looked for first, so cancel reaches it (code review 4 of #345); the read after a
     * cancel never fails the terminate (code review 5).
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $order = self::order($service);
        if (($settings['terminate_mode'] ?? self::KEEP) !== self::CANCEL || $order === null) {
            return true;
        }
        if ($order->order_id === null && $order->requested_at !== null && !$this->recover($order)) {
            // An order request still open and not found yet: not ended here, so it is tried again (R2-3 of #345).
            throw new Exception('Openprovider SSL: an order request is still open and its order not found yet: terminate again later');
        }
        if ($order->order_id === null) {
            return true;
        }
        [$registrar, $credentials] = $this->openprovider($order);
        $registrar->sslCall($credentials, 'POST', "/v1/ssl/orders/{$order->order_id}/cancel", ['id' => $order->order_id]);
        try {
            $this->sync($order);
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /** The Openprovider product the service was paid for (kept at payment); the product's setting before that. */
    private static function productId(Service $service, array $settings): int
    {
        return self::order($service)->product_id ?? (int) ($settings['product_id'] ?? 0);
    }

    /**
     * Openprovider's SSL products with this account's prices (its reseller cost per period, `with_price`), for the
     * catalogue page. A read.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        [$registrar, $credentials] = $this->openprovider();
        $rows = [];
        // Paged up to Openprovider's `total` (a page may be shorter than asked: ADV-9 of #348), at most 2000.
        while (count($rows) < 2000) {
            $data = $registrar->sslCall($credentials, 'GET', '/v1/ssl/products?' . http_build_query(['with_price' => 'true', 'limit' => 100, 'offset' => count($rows)]));
            $page = array_values(array_filter((array) ($data['results'] ?? []), 'is_array'));
            array_push($rows, ...$page);
            $total = is_numeric($data['total'] ?? null) ? (int) $data['total'] : null;
            if ($page === [] || ($total !== null && count($rows) >= $total) || ($total === null && count($page) < 100)) {
                break;
            }
        }

        return $rows;
    }

    /** Openprovider changes no certificate's product: a new one is ordered. */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        throw new Exception('Openprovider SSL: a certificate cannot change product; order a new one');
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        return self::order($service) === null ? [] : [['type' => 'ssl']];
    }

    /**
     * What the customer's CSR asks for, checked against the product: its name (a wildcard only for a wildcard product),
     * and no names but it and its www (several names are a product of their own, not offered here yet).
     *
     * @return array{common_name: string, host_names: list<string>, wildcard: bool}
     */
    public function decodeCsr(Service $service, $settings, $properties, string $csr): array
    {
        [$registrar, $credentials] = $this->openprovider();
        $decoded = self::answered(fn () => $registrar->sslCall($credentials, 'POST', '/v1/ssl/csr/decode', ['csr' => $csr]));
        $name = strtolower(trim((string) ($decoded['subject']['common_name'] ?? '')));
        if (str_ends_with($name, '.')) {
            throw new ServerRefused(__('services.ssl.csr_no_name')); // «name.»: not what a certificate names (ADV-6 of #345)
        }
        $wildcard = str_starts_with($name, '*.');
        $bare = $wildcard ? substr($name, 2) : $name;
        if ($bare === '' || filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || !str_contains($bare, '.')) {
            throw new ServerRefused(__('services.ssl.csr_no_name'));
        }
        $product = self::answered(fn () => $registrar->sslCall($credentials, 'GET', '/v1/ssl/products/' . self::productId($service, $settings)));
        if ($wildcard && empty($product['is_wildcard_supported'])) {
            throw new ServerRefused(__('services.ssl.csr_wildcard'));
        }
        $base = str_starts_with($bare, 'www.') ? substr($bare, 4) : $bare;
        $allowed = $wildcard ? [$name, $bare] : [$base, "www.{$base}"];
        foreach ((array) ($decoded['subject_alternative_name'] ?? []) as $san) {
            if (!in_array(strtolower(trim((string) $san)), $allowed, true)) {
                throw new ServerRefused(__('services.ssl.csr_names'));
            }
        }

        return ['common_name' => $name, 'host_names' => [$name], 'wildcard' => $wildcard];
    }

    /** @return list<string> the approver addresses Openprovider allows for this name and product */
    public function approverEmails(Service $service, $settings, $properties, string $commonName): array
    {
        [$registrar, $credentials] = $this->openprovider();
        $domain = preg_replace('/^\*\./', '', $commonName);
        $data = self::answered(fn () => $registrar->sslCall($credentials, 'GET', '/v1/ssl/approver-emails?' . http_build_query([
            'product_id' => self::productId($service, $settings), 'domain' => $domain])));

        return array_values(array_filter(array_map(fn ($e) => is_string($e) ? strtolower(trim($e)) : null, (array) ($data['results'] ?? []))));
    }

    /**
     * The certificate ordered: the CSR the customer sent and how its name is validated. Marked before it is asked for,
     * by one atomic claim (two requests at once: one orders, the other is refused; code review 1 of #345): a lost answer
     * is recovered by its exact CSR, never asked again; only Openprovider's refusal takes the mark off.
     */
    public function placeOrder(Service $service, $settings, $properties, string $csr, string $method, ?string $approverEmail = null): bool
    {
        $order = self::order($service) ?? throw new Exception('Openprovider SSL: the service has not been created');
        if ($order->order_id !== null) {
            throw new ServerRefused(__('services.ssl.already_ordered'));
        }
        [$registrar, $credentials, $connection] = $this->openprovider();
        if ($order->requested_at !== null) {
            if ($this->recover($order)) {
                return true;
            }

            throw new Exception('Openprovider SSL: an earlier order request was not answered and no such order is found yet: staff check it at Openprovider');
        }
        if (!in_array($method, SslOrder::METHODS, true)) {
            throw new ServerRefused(__('services.ssl.method_invalid'));
        }
        $csrInfo = $this->decodeCsr($service, $settings, $properties, $csr);
        $approverEmail = $approverEmail !== null ? strtolower(trim($approverEmail)) : null;
        if ($method === 'email' && !in_array($approverEmail, $this->approverEmails($service, $settings, $properties, $csrInfo['common_name']), true)) {
            throw new ServerRefused(__('services.ssl.approver_invalid'));
        }
        // A handle of this connection's account only (never one made on another); written by the claim alone, so a request
        // racing this one never overwrites the handle another's order was made with (R2-1 of #345).
        $handle = SslOrder::query()->where('connection_id', $connection->id)
            ->whereHas('service', fn ($q) => $q->where('client_id', $service->client_id))->whereNotNull('organization_handle')->value('organization_handle');
        if ($handle === null) {
            $contact = $service->client->primaryContact ?? $service->client->ensurePrimaryContact();
            $handle = self::answered(fn () => $registrar->customerHandle($contact, $credentials));
        }
        $claimed = SslOrder::query()->whereKey($order->id)->whereNull('order_id')->whereNull('requested_at')->update([
            'connection_id' => $connection->id, 'common_name' => $csrInfo['common_name'],
            'host_names' => json_encode($csrInfo['host_names']), 'csr' => trim($csr), 'validation_method' => $method,
            'approver_email' => $method === 'email' ? $approverEmail : null, 'organization_handle' => $handle, 'requested_at' => now(), 'updated_at' => now(),
        ]);
        if ($claimed !== 1) {
            throw new ServerRefused(__('services.ssl.already_ordered'));
        }
        $order->refresh();
        try {
            $data = $registrar->sslCall($credentials, 'POST', '/v1/ssl/orders', array_filter([
                'product_id' => $order->product_id,
                'period' => $order->period,
                'csr' => trim($csr),
                'host_names' => $csrInfo['host_names'],
                'domain_validation_methods' => [['host_name' => $csrInfo['common_name'], 'method' => $method]],
                'approver_email' => $order->approver_email,
                'organization_handle' => $handle,
                'technical_handle' => $handle,
                'autorenew' => 'off', // the billing clock is ours (as domains, D-12)
                'enable_dns_automation' => in_array($settings['dns_automation'] ?? false, [true, 1, '1', 'on', 'true'], true),
                'signature_hash_algorithm' => 'sha2',
                'software_id' => 'linux',
                'start_provision' => true,
            ], fn ($v) => $v !== null), charge: true);
        } catch (RegistrarRefused $e) {
            $order->forceFill(['requested_at' => null])->save();

            throw new ServerRefused(preg_replace('/^Openprovider error \d+: /', 'Openprovider: ', $e->getMessage()), 0, $e);
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            // Answered, no order id: maybe made. The mark stays; the sync recovers it.
            throw new Exception('Openprovider SSL: the order was answered without its id: it is looked for at Openprovider');
        }
        $order->forceFill(['order_id' => $id, 'status' => 'REQ'])->save();
        try {
            $this->sync($order);
        } catch (Throwable $e) {
            report($e); // ordered: what to publish comes with the next sync
        }

        return true;
    }

    /**
     * An order asked for whose answer was lost: Openprovider's order with this very CSR, name, product and customer handle
     * (never another account's order of a reused key: ADV-5 of #345), made since (minus the clock's slack), that no
     * service here has, adopted. Not found: nothing (still not known).
     */
    public function recover(SslOrder $order): bool
    {
        if ($order->order_id !== null || $order->requested_at === null || $order->common_name === null || $order->csr === null) {
            return false;
        }
        [$registrar, $credentials] = $this->openprovider($order);
        $data = $registrar->sslCall($credentials, 'GET', '/v1/ssl/orders?' . http_build_query([
            'common_name_pattern' => $order->common_name, 'order_by.order_date' => 'desc', 'limit' => 20]));
        $since = $order->requested_at->copy()->subMinutes(self::RECOVERY_WINDOW_MINUTES);
        foreach ((array) ($data['results'] ?? []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || strtolower((string) ($row['common_name'] ?? '')) !== $order->common_name || (int) ($row['product_id'] ?? 0) !== $order->product_id
                || self::sameCsr($row['csr'] ?? null, $order->csr) === false || ($row['organization_handle'] ?? null) !== $order->organization_handle) {
                continue;
            }
            $made = self::date($row['order_date'] ?? null);
            if ($made === null || $made->lessThan($since)
                || SslOrder::query()->where('connection_id', $order->connection_id)->where('order_id', $id)->whereKeyNot($order->id)->exists()) {
                continue;
            }
            $order->forceFill(['order_id' => $id])->save();
            $this->sync($order);

            return true;
        }
        if (self::stuck($order)) {
            // Told, never silent (code review 2 of #345): staff check Openprovider, then clear the request.
            throw new Exception('Openprovider SSL: an order request was not answered and no such order is found at Openprovider: check it there, then clear the request');
        }

        return false;
    }

    /**
     * Staff, after checking Openprovider has no such order: the request whose answer was lost cleared, so the customer
     * may order again. Only a request stuck (older than STUCK_MINUTES), and only when its order is not found now: one
     * found is adopted instead (R2-2 of #345: never a second charge for an order made).
     */
    public function clearRequest(SslOrder $order): void
    {
        $order->refresh();
        if ($order->order_id !== null || $order->requested_at === null || !self::stuck($order)) {
            throw new Exception('Openprovider SSL: there is no stuck order request to clear (it may still be answered)');
        }
        try {
            $found = $this->recover($order);
        } catch (Throwable $e) {
            $found = $order->fresh()->order_id !== null; // stuck and not found: the expected exception
        }
        if ($found) {
            throw new Exception("Openprovider SSL: the order was found at Openprovider ({$order->fresh()->order_id}) and linked: nothing cleared");
        }
        $cleared = SslOrder::query()->whereKey($order->id)->whereNull('order_id')->whereNotNull('requested_at')
            ->update(['requested_at' => null, 'sync_error' => null, 'updated_at' => now()]);
        if ($cleared !== 1) {
            throw new Exception('Openprovider SSL: there is no unanswered order request to clear');
        }
    }

    /** What Openprovider tells of the order now: its status, dates, certificate and what to publish to validate. */
    public function sync(SslOrder $order): void
    {
        if ($order->order_id === null) {
            return;
        }
        [$registrar, $credentials] = $this->openprovider($order);
        $data = $registrar->sslCall($credentials, 'GET', "/v1/ssl/orders/{$order->order_id}");
        if ((int) ($data['id'] ?? 0) !== $order->order_id) {
            throw new Exception("Openprovider SSL: order {$order->order_id} was not read");
        }
        $text = fn (string $key) => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $order->forceFill([
            'status' => $text('status'),
            'common_name' => $text('common_name') ?? $order->common_name,
            'expires_at' => self::date($data['expiration_date'] ?? null),
            'reissue_at' => self::date($data['reissue_at'] ?? null),
            'order_ends_at' => self::date($data['order_ends_at'] ?? null),
            'certificate' => $text('certificate'),
            'intermediate' => $text('intermediate_certificate'),
            'validation' => [
                'methods' => array_values(array_filter((array) ($data['domain_validation_methods'] ?? []), 'is_array')),
                'statuses' => is_array($data['domain_validation_statuses'] ?? null) ? $data['domain_validation_statuses'] : [],
                'data' => array_values(array_filter((array) ($data['additional_data'] ?? []), 'is_array')),
                'approver' => $text('email_approver'),
            ],
            'synced_at' => now(),
            'sync_error' => null,
        ])->save();
    }

    /** Openprovider's approver email sent again (email validation). */
    public function resendApproverEmail(Service $service, $settings, $properties): bool
    {
        $order = self::order($service);
        if ($order?->order_id === null || $order->validation_method !== 'email' || $order->issued()) {
            throw new ServerRefused(__('services.ssl.resend_not_now'));
        }
        [$registrar, $credentials] = $this->openprovider($order);
        self::answered(fn () => $registrar->sslCall($credentials, 'POST', "/v1/ssl/orders/{$order->order_id}/approver-email/resend", ['id' => $order->order_id]));

        return true;
    }

    /** An order request unanswered and not found for STUCK_MINUTES. */
    public static function stuck(SslOrder $order): bool
    {
        return $order->order_id === null && $order->requested_at !== null && $order->requested_at->lessThan(now()->subMinutes(self::STUCK_MINUTES));
    }

    /** The same CSR, whatever its line endings and outer whitespace. */
    private static function sameCsr(mixed $a, ?string $b): bool
    {
        $norm = fn (mixed $csr) => is_string($csr) ? preg_replace('/\s+/', '', $csr) : null;

        return $norm($a) !== null && $norm($a) !== '' && $norm($a) === $norm($b);
    }

    /** Openprovider's dates («2026-01-01 0:00:01», Amsterdam's time as its domains'); an empty or zero one is none. */
    private static function date(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($value, 'Europe/Amsterdam')->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
