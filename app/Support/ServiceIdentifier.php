<?php

namespace App\Support;

use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Ops\Scrub;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * What tells a service apart from the others of its product (docs/ROADMAP.md «Τι ξεχωρίζει κάθε υπηρεσία», owner
 * 2026-11-03: ten «Hosting Plus» look alike). One place for every page and mail that names a service: the services list,
 * its page (title, breadcrumb), the invoice lines, the home page, and its mails (Service::mailName, #592; never a username there).
 *
 * - A license: its own label (its nickname) and the IP it runs on, else its key masked (prefix…last4). A service of the
 *   licensing module with no license row yet (its domain is its key: code review of #592, F5) the same.
 * - Anything else: its domain (hosting; a VM's or a server's hostname; one with no dot is no domain but a key, masked),
 *   or with none, its own label, the hostname it was ordered with and its panel's username (Centova, cPanel…: only to
 *   who may manage the service, review of #615); then its IP.
 *
 * Never a password nor another property: only the `*_username` keys, the `username` column and `hostname`.
 */
final class ServiceIdentifier
{
    /**
     * The service's relations it reads: eager load them on a list.
     *
     * @var list<string>
     */
    public const RELATIONS = ['license', 'product.server', 'properties'];

    /**
     * What tells it apart, most telling first. Its panel's username only for whom may see it ($username; null: who may
     * manage the service, as its page shows it: review of #615), never in a mail.
     *
     * @return list<string>
     */
    public static function parts(Service $service, ?bool $username = null): array
    {
        $label = trim((string) ($service->getAttributes()['label'] ?? ''));
        $license = $service->license;
        if ($license !== null || $service->product?->server?->extension === 'NixpalLicensing') {
            $ips = $license === null ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $license->valid_ip))));
            $ip = $ips === [] ? '' : $ips[0] . (count($ips) > 1 ? ' +' . (count($ips) - 1) : '');
            $parts = array_values(array_filter([$label, $ip], fn (string $part) => $part !== ''));
            $key = (string) Scrub::licenseKey((string) ($license->license_key ?? $service->domain ?? ''));

            return $parts !== [] ? $parts : ($key !== '' ? [$key] : []);
        }

        $domain = trim((string) $service->domain);
        if ($domain !== '') {
            // No dot: no domain but a key, or whatever was typed there (a license whose module is gone): masked.
            $parts = [str_contains($domain, '.') ? $domain : (string) Scrub::licenseKey($domain)];
        } else {
            // No domain: its own label first, as a mail named it (#592; review of #615), then its hostname and username.
            $username ??= self::mayManage($service);
            $parts = [$label, ...self::fromProperties($service, $username)];
        }
        $parts[] = trim((string) $service->dedicated_ip);

        return array_values(array_unique(array_filter($parts, fn (string $part) => $part !== '')));
    }

    /** The parts in one line («shop.example.gr · 203.0.113.10»), cut to $limit characters when given; '' for none. */
    public static function line(Service $service, ?int $limit = null, ?bool $username = null): string
    {
        $line = implode(' · ', self::parts($service, $username));

        return $limit === null ? $line : Str::limit($line, $limit);
    }

    /**
     * Its name and what tells it apart («Hosting Plus — shop.example.gr»): $name, then the parts that are not $name
     * already (its label is often both).
     */
    public static function named(Service $service, string $name, ?int $limit = null, ?bool $username = null): string
    {
        $name = trim($name);
        $line = self::besides($service, $name, $limit, $username);

        return match (true) {
            $line === '' => $name,
            $name === '' => $line,
            default => "{$name} — {$line}",
        };
    }

    /**
     * The parts that are not $name already (a service's label is often one), nor in $except (what the page says beside
     * it), in one line; '' for none.
     *
     * @param  list<string>  $except
     */
    public static function besides(Service $service, string $name, ?int $limit = null, ?bool $username = null, array $except = []): string
    {
        $skip = [trim($name), ...array_map('trim', $except)];
        $line = implode(' · ', array_filter(self::parts($service, $username), fn (string $part) => !in_array($part, $skip, true)));

        return $limit === null ? $line : Str::limit($line, $limit);
    }

    /**
     * What tells an invoice line's service apart (its page, the list): its own label and the parts its text does not
     * say already (a line from WHMCS often has the domain in it; a whole word, not inside a longer one: review of #615);
     * '' for a line of no service, or of one deleted since.
     */
    public static function forLine(InvoiceItem $item): string
    {
        $reference = $item->reference_type === null ? null : $item->reference;
        $service = match (true) {
            $reference instanceof Service => $reference,
            $reference instanceof ServiceUpgrade => $reference->service,
            default => null,
        };
        if (!$service instanceof Service) {
            return '';
        }
        $text = (string) $item->description;
        $label = trim((string) ($service->getAttributes()['label'] ?? ''));
        $parts = array_values(array_unique(array_filter([$label, ...self::parts($service)], fn (string $part) => $part !== '')));

        return implode(' · ', array_filter($parts, fn (string $part) => preg_match('/(?<![\p{L}\p{N}.\-])' . preg_quote($part, '/') . '(?![\p{L}\p{N}\-]|\.[\p{L}\p{N}])/iu', $text) !== 1));
    }

    /**
     * Who may manage it (its page shows its username then): the services policy's `update`, which asks only of its
     * account (a grant with `manageproducts`, or staff with admin.services.update), so asked once per account in a
     * request, not on each row of a list (review of #615).
     */
    private static function mayManage(Service $service): bool
    {
        $asked = request()->attributes->get(self::class, []);
        $client = (int) $service->client_id;
        if (!array_key_exists($client, $asked)) {
            $asked[$client] = Gate::allows('update', $service);
            request()->attributes->set(self::class, $asked);
        }

        return $asked[$client];
    }

    /**
     * The hostname it was ordered with (a VM's: the customer's own field), and its panel's username when $username: its
     * module's (`centova_username` of CentovaCast…, a stale one of another module after), else the service's own column.
     *
     * @return list<string>
     */
    private static function fromProperties(Service $service, bool $username): array
    {
        $properties = $service->relationLoaded('properties') ? $service->properties : $service->properties()->get();
        $hostname = trim((string) $properties->first(fn ($property) => $property->key === 'hostname' && filled($property->value))?->value);
        if (!$username) {
            return [$hostname];
        }
        $module = strtolower((string) $service->product?->server?->extension);
        $names = $properties->filter(fn ($property) => preg_match('/^[a-z0-9]+_username$/', (string) $property->key) === 1 && filled($property->value))
            ->sortByDesc(fn ($property) => $module !== '' && str_starts_with($module, Str::before((string) $property->key, '_username')));
        $name = trim((string) ($names->first()?->value ?? $service->getAttributes()['username'] ?? ''));

        return [$hostname, $name];
    }
}
