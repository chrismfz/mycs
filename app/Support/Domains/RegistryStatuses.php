<?php

namespace App\Support\Domains;

use App\Models\Domain;

/**
 * A .gr registry's statuses in words (grepp.md §6: EPP and the EETT's, as the sync keeps them in meta.registrar), for the
 * admin (G8-3) and the portal (P2): each with what it means and its code; one not known, as given.
 */
final class RegistryStatuses
{
    /** @return list<string> */
    public static function words(?string $statuses, bool $withCode = true): array
    {
        return array_values(array_map(function (string $status) use ($withCode) {
            $key = 'domains.registry_status.' . $status;

            return trans()->has($key) ? __($key) . ($withCode ? " ({$status})" : '') : $status;
        }, array_filter(array_map('trim', explode(',', $statuses ?? '')))));
    }

    /**
     * What the portal shows of a .gr domain's record (P2): its statuses and the EETT's, its forms and when the registry
     * last changed it; nothing internal (no ROID, protocol, handles). Null when it has none (not a .gr name, never read).
     *
     * @return array{statuses: list<string>, regulator: list<string>, forms: ?string, updated: ?string}|null
     */
    public static function forCustomer(Domain $domain): ?array
    {
        $record = $domain->meta['registrar'] ?? null;
        if (!is_array($record) || !is_string($record['statuses'] ?? null) || !isset($record['roid'])
            || $domain->effectiveRegistrarConnection()?->registrar !== 'grepp') {
            return null;
        }
        $text = fn (string $key): ?string => is_scalar($record[$key] ?? null) && (string) $record[$key] !== '' ? (string) $record[$key] : null;

        return [
            'statuses' => self::words($record['statuses'], withCode: false),
            'regulator' => self::words($text('regulator_statuses'), withCode: false),
            'forms' => $text('bundle'),
            'updated' => $text('updated'),
        ];
    }
}
