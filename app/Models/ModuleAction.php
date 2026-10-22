<?php

namespace App\Models;

use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\RenewAsTransfer;
use App\Support\Redact;
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
 */
class ModuleAction extends Model
{
    public const STATUS_OPEN = 'open';

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

    /** The resolution of an order staff closed «stop it»: on hold, its unconfirmed request still for them to say. */
    public const CLOSED_ON_HOLD = 'closed, on hold';

    public const DOMAIN_ACTIONS = [self::DOMAIN_RENEW, self::DOMAIN_REGISTER, self::DOMAIN_TRANSFER];

    /**
     * A contact's details sent to the registrar of a domain that uses it (P1b-2): retried by sending them again; resolved
     * by hand when fixed at the registrar. Not a DOMAIN_ACTION: nothing paid, nothing put on hold.
     */
    public const DOMAIN_CONTACT = 'domain_contact';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'given_up_at' => 'datetime',
            'resolved_at' => 'datetime',
            'attempts' => 'integer',
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
            'last_error' => Str::limit(Redact::text($error->getMessage()), 2000)])->save();
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

    private static function openFor(Service $service, string $action): ?self
    {
        return self::query()->open()->where('service_id', $service->id)->where('action', $action)->latest('id')->first();
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

        return $row;
    }

    public static function recordFailure(Service $service, string $action, ?string $module, Throwable $error): self
    {
        $row = self::openFor($service, $action) ?? new self(['service_id' => $service->id, 'action' => $action, 'status' => self::STATUS_OPEN]);
        $row->fill(['module' => $module, 'attempts' => $row->attempts + 1, 'last_attempt_at' => now(),
            'last_error' => Str::limit($error->getMessage(), 2000)])->save();

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

        return self::query()->open()->where('service_id', $service->id)->where('action', $action)->whereNull('given_up_at')
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
        $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => 'marked resolved by staff',
            'resolved_by_staff_id' => $staff?->id])->save();
        if ($this->action === 'create' && $this->service !== null) {
            ExtensionHelper::startCreated($this->service);
        }

    }
}
