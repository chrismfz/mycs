<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * A domain already at a registrar, onto an account (docs/domains.md §7; «Add existing domain» and `domains:adopt`):
 * read from the registrar, never changed there. Only a live record of this very name, with its expiry, is taken; its
 * expiry, status, nameservers and id come from the registrar; its contact roles are the account's primary contact until
 * staff choose others. Nothing is created when the registrar does not hold it so.
 */
class DomainAdopter
{
    public function __construct(private readonly DomainSyncService $sync) {}

    /** @throws DisplayException why it was refused */
    public function adopt(string $name, Client $client, DomainRegistrarConnection $connection, ?string $currency = null): Domain
    {
        if (!$connection->isUsable()) {
            throw new DisplayException(__('Choose an active registrar connection with an API.'));
        }
        try {
            $fqdn = Domain::normalise($name);
        } catch (InvalidArgumentException) {
            throw new DisplayException(__('Not a domain name.'));
        }
        [$sld, $tld] = explode('.', $fqdn, 2) + [1 => ''];
        if ($tld === '') {
            throw new DisplayException(__('Not a domain name.'));
        }
        if (Domain::query()->where('live_fqdn', $fqdn)->exists()) {
            throw new DisplayException(__(':domain is already here.', ['domain' => $fqdn]));
        }
        $currency = $currency ?: (Invoice::query()->where('client_id', $client->id)->latest('id')->value('currency_code')
            ?: Service::query()->where('client_id', $client->id)->latest('id')->value('currency_code')
            ?: (string) config('settings.default_currency', 'USD'));
        if (!Currency::query()->whereKey($currency)->exists()) {
            throw new DisplayException(__('No currency :code.', ['code' => $currency]));
        }

        $domain = new Domain(['client_id' => $client->id, 'registrar_connection_id' => $connection->id, 'sld' => $sld, 'tld' => $tld,
            'fqdn' => $fqdn, 'status' => DomainStatus::Active, 'currency_code' => $currency,
            'domain_tld_id' => DomainTld::query()->where('tld', $tld)->value('id')]);
        // The registrar first, then anything here: only a live record of this very name, with its expiry.
        try {
            $result = $this->sync->read($domain);
        } catch (Throwable $e) {
            throw new DisplayException($fqdn . ': ' . $e->getMessage());
        }
        // Active as the adapter reads it (Openprovider's ACT, the .gr registry's ok: never one registrar's raw words).
        if ($result->deadRecord || $result->status !== DomainStatus::Active || $result->expiresAt === null) {
            throw new DisplayException(__(':domain is «:status» at the registrar: only an active domain is added.', ['domain' => $fqdn, 'status' => (string) $result->rawStatus]));
        }

        $client->ensurePrimaryContact();
        $contact = $client->fresh()?->primaryContact;

        return DB::transaction(function () use ($domain, $contact) {
            $domain->fill(['registrant_contact_id' => $contact?->id, 'admin_contact_id' => $contact?->id]);
            $domain->save();
            $this->sync->sync($domain, true); // the same truth, written through the one path

            return $domain->fresh() ?? $domain;
        });
    }
}
