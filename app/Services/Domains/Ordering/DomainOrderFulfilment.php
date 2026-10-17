<?php

namespace App\Services\Domains\Ordering;

use App\Enums\DomainStatus;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\RegistrarRefused;
use App\Support\Redact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A domain ordered (D8b-2, docs/domains.md §4): once its line is paid (or it is free), registered or its transfer started
 * at its TLD's registrar, once (D-17): under the domain's lock, logged «pending» before the call, never asked again while
 * an answer is not known. A registration gets the company's nameservers, a transfer keeps those the registry has (owner
 * 2026-10-17), else the company's. A refusal (4xx) or no answer goes to staff (Pending Module Actions, D-15); the
 * customer's payment never fails for it.
 *
 * Sent, it is marked (meta.sent_at) and its line applied; then synced: a registration is Active with its expiry, a
 * transfer stays pending until the registrar completes it (the 4-hourly sync moves it).
 */
class DomainOrderFulfilment
{
    public const SENT = 'sent';

    public const WAITING = 'waiting';      // not paid yet

    public const DONE = 'done';            // sent before, or no longer pending

    public const STAFF = 'staff';          // refused, no answer, no API, or busy: Pending Module Actions

    public const BUSY = 'busy';

    public function __construct(
        private readonly DomainRegistrarFactory $registrars,
        private readonly DomainSyncService $sync,
        private readonly DomainLookup $lookup,
    ) {}

    /** The line that pays it (register or transfer), if any (a free transfer has none). */
    public static function lineOf(Domain $domain): ?InvoiceItem
    {
        return $domain->invoiceItems()->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER])
            ->whereHas('invoice', fn ($i) => $i->where('status', '!=', Invoice::STATUS_CANCELLED))->latest('id')->first();
    }

    /**
     * The ordered domains to send now: pending, never sent, their line paid and not applied, or free (no line); not one
     * already with staff (an open Pending Module Action: refused, or no answer), else the cron would ask again each run.
     */
    public static function due(): Builder
    {
        $own = fn ($line) => $line->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER]);

        return Domain::query()->whereIn('status', [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value])
            ->whereNotNull('order_id')->where(fn ($q) => $q->whereNull('meta')->orWhereNull('meta->sent_at'))
            ->where(fn ($q) => $q->whereHas('invoiceItems', fn ($line) => $own($line)->whereNull('applied_at')
                ->whereHas('invoice', fn ($i) => $i->where('status', Invoice::STATUS_PAID)))
                ->orWhereDoesntHave('invoiceItems', $own))
            ->whereNotExists(fn ($q) => $q->from('module_actions')->whereColumn('module_actions.domain_id', 'domains.id')
                ->where('module_actions.status', ModuleAction::STATUS_OPEN)
                ->whereIn('module_actions.action', [ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER]));
    }

    public function fulfil(int $domainId): string
    {
        $lock = Cache::lock("domain-registrar:{$domainId}", 600);
        if (!$lock->get()) {
            return self::BUSY;
        }
        try {
            return $this->fulfilLocked($domainId);
        } finally {
            $lock->release();
        }
    }

    private function fulfilLocked(int $domainId): string
    {
        $domain = Domain::query()->with(['nameservers', 'domainTld.registrarConnection', 'registrarConnection', 'order'])->find($domainId);
        if ($domain === null || !in_array($domain->status, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true)
            || !empty($domain->meta['sent_at']) || $domain->order_id === null) {
            return self::DONE;
        }
        $line = self::lineOf($domain);
        if ($line !== null && $line->invoice?->status !== Invoice::STATUS_PAID) {
            return self::WAITING;
        }
        $register = $domain->status === DomainStatus::PendingRegister;
        $action = $register ? ModuleAction::DOMAIN_REGISTER : ModuleAction::DOMAIN_TRANSFER;
        // Never twice (D-17): a charge with no answer yet waits for staff to check the registrar.
        if (DomainRegistrarLog::query()->where('domain_id', $domain->id)->whereIn('action', ['register', 'transfer'])
            ->where('status', DomainRegistrarLog::STATUS_PENDING)->exists()) {
            ModuleAction::domainFailed($domain, $line, null, new RuntimeException(__('domains.order.unconfirmed', ['domain' => $domain->fqdn])), $action);

            return self::STAFF;
        }
        $connection = $domain->effectiveRegistrarConnection();
        if (!$connection instanceof DomainRegistrarConnection || !$connection->isUsable()) {
            ModuleAction::domainFailed($domain, $line, $connection?->registrar, new RuntimeException(__('domains.order.by_hand', ['domain' => $domain->fqdn])), $action);

            return self::STAFF;
        }
        // At least two nameservers (D-16), else staff set them first (no defaults configured, the registry's unknown).
        if (!$this->nameservers($domain, $register)) {
            ModuleAction::domainFailed($domain, $line, $connection->registrar, new RuntimeException(__('domains.order.no_nameservers', ['domain' => $domain->fqdn])), $action);

            return self::STAFF;
        }

        $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id,
            'action' => $register ? 'register' : 'transfer', 'status' => DomainRegistrarLog::STATUS_PENDING, 'invoice_id' => $line?->invoice_id,
            'request' => ['years' => $domain->years, 'nameservers' => $domain->nameservers->pluck('host')->all(), 'line' => $line?->id]]);
        try {
            $registrar = $this->registrars->for($connection);
            $credentials = $this->registrars->credentialsFor($connection);
            $result = $register ? $registrar->register($domain, max(1, (int) $domain->years), $credentials)
                : $registrar->transferIn($domain, (string) $domain->epp_code, $credentials);
        } catch (RegistrarRefused $e) {
            // Refused (4xx): nothing done at the registrar. Staff decide (a wrong EPP code, a contact refused).
            $log->update(['status' => DomainRegistrarLog::STATUS_FAILED, 'error' => mb_substr(Redact::text($e->getMessage()), 0, 2000)]);
            ModuleAction::domainFailed($domain, $line, $connection->registrar, $e, $action);

            return self::STAFF;
        } catch (Throwable $e) {
            // No answer (5xx, timeout): it may have been done. Pending, never sent again until staff check (D-17).
            $log->update(['error' => mb_substr(Redact::text($e->getMessage()), 0, 2000)]);
            report($e);
            ModuleAction::domainFailed($domain, $line, $connection->registrar, $e, $action);

            return self::STAFF;
        }

        DB::transaction(function () use ($domain, $line, $log, $result, $action) {
            $log->update(['status' => DomainRegistrarLog::STATUS_OK, 'response' => ['status' => $result->rawStatus, 'id' => $result->registrarDomainId]]);
            $meta = $domain->meta ?? [];
            $meta['sent_at'] = now()->toIso8601String();
            $domain->forceFill(['meta' => $meta, 'registrar_domain_id' => $result->registrarDomainId ?? $domain->registrar_domain_id])->save();
            $line?->forceFill(['applied_at' => now()])->saveQuietly();
            ModuleAction::domainSucceeded($domain, $line, $action);
        });
        // Its registrar's truth now (a registration: Active with its expiry); a sync that fails is retried every 4 hours.
        try {
            $this->sync->sync($domain->refresh(), lock: false); // under our lock already
        } catch (Throwable $e) {
            report($e);
        }
        $domain->order?->refreshStatus();
        $this->notify($domain->refresh(), $register);

        return self::SENT;
    }

    /** The customer's mail: registered (with its expiry, once synced), or its transfer started. */
    private function notify(Domain $domain, bool $register): void
    {
        try {
            NotificationHelper::domainNotification($domain->client, $domain, $register ? 'domain_registered' : 'domain_transfer_started', [
                'fqdn' => $domain->fqdn,
                'years' => $domain->years,
                'expires' => $domain->expires_at?->format('d/m/Y'),
                'nameservers' => $domain->nameservers()->pluck('host')->implode(', '),
            ]);
        } catch (Throwable $e) {
            report($e); // sent at the registrar: a mail that fails is not a failed order
        }
    }

    /**
     * Its nameservers before the call (D-16: always sent): a registration the company's (domain_default_nameservers); a
     * transfer those the registry has now (owner 2026-10-17: it keeps working while it moves), else the company's.
     */
    private function nameservers(Domain $domain, bool $register): bool
    {
        if ($domain->nameservers->count() >= 2) {
            return true;
        }
        $hosts = [];
        if (!$register) {
            $record = $this->lookup->whois($domain->fqdn)['record'] ?? null;
            $hosts = array_values(array_filter((array) ($record->nameservers ?? []), 'is_string'));
        }
        $hosts = array_values(array_filter(array_unique(array_map('mb_strtolower', $hosts)),
            fn ($h) => preg_match(DomainManagementService::HOST, $h) === 1));
        if (count($hosts) < 2) {
            $hosts = self::defaultNameservers();
        }
        if (count($hosts) < 2) {
            return false;
        }
        $domain->nameservers()->delete();
        foreach (array_slice($hosts, 0, 13) as $i => $host) {
            $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
        }
        $domain->load('nameservers');

        return true;
    }

    /** @return list<string> the company's nameservers for new domains (domain_default_nameservers, one per line) */
    public static function defaultNameservers(): array
    {
        $hosts = preg_split('/[\s,]+/', trim((string) config('settings.domain_default_nameservers', ''))) ?: [];

        return array_values(array_unique(array_filter(array_map('mb_strtolower', $hosts), fn ($h) => preg_match(DomainManagementService::HOST, $h) === 1)));
    }

    /** The domains a paid invoice orders (ProcessPaidInvoiceService): sent after commit, one job each. */
    public static function forInvoice(Invoice $invoice): array
    {
        return $invoice->items()->where('reference_type', Domain::class)->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER])
            ->whereNull('applied_at')->pluck('reference_id')->map(fn ($id) => (int) $id)->all();
    }
}
