<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Staff;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Redact;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A domain managed from MyCS, not the registrar's panel (docs/domains.md D4b): its nameservers, transfer lock, WHOIS
 * privacy and contacts, its EPP code, and what the registrar holds for a contact. None of these charges.
 *
 * Every call (D-17): one at a time per domain (a lock), logged with who asked, what was asked and answered, or why it
 * failed (secrets masked; an EPP code never logged or stored), and the registrar's answer applied to the domain as a
 * sync's would be (audited). A write the registrar refused changes nothing here.
 */
class DomainManagementService
{
    /** RFC 1035 host name, lowercase ASCII (IDN as punycode). */
    private const LOCK_SECONDS = 600;

    private const HOST = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
        private readonly DomainLifecycle $lifecycle,
    ) {}

    /** It can be managed: routed to an active connection with an API, and not ended. */
    public function isManageable(Domain $domain): bool
    {
        return $this->sync->isSyncable($domain);
    }

    /**
     * @param  list<string>  $hosts  2 to 13, in order
     */
    public function setNameservers(Domain $domain, array $hosts, ?Staff $staff): void
    {
        $hosts = array_values(array_unique(array_map(fn ($host) => Domain::normalise(trim((string) $host)), array_filter($hosts, fn ($host) => trim((string) $host) !== ''))));
        if (count($hosts) < 2 || count($hosts) > 13) {
            throw new InvalidArgumentException(__('domains.manage.nameserver_count'));
        }
        foreach ($hosts as $host) {
            if (preg_match(self::HOST, $host) !== 1) {
                throw new InvalidArgumentException(__('domains.manage.nameserver_invalid', ['host' => $host]));
            }
        }

        $this->write($domain, 'nameservers', ['nameservers' => $hosts], $staff,
            fn (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) => $registrar->updateDomain($domain, new DomainChanges(nameservers: $hosts), $credentials));
    }

    public function setTransferLock(Domain $domain, bool $locked, ?Staff $staff): void
    {
        $this->write($domain, $locked ? 'lock' : 'unlock', ['transfer_lock' => $locked], $staff,
            fn (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) => $registrar->updateDomain($domain, new DomainChanges(transferLock: $locked), $credentials));
    }

    public function setWhoisPrivacy(Domain $domain, bool $enabled, ?Staff $staff): void
    {
        $this->write($domain, $enabled ? 'privacy_on' : 'privacy_off', ['whois_privacy' => $enabled], $staff,
            fn (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) => $registrar->updateDomain($domain, new DomainChanges(whoisPrivacy: $enabled), $credentials));
    }

    /**
     * The contacts of its roles (D-3), the account's own: a role whose contact changes gets a new handle at the
     * registrar. If the registrar refuses, the roles stay as they were.
     *
     * @param  array<string, int|null>  $contacts  role => contact id (null: none; a missing role is unchanged)
     */
    public function setContacts(Domain $domain, array $contacts, ?Staff $staff): void
    {
        $changed = [];
        foreach (Domain::ROLES as $role) {
            if (!array_key_exists($role, $contacts)) {
                continue;
            }
            $id = $contacts[$role] !== null ? (int) $contacts[$role] : null;
            if ($id !== null && !Contact::query()->whereKey($id)->where('client_id', $domain->client_id)->exists()) {
                throw new InvalidArgumentException(__('domains.manage.contact_not_the_accounts'));
            }
            if ($id !== $domain->{"{$role}_contact_id"}) {
                $changed["{$role}_contact_id"] = $id;
            }
        }
        $registrant = array_key_exists('registrant_contact_id', $changed) ? $changed['registrant_contact_id'] : $domain->registrant_contact_id;
        if ($registrant === null) {
            throw new InvalidArgumentException(__('domains.manage.registrant_required'));
        }
        if ($changed === []) {
            return;
        }
        $details = $domain->meta['registrar'] ?? [];
        if (array_key_exists('registrant_contact_id', $changed)) {
            // A new registrant is an owner change: on many TLDs a trade, charged and approved by the owner (adversarial
            // F6). Only where the registrar says the owner may simply be modified; a trade goes with D5.
            if (($details['modify_owner_allowed'] ?? null) !== true) {
                throw new InvalidArgumentException(__('domains.manage.owner_change_not_here'));
            }
        } elseif ($domain->registrant_contact_id !== null && trim((string) $domain->registrant_handle) === '') {
            // Sending the other roles sends the registrant too: unlinked, it would become a new owner at the registrar.
            throw new InvalidArgumentException(__('domains.manage.registrant_not_linked'));
        }

        $before = $domain->only(array_merge(...array_map(fn ($role) => ["{$role}_contact_id", "{$role}_handle"], Domain::ROLES)));
        $this->write($domain, 'contacts', $changed, $staff, function (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) use ($domain, $changed) {
            // Saved first (the model clears a changed role's handle), then sent: the registrar makes the new handles.
            $domain->forceFill($changed)->save();
            foreach (Domain::ROLES as $role) {
                $domain->unsetRelation("{$role}Contact");
            }

            return $registrar->updateDomain($domain, new DomainChanges(applyContacts: true), $credentials);
        }, function () use ($domain, $before) {
            // Refused: the roles as they were (their handles too), not a half-change; audited as the change was (review
            // 6a). A handle the registrar made meanwhile stays there unused (an orphan customer, harmless).
            $domain->refresh()->forceFill($before)->save();
        });
    }

    /** The EPP code, for staff to give the customer: read from the registrar each time, never stored or logged. */
    public function eppCode(Domain $domain, ?Staff $staff): string
    {
        $code = null;
        $this->call($domain, 'epp_code', [], $staff, function (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) use ($domain, &$code) {
            $code = $registrar->getEppCode($domain, $credentials);
            if ($code === null) {
                throw new RuntimeException(__('domains.manage.no_epp_code'));
            }

            return ['epp_code' => 'read']; // the log says it was read, never what it is
        });

        return (string) $code;
    }

    /** What the registrar holds for a role's handle (name, email, address…), read when staff ask. */
    public function registrarContact(Domain $domain, string $role, ?Staff $staff): ?RegistrarContact
    {
        $handle = trim((string) $domain->{"{$role}_handle"});
        if (!in_array($role, Domain::ROLES, true) || $handle === '') {
            return null;
        }
        $contact = null;
        $this->call($domain, 'contact', ['role' => $role, 'handle' => $handle], $staff,
            function (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) use ($handle, &$contact) {
                $contact = $registrar->getContact($handle, $credentials);

                return ['found' => $contact !== null];
            });

        return $contact;
    }

    /**
     * A write: the registrar's answer is applied here (audited).
     *
     * @param  array<string, mixed>  $request
     * @param  Closure(DomainRegistrar, DomainRegistrarCredentials): DomainSyncResult  $send
     * @param  (Closure(): void)|null  $undo  what to put back if the registrar refused
     */
    private function write(Domain $domain, string $action, array $request, ?Staff $staff, Closure $send, ?Closure $undo = null): void
    {
        $this->call($domain, $action, $request, $staff, function (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) use ($domain, $send, $undo) {
            try {
                $result = $send($registrar, $credentials);
            } catch (ConnectionException $e) {
                // No answer: it may have landed (review 6b). Nothing undone; the registrar is read again instead.
                try {
                    $this->sync->sync($domain->refresh(), true, false); // this call holds the lock
                } catch (Throwable) {
                }

                throw $e;
            } catch (Throwable $e) {
                if ($undo !== null) {
                    $undo();
                }

                throw $e;
            }
            $changes = $this->sync->applyResult($domain->refresh(), $result);
            $this->lifecycle->advance($domain->refresh()); // as «Sync now» (review 9)

            return ['changed' => array_keys($changes), 'status' => $result->rawStatus, 'nameservers' => $result->nameservers,
                'transfer_lock' => $result->transferLock, 'whois_privacy' => $result->whoisPrivacy];
        });
    }

    /**
     * One registrar call under the domain's lock, logged whatever its outcome.
     *
     * @param  array<string, mixed>  $request
     * @param  Closure(DomainRegistrar, DomainRegistrarCredentials): array<string, mixed>  $call  the response to log
     */
    private function call(Domain $domain, string $action, array $request, ?Staff $staff, Closure $call): void
    {
        $connection = $domain->effectiveRegistrarConnection();
        if (!$this->isManageable($domain) || !$connection instanceof DomainRegistrarConnection) {
            throw new DomainRegistrarNotConfigured(__('domains.manage.not_manageable', ['domain' => $domain->fqdn]));
        }

        // Longer than the longest call (review 5: contacts make up to 7 requests of 30s, each retried once on a 401), and
        // PHP allowed as long, so the lock never lapses mid-call and `finally` always runs.
        set_time_limit(self::LOCK_SECONDS);
        $lock = Cache::lock("domain-registrar:{$domain->id}", self::LOCK_SECONDS);
        if (!$lock->get()) {
            throw new RuntimeException(__('domains.manage.busy', ['domain' => $domain->fqdn]));
        }
        $log = ['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id, 'staff_id' => $staff?->id,
            'action' => $action, 'request' => Redact::array($request)];
        try {
            $response = $call($this->factory->for($connection), $this->factory->credentialsFor($connection));
            DomainRegistrarLog::query()->create($log + ['status' => DomainRegistrarLog::STATUS_OK, 'response' => Redact::array($response)]);
        } catch (Throwable $e) {
            DomainRegistrarLog::query()->create($log + ['status' => DomainRegistrarLog::STATUS_FAILED,
                'error' => mb_substr(Redact::text($e->getMessage()), 0, 2000)]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
