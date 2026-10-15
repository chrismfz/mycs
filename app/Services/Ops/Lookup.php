<?php

namespace App\Services\Ops;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Service;
use App\Models\User;
use RuntimeException;

/**
 * Finding what staff name (docs/ops.md), the same for the commands and the MCP. Not found, or more than one: a
 * RuntimeException that says so.
 */
final class Lookup
{
    /** An id, a license key, or a domain (of several, a live one before an ended one). */
    public static function service(string $id): Service
    {
        $id = trim($id);
        $service = ctype_digit($id) ? Service::query()->find((int) $id)
            : (Service::query()->find(License::query()->where('license_key', $id)->value('service_id'))
                ?? Service::query()->where('domain', $id)->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [Service::STATUS_CANCELLED, Service::STATUS_TERMINATED])
                    ->latest('id')->first());

        return $service ?? throw new RuntimeException("No service {$id}.");
    }

    /** An id, or an email: a primary contact's, then a login's, then any contact's; on several accounts, asked by id. */
    public static function client(string $id): Client
    {
        $id = trim($id);
        if (ctype_digit($id)) {
            return Client::query()->find((int) $id) ?? throw new RuntimeException("No account {$id}.");
        }
        $ids = Contact::query()->where('email', $id)->whereNotNull('primary_of_client_id')->pluck('primary_of_client_id')->all()
            ?: (array) User::query()->where('email', $id)->first()?->clients()->pluck('clients.id')->all()
            ?: Contact::query()->where('email', $id)->pluck('client_id')->all();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > 1) {
            throw new RuntimeException("{$id} is on accounts " . implode(', ', $ids) . ': ask by id.');
        }

        return ($ids === [] ? null : Client::query()->find($ids[0])) ?? throw new RuntimeException("No account {$id}.");
    }

    /** A number, an id, or #id (the id for sure); a number that is another invoice's id is asked. */
    public static function invoice(string $id): Invoice
    {
        $id = trim($id);
        if (str_starts_with($id, '#')) {
            return Invoice::query()->find((int) substr($id, 1)) ?? throw new RuntimeException("No invoice {$id}.");
        }
        $byNumber = Invoice::query()->where('number', $id)->first();
        $byId = ctype_digit($id) ? Invoice::query()->find((int) $id) : null;
        if ($byNumber && $byId && !$byNumber->is($byId)) {
            throw new RuntimeException("{$id} is the number of #{$byNumber->id} and the id of another: ask #{$id} for the id, or by its number.");
        }

        return $byNumber ?? $byId ?? throw new RuntimeException("No invoice {$id}.");
    }
}
