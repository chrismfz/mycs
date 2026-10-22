<?php

namespace MyCS\Extensions\Servers\OpenproviderSsl;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Exceptions\ServerRefused;
use App\Helpers\NotificationHelper;
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

    /** A paid order is renewed this many days before its end, at the earliest. */
    public const RENEW_DAYS = 30;

    /** The customer has paid beyond the order's end by at least this much (a renewal adds a period, not days of drift). */
    private const PAID_BEYOND_DAYS = 30;

    /** A renewal asked for whose order end has not moved after this long is told (sync_error) for staff to check. */
    private const RENEW_STUCK_HOURS = 24;

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
        if ($order->renew_requested_at !== null) {
            $this->recoverRenewal($order);
            $order->refresh();
            if ($order->renew_requested_at instanceof Carbon) {
                // A renewal not seen: it may have made a new order. Not ended here, so it is tried again (ADV-9 of #350).
                throw new Exception('Openprovider SSL: a renewal is still not seen: terminate again later');
            }
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
        // The order a renewal replaced may still carry a valid certificate: cancelled too, as far as Openprovider lets it (ADV-9 of #350).
        foreach (array_reverse($order->previous_order_ids ?? []) as $previous) {
            try {
                $registrar->sslCall($credentials, 'POST', "/v1/ssl/orders/{$previous}/cancel", ['id' => $previous]);
            } catch (Throwable $e) {
                report($e);
            }
            break; // the last one replaced: the older ones have ended
        }
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
            throw new StillNotFound('Openprovider SSL: an order request was not answered and no such order is found at Openprovider: check it there, then clear the request');
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
        if ($order->renew_requested_at !== null && self::stuck($order)) {
            // A renewal not seen: looked for once more; found, it is linked and nothing is cleared (never a second charge).
            // Only Openprovider read and showing nothing permits it: no answer, an error, never clears (code review 1 of #350).
            try {
                $found = $this->recoverRenewal($order);
            } catch (StillNotFound) {
                $found = false;
            }
            if ($found || $order->fresh()->renew_requested_at === null) {
                throw new Exception('Openprovider SSL: the renewal was found at Openprovider: nothing cleared');
            }
            SslOrder::query()->whereKey($order->id)->whereNotNull('renew_requested_at')
                ->update(['renew_requested_at' => null, 'renew_for' => null, 'sync_error' => null, 'updated_at' => now()]);

            return;
        }
        if ($order->order_id !== null || $order->requested_at === null || !self::stuck($order)) {
            throw new Exception('Openprovider SSL: there is no stuck order request to clear (it may still be answered)');
        }
        try {
            $found = $this->recover($order);
        } catch (StillNotFound) {
            $found = false; // read, and not found: the one case it may be cleared
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

    /**
     * Staff, for an order that has ended (its end passed, or cancelled, expired, rejected, failed): the service waits for
     * a new CSR again, its old order kept in previous_order_ids. The customer's next order is a new one (charged): staff
     * only, when the customer has paid for it (R2-1 of #350).
     */
    public function startOver(SslOrder $order): void
    {
        $order->refresh();
        $ended = $order->order_id !== null && ($order->order_ends_at?->isPast() || in_array($order->status, ['CAN', 'EXP', 'REJ', 'FAI'], true));
        if (!$ended || $order->renew_requested_at !== null) {
            throw new Exception('Openprovider SSL: only an ended order, with no renewal pending, starts over');
        }
        $order->forceFill([
            'previous_order_ids' => array_values(array_unique(array_merge($order->previous_order_ids ?? [], [$order->order_id]))),
            'order_id' => null, 'requested_at' => null, 'status' => null, 'common_name' => null, 'host_names' => null, 'csr' => null,
            'validation_method' => null, 'approver_email' => null, 'validation' => null, 'expires_at' => null, 'reissue_at' => null,
            'order_ends_at' => null, 'certificate' => null, 'intermediate' => null, 'sync_error' => null, 'notified_hash' => null,
        ])->save();
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
            // A new order or a reissue waiting for its validation gives no certificate yet: the one in use stays (code review 5).
            'expires_at' => self::date($data['expiration_date'] ?? null) ?? ($text('certificate') === null && $text('status') !== 'CAN' ? $order->expires_at : null),
            'reissue_at' => self::date($data['reissue_at'] ?? null),
            'order_ends_at' => self::date($data['order_ends_at'] ?? null),
            // …but never a cancelled (revoked) order's (R2-5 of #350).
            'certificate' => $text('certificate') ?? ($text('status') === 'CAN' ? null : $order->certificate),
            'intermediate' => $text('certificate') !== null ? $text('intermediate_certificate') : ($text('status') === 'CAN' ? null : $order->intermediate),
            'validation' => [
                'methods' => array_values(array_filter((array) ($data['domain_validation_methods'] ?? []), 'is_array')),
                'statuses' => is_array($data['domain_validation_statuses'] ?? null) ? $data['domain_validation_statuses'] : [],
                'data' => array_values(array_filter((array) ($data['additional_data'] ?? []), 'is_array')),
                'approver' => $text('email_approver'),
            ],
            'synced_at' => now(),
            'sync_error' => null,
        ])->save();
        $this->tellNewCertificate($order);
    }

    /**
     * A certificate the customer was not told of yet (issued, or reissued): told once, by its hash claimed atomically (a
     * sync and a customer's read at once tell it once). Only for a live service; a failed mail is reported, the sync goes on.
     */
    private function tellNewCertificate(SslOrder $order): void
    {
        if ($order->certificate === null) {
            return;
        }
        $hash = hash('sha256', $order->certificate);
        $service = $order->service()->first();
        if ($hash === $order->notified_hash || $service === null || $service->status !== Service::STATUS_ACTIVE) {
            return;
        }
        $previous = $order->notified_hash;
        $claimed = SslOrder::query()->whereKey($order->id)->where(fn ($q) => $q->whereNull('notified_hash')->orWhere('notified_hash', '!=', $hash))
            ->update(['notified_hash' => $hash]);
        $order->notified_hash = $hash;
        if ($claimed !== 1) {
            return;
        }
        try {
            NotificationHelper::sslCertificateIssuedNotification($service->client, $service, [
                'common_name' => (string) $order->common_name, 'expires' => $order->expires_at?->toDateString()]);
        } catch (Throwable $e) {
            report($e);
            SslOrder::query()->whereKey($order->id)->where('notified_hash', $hash)->update(['notified_hash' => $previous]); // told next time
        }
    }

    /**
     * A new certificate for the order paid (Openprovider charges no reissue): a new CSR for the same name, validated
     * again. Only an issued certificate whose order has not ended.
     */
    public function reissue(Service $service, $settings, $properties, string $csr, string $method, ?string $approverEmail = null): bool
    {
        $order = self::order($service);
        if ($order?->order_id === null || !$order->issued() || $order->status !== 'ACT' || ($order->order_ends_at !== null && $order->order_ends_at->isPast())
            || $order->renew_requested_at !== null) { // never during a renewal: its order is found by this CSR (ADV-4 of #350)
            throw new ServerRefused(__('services.ssl.reissue_not_now'));
        }
        if (!in_array($method, SslOrder::METHODS, true)) {
            throw new ServerRefused(__('services.ssl.method_invalid'));
        }
        $csrInfo = $this->decodeCsr($service, $settings, $properties, $csr);
        if ($csrInfo['common_name'] !== strtolower((string) $order->common_name)) {
            throw new ServerRefused(__('services.ssl.reissue_same_name', ['name' => $order->common_name]));
        }
        $approverEmail = $approverEmail !== null ? strtolower(trim($approverEmail)) : null;
        if ($method === 'email' && !in_array($approverEmail, $this->approverEmails($service, $settings, $properties, $csrInfo['common_name']), true)) {
            throw new ServerRefused(__('services.ssl.approver_invalid'));
        }
        [$registrar, $credentials] = $this->openprovider($order);
        self::answered(fn () => $registrar->sslCall($credentials, 'POST', "/v1/ssl/orders/{$order->order_id}/reissue", array_filter([
            'id' => $order->order_id,
            'csr' => trim($csr),
            'host_names' => $csrInfo['host_names'],
            'domain_validation_methods' => [['host_name' => $csrInfo['common_name'], 'method' => $method]],
            'approver_email' => $method === 'email' ? $approverEmail : null,
            'organization_handle' => $order->organization_handle,
            'enable_dns_automation' => in_array($settings['dns_automation'] ?? false, [true, 1, '1', 'on', 'true'], true),
            'signature_hash_algorithm' => 'sha2',
            'software_id' => 'linux',
        ], fn ($v) => $v !== null)));
        $order->forceFill(['csr' => trim($csr), 'validation_method' => $method, 'approver_email' => $method === 'email' ? $approverEmail : null])->save();
        try {
            $this->sync($order);
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /** Another approver address (email validation, not issued yet): only one Openprovider allows for the name. */
    public function changeApproverEmail(Service $service, $settings, $properties, string $approverEmail): bool
    {
        $order = self::order($service);
        if ($order === null || !$order->waiting() || $order->validation_method !== 'email') {
            throw new ServerRefused(__('services.ssl.resend_not_now'));
        }
        $approverEmail = strtolower(trim($approverEmail));
        if (!in_array($approverEmail, $this->approverEmails($service, $settings, $properties, (string) $order->common_name), true)) {
            throw new ServerRefused(__('services.ssl.approver_invalid'));
        }
        [$registrar, $credentials] = $this->openprovider($order);
        self::answered(fn () => $registrar->sslCall($credentials, 'PUT', "/v1/ssl/orders/{$order->order_id}/approver-email",
            ['id' => $order->order_id, 'approver_email' => $approverEmail]));
        $order->forceFill(['approver_email' => $approverEmail])->save();
        try {
            $this->sync($order);
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * The order renewed at Openprovider (charged) once the customer has paid beyond its end (the service's paid period
     * reaches past the order's end) and that end is near. Marked before it is asked for, by one atomic claim; the mark
     * stays until Openprovider shows the order's end moved (or the new order it made), so a renewal is never asked twice,
     * whatever answer is lost. A refusal takes the mark off and is told. Returns whether a renewal was asked for.
     */
    public function renewIfPaid(SslOrder $order): bool
    {
        if ($order->renew_requested_at !== null) {
            $this->recoverRenewal($order);

            return false;
        }
        $service = $order->service()->with('plan', 'product.settings')->first();
        // A plan billed again only (never a free or one-time one, whose paid date never moves: code review 4 of #350).
        if ($order->order_id === null || $order->order_ends_at === null || $service?->status !== Service::STATUS_ACTIVE
            || $service->plan?->type !== 'recurring' || $order->order_ends_at->greaterThan(now()->addDays(self::RENEW_DAYS))) {
            return false;
        }
        // Paid: by paid invoices' periods (never a date staff moved, an invoice refunded: ADV-5), for the whole period
        // Openprovider renews, the order's years, less a month's slack (never 3 years renewed with one paid: R2-3).
        $paidThrough = $service->paidThrough($order->order_ends_at);
        if (!$paidThrough->greaterThan($order->order_ends_at->copy()->addDays(self::PAID_BEYOND_DAYS))) {
            return false;
        }
        if ($order->order_ends_at->isPast()) {
            // Paid and ended unrenewed, whatever its status (EXP…): told; staff start a new order (ADV-8, R2-1).
            throw new Exception('Openprovider SSL: the order ended unrenewed though its customer has paid: start a new order for it');
        }
        if ($order->status !== 'ACT' || !$paidThrough->greaterThanOrEqualTo($order->order_ends_at->copy()->addYears(max(1, $order->period))->subDays(self::PAID_BEYOND_DAYS))) {
            return false; // not issued, or paid for less than the order's period: never renewed beyond what is paid
        }
        // Its connection resolved before the claim: a claim with nothing asked would only raise a false alarm (code review 7).
        [$registrar, $credentials] = $this->openprovider($order);
        // Claimed for this very order end: a process holding an older read never renews again once it has moved (code review 2).
        $claimed = SslOrder::query()->whereKey($order->id)->where('order_id', $order->order_id)->where('status', 'ACT')
            ->where('order_ends_at', $order->getRawOriginal('order_ends_at'))->whereNull('renew_requested_at')
            ->update(['renew_requested_at' => now(), 'renew_for' => $order->getRawOriginal('order_ends_at'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        $order->refresh();
        $dnsAutomation = in_array($service->product?->settings->pluck('value', 'key')->get('dns_automation'), [true, 1, '1', 'on', 'true'], true);
        try {
            $data = $registrar->sslCall($credentials, 'POST', "/v1/ssl/orders/{$order->order_id}/renew",
                ['id' => $order->order_id, 'enable_dns_automation' => $dnsAutomation], charge: true);
        } catch (RegistrarRefused $e) {
            if (in_array($e->status, [408, 409, 423, 425, 429, 499], true)) {
                // A timeout or a busy answer (a proxy's, ADV-3 of #350): maybe done. The mark stays; the next sync looks.
                throw new Exception('Openprovider SSL: the renewal was not answered clearly (' . $e->status . '): it is looked for', 0, $e);
            }
            $order->forceFill(['renew_requested_at' => null, 'renew_for' => null])->save();

            throw new Exception('Openprovider SSL: the renewal was refused: ' . $e->getMessage(), 0, $e);
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0 && $id !== $order->order_id) {
            $this->adoptRenewal($order, $id);
        } elseif ($id > 0) {
            // Answered: renewed, only its end not shown yet. Never cleared into a second charge (ADV-10 of #350).
            $order->forceFill(['renew_answered_at' => now()])->save();
        }
        try {
            $this->sync($order);
            $this->renewalSeen($order);
        } catch (Throwable $e) {
            report($e); // renewed: the next sync reads it
        }

        return true;
    }

    /**
     * The renewal made a new order: it is the service's now, the old id kept, and the renewal seen (a new order may wait
     * for its validation, with no end yet: never a false alarm, code review 5 of #350).
     */
    private function adoptRenewal(SslOrder $order, int $id): void
    {
        $order->forceFill(['previous_order_ids' => array_values(array_unique(array_merge($order->previous_order_ids ?? [], [$order->order_id]))),
            'order_id' => $id, 'renew_requested_at' => null, 'renew_for' => null, 'renew_answered_at' => null])->save();
    }

    /** The order's end moved by a period at least (half a year: never a second's drift, code review 3 of #350): renewed. */
    private function renewalSeen(SslOrder $order): bool
    {
        if ($order->renew_for !== null && $order->order_ends_at !== null && $order->order_ends_at->greaterThan($order->renew_for->copy()->addDays(180))) {
            $order->forceFill(['renew_requested_at' => null, 'renew_for' => null, 'renew_answered_at' => null])->save();

            return true;
        }

        return false;
    }

    /**
     * A renewal asked for and not seen yet: the order read again (its end moved: done); else the new order it may have
     * made looked for (this CSR, handle and product, made since, no service's), adopted. Not seen after a day: told.
     */
    public function recoverRenewal(SslOrder $order): bool
    {
        if ($order->renew_requested_at === null) {
            return false;
        }
        $this->sync($order);
        if ($this->renewalSeen($order)) {
            return true;
        }
        [$registrar, $credentials] = $this->openprovider($order);
        $data = $registrar->sslCall($credentials, 'GET', '/v1/ssl/orders?' . http_build_query([
            'common_name_pattern' => $order->common_name, 'order_by.order_date' => 'desc', 'limit' => 20]));
        $since = $order->renew_requested_at->copy()->subMinutes(self::RECOVERY_WINDOW_MINUTES);
        foreach ((array) ($data['results'] ?? []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $made = self::date($row['order_date'] ?? null);
            // (a new order found is the renewal: adoptRenewal marks it seen)
            if ($id <= 0 || $id === $order->order_id || in_array($id, $order->previous_order_ids ?? [], true)
                || strtolower((string) ($row['common_name'] ?? '')) !== strtolower((string) $order->common_name)
                || (int) ($row['product_id'] ?? 0) !== $order->product_id || ($row['organization_handle'] ?? null) !== $order->organization_handle
                // never the order a live service of this CSR is waiting for, made since its request (ADV-11, R2-4 of #350)
                || SslOrder::query()->where('connection_id', $order->connection_id)->whereKeyNot($order->id)->whereNull('order_id')->whereNotNull('requested_at')
                    ->whereHas('service', fn ($q) => $q->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED]))->get(['csr', 'requested_at'])
                    ->contains(fn (SslOrder $other) => self::sameCsr($other->csr, $order->csr) && $made !== null
                        && !$made->lessThan($other->requested_at->copy()->subMinutes(self::RECOVERY_WINDOW_MINUTES)))
                || self::sameCsr($row['csr'] ?? null, $order->csr) === false || $made === null || $made->lessThan($since)
                || SslOrder::query()->where('connection_id', $order->connection_id)->where('order_id', $id)->whereKeyNot($order->id)->exists()) {
                continue;
            }
            $this->adoptRenewal($order, $id);
            try {
                $this->sync($order);
            } catch (Throwable $e) {
                report($e);
            }

            return true;
        }
        if ($order->renew_requested_at->lessThan(now()->subHours(self::RENEW_STUCK_HOURS)) && $order->renew_answered_at !== null) {
            // Openprovider answered it: renewed. Told, never clearable into a second charge (ADV-10 of #350).
            throw new Exception('Openprovider SSL: Openprovider answered the renewal, yet the order\'s end has not moved after a day: check it there');
        }
        if ($order->renew_requested_at->lessThan(now()->subHours(self::RENEW_STUCK_HOURS))) {
            throw new StillNotFound('Openprovider SSL: a renewal was asked for and Openprovider does not show it after a day: check the order there, then clear the request');
        }

        return false;
    }

    /** Openprovider's approver email sent again (email validation). */
    public function resendApproverEmail(Service $service, $settings, $properties): bool
    {
        $order = self::order($service);
        if ($order === null || !$order->waiting() || $order->validation_method !== 'email') {
            throw new ServerRefused(__('services.ssl.resend_not_now'));
        }
        [$registrar, $credentials] = $this->openprovider($order);
        self::answered(fn () => $registrar->sslCall($credentials, 'POST', "/v1/ssl/orders/{$order->order_id}/approver-email/resend", ['id' => $order->order_id]));

        return true;
    }

    /** An order request unanswered and not found for STUCK_MINUTES, or a renewal not seen for RENEW_STUCK_HOURS. */
    public static function stuck(SslOrder $order): bool
    {
        return ($order->order_id === null && $order->requested_at !== null && $order->requested_at->lessThan(now()->subMinutes(self::STUCK_MINUTES)))
            || ($order->renew_requested_at !== null && $order->renew_answered_at === null && $order->renew_requested_at->lessThan(now()->subHours(self::RENEW_STUCK_HOURS)));
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
