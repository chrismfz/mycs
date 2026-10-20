<?php

namespace App\Services\Domains;

use App\Jobs\Domains\PushContactJob;
use App\Models\Contact;
use App\Models\Domain;

/**
 * P1b-2: a contact's address, phone or email changed: each domain that uses it, in each role, has its registrar's contact
 * (the role's handle) set to the same, once per handle (one handle in several roles, or the .gr registry's contact
 * shared by several domains, is sent once). Off the request, after it commits; what fails is a Pending Module Action.
 */
final class ContactPush
{
    /** What reaches the registrars; who the contact is never does (P1b-1, Contact::IDENTITY). */
    public const DETAILS = ['address1', 'address2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];

    /**
     * The handles to update, each with the domains it is a contact of (the first one's registrar connection is used).
     *
     * @return array<string, list<int>> «connection id|handle» => domain ids
     */
    public static function targets(Contact $contact): array
    {
        $targets = [];
        foreach ($contact->domainRoles() as $use) {
            $domain = Domain::query()->find($use['domain_id']);
            $handle = trim((string) $domain?->{"{$use['role']}_handle"});
            $connection = $domain?->effectiveRegistrarConnection();
            // A role with no contact at its registrar yet (not registered, an import still unlinked): nothing to update.
            if ($domain === null || $handle === '' || $connection === null || (int) $domain->client_id !== (int) $contact->client_id) {
                continue;
            }
            $key = $connection->id . '|' . $handle;
            $targets[$key] = array_values(array_unique([...($targets[$key] ?? []), (int) $domain->id]));
        }

        return $targets;
    }

    /** A failed push retried by staff (Pending Module Actions): each role of the domain with a handle, sent again. */
    public static function retryDomain(int $domainId): int
    {
        $domain = Domain::query()->find($domainId);
        $sent = 0;
        foreach ($domain === null ? [] : Domain::ROLES as $role) {
            $contact = $domain->{"{$role}Contact"};
            $handle = trim((string) $domain->{"{$role}_handle"});
            if ($contact instanceof Contact && $handle !== '' && (int) $contact->client_id === (int) $domain->client_id) {
                PushContactJob::dispatch($contact->id, $handle, [$domain->id]);
                $sent++;
            }
        }

        return $sent;
    }

    public static function dispatch(Contact $contact): int
    {
        $targets = self::targets($contact);
        foreach ($targets as $key => $domainIds) {
            [, $handle] = explode('|', $key, 2);
            PushContactJob::dispatch($contact->id, $handle, $domainIds)->afterCommit();
        }

        return count($targets);
    }
}
