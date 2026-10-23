<?php

namespace App\Services\Domains;

use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainNameserver;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Staff;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nixpal\DomainTools\DomainName;
use OwenIt\Auditing\Events\AuditCustom;
use Throwable;

/**
 * Staff correct a domain's own record when something changed outside MyCS (owner 2026-10-23): its name as the registry
 * writes it, the registrar it is at (moved by hand), its nameservers where no registrar API keeps them. Nothing is asked
 * of any registrar; each change is audited before → after on the domain.
 */
class DomainRecordCorrections
{
    public function __construct(private readonly DomainManagementService $manage, private readonly DomainSyncService $sync) {}

    /** Its name corrected (the registry's spelling, or a typo): our record only, a registrar never renames a domain. */
    public function rename(Domain $domain, string $typed, Staff $staff): void
    {
        try {
            $fqdn = Domain::normalise($typed);
        } catch (InvalidArgumentException) {
            throw new DisplayException(__('domains.correct.not_a_name', ['name' => $typed]));
        }
        $tlds = DomainTld::query()->pluck('tld')->map(fn ($tld) => (string) DomainName::toAscii((string) $tld))->filter()->all();
        if ($fqdn === '' || !DomainName::validDomain($fqdn) || !str_contains($fqdn, '.')) {
            throw new DisplayException(__('domains.correct.not_a_name', ['name' => $typed]));
        }
        if ($fqdn === $domain->fqdn) {
            return;
        }
        // By the TLDs we know (co.uk before uk); one we do not sell: its first label is the name, the rest its ending.
        $name = DomainName::parse($fqdn, $tlds);
        [$sld, $ending] = $name !== null && $name->tld !== null && $name->withTld() === $fqdn
            ? [$name->sld, $name->tld]
            : explode('.', $fqdn, 2);
        $tld = DomainTld::query()->get()->first(fn (DomainTld $t) => DomainName::toAscii((string) $t->tld) === $ending);
        $before = ['fqdn' => $domain->fqdn, 'sld' => $domain->sld, 'tld' => $domain->tld, 'domain_tld_id' => $domain->domain_tld_id];
        $after = ['fqdn' => $fqdn, 'sld' => $sld, 'tld' => $ending, 'domain_tld_id' => $tld->id ?? $domain->domain_tld_id];
        try {
            $domain->forceFill($after)->save(); // audited (its fields)
        } catch (UniqueConstraintViolationException) {
            throw new DisplayException(__('domains.order.already_here', ['domain' => $fqdn]));
        }
        $this->audit($domain, 'record_renamed', $before, $after, $staff);
    }

    /**
     * Where it is (moved to another registrar by hand, or kept by hand): another connection, or none of its own (its
     * TLD's). Then read from there, if it has an API: the sync says whether it is there.
     *
     * @return string|null what the sync said, or why it could not read it
     */
    public function moveToRegistrar(Domain $domain, ?int $connectionId, Staff $staff): ?string
    {
        $connection = $connectionId === null ? null : DomainRegistrarConnection::query()->find($connectionId);
        if ($connectionId !== null && $connection === null) {
            throw new DisplayException(__('domains.correct.no_connection'));
        }
        if ((int) $domain->registrar_connection_id === (int) $connectionId && ($connectionId !== null || $domain->registrar_connection_id === null)) {
            return null;
        }
        $before = ['registrar_connection_id' => $domain->registrar_connection_id, 'registrar' => $domain->effectiveRegistrarConnection()?->label];
        // Under the registrar's lock: never while a write to the old one is under way (D-17).
        $lock = cache()->lock("domain-registrar:{$domain->id}", 30);
        if (!$lock->get()) {
            throw new DisplayException(__('domains.manage.busy', ['domain' => $domain->fqdn]));
        }
        try {
            $domain->forceFill(['registrar_connection_id' => $connectionId])->save(); // audited
            $domain->unsetRelation('registrarConnection');
        } finally {
            $lock->release();
        }
        $this->audit($domain, 'record_registrar', $before, ['registrar_connection_id' => $connectionId,
            'registrar' => $domain->effectiveRegistrarConnection()?->label], $staff);
        if (!$this->sync->isSyncable($domain)) {
            return null;
        }
        try {
            $changes = $this->sync->sync($domain);

            return $changes === [] ? __('Nothing changed.') : implode(', ', array_keys($changes));
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 300);
        }
    }

    /** Its nameservers set here, where no registrar API keeps them (a domain kept by hand): ours only. */
    public function setNameserversByHand(Domain $domain, array $hosts, Staff $staff): void
    {
        if ($this->manage->isManageable($domain)) {
            throw new DisplayException(__('domains.correct.at_the_registrar'));
        }
        $typed = array_filter(array_map(fn ($host) => trim((string) $host), $hosts), fn (string $host) => $host !== '');
        $hosts = [];
        foreach ($typed as $host) {
            try {
                $hosts[] = $name = Domain::normalise($host);
            } catch (InvalidArgumentException) {
                $name = null;
            }
            if ($name === null || !DomainName::validDomain($name)) {
                throw new DisplayException(__('domains.manage.nameserver_invalid', ['host' => $host]));
            }
        }
        $hosts = array_values(array_unique($hosts));
        if (count($hosts) > 13) {
            throw new DisplayException(__('domains.manage.nameserver_count'));
        }
        $before = $domain->nameservers()->orderBy('sort')->pluck('host')->all();
        if ($before === $hosts) {
            return;
        }
        DB::transaction(function () use ($domain, $hosts) {
            $domain->nameservers()->delete();
            foreach ($hosts as $sort => $host) {
                DomainNameserver::query()->create(['domain_id' => $domain->id, 'host' => $host, 'sort' => $sort]);
            }
        });
        $this->audit($domain, 'record_nameservers', ['nameservers' => $before], ['nameservers' => $hosts], $staff);
    }

    /** @param  array<string, mixed>  $old @param  array<string, mixed>  $new */
    private function audit(Domain $domain, string $event, array $old, array $new, Staff $staff): void
    {
        $domain->auditEvent = $event;
        $domain->isCustomEvent = true;
        $domain->auditCustomOld = $old;
        $domain->auditCustomNew = $new + ['staff_id' => $staff->id];
        event(new AuditCustom($domain));
        $domain->isCustomEvent = false;
    }
}
