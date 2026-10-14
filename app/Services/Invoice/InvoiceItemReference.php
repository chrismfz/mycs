<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;

/**
 * What an invoice line may point at (audit sweep AUTH-8 / DATA-19): a service (renewal) or a service upgrade of the
 * invoice's own customer, or nothing. Any class name used to be accepted and instantiated (app($class)); a line on
 * Credit sold account credit, and one on another customer's service renewed it when this invoice was paid.
 */
final class InvoiceItemReference
{
    public const TYPES = [Service::class, ServiceUpgrade::class];

    /**
     * Why the pair (type, id) the line will have is refused, as [field, message], or null. Checked as a pair: an
     * update sending one of them keeps the other from the stored line.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function problem(?Invoice $invoice, mixed $type, mixed $id): ?array
    {
        $type = $type === '' ? null : $type;
        $id = $id === '' ? null : $id;
        if ($type === null && $id === null) {
            return null;
        }
        if ($type === null || $id === null) {
            return [$type === null ? 'reference_type' : 'reference_id', 'reference_type and reference_id go together: both or neither.'];
        }
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            return ['reference_type', 'The reference_type must be one of: ' . implode(', ', self::TYPES) . '.'];
        }
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return ['reference_id', 'The reference_id must be an id.'];
        }
        $owner = self::ownerOf($type, (int) $id);
        if ($owner === null) {
            return ['reference_id', 'The selected reference_id is invalid for the given reference_type.'];
        }
        // The invoice is known on both paths (create: invoice_id is required and exists; update: the line's own).
        if ($invoice !== null && $owner !== (int) $invoice->client_id) {
            return ['reference_id', "The referenced service belongs to a different customer than the invoice's."];
        }

        return null;
    }

    /** The customer of the service a line on ($type, $id) renews, or null when there is none. */
    public static function ownerOf(string $type, int $id): ?int
    {
        $owner = match ($type) {
            Service::class => Service::query()->whereKey($id)->value('client_id'),
            ServiceUpgrade::class => ServiceUpgrade::query()->whereKey($id)->first()?->service?->client_id,
            default => null,
        };

        return $owner === null ? null : (int) $owner;
    }
}
