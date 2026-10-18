<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Contracts\EmailsAuthCode;
use App\Contracts\RecallsRegistration;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Staff;
use App\Models\User;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Redact;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public const HOST = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

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
    public function setNameservers(Domain $domain, array $hosts, Staff|User|null $staff): void
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

    public function setTransferLock(Domain $domain, bool $locked, Staff|User|null $staff): void
    {
        $this->write($domain, $locked ? 'lock' : 'unlock', ['transfer_lock' => $locked], $staff,
            fn (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) => $registrar->updateDomain($domain, new DomainChanges(transferLock: $locked), $credentials));
    }

    public function setWhoisPrivacy(Domain $domain, bool $enabled, Staff|User|null $staff): void
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
    public function setContacts(Domain $domain, array $contacts, Staff|User|null $staff): void
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
        // A role not changed that has a contact here but is not linked to the registrar's handle: sending it would replace
        // the registrar's with a new one nobody asked for. Linked first (D4c).
        foreach (Domain::ROLES as $role) {
            if (!array_key_exists("{$role}_contact_id", $changed) && $domain->{"{$role}_contact_id"} !== null && $this->isUnlinked($domain, $role)) {
                throw new InvalidArgumentException(__('domains.manage.role_not_linked', ['role' => __('domains.role.' . $role)]));
            }
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
    public function eppCode(Domain $domain, Staff|User|null $staff): string
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

    /**
     * The registry emails the registrant a link to reset the EPP code (DACoR: a registrar with authCodeByEmail, the .gr
     * registry). Free; logged with who asked; returns the registry's words, the address masked.
     */
    public function emailAuthCode(Domain $domain, Staff|User|null $staff): string
    {
        $message = '';
        $this->call($domain, 'auth_code_email', [], $staff, function (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) use ($domain, &$message) {
            if (!$registrar instanceof EmailsAuthCode) {
                throw new DomainRegistrarNotConfigured(__('domains.manage.no_auth_code_email'));
            }
            $message = $registrar->emailAuthCode($domain, $credentials);

            return ['emailed' => $message];
        });

        return $message;
    }

    /** Days a registration can be recalled (the .gr registry: 5, counted from its registration). */
    public const RECALL_DAYS = 5;

    /**
     * Staff recall a registration (within RECALL_DAYS of it, at a registrar that can: the .gr registry): the registry
     * cancels it; here the domain is cancelled. Its payment is staff's to refund (the order's Cancel & Refund). Logged.
     */
    public function recall(Domain $domain, Staff $staff): string
    {
        if (!$this->recallable($domain)) {
            throw new DisplayException(__('domains.manage.not_recallable', ['domain' => $domain->fqdn, 'days' => self::RECALL_DAYS]));
        }
        $said = '';
        $this->call($domain, 'recall', [], $staff, function (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) use ($domain, &$said) {
            if (!$registrar instanceof RecallsRegistration) {
                throw new DomainRegistrarNotConfigured(__('domains.manage.not_recallable', ['domain' => $domain->fqdn, 'days' => self::RECALL_DAYS]));
            }
            $said = $registrar->recall($domain, $credentials);

            return ['recalled' => $said];
        });
        $meta = $domain->meta ?? [];
        $meta['recalled_at'] = now()->toIso8601String();
        $domain->forceFill(['status' => DomainStatus::Cancelled, 'meta' => $meta])->save(); // audited

        return $said;
    }

    /** A registration recallable now: at a registrar that recalls, pending or active, registered within RECALL_DAYS. */
    public function recallable(Domain $domain): bool
    {
        $since = $domain->registered_at ?? $domain->created_at;
        if (!in_array($domain->status, [DomainStatus::PendingRegister, DomainStatus::Active], true) || $since === null
            || $since->copy()->startOfDay()->lt(today()->subDays(self::RECALL_DAYS))) {
            return false;
        }
        $connection = $domain->effectiveRegistrarConnection();
        try {
            return $connection instanceof DomainRegistrarConnection && $connection->isUsable()
                && $this->factory->for($connection)->capabilities()->supportsRecall;
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether its registrar emails the code instead of giving it (DACoR). */
    public function emailsAuthCode(Domain $domain): bool
    {
        $connection = $domain->effectiveRegistrarConnection();
        try {
            return $connection instanceof DomainRegistrarConnection && $connection->isUsable()
                && $this->factory->for($connection)->capabilities()->authCodeByEmail;
        } catch (Throwable) {
            return false;
        }
    }

    /** A role's handle: ours (linked), else the one the registrar reported at the last sync (not linked yet). */
    public function handleFor(Domain $domain, string $role): ?string
    {
        if (!in_array($role, Domain::ROLES, true)) {
            return null;
        }
        $ours = trim((string) $domain->{"{$role}_handle"});
        $theirs = $domain->meta['registrar_handles'][$role] ?? null;

        return $ours !== '' ? $ours : (is_string($theirs) && trim($theirs) !== '' ? trim($theirs) : null);
    }

    /** The registrar reports a handle for this role that is not linked to a contact here. */
    public function isUnlinked(Domain $domain, string $role): bool
    {
        return trim((string) $domain->{"{$role}_handle"}) === '' && $this->handleFor($domain, $role) !== null;
    }

    /** What the registrar holds for a role's handle (name, email, address…), read when staff ask; linked or not. */
    public function registrarContact(Domain $domain, string $role, Staff|User|null $staff): ?RegistrarContact
    {
        $handle = $this->handleFor($domain, $role);
        if ($handle === null) {
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
     * A role's registrar handle linked here by staff (D4c): the registrar is not written, WHOIS stays as it is. To an
     * existing contact of the account (staff say it is the same person, whatever its email), or to a new contact made
     * from the registrar's record (an end customer registered by an agency account). Audited and logged.
     */
    public function linkContact(Domain $domain, string $role, ?int $contactId, Staff|User|null $staff): Contact
    {
        if (!$this->isUnlinked($domain, $role)) {
            throw new InvalidArgumentException(__('domains.manage.nothing_to_link'));
        }
        // The account's own, and not archived: a contact holding a role is never archived (Contact::archive, adversarial A4).
        if ($contactId !== null && !Contact::query()->whereKey($contactId)->where('client_id', $domain->client_id)->whereNull('archived_at')->exists()) {
            throw new InvalidArgumentException(__('domains.manage.contact_not_the_accounts'));
        }
        $handle = (string) $this->handleFor($domain, $role);
        $linked = null;
        $this->call($domain, 'link_contact', ['role' => $role, 'handle' => $handle, 'contact_id' => $contactId ?? 'new'], $staff,
            function (DomainRegistrar $registrar, DomainRegistrarCredentials $credentials) use ($domain, $role, $handle, &$contactId, &$linked) {
                // Under the lock, from what is true now (adversarial A1, A2): the role still unlinked here, and the handle
                // still the registrar's for it; a stale one would later be sent back to the registrar as this role's.
                $domain->refresh();
                if (trim((string) $domain->{"{$role}_handle"}) !== '') {
                    throw new InvalidArgumentException(__('domains.manage.nothing_to_link'));
                }
                $now = $registrar->syncDomain($domain, $credentials)->contactHandles;
                if (($now[$role] ?? null) !== $handle) {
                    throw new InvalidArgumentException(__('domains.manage.handle_changed', ['handle' => $handle]));
                }
                // With it, every role the registrar gives the same handle that has no contact here (review 3); a role with
                // a contact keeps it: linking the admin never replaces the registrant's (adversarial A3).
                $roles = array_values(array_filter(Domain::ROLES, fn (string $r) => $r === $role || (($now[$r] ?? null) === $handle
                    && trim((string) $domain->{"{$r}_handle"}) === '' && $domain->{"{$r}_contact_id"} === null)));
                // A handle this domain already linked to a contact is that contact: never a second one (adversarial A5).
                foreach (Domain::ROLES as $r) {
                    if ($contactId === null && $domain->{"{$r}_handle"} === $handle && $domain->{"{$r}_contact_id"} !== null) {
                        $contactId = (int) $domain->{"{$r}_contact_id"};
                    }
                }
                $record = $contactId === null ? ($registrar->getContact($handle, $credentials)
                    ?? throw new RuntimeException(__('domains.manage.not_at_the_registrar', ['handle' => $handle]))) : null;
                // One transaction: a failed save leaves no new contact behind (review 1).
                DB::transaction(function () use ($domain, $roles, $handle, $contactId, $record, &$linked) {
                    $linked = $record !== null ? $this->contactFrom($domain, $record) : Contact::query()->findOrFail($contactId);
                    $meta = $domain->meta ?? [];
                    $fill = [];
                    foreach ($roles as $role) {
                        // Both at once: the model would clear a role's handle whose contact changes on its own.
                        $fill["{$role}_contact_id"] = $linked->id;
                        $fill["{$role}_handle"] = $handle;
                        // Linked by staff's word, not by a matching email: ensureHandles never lends it to another role of
                        // this contact (review 2).
                        $meta['linked_handles'][$role] = $handle;
                    }
                    $domain->forceFill($fill + ['meta' => $meta])->save(); // audited
                });
                // Done here whatever the registrar answers now: the re-read is best-effort (review 1), as after a write.
                $synced = true;
                try {
                    $this->sync->sync($domain->refresh(), true, false); // this call holds the lock
                    $this->lifecycle->advance($domain->refresh());
                } catch (Throwable) {
                    $synced = false;
                }

                return ['contact_id' => $linked?->id, 'created' => $contactId === null, 'synced' => $synced];
            });

        return $linked;
    }

    /** A contact of the domain's account from the registrar's record of a handle. */
    private function contactFrom(Domain $domain, RegistrarContact $record): Contact
    {
        $org = trim((string) $record->org);
        $name = trim($record->name);
        $person = $name !== '' && $name !== $org && $name !== $record->handle ? $name : '';
        [$first, $last] = $person !== '' ? array_pad(preg_split('/\s+/u', $person, 2) ?: [], 2, '') : ['', ''];

        if ($first === '' && $last === '' && $org === '') {
            $last = (string) $record->email; // a contact is never nameless (displayName)
        }
        $cut = fn (?string $value, int $length = 255) => $value === null || trim($value) === '' ? null : mb_substr(trim($value), 0, $length);
        $contact = new Contact;
        $contact->forceFill([ // client_id is not fillable; the registrar's text cut to the columns (adversarial A6)
            'client_id' => $domain->client_id,
            'type' => $org !== '' && $person === '' ? 'business' : 'individual',
            'first_name' => $cut($first),
            'last_name' => $cut($last),
            'company_name' => $cut($org),
            'email' => $cut($record->email),
            'phone' => $cut($record->phone),
            'address1' => $cut($record->address1),
            'address2' => $cut($record->address2),
            'city' => $cut($record->city),
            'postcode' => $cut($record->postcode),
            'country' => $record->country !== null && preg_match('/^[A-Za-z]{2}$/', trim($record->country)) === 1 ? strtoupper(trim($record->country)) : null,
        ])->save();

        return $contact;
    }

    /**
     * A write: the registrar's answer is applied here (audited).
     *
     * @param  array<string, mixed>  $request
     * @param  Closure(DomainRegistrar, DomainRegistrarCredentials): DomainSyncResult  $send
     * @param  (Closure(): void)|null  $undo  what to put back if the registrar refused
     */
    private function write(Domain $domain, string $action, array $request, Staff|User|null $staff, Closure $send, ?Closure $undo = null): void
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
    private function call(Domain $domain, string $action, array $request, Staff|User|null $staff, Closure $call): void
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
        $log = ['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id, 'staff_id' => $staff instanceof Staff ? $staff->id : null,
            // Who asked (D-22): staff, or the customer's login in the client area (D7-2).
            'user_id' => $staff instanceof User ? $staff->id : null,
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
