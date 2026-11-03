<?php

namespace App\Support;

use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Ops\Scrub;
use Illuminate\Support\Str;

/**
 * What tells a service apart from the others of its product (docs/ROADMAP.md «Τι ξεχωρίζει κάθε υπηρεσία», owner
 * 2026-11-03: ten «Hosting Plus» look alike). One place for every page and mail that names a service: the services list,
 * its page (title, breadcrumb), the invoice lines, the home page, and its mails (Service::mailName, #592).
 *
 * - A license: its own label (its nickname) and the IP it runs on, else its key masked (prefix…last4). A service of the
 *   licensing module with no license row yet (its domain is its key: code review of #592, F5) the same.
 * - Anything else: its domain (hosting; a VM's or a server's hostname; one with no dot is no domain but a key, masked),
 *   its IP, and, with no domain, its panel's username (Centova, cPanel…) or the hostname it was ordered with; with
 *   none of these, its own label.
 *
 * Never a password nor another property: only the `*_username` keys and `hostname`.
 */
final class ServiceIdentifier
{
    /**
     * The service's relations it reads: eager load them on a list.
     *
     * @var list<string>
     */
    public const RELATIONS = ['license', 'product.server', 'properties'];

    /** @return list<string> what tells it apart, most telling first */
    public static function parts(Service $service): array
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

        $parts = [];
        $domain = trim((string) $service->domain);
        if ($domain !== '') {
            // No dot: no domain but a key, or whatever was typed there (a license whose module is gone): masked.
            $parts[] = str_contains($domain, '.') ? $domain : (string) Scrub::licenseKey($domain);
        }
        $ip = trim((string) $service->dedicated_ip);
        if ($ip !== '' && !in_array($ip, $parts, true)) {
            $parts[] = $ip;
        }
        if ($domain === '') {
            $parts = [...self::fromProperties($service), ...$parts];
        }

        return $parts !== [] ? array_values(array_unique($parts)) : ($label !== '' ? [$label] : []);
    }

    /** The parts in one line («shop.example.gr · 203.0.113.10»), cut to $limit characters when given; '' for none. */
    public static function line(Service $service, ?int $limit = null): string
    {
        $line = implode(' · ', self::parts($service));

        return $limit === null ? $line : Str::limit($line, $limit);
    }

    /**
     * Its name and what tells it apart («Hosting Plus — shop.example.gr»): $name, then the parts that are not $name
     * already (its label is often both).
     */
    public static function named(Service $service, string $name, ?int $limit = null): string
    {
        $name = trim($name);
        $line = self::besides($service, $name, $limit);

        return match (true) {
            $line === '' => $name,
            $name === '' => $line,
            default => "{$name} — {$line}",
        };
    }

    /** The parts that are not $name already (a service's label is often one), in one line; '' for none. */
    public static function besides(Service $service, string $name, ?int $limit = null): string
    {
        $line = implode(' · ', array_filter(self::parts($service), fn (string $part) => $part !== trim($name)));

        return $limit === null ? $line : Str::limit($line, $limit);
    }

    /**
     * What tells an invoice line's service apart (its page, the list): the parts its text does not say already (a line
     * from WHMCS often has the domain in it); '' for a line of no service, or of one deleted since.
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
        $text = mb_strtolower((string) $item->description);

        return implode(' · ', array_filter(self::parts($service), fn (string $part) => !str_contains($text, mb_strtolower($part))));
    }

    /** @return list<string> its panel's username, or the hostname it was ordered with (a VM's) */
    private static function fromProperties(Service $service): array
    {
        $properties = $service->relationLoaded('properties') ? $service->properties : $service->properties()->get();
        $username = $properties->first(fn ($property) => preg_match('/^[a-z0-9]+_username$/', (string) $property->key) === 1
            && !str_contains((string) $property->key, 'pending') && filled($property->value));
        $hostname = $properties->first(fn ($property) => $property->key === 'hostname' && filled($property->value));

        return array_values(array_filter([trim((string) $hostname?->value), trim((string) $username?->value)], fn (string $part) => $part !== ''));
    }
}
