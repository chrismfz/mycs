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
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarRefused;
use App\Support\Domains\RegistrarTransactions;
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
            ->where(fn ($q) => $q->whereNull('meta')->orWhereNull('meta->on_hold'))
            ->where(fn ($q) => $q->whereHas('invoiceItems', fn ($line) => $own($line)->whereNull('applied_at')
                ->whereHas('invoice', fn ($i) => $i->where('status', Invoice::STATUS_PAID)))
                // Free only as recorded when it was ordered (a free transfer, D-6), never from a line gone (code review 1).
                ->orWhere(fn ($free) => $free->where('status', DomainStatus::PendingTransfer->value)->where('meta->free_order', true)
                    ->whereDoesntHave('invoiceItems', $own)))
            ->whereNotExists(fn ($q) => $q->from('module_actions')->whereColumn('module_actions.domain_id', 'domains.id')
                ->where('module_actions.status', ModuleAction::STATUS_OPEN)
                ->whereIn('module_actions.action', [ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER]));
    }

    /**
     * Paid: its own line on a paid invoice, or a free transfer as recorded when ordered (meta.free_order) with no line.
     * A line removed, or only on a cancelled invoice, is not «free» (code review 1).
     */
    public static function paid(Domain $domain, ?InvoiceItem $line): bool
    {
        if ($line !== null) {
            return $line->invoice?->status === Invoice::STATUS_PAID;
        }

        return $domain->status === DomainStatus::PendingTransfer && ($domain->meta['free_order'] ?? false) === true
            && !$domain->invoiceItems()->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER])->exists();
    }

    /** Still to send: ordered, pending, never sent, not put on hold by staff (Close). */
    private static function open(?Domain $domain): bool
    {
        return $domain !== null && $domain->order_id !== null
            && in_array($domain->status, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true)
            && empty($domain->meta['sent_at']) && empty($domain->meta['on_hold']);
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
        if (!self::open($domain)) {
            return self::DONE;
        }
        $line = self::lineOf($domain);
        if (!self::paid($domain, $line)) {
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
        // Its nameservers (D-16), else staff set them first: no defaults configured, or a transfer's not readable. A
        // registrar whose transfer keeps the nameservers the name has (the .gr registry sends none: G4b of grepp) needs
        // none for a transfer.
        $keeps = !$register && (function () use ($connection): bool {
            try {
                return $this->registrars->for($connection)->capabilities()->transferKeepsNameservers;
            } catch (Throwable) {
                return false;
            }
        })();
        if (!$keeps && ($problem = $this->nameservers($domain, $register)) !== null) {
            ModuleAction::domainFailed($domain, $line, $connection->registrar, new RuntimeException(__("domains.order.{$problem}", ['domain' => $domain->fqdn])), $action);

            return self::STAFF;
        }

        // The registrar's client before the log (code review 7): a local failure here charged nothing, so no pending log.
        try {
            $registrar = $this->registrars->for($connection);
            $credentials = $this->registrars->credentialsFor($connection);
        } catch (Throwable $e) {
            report($e);
            ModuleAction::domainFailed($domain, $line, $connection->registrar, $e, $action);

            return self::STAFF;
        }
        // Again after the slow lookups (RDAP): cancelled, refunded or sent meanwhile is not sent (code review 2).
        $domain->refresh();
        $line = self::lineOf($domain);
        if (!self::open($domain)) {
            return self::DONE;
        }
        if (!self::paid($domain, $line)) {
            return self::WAITING;
        }

        $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id,
            'action' => $register ? 'register' : 'transfer', 'status' => DomainRegistrarLog::STATUS_PENDING, 'invoice_id' => $line?->invoice_id,
            'request' => ['years' => $domain->years, 'nameservers' => $domain->nameservers->pluck('host')->all(), 'line' => $line?->id]]);
        $transactions = app(RegistrarTransactions::class);
        $mark = $transactions->mark();
        try {
            $result = $register ? $registrar->register($domain, max(1, (int) $domain->years), $credentials)
                : $registrar->transferIn($domain, (string) $domain->epp_code, $credentials);
        } catch (RegistrarRefused|RegistrarNotAsked|DomainRegistrarNotConfigured $e) {
            // Refused (4xx), or failed before it was asked (nameservers, glue, contacts: ADV-6): nothing done at the
            // registrar. Staff decide (a wrong EPP code, a contact refused, a nameserver to fix).
            $clean = $this->clean($domain, $e);
            $log->update(['status' => DomainRegistrarLog::STATUS_FAILED, 'error' => mb_substr($clean->getMessage(), 0, 2000), 'transactions' => $transactions->since($mark)]);
            ModuleAction::domainFailed($domain, $line, $connection->registrar, $clean, $action);

            return self::STAFF;
        } catch (Throwable $e) {
            // No answer (5xx, timeout): it may have been done. Pending, never sent again until staff check (D-17).
            $clean = $this->clean($domain, $e);
            $log->update(['error' => mb_substr($clean->getMessage(), 0, 2000), 'transactions' => $transactions->since($mark)]);
            report($clean);
            ModuleAction::domainFailed($domain, $line, $connection->registrar, $clean, $action);

            return self::STAFF;
        }

        $done = $transactions->since($mark);
        $ordered = DB::transaction(function () use ($domain, $line, $log, $result, $action, $connection, $done, $register) {
            $log->update(['status' => DomainRegistrarLog::STATUS_OK, 'response' => ['status' => $result->rawStatus, 'id' => $result->registrarDomainId], 'transactions' => $done]);
            $fresh = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $meta = $fresh->meta ?? [];
            $meta['sent_at'] = now()->toIso8601String();
            // What its answer told (a .gr registration's protocol: its recall needs it), until a sync reads its truth.
            if ($result->details !== []) {
                $meta['registrar'] = $result->details + (is_array($meta['registrar'] ?? null) ? $meta['registrar'] : []);
            }
            // A transfer's notes (its contacts replaced or why not, its owner): kept apart, the sync after replaces
            // meta.registrar (review 2 of #198).
            $notes = array_intersect_key($result->details, array_flip(['transfer', 'contacts', 'registrant']));
            if (!$register && $notes !== []) {
                $meta['transfer'] = $notes + ['at' => now()->toIso8601String()];
            }
            // Cancelled or refunded while the registrar was asked (code review 2): done there all the same; staff decide.
            $line = $line?->fresh(['invoice']);
            $ordered = in_array($fresh->status, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true) && self::paid($fresh, $line);
            $fresh->forceFill(['meta' => $meta, 'registrar_domain_id' => $result->registrarDomainId ?? $fresh->registrar_domain_id])->save();
            if ($ordered) {
                $line?->forceFill(['applied_at' => now()])->saveQuietly();
                ModuleAction::domainSucceeded($fresh, $line, $action);
            } else {
                ModuleAction::domainFailed($fresh, $line, $connection->registrar, new RuntimeException(__('domains.order.sent_after_cancel', ['domain' => $fresh->fqdn])), $action);
            }

            return $ordered;
        });
        if (!$ordered) {
            return self::STAFF;
        }
        // Its registrar's truth now (a registration: Active with its expiry); a sync that fails is retried by the next batch (pending ones first).
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

    /** Its message without secrets, the domain's EPP code included (adversarial ADV-7: a refusal may repeat it). */
    private function clean(Domain $domain, Throwable $e): RuntimeException
    {
        $code = trim((string) $domain->epp_code);
        $message = Redact::text($e->getMessage());
        if ($code !== '') {
            $message = str_replace($code, Redact::MASK, $message);
        }

        return new RuntimeException($message);
    }

    /**
     * Its nameservers before the call (D-16: always sent), or the problem (a domains.order key) for staff:
     * - a registration: the company's (Default Nameserver 1 to 5, at least two), else «no_nameservers»;
     * - a transfer: those the registry has now (owner 2026-10-17: it keeps working while it moves), even one; never the
     *   company's in their place when the registry could not be read (adversarial ADV-4: its DNS would move to us), only
     *   when the registry shows none at all.
     */
    private function nameservers(Domain $domain, bool $register): ?string
    {
        if ($domain->nameservers->count() >= ($register ? 2 : 1)) {
            return null;
        }
        if ($register) {
            $hosts = self::defaultNameservers();
            if (count($hosts) < 2) {
                return 'no_nameservers';
            }
        } else {
            $answer = $this->lookup->whois($domain->fqdn);
            $record = $answer['status'] === 'found' ? $answer['record'] : null;
            if ($record === null) {
                return 'ns_unknown'; // not readable (RDAP down, port 43 text only, a TLD without either)
            }
            $hosts = array_values(array_filter(array_unique(array_map('mb_strtolower', array_filter((array) $record->nameservers, 'is_string'))),
                fn ($h) => preg_match(DomainManagementService::HOST, $h) === 1));
            if ($hosts === [] && (array) $record->nameservers !== []) {
                return 'ns_unknown'; // listed, none usable
            }
            if ($hosts === []) {
                $hosts = self::defaultNameservers(); // no delegation to keep
                if (count($hosts) < 2) {
                    return 'no_nameservers';
                }
            }
        }
        $domain->nameservers()->delete();
        foreach (array_slice($hosts, 0, 13) as $i => $host) {
            $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
        }
        $domain->load('nameservers');

        return null;
    }

    /** @return list<string> the company's nameservers for new domains (Settings → Domains: Default Nameserver 1 to 5) */
    public static function defaultNameservers(): array
    {
        $hosts = array_map(fn (int $n) => rtrim(trim((string) config("settings.domain_nameserver_{$n}", '')), '.'), range(1, 5));

        return array_values(array_unique(array_filter(array_map('mb_strtolower', $hosts), fn ($h) => preg_match(DomainManagementService::HOST, $h) === 1)));
    }
}
