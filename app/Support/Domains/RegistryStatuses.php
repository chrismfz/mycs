<?php

namespace App\Support\Domains;

use App\Models\Domain;
use Illuminate\Support\Carbon;

/**
 * A .gr registry's statuses in words (grepp.md §6: EPP and the EETT's, as the sync keeps them in meta.registrar), for the
 * admin (G8-3) and the portal (P2): each with what it means and its code; one not known, as given.
 */
final class RegistryStatuses
{
    /**
     * @param  bool  $customer  the portal's plainer words where they differ (review 4 of #220), without the code
     * @return list<string>
     */
    public static function words(?string $statuses, bool $withCode = true, bool $customer = false): array
    {
        return array_values(array_map(function (string $status) use ($withCode, $customer) {
            $key = $customer && trans()->has('domains.portal_registry_status.' . $status) ? 'domains.portal_registry_status.' . $status : 'domains.registry_status.' . $status;

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
        // Ended (moved away, cancelled, deleted): no longer read, so its last record would say what is no more (review 1).
        if (!is_array($record) || !is_string($record['statuses'] ?? null) || !isset($record['roid']) || $domain->status->isTerminal()
            || $domain->effectiveRegistrarConnection()?->registrar !== 'grepp') {
            return null;
        }
        $text = fn (string $key): ?string => is_scalar($record[$key] ?? null) && (string) $record[$key] !== '' ? (string) $record[$key] : null;
        // Its forms by name only (review 2: «dname, chargeable» is the registry's jargon), as «name (type, …)» kept them.
        $forms = $text('bundle') === null ? null : implode(' · ', array_filter(array_map('trim',
            explode(',', (string) preg_replace('/\s*\([^)]*\)/u', '', (string) $text('bundle'))))));
        // When the registry last changed it, in the app's time as the page's other dates (review 5); kept «Y-m-d H:i UTC».
        $updated = $text('updated');
        try {
            $updated = $updated !== null ? Carbon::parse($updated)->timezone(config('app.timezone'))->format('d/m/Y H:i') : null;
        } catch (\Throwable) {
        }

        return [
            'statuses' => self::words($record['statuses'], withCode: false, customer: true),
            'regulator' => self::words($text('regulator_statuses'), withCode: false, customer: true),
            'forms' => $forms ?: null,
            'updated' => $updated,
        ];
    }
}
