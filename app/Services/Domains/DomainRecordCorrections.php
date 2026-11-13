<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainNameserver;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
        self::refuseSent($domain);
        // By the TLDs we know (co.uk before uk): a name under one of them with more labels is a subdomain, not a domain
        // (ADV-2 of #403). One we do not sell: its first label is the name, the rest its ending.
        $name = DomainName::parse($fqdn, $tlds);
        if ($name !== null && $name->tld !== null && $name->withTld() !== $fqdn) {
            throw new DisplayException(__('domains.correct.not_a_name', ['name' => $typed]));
        }
        [$sld, $ending] = $name !== null && $name->tld !== null ? [$name->sld, $name->tld] : explode('.', $fqdn, 2);
        $tld = DomainTld::query()->get()->first(fn (DomainTld $t) => DomainName::toAscii((string) $t->tld) === $ending);
        $before = ['fqdn' => $domain->fqdn, 'sld' => $domain->sld, 'tld' => $domain->tld, 'domain_tld_id' => $domain->domain_tld_id];
        // An ending we do not sell has no TLD of ours: never priced, staged or routed as the old one (ADV-1 of #403).
        $after = ['fqdn' => $fqdn, 'sld' => $sld, 'tld' => $ending, 'domain_tld_id' => $tld?->id];
        // Under the registrar's lock: never while a write to it is under way (ADV-4 of #403).
        $lock = cache()->lock("domain-registrar:{$domain->id}", 30);
        if (!$lock->get()) {
            throw new DisplayException(__('domains.manage.busy', ['domain' => $domain->fqdn]));
        }
        try {
            $domain->forceFill($after)->save(); // audited (its fields)
        } catch (UniqueConstraintViolationException) {
            throw new DisplayException(__('domains.order.already_here', ['domain' => $fqdn]));
        } finally {
            $lock->release();
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
        self::refuseSent($domain);
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

    /** Still with us: its registrar or its dates move it between these; staff may end it from any of them. */
    private const LIVE = [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption];

    /**
     * The statuses staff may set from where it is now (owner 2026-11-13, as WHMCS's select, kept to three): a live one
     * ended (cancelled here, or at another registrar now), and back only what was ended that way: one cancelled by staff
     * here, or transferred away. A cancelled order never registered, a recalled registration, one the import or «name
     * taken» closed are not brought back (ADV-848-1, 2: they exist at no registrar). The rest (expired, grace, redemption,
     * deleted, pending) are its registrar's and its dates', set by the sync and the daily cron, never by hand.
     *
     * @return list<DomainStatus>
     */
    public static function statusChoices(Domain $domain): array
    {
        return match (true) {
            in_array($domain->status, self::LIVE, true) => [DomainStatus::Cancelled, DomainStatus::TransferredAway],
            $domain->status === DomainStatus::TransferredAway,
            $domain->status === DomainStatus::Cancelled && is_array($domain->meta['cancelled_by_staff'] ?? null) => [DomainStatus::Active],
            default => [],
        };
    }

    /**
     * Its status set by staff, our record only (nothing sent to any registrar). Ended, auto-renew goes off and the sync
     * and billing stop; the daily cron cancels its open renewal invoices (cancelled: that night; transferred away: a day
     * later, D7), one with money on it left for staff. Back: its status and auto-renew as before it ended, then synced;
     * invoices cancelled meanwhile stay cancelled. Audited before → after.
     *
     * @return string|null what the sync said after it came back, or why it could not read it
     */
    public function setStatus(Domain $domain, DomainStatus $to, Staff $staff): ?string
    {
        $domain->refresh();
        if ($domain->status === $to) {
            return null;
        }
        if (!in_array($to, self::statusChoices($domain), true)) {
            throw new DisplayException(__('domains.correct.status_not_allowed', ['from' => $domain->status->getLabel(), 'to' => $to->getLabel()]));
        }
        $before = ['status' => $domain->status->value, 'auto_renew' => (bool) $domain->auto_renew];
        // Under the registrar's lock: never while a renewal or another write to it is under way (D-17).
        $lock = cache()->lock("domain-registrar:{$domain->id}", 30);
        if (!$lock->get()) {
            throw new DisplayException(__('domains.manage.busy', ['domain' => $domain->fqdn]));
        }
        try {
            $domain->refresh(); // as it is under the lock
            if (!in_array($to, self::statusChoices($domain), true)) {
                throw new DisplayException(__('domains.correct.status_not_allowed', ['from' => $domain->status->getLabel(), 'to' => $to->getLabel()]));
            }
            match ($to) {
                DomainStatus::TransferredAway => $this->manage->markTransferredAway($domain, $staff),
                DomainStatus::Cancelled => $this->endHere($domain, $staff),
                default => $this->resume($domain, $staff),
            };
        } finally {
            $lock->release();
        }
        $domain->refresh();
        $this->audit($domain, 'record_status', $before, ['status' => $domain->status->value, 'auto_renew' => (bool) $domain->auto_renew], $staff);
        if ($to !== DomainStatus::Active || !$this->sync->isSyncable($domain)) {
            return null;
        }
        try {
            $changes = $this->sync->sync($domain);

            return $changes === [] ? __('Nothing changed.') : implode(', ', array_keys($changes));
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 300); // its sync error shown on the page; back all the same
        }
    }

    /** Cancelled here: what it was, for «Active» to put back, and its open invoices then (as «Transferred away» keeps). */
    private function endHere(Domain $domain, Staff $staff): void
    {
        $open = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)
            ->whereHas('invoice', fn ($invoice) => $invoice->whereIn('status', Invoice::OPEN_STATUSES))->pluck('invoice_id')->unique()->values()->all();
        $meta = $domain->meta ?? [];
        $meta['cancelled_by_staff'] = array_filter(['at' => now()->toIso8601String(), 'staff_id' => $staff->id, 'from' => $domain->status->value,
            'auto_renew' => (bool) $domain->auto_renew, 'open_invoices' => $open ?: null], fn ($v) => $v !== null);
        $domain->forceFill(['status' => DomainStatus::Cancelled, 'auto_renew' => false, 'meta' => $meta])->save(); // audited
    }

    /**
     * Back as it was before it ended (staff's own note of it); a transferred away one marked by the sync, with no auto-renew
     * noted, renews only when staff turn it on. Never while another row holds its name (ADV-848-3): said, not a 500.
     */
    private function resume(Domain $domain, Staff $staff): void
    {
        $meta = $domain->meta ?? [];
        $key = $domain->status === DomainStatus::Cancelled ? 'cancelled_by_staff' : 'transferred_away';
        $was = is_array($meta[$key] ?? null) ? $meta[$key] : [];
        $from = DomainStatus::tryFrom((string) ($was['from'] ?? ''));
        unset($meta['cancelled_by_staff'], $meta['transferred_away']);
        $meta['resumed'] = ['at' => now()->toIso8601String(), 'staff_id' => $staff->id];
        try {
            $domain->forceFill(['status' => in_array($from, self::LIVE, true) ? $from : DomainStatus::Active,
                'auto_renew' => (bool) ($was['auto_renew'] ?? false), 'meta' => $meta])->save(); // audited
        } catch (UniqueConstraintViolationException) {
            $holder = DomainNameHold::holder($domain->fqdn);
            throw new DisplayException(__('domains.correct.name_held', ['domain' => $domain->fqdn, 'holder' => $holder === null ? '—' : '#' . $holder->id]));
        }
        // Where its dates put it now (a month later it is no longer «active»), for one no registrar API reads.
        app(DomainLifecycle::class)->advance($domain);
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

    /**
     * A registration or transfer sent and not done yet: the registrar has it under its name there; renamed or moved here,
     * the sync would not find it and fail the order (ADV-3 of #403).
     */
    private static function refuseSent(Domain $domain): void
    {
        if (in_array($domain->status, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true) && filled($domain->meta['sent_at'] ?? null)) {
            throw new DisplayException(__('domains.correct.sent', ['domain' => $domain->fqdn]));
        }
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
