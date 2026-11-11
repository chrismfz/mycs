<?php

namespace App\Models;

use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Jobs\Server\UpgradeJob;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\RenewAsTransfer;
use App\Support\AfterCommit;
use App\Support\LogText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One action a server module was asked to do (Σ1, WHMCS's Module Log and Module Queue). A failure stays open, its
 * attempts counted on the same row, until the action succeeds (completed), an opposite one does or staff mark it
 * (resolved). The open ones are the Pending Module Actions.
 *
 * @property string $action
 * @property int|null $service_id
 * @property int|null $domain_id
 * @property int|null $invoice_item_id
 * @property string $status
 * @property array<string, bool>|null $job_options
 */
class ModuleAction extends Model
{
    public const STATUS_OPEN = 'open';

    /** The start of last_error of a server job its payment decided and the queue never took (AfterCommit::job, S4). */
    public const NOT_QUEUED = 'Not queued: ';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_RESOLVED = 'resolved';

    /** ExtensionHelper's server functions and the action each records. */
    public const ACTIONS = [
        'createServer' => 'create',
        'suspendServer' => 'suspend',
        'unsuspendServer' => 'unsuspend',
        'terminateServer' => 'terminate',
        'upgradeServer' => 'upgrade',
    ];

    /** A success makes these open failures moot: the account is where the newer action put it. */
    private const SUPERSEDES = [
        'create' => [],
        'suspend' => ['unsuspend'],
        'unsuspend' => ['suspend'],
        'terminate' => ['create', 'suspend', 'unsuspend', 'upgrade'],
        'upgrade' => [],
    ];

    /** A paid domain renewal at its registrar (D5b-2): the domain's row, with the paid line it is for. */
    public const DOMAIN_RENEW = 'domain_renew';

    /** A domain ordered (D8b-2): its registration or transfer at its registrar, refused or with no answer. */
    public const DOMAIN_REGISTER = 'domain_register';

    public const DOMAIN_TRANSFER = 'domain_transfer';

    /** The resolution of a renewal staff closed «not renewed»: its line is then charged only by their Retry. */
    public const CLOSED_UNRENEWED = 'closed without renewing';

    /** A domain's row for its transfer lock, to be put on again by hand (RenewAsTransfer::lockAtSource). */
    public const SUBJECT_RELOCK = 'relock';

    /** A server action on a machine with no API (OfflineServer): a task for staff, never run (no Retry). */
    public const SUBJECT_BY_HAND = 'by hand';

    /** A paid renewal done by hand (an offline module that renews so: OfflineSsl), never run by a module. */
    public const RENEW = 'renew';

    /** A newer task on the same machine makes these older ones moot (the machine goes where the newer one says). */
    public const BY_HAND_SUPERSEDES = [
        'create' => ['create'],
        'suspend' => ['suspend', 'unsuspend'],
        'unsuspend' => ['suspend', 'unsuspend'],
        'terminate' => ['create', 'suspend', 'unsuspend', 'upgrade', 'terminate', self::RENEW],
        'upgrade' => ['upgrade'],
        self::RENEW => [self::RENEW],
    ];

    /** The resolution of an order staff closed «stop it»: on hold, its unconfirmed request still for them to say. */
    public const CLOSED_ON_HOLD = 'closed, on hold';

    public const DOMAIN_ACTIONS = [self::DOMAIN_RENEW, self::DOMAIN_REGISTER, self::DOMAIN_TRANSFER];

    /**
     * A contact's details sent to the registrar of a domain that uses it (P1b-2): retried by sending them again; resolved
     * by hand when fixed at the registrar. Not a DOMAIN_ACTION: nothing paid, nothing put on hold.
     */
    public const DOMAIN_CONTACT = 'domain_contact';

    /**
     * «Check this» tasks a server's read opens (StrayAccounts, owner 2026-11-08): an account still on its server whose
     * service ended in MyCS, and a running one no service has. One open per server and account (subject «server:remote»),
     * closed on its own once a read no longer finds it; never anything run on the server (no Retry).
     */
    public const STRAY_ENDED = 'stray_ended';

    public const STRAY_UNMAPPED = 'stray_unmapped';

    public const STRAY = [self::STRAY_ENDED, self::STRAY_UNMAPPED];

    /** A registrar sync's warning, what it did not apply (DomainSyncService::plan's «_warning»): the same kind of task. */
    public const DOMAIN_CHECK = 'domain_check';

    /**
     * A registered domain whose order staff cancelled with a refund (CancelOrder, sweep 8 S8-DOMAINS-1): the refund does
     * not undo the registration, the domain keeps its status. Staff decide by hand; closed only by them («Checked»).
     */
    public const DOMAIN_REFUNDED = 'domain_refunded';

    /** The tasks that only ask staff to look: nothing to retry, closed by staff («Checked») or by a later read. */
    public const CHECKS = [self::STRAY_ENDED, self::STRAY_UNMAPPED, self::DOMAIN_CHECK, self::DOMAIN_REFUNDED];

    /** The resolution of a row staff closed by hand («Mark resolved»). */
    public const RESOLVED_BY_STAFF = 'marked resolved by staff';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'given_up_at' => 'datetime',
            'resolved_at' => 'datetime',
            'attempts' => 'integer',
            'job_options' => 'array',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return BelongsTo<InvoiceItem, $this> */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    /**
     * A domain's renewal, registration or transfer failed, or waits for staff (D-15): one open row per domain, line (none
     * for a free transfer) and action, its attempts counted.
     */
    public static function domainFailed(Domain $domain, ?InvoiceItem $line, ?string $module, Throwable $error, string $action = self::DOMAIN_RENEW, ?string $subject = null): self
    {
        $row = self::query()->open()->where('domain_id', $domain->id)->where('invoice_item_id', $line?->id)->where('action', $action)
            ->where('subject', $subject)->latest('id')->first()
            ?? new self(['domain_id' => $domain->id, 'invoice_item_id' => $line?->id, 'action' => $action, 'subject' => $subject, 'status' => self::STATUS_OPEN]);
        $new = !$row->exists;
        $row->fill(['module' => $module, 'attempts' => $row->attempts + 1, 'last_attempt_at' => now(),
            'last_error' => Str::limit((string) LogText::of($error->getMessage()), 2000)])->save();
        // Staff told once, when it opens (a registration, transfer or renewal: WHMCS's «Domain Renewal Failed», mail
        // check of 2026-10-21), never on each retry; after it commits, so a rolled-back one tells no one.
        if ($new && in_array($action, self::DOMAIN_ACTIONS, true)) {
            DB::afterCommit(fn () => $row->tellStaff($domain));
        }

        return $row;
    }

    /** The mail to staff of a domain's registration, transfer or renewal that waits for them. */
    private function tellStaff(Domain $domain): void
    {
        try {
            $what = match ($this->action) {
                self::DOMAIN_REGISTER => 'registration',
                self::DOMAIN_TRANSFER => 'transfer',
                default => 'renewal',
            };
            $url = DomainResource::getUrl('view', ['record' => $domain->id], panel: 'admin');
            NotificationHelper::sendSystemEmailNotification("Domain {$what} needs staff: {$domain->fqdn}", e("The {$what} of {$domain->fqdn} waits for staff: "
                . $this->last_error) . '<br><br>' . e('It is listed in Pending Module Actions: retry it there once the cause is fixed, or close it.')
                . '<br><a href="' . e($url) . '">' . e($domain->fqdn) . '</a>');
        } catch (Throwable $e) {
            report($e); // the row is what counts: a mail that fails is not a lost failure
        }
    }

    /** Done (renewed, registered, transferred, adopted, or by hand): its open row, if any, completed. */
    public static function domainSucceeded(Domain $domain, ?InvoiceItem $line, string $action = self::DOMAIN_RENEW, ?string $subject = null): void
    {
        $now = now();
        self::query()->open()->where('domain_id', $domain->id)->where('invoice_item_id', $line?->id)->where('action', $action)->where('subject', $subject)
            ->update(['status' => self::STATUS_COMPLETED, 'resolved_at' => $now, 'resolution' => 'succeeded', 'updated_at' => $now]);
    }

    /**
     * Staff close a domain's renewal without it (refunded, ended, or checked at the registrar: the unconfirmed charge was
     * not done). Its line's unconfirmed charges are settled as not done (D5b-2 review 6), and the line is never charged
     * again on its own, only by staff's Retry (sweep R5/S3). An order's row is put on hold, its unconfirmed request
     * left as it is: «Not done there» says it was not done (sweep S2). Under the domain's registrar lock: never over a
     * charge being asked (sweep S4).
     *
     * @throws RuntimeException busy: a change to the domain is under way
     */
    public function closeUnrenewed(?Staff $staff): void
    {
        if (!in_array($this->action, self::DOMAIN_ACTIONS, true) || $this->status !== self::STATUS_OPEN) {
            return;
        }
        $this->underDomainLock(function () use ($staff) {
            if ($this->refresh()->status !== self::STATUS_OPEN) {
                return;
            }
            // An order closed is not sent again on its own (code review 3): on hold until staff cancel or refund its order,
            // or mark it «Not done there» first to retry.
            if ($this->action !== self::DOMAIN_RENEW && ($domain = Domain::query()->find($this->domain_id)) !== null) {
                $domain->forceFill(['meta' => ['on_hold' => now()->toIso8601String()] + ($domain->meta ?? [])])->save();
            }
            if ($this->action === self::DOMAIN_RENEW && $this->invoice_item_id !== null) {
                // A transfer that replaced the renewal, with no clear answer: left unlocked while it might be under way. Not
                // done, it is locked again where we unlocked it (adversarial ADV-4 of #307).
                $transfer = DomainRegistrarLog::query()->where('domain_id', $this->domain_id)->where('status', DomainRegistrarLog::STATUS_PENDING)
                    ->where('action', 'transfer')->where('request->renewal', true)->where('request->line', $this->invoice_item_id)->exists();
                self::settleRenewalCharges((int) $this->domain_id, (int) $this->invoice_item_id, DomainRegistrarLog::STATUS_FAILED,
                    'Checked by staff at the registrar: not done.', $staff);
                $domain = Domain::query()->find($this->domain_id);
                if ($transfer && $domain !== null && isset($domain->meta['unlocked_by_us'])) {
                    RenewAsTransfer::lockAtSource($domain, 'staff closed its transfer: not done');
                }
            }
            $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(),
                'resolution' => $this->action === self::DOMAIN_RENEW ? self::CLOSED_UNRENEWED : self::CLOSED_ON_HOLD, 'resolved_by_staff_id' => $staff?->id])->save();
        });
    }

    /**
     * A renewal's unconfirmed charges settled by staff: its own line's (sweep S1), and any whose line no longer waits
     * (refunded, applied) and that nothing else would ever settle: it would block every later renewal of the domain for
     * good (adversarial ADV-1/ADV-2 of #306). Another line still waiting keeps its own: its row says it. A renew or
     * restore, or a transfer that replaced the renewal (RenewAsTransfer, code review 2 of #303).
     */
    public static function settleRenewalCharges(int $domainId, int $lineId, string $status, string $why, ?Staff $staff): void
    {
        $waiting = DomainRenewalService::waitingLines()->where('reference_id', $domainId)->whereKeyNot($lineId)->pluck('id')->all();
        DomainRegistrarLog::query()->where('domain_id', $domainId)->where('status', DomainRegistrarLog::STATUS_PENDING)
            ->where(fn ($q) => $q->whereIn('action', ['renew', 'restore'])->orWhere(fn ($t) => $t->where('action', 'transfer')->where('request->renewal', true)))
            ->get()->filter(fn (DomainRegistrarLog $log) => !in_array((int) ($log->request['line'] ?? 0), $waiting, true))
            ->each(fn (DomainRegistrarLog $log) => $log->update(['status' => $status, 'error' => $why, 'staff_id' => $staff?->id]));
    }

    /** An order's row closed «stop it» (on hold). */
    public function onHold(): bool
    {
        return $this->status === self::STATUS_RESOLVED && $this->resolution === self::CLOSED_ON_HOLD
            && in_array($this->action, [self::DOMAIN_REGISTER, self::DOMAIN_TRANSFER], true);
    }

    /** Run under the domain's registrar lock, or throw «busy» (a charge or change under way). */
    private function underDomainLock(callable $callback): void
    {
        $lock = Cache::lock("domain-registrar:{$this->domain_id}", 600);
        if (!$lock->get()) {
            throw new RuntimeException(__('domains.manage.busy', ['domain' => (string) Domain::query()->whereKey($this->domain_id)->value('fqdn')]));
        }
        try {
            $callback();
        } finally {
            $lock->release();
        }
    }

    /** @return BelongsTo<Staff, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'resolved_by_staff_id');
    }

    /**
     * @param  Builder<ModuleAction>  $query
     * @return Builder<ModuleAction>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Waiting on staff: given up after its retries, or a domain's action (a paid renewal, D5b-2: never given up by itself,
     * always theirs). The dashboard's «Needs attention» and the list's «Needs staff» filter count the same.
     *
     * @param  Builder<ModuleAction>  $query
     * @return Builder<ModuleAction>
     */
    public function scopeNeedsStaff(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)->failures()
            ->where(fn (Builder $query) => $query->whereNotNull('given_up_at')->orWhereIn('action', self::DOMAIN_ACTIONS));
    }

    /** A «check this» task (CHECKS): not a failure (owner 2026-11-08), see scopeFailures. */
    public function isCheck(): bool
    {
        return in_array($this->action, self::CHECKS, true);
    }

    /**
     * Not a «check this» task (CHECKS): those are not failures (owner 2026-11-08, after review). They make no Health
     * warning, no failed day in the Cron calendar or the summary email, and are counted on their own («N accounts to
     * check»).
     *
     * @param  Builder<ModuleAction>  $query
     * @return Builder<ModuleAction>
     */
    public function scopeFailures(Builder $query): Builder
    {
        return $query->whereNotIn('action', self::CHECKS);
    }

    /**
     * The open «check this» tasks, by kind: accounts (STRAY) and domains (DOMAIN_CHECK, DOMAIN_REFUNDED).
     *
     * @return array{accounts: int, domains: int}
     */
    public static function openChecks(): array
    {
        $counts = self::query()->open()->whereIn('action', self::CHECKS)->selectRaw('action, COUNT(*) as total')->groupBy('action')
            ->pluck('total', 'action')->map(fn ($total) => (int) $total);

        return ['accounts' => (int) $counts->only(self::STRAY)->sum(), 'domains' => (int) $counts->only([self::DOMAIN_CHECK, self::DOMAIN_REFUNDED])->sum()];
    }

    /**
     * An open task its service's delete would take with it (module_actions.service_id ON DELETE CASCADE): a termination
     * not done yet on the server (a failure, or a step left to staff), any task for staff by hand, or a read's «ended in
     * MyCS, still on the server» check (its account, once unmapped, may not be running and would open nothing again:
     * REV-STRAY-1). The service is kept until staff close it (sweep3 L-DATA-INTEGRITY-2, PROVISIONING-5: owner's call,
     * no migration).
     */
    public static function openTaskKeeps(int $serviceId): bool
    {
        return self::query()->open()->where('service_id', $serviceId)
            ->where(fn (Builder $query) => $query->whereIn('action', ['terminate', self::STRAY_ENDED])->orWhere('subject', self::SUBJECT_BY_HAND))
            ->exists();
    }

    /**
     * The service runs again (staff brought back one that ended): its open «remove it by hand» tasks are closed with why,
     * or staff following one would destroy a live machine (sweep 8, S8-PROVISIONING-2). A module's own failed
     * termination needs none of this: TerminateJob refuses a service no longer ended.
     */
    public static function closeByHandTerminations(Service $service): void
    {
        $now = now();
        self::query()->open()->where('service_id', $service->id)->where('action', 'terminate')->where('subject', self::SUBJECT_BY_HAND)
            ->update(['status' => self::STATUS_RESOLVED, 'resolved_at' => $now, 'updated_at' => $now,
                'resolution' => "closed: the service is {$service->status} again, do not remove it"]);
    }

    private static function openFor(Service $service, string $action): ?self
    {
        // A module's own row: never a task for staff (its instructions overwritten by a later failure: review of #447).
        return self::query()->open()->where('service_id', $service->id)->where('action', $action)->whereNull('subject')->latest('id')->first();
    }

    /** The open module failures that a done $action makes moot (a terminated service's failed suspension…), closed. */
    public static function closeSuperseded(Service $service, string $action, string $resolution): void
    {
        if ((self::SUPERSEDES[$action] ?? []) !== []) {
            $now = now();
            self::query()->open()->where('service_id', $service->id)->whereNull('subject')->whereIn('action', self::SUPERSEDES[$action])
                ->update(['status' => self::STATUS_RESOLVED, 'resolved_at' => $now, 'resolution' => $resolution, 'updated_at' => $now]);
        }
    }

    public static function recordSuccess(Service $service, string $action, ?string $module): self
    {
        $now = now();
        $row = self::openFor($service, $action) ?? new self(['service_id' => $service->id, 'action' => $action]);
        $row->fill(['module' => $module, 'status' => self::STATUS_COMPLETED, 'attempts' => $row->attempts + 1, 'last_attempt_at' => $now,
            'resolved_at' => $now, 'resolution' => 'succeeded'])->save();

        if (self::SUPERSEDES[$action] !== []) {
            self::query()->open()->where('service_id', $service->id)->whereIn('action', self::SUPERSEDES[$action])
                ->update(['status' => self::STATUS_RESOLVED, 'resolved_at' => $now, 'resolution' => "superseded by {$action}", 'updated_at' => $now]);
        }
        // Staff's task for the same action, done by the module after all (a destroy once «Terminate: Destroy» is set).
        self::query()->open()->where('service_id', $service->id)->where('action', $action)->where('subject', self::SUBJECT_BY_HAND)
            ->update(['status' => self::STATUS_RESOLVED, 'resolved_at' => $now, 'resolution' => 'done by the module', 'updated_at' => $now]);

        return $row;
    }

    /**
     * The create, unsuspension or upgrade of this service a payment decided and the queue never took (a queue other than
     * the database one: predeploy S4, ADV-582-5): asked for again by that payment's replay. Never a guess from the
     * service's state (a create killed mid-way leaves no such row: code review of #582): only the row its failed push
     * wrote. The job closes it when it is done; a create of a service no longer pending does nothing.
     */
    public static function askAgainNotQueued(Service $service): void
    {
        $lost = self::query()->open()->where('service_id', $service->id)->whereIn('action', ['create', 'unsuspend', 'upgrade'])->whereNull('subject')
            ->where('last_error', 'like', self::NOT_QUEUED . '%')->latest('id')->get()->unique('action');
        foreach ($lost as $row) {
            AfterCommit::job($row->lifecycleJob($service));
        }
    }

    /**
     * The server job that runs this action again (staff's Retry, a payment's replay), with what the failed one was asked
     * for (job_options: a silent terminate stays silent, a welcome mail staff chose not to send is not sent;
     * L-NOTIFICATIONS-2). A row without them: the defaults.
     */
    public function lifecycleJob(?Service $service = null): CreateJob|SuspendJob|UnsuspendJob|TerminateJob|UpgradeJob
    {
        $service ??= $this->service;
        $options = (array) ($this->job_options ?? []);
        $notify = (bool) ($options['send_notification'] ?? true);

        return match ($this->action) {
            'create' => new CreateJob($service, $notify, (bool) ($options['by_staff'] ?? false)),
            'suspend' => new SuspendJob($service, $notify),
            'unsuspend' => new UnsuspendJob($service),
            'terminate' => new TerminateJob($service, $notify),
            'upgrade' => new UpgradeJob($service),
            default => throw new \InvalidArgumentException("Unknown module action {$this->action}"),
        };
    }

    public static function recordFailure(Service $service, string $action, ?string $module, Throwable $error): self
    {
        $row = self::openFor($service, $action) ?? new self(['service_id' => $service->id, 'action' => $action, 'status' => self::STATUS_OPEN]);
        $row->fill(['module' => $module, 'attempts' => $row->attempts + 1, 'last_attempt_at' => now(),
            'last_error' => Str::limit((string) LogText::of($error->getMessage()), 2000)])->save();

        return $row;
    }

    /**
     * No automatic retry left (the job's last attempt failed): true the first time, so staff hear once. A job that died
     * without reaching the module's answer (a timeout, a killed worker) is recorded here, else nobody would see it
     * (review R4).
     */
    public static function giveUp(Service $service, string $action, ?Throwable $error = null): bool
    {
        if (self::openFor($service, $action) === null) {
            self::recordFailure($service, $action, $service->provisioningServer()?->extension,
                $error ?? new RuntimeException('The job failed before the server answered.'));
        }

        return self::query()->open()->where('service_id', $service->id)->where('action', $action)->whereNull('subject')->whereNull('given_up_at')
            ->update(['given_up_at' => now(), 'updated_at' => now()]) > 0;
    }

    /**
     * A domain ordered, checked at its registrar and not done there (D8b-2): its unconfirmed charges settled as failed,
     * the row left open so staff can Retry (code review 3: Close ends it instead).
     */
    public function markNotDone(?Staff $staff): void
    {
        // Open, or closed «stop it» with its request still unconfirmed: staff checked it since (adversarial ADV-4 of #306).
        if (!in_array($this->action, [self::DOMAIN_REGISTER, self::DOMAIN_TRANSFER], true) || !($this->status === self::STATUS_OPEN || $this->onHold())) {
            return;
        }
        // Its order's requests only, never a transfer that replaced a renewal (RenewAsTransfer: the renewal's row); under
        // the lock, never over a request being sent (sweep S4).
        $this->underDomainLock(function () use ($staff) {
            DomainRegistrarLog::query()->where('domain_id', $this->domain_id)->where('status', DomainRegistrarLog::STATUS_PENDING)
                ->whereIn('action', ['register', 'transfer'])->whereNull('request->renewal')->update(['status' => DomainRegistrarLog::STATUS_FAILED,
                    'error' => 'Checked by staff: not done at the registrar.', 'staff_id' => $staff?->id, 'updated_at' => now()]);
            $this->forceFill(['last_error' => 'Checked: not done at the registrar. Retry to send it.'])->save();
        });
    }

    /**
     * Staff close it: the account was fixed by hand, or it no longer matters. A create resolved means the account
     * exists: a pending service starts, or it would stay pending, listed nowhere and never billed (review R3).
     */
    public function markResolved(?Staff $staff): void
    {
        // A domain's renewal: «renewed by hand» applies its paid line only as the registrar shows it (D5b-2 review 6);
        // throws why not, and the row stays open.
        if ($this->action === self::DOMAIN_RENEW && $this->subject === self::SUBJECT_RELOCK) {
            // Locked by hand at the registrar: nothing of ours left unlocked.
            if (($domain = Domain::query()->find($this->domain_id)) !== null && isset($domain->meta['unlocked_by_us'])) {
                $meta = $domain->meta;
                unset($meta['unlocked_by_us']);
                $domain->forceFill(['meta' => $meta])->save();
            }
            $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => 'locked by hand',
                'resolved_by_staff_id' => $staff?->id])->save();

            return;
        }
        if ($this->action === self::DOMAIN_RENEW) {
            if ($this->invoiceItem !== null && $this->invoiceItem->applied_at === null) {
                app(DomainRenewalService::class)->markRenewedByHand($this->invoiceItem, $staff);
            }
            $this->refresh();
            if ($this->status === self::STATUS_OPEN) {
                $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => 'renewed by hand',
                    'resolved_by_staff_id' => $staff?->id])->save();
            }

            return;
        }
        // A domain ordered (D8b-2): «done by hand» (registered or its transfer started at the registrar by staff): it is
        // sent and its line applied; its unconfirmed charges count as done. With a registrar API the sync takes it on;
        // without one nothing would (code review 5): a registration is Active for its years, a transfer Active (its
        // expiry for staff to set, listed under «Records to check»). Under the domain's registrar lock (code review 10).
        if (in_array($this->action, [self::DOMAIN_REGISTER, self::DOMAIN_TRANSFER], true)) {
            // An ended domain (REV-769-5): «done» would use up its payment for a domain that stays cancelled, listed nowhere.
            $ended = Domain::query()->find($this->domain_id);
            if ($ended !== null && $ended->status->isTerminal()) {
                throw new RuntimeException(__('domains.order.done_by_hand_ended', ['domain' => $ended->fqdn, 'status' => $ended->status->value]));
            }
            Cache::lock("domain-registrar:{$this->domain_id}", 600)->block(15, fn () => DB::transaction(function () use ($staff) {
                $domain = Domain::query()->lockForUpdate()->find($this->domain_id);
                if ($domain !== null) {
                    $meta = $domain->meta ?? [];
                    $meta['sent_at'] ??= now()->toIso8601String();
                    unset($meta['on_hold']);
                    $updates = ['meta' => $meta];
                    $connection = $domain->effectiveRegistrarConnection();
                    $api = $connection instanceof DomainRegistrarConnection && $connection->isUsable();
                    if (!$api && $domain->status === DomainStatus::PendingRegister) {
                        $updates += ['status' => DomainStatus::Active, 'registered_at' => now()->toDateString(),
                            'expires_at' => now()->addYears(max(1, (int) $domain->years))->toDateString()];
                    } elseif (!$api && $domain->status === DomainStatus::PendingTransfer) {
                        $updates += ['status' => DomainStatus::Active, 'transferred_at' => now()->toDateString()];
                    }
                    $domain->forceFill($updates)->save();
                    DB::afterCommit(fn () => $domain->order?->refreshStatus());
                }
                $this->invoiceItem?->forceFill(['applied_at' => $this->invoiceItem->applied_at ?? now()])->saveQuietly();
                DomainRegistrarLog::query()->where('domain_id', $this->domain_id)->where('status', DomainRegistrarLog::STATUS_PENDING)
                    ->whereIn('action', ['register', 'transfer'])->whereNull('request->renewal')->update(['status' => DomainRegistrarLog::STATUS_OK,
                        'error' => 'Done by staff at the registrar.', 'staff_id' => $staff?->id, 'updated_at' => now()]);
                $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => 'done by hand',
                    'resolved_by_staff_id' => $staff?->id])->save();
            }));

            return;
        }
        $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => self::RESOLVED_BY_STAFF,
            'resolved_by_staff_id' => $staff?->id])->save();
        if ($this->action === 'create' && $this->service !== null) {
            ExtensionHelper::startCreated($this->service);
        }

    }
}
