<?php

namespace App\Models;

use App\Enums\DomainStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A domain name (Πυλώνας A / A1) — docs/domains/README.md §3.4.
 *
 * Authoritative name = sld + tld; `fqdn` is derived (kept for lookups + the
 * per-tenant unique). Two orthogonal clocks: `expires_at` (REGISTRAR truth,
 * pulled by the A2 sync) vs the linked ServiceContract's next_due_date (the
 * billing clock, 1:1 per domain — owner decision). `customer_id` NULL =
 * «αδέσποτο»: imported/unmatched, outside renewal billing until «Ανάθεση σε
 * πελάτη».
 *
 * @property int $id
 * @property int|null $legacy_id
 * @property int|null $user_id
 * @property int|null $service_id
 * @property int|null $registrar_connection_id
 * @property string $sld
 * @property string $tld
 * @property string $fqdn
 * @property DomainStatus|null $status
 * @property Carbon|null $registered_at
 * @property Carbon|null $transferred_at
 * @property Carbon|null $expires_at
 * @property bool $auto_renew
 * @property bool $transfer_lock
 * @property bool $whois_privacy
 * @property bool $dnssec_enabled
 * @property bool $consent_publish
 * @property string|null $registrar_domain_id
 * @property Carbon|null $last_synced_at
 * @property string|null $sync_error
 * @property array<string, mixed>|null $module_meta
 * @property DomainRegistrarConnection|null $registrarConnection
 * @property Collection<int, DomainContact> $contacts
 * @property Collection<int, DomainNameserver> $nameservers
 */
class Domain extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'legacy_id',
        'user_id',
        'service_id',
        'registrar_connection_id',
        'sld',
        'tld',
        'fqdn',
        'status',
        'registered_at',
        'transferred_at',
        'expires_at',
        'auto_renew',
        'transfer_lock',
        'whois_privacy',
        'dnssec_enabled',
        'consent_publish',
        'registrar_domain_id',
        'idn_script',
        'grace_days_override',
        'redemption_days_override',
        'fee_override',
        'price_override',
        'module_meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'registered_at' => 'date',
            'transferred_at' => 'date',
            'expires_at' => 'date',
            'auto_renew' => 'boolean',
            'transfer_lock' => 'boolean',
            'whois_privacy' => 'boolean',
            'dnssec_enabled' => 'boolean',
            'consent_publish' => 'boolean',
            'last_synced_at' => 'datetime',
            'fee_override' => 'decimal:2',
            'price_override' => 'decimal:2',
            'module_meta' => 'array',
        ];
    }

    /** The client (Ekdosi: customer). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The billing side, 1:1 (Ekdosi: ServiceContract). Its expires_at is the billing clock. */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function registrarConnection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class, 'registrar_connection_id');
    }

    public function nameservers(): HasMany
    {
        return $this->hasMany(DomainNameserver::class)->orderBy('sort_order');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(DomainContact::class);
    }

    /** «Αδέσποτο» — imported/unmatched, outside renewal billing until assigned. */
    public function isUnassigned(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Offerable for «Ανάθεση σε πελάτη»: unassigned AND not in a terminal
     * status (the assign action's guard refuses those — button/badges stay in
     * sync via this one predicate + scopeAssignable).
     */
    public function isAssignable(): bool
    {
        return $this->user_id === null
            && !($this->status instanceof DomainStatus && $this->status->isTerminal());
    }

    /** Query twin of isAssignable() for worklist tabs/badges. */
    public function scopeAssignable($query)
    {
        return $query->whereNull('user_id')
            ->whereNotIn('status', DomainStatus::terminalValues());
    }

    /** ONE «λήγει σύντομα» window for list column, tab, badge AND View header. */
    public const EXPIRING_SOON_DAYS = 45;

    /**
     * Expired = strictly BEFORE today: on the expiry date itself the registrar
     * still holds the name, so the UI must say «λήγει σήμερα», not «έληξε».
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(Carbon::today());
    }

    /** Row-color hint (any status — a Grace domain nearing its date still warns). */
    public function isExpiringSoon(): bool
    {
        return $this->expires_at !== null
            && !$this->isExpired()
            && $this->expires_at->lte(Carbon::today()->addDays(self::EXPIRING_SOON_DAYS));
    }

    /**
     * The «Λήγουν σύντομα» WORKLIST (tab/badge): Active domains inside the
     * window and NOT already expired — deliberately narrower than the color
     * hint above (an expired or cancelled row is not a renewal to chase here).
     */
    public function scopeExpiringSoon($query)
    {
        return $query
            ->where('status', DomainStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', Carbon::today())
            ->where('expires_at', '<=', Carbon::today()->addDays(self::EXPIRING_SOON_DAYS));
    }

    /**
     * §2 routing, THE one source (UI display AND the sync both use it): the
     * domain's own connection wins, else the TLD's default. Null = manual.
     */
    public function effectiveRegistrarConnection(): ?DomainRegistrarConnection
    {
        // Spike: the domain's own connection only. Ekdosi falls back to the TLD's default
        // connection (domain_tlds); that routing table comes back in Phase 2.
        /** @var DomainRegistrarConnection|null */
        return $this->registrarConnection;
    }

    /** Keep fqdn derived from the authoritative sld + tld pair. */
    public static function fqdnFor(string $sld, string $tld): string
    {
        return mb_strtolower(trim($sld) . '.' . ltrim(trim($tld), '.'));
    }
}
