<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Jobs\Domains\PushContactJob;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\ModuleAction;
use RuntimeException;

/**
 * P1b-2: a contact's address, phone or email changed: each registrar contact (a role's handle) that is this contact's
 * alone is set to the same, once per handle, off the request, after it commits. Never (reviews of #248):
 * - a handle another contact also has, in any account (a .gr contact or an agency's handle linked by staff or the sync):
 *   its other owners' WHOIS would change; staff are told (a Pending Module Action) and update it by hand;
 * - through a connection switched off, or the .gr registry for a contact not made under our prefix.
 * A holder's email too (owner 2026-10-20): one who lost the old address is the reason to change it; the form says that a
 * gTLD's registrar asks the new address to confirm it.
 * The job checks it all again when it runs.
 */
final class ContactPush
{
    /** What reaches the registrars; who the contact is never does (P1b-1, Contact::IDENTITY). */
    public const DETAILS = ['address1', 'address2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];

    /**
     * The handles to update now, each with its connection and its domains.
     *
     * @param  list<int>|null  $onlyDomains  only these domains (a staff retry)
     * @return list<array{connection: int, handle: string, domains: list<int>}>
     */
    public static function targets(Contact $contact, ?array $onlyDomains = null, bool $flagShared = true): array
    {
        $targets = [];
        foreach ($contact->domainRoles(atRegistrar: true) as $use) {
            if ($onlyDomains !== null && !in_array($use['domain_id'], $onlyDomains, true)) {
                continue;
            }
            $domain = Domain::query()->find($use['domain_id']);
            $connection = $domain?->effectiveRegistrarConnection();
            $handle = trim((string) $domain?->{"{$use['role']}_handle"});
            if ($domain === null || $handle === '' || !$connection instanceof DomainRegistrarConnection || !$connection->isUsable()) {
                continue;
            }
            $key = $connection->id . '|' . $handle;
            $targets[$key] ??= ['connection' => $connection->id, 'handle' => $handle, 'domains' => []];
            $targets[$key]['domains'] = array_values(array_unique([...$targets[$key]['domains'], (int) $domain->id]));
        }

        $sendable = [];
        foreach ($targets as $target) {
            if (self::sharedWithOthers($target['handle'], $contact->id)) {
                if ($flagShared) {
                    foreach (Domain::query()->whereKey($target['domains'])->get() as $domain) {
                        ModuleAction::domainFailed($domain, null, null, new RuntimeException("The registrar contact {$target['handle']} is also another contact's: not sent (it would change their WHOIS too). Update it at the registrar by hand, or give this contact its own."),
                            ModuleAction::DOMAIN_CONTACT, $target['handle']);
                    }
                }

                continue;
            }
            $sendable[] = $target;
        }

        return $sendable;
    }

    /** Whether a domain, in any account and any role, holds this handle for another contact (or for none of ours). */
    public static function sharedWithOthers(string $handle, int $contactId): bool
    {
        foreach (Domain::ROLES as $role) {
            $other = Domain::query()->where("{$role}_handle", $handle)
                ->whereNotIn('status', DomainStatus::terminalValues())
                ->where(fn ($q) => $q->whereNull("{$role}_contact_id")->orWhere("{$role}_contact_id", '!=', $contactId))
                ->exists();
            if ($other) {
                return true;
            }
        }

        return false;
    }

    public static function dispatch(Contact $contact): int
    {
        $targets = self::targets($contact);
        foreach ($targets as $target) {
            PushContactJob::dispatch($contact->id, $target['connection'], $target['handle'], $target['domains'])->afterCommit();
        }

        return count($targets);
    }

    /** A failed push retried by staff (Pending Module Actions): the domain's contacts' handles, checked as a new push. */
    public static function retryDomain(int $domainId): int
    {
        $domain = Domain::query()->find($domainId);
        if ($domain === null || $domain->status->isTerminal()) {
            return 0;
        }
        $sent = 0;
        $contacts = collect(Domain::ROLES)->map(fn (string $role) => $domain->{"{$role}Contact"})->filter()->unique('id');
        foreach ($contacts as $contact) {
            foreach (self::targets($contact, [$domain->id]) as $target) {
                PushContactJob::dispatch($contact->id, $target['connection'], $target['handle'], [$domain->id]);
                $sent++;
            }
        }

        return $sent;
    }
}
