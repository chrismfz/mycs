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
        // Held here already (D-25: active, grace, redemption, an order paid, an expired row still recoverable) is not
        // added twice. An expired row past its window or an unpaid order of it does not hold it: added, it holds the name and those are closed (DomainNameHold::closeOthers).
        if (Domain::query()->where('live_fqdn', $fqdn)->exists()) {
            throw new DisplayException(__(':domain is already here.', ['domain' => $fqdn]));
        }
        $currency = $currency ?: (Invoice::query()->where('client_id', $client->id)->latest('id')->value('currency_code')
            ?: Service::query()->where('client_id', $client->id)->latest('id')->value('currency_code')
            ?: (string) config('settings.default_currency', 'USD'));
        if (!Currency::query()->whereKey($currency)->exists()) {
            throw new DisplayException(__('No currency :code.', ['code' => $currency]));
        }

        $tldModel = DomainTld::query()->where('tld', $tld)->first();
        $domain = new Domain(['client_id' => $client->id, 'registrar_connection_id' => $connection->id, 'sld' => $sld, 'tld' => $tld,
            'fqdn' => $fqdn, 'status' => DomainStatus::Active, 'currency_code' => $currency, 'domain_tld_id' => $tldModel?->id]);
        // Its renewal period (sweep DOMAINS-5): never the column's 1 year where its registrar renews in steps (the .gr
        // registry: 2), which is never billed; its TLD's shortest renewal in those steps, else one step.
        $domain->years = self::renewalYears($connection, $tldModel, $currency);
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

    /** The shortest period its TLD renews for in $currency within its registrar's renewal steps; one step when none. */
    private static function renewalYears(DomainRegistrarConnection $connection, ?DomainTld $tld, string $currency): int
    {
        try {
            $step = max(1, app(DomainRegistrarFactory::class)->for($connection)->capabilities()->renewYearStep);
        } catch (Throwable) {
            $step = 1;
        }
        $priced = $tld?->offeredYears('renew', $currency)->filter(fn (int $y) => $y >= 1 && $y % $step === 0)->min();

        return $priced === null ? $step : (int) $priced;
    }
}
