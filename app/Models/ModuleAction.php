<?php

namespace App\Models;

use App\Helpers\ExtensionHelper;
use App\Services\Domains\DomainRenewalService;
use App\Support\Redact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
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

    /** A domain's renewal failed, or waits for staff (D-15): one open row per paid line, its attempts counted. */
    public static function domainFailed(Domain $domain, InvoiceItem $line, ?string $module, Throwable $error): self
    {
        $row = self::query()->open()->where('domain_id', $domain->id)->where('invoice_item_id', $line->id)->where('action', self::DOMAIN_RENEW)->latest('id')->first()
            ?? new self(['domain_id' => $domain->id, 'invoice_item_id' => $line->id, 'action' => self::DOMAIN_RENEW, 'status' => self::STATUS_OPEN]);
        $row->fill(['module' => $module, 'attempts' => $row->attempts + 1, 'last_attempt_at' => now(),
            'last_error' => Str::limit(Redact::text($error->getMessage()), 2000)])->save();

        return $row;
    }

    /** Done (renewed, adopted, or by hand): its open row, if any, completed. */
    public static function domainSucceeded(Domain $domain, InvoiceItem $line): void
    {
        $now = now();
        self::query()->open()->where('domain_id', $domain->id)->where('invoice_item_id', $line->id)->where('action', self::DOMAIN_RENEW)
            ->update(['status' => self::STATUS_COMPLETED, 'resolved_at' => $now, 'resolution' => 'succeeded', 'updated_at' => $now]);
    }

    /**
     * Staff close a domain's renewal without it (refunded, ended, or checked at the registrar: the unconfirmed charge was
     * not done). Its unconfirmed charges are settled as not done, so a later Retry may charge (D5b-2 review 6).
     */
    public function closeUnrenewed(?Staff $staff): void
    {
        if ($this->action !== self::DOMAIN_RENEW || $this->status !== self::STATUS_OPEN) {
            return;
        }
        DomainRegistrarLog::query()->where('domain_id', $this->domain_id)->where('status', DomainRegistrarLog::STATUS_PENDING)
            ->whereIn('action', ['renew', 'restore'])->update(['status' => DomainRegistrarLog::STATUS_FAILED,
                'error' => 'Checked by staff at the registrar: not done.', 'staff_id' => $staff?->id, 'updated_at' => now()]);
        $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => 'closed without renewing',
            'resolved_by_staff_id' => $staff?->id])->save();
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
                $error ?? new \RuntimeException('The job failed before the server answered.'));
        }

        return self::query()->open()->where('service_id', $service->id)->where('action', $action)->whereNull('given_up_at')
            ->update(['given_up_at' => now(), 'updated_at' => now()]) > 0;
    }

    /**
     * Staff close it: the account was fixed by hand, or it no longer matters. A create resolved means the account
     * exists: a pending service starts, or it would stay pending, listed nowhere and never billed (review R3).
     */
    public function markResolved(?Staff $staff): void
    {
        // A domain's renewal: «renewed by hand» applies its paid line only as the registrar shows it (D5b-2 review 6);
        // throws why not, and the row stays open.
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
        $this->forceFill(['status' => self::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => 'marked resolved by staff',
            'resolved_by_staff_id' => $staff?->id])->save();
        if ($this->action === 'create' && $this->service !== null) {
            ExtensionHelper::startCreated($this->service);
        }

    }
}
