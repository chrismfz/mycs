<?php

namespace App\Models;

use App\Enums\DomainStatus;
use Carbon\CarbonInterface;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Nixpal\DomainTools\DomainName;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A domain name (docs/domains.md, D1): its own record, billed directly (D-4), its expiry the registrar's and the due
 * date too. Its contact roles are the account's contacts (D-3), each with its handle at the registrar. It ends by
 * status and keeps its history (D-18).
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $domain_tld_id
 * @property int|null $registrar_connection_id
 * @property string $sld
 * @property string $tld
 * @property string $fqdn lowercase ASCII (IDN as punycode)
 * @property string|null $live_fqdn = fqdn while not ended, computed by the database
 * @property DomainStatus $status
 * @property Carbon|null $registered_at
 * @property Carbon|null $transferred_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $grace_ends_on
 * @property Carbon|null $redemption_ends_on
 * @property int $years
 * @property string $currency_code
 * @property string|null $recurring_amount
 * @property bool $auto_renew
 * @property string|null $sync_error
 * @property int|null $registrant_contact_id
 * @property int|null $admin_contact_id
 * @property int|null $tech_contact_id
 * @property int|null $billing_contact_id
 * @property string|null $registrant_handle
 * @property string|null $admin_handle
 * @property string|null $tech_handle
 * @property string|null $billing_handle
 * @property string|null $registrar_domain_id
 * @property int|null $grace_days_override
 * @property int|null $redemption_days_override
 * @property string|null $notes
 * @property array<string, mixed>|null $meta
 * @property Client $client
 * @property DomainTld|null $domainTld
 * @property DomainRegistrarConnection|null $registrarConnection
 */
class Domain extends Model implements Auditable
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory, Traits\Auditable;

    public const ROLES = ['registrant', 'admin', 'tech', 'billing'];

    protected $fillable = [
        'client_id', 'domain_tld_id', 'registrar_connection_id', 'sld', 'tld', 'fqdn', 'status',
        'registered_at', 'transferred_at', 'expires_at', 'years', 'currency_code', 'recurring_amount',
        'auto_renew', 'transfer_lock', 'id_protection', 'dns_management', 'email_forwarding', 'dnssec_enabled',
        'registrant_contact_id', 'admin_contact_id', 'tech_contact_id', 'billing_contact_id',
        'registrant_handle', 'admin_handle', 'tech_handle', 'billing_handle',
        'registrar_domain_id', 'idn_script', 'last_synced_at', 'sync_error',
        'grace_days_override', 'grace_fee_override', 'redemption_days_override', 'redemption_fee_override', 'notes', 'meta',
    ];

    /**
     * Staff's notes, the registrar's raw data and handles never reach a template, the client area or a JSON of it
     * (`{{ domain }}` prints the model: review M1); nor its connection, whose settings are the credentials.
     */
    protected $hidden = ['notes', 'meta', 'registrant_handle', 'admin_handle', 'tech_handle', 'billing_handle', 'registrarConnection', 'registrarLogs'];

    /** Bookkeeping of the sync, not a change of the domain (docs/domains.md D-9). */
    protected $auditExclude = ['last_synced_at', 'sync_error'];

    public const TEMPLATE_HIDDEN = ['notes', 'meta', 'registrant_handle', 'admin_handle', 'tech_handle', 'billing_handle', 'registrarConnection', 'registrarLogs'];

    protected static function booted(): void
    {
        // One form of a name (reviews A3, C): lowercase ASCII, IDN as punycode, no trailing dot, fqdn = sld.tld. The
        // database compares names case- and accent-blind, so «άλφα.gr» and «αλφα.gr» must not both be stored as such.
        static::saving(function (self $domain) {
            // A new expiry ends the registrar's stage dates of the old one (adversarial D5a F1: a renewal elsewhere, or a
            // move to a manual registrar, kept last year's dates and deleted the domain early), unless they are set with it.
            if ($domain->exists && $domain->isDirty('expires_at') && !$domain->isDirty(['grace_ends_on', 'redemption_ends_on'])
                && $domain->expires_at !== null && ($domain->getOriginal('expires_at') === null || $domain->expires_at->gt($domain->getOriginal('expires_at')))) {
                $domain->grace_ends_on = null;
                $domain->redemption_ends_on = null;
            }
            $domain->sld = self::normalise($domain->sld);
            $domain->tld = self::normalise($domain->tld);
            $fqdn = self::normalise($domain->fqdn ?: $domain->sld . '.' . $domain->tld);
            if ($fqdn !== $domain->sld . '.' . $domain->tld) {
                throw new InvalidArgumentException("The domain «{$fqdn}» is not «{$domain->sld}» on «{$domain->tld}».");
            }
            $domain->fqdn = $fqdn;
            // A role's handle is its contact's at the registrar: another contact is another handle (review F3), made
            // on the next write. A handle set with it (the importer, the sync) is kept.
            foreach (self::ROLES as $role) {
                if ($domain->isDirty("{$role}_contact_id") && !$domain->isDirty("{$role}_handle")) {
                    $domain->{"{$role}_handle"} = null;
                }
            }
        });
    }

    /** A name as it is stored: trimmed, lowercase, ASCII (punycode), without the root's trailing dot. */
    public static function normalise(?string $name): string
    {
        $name = rtrim(mb_strtolower(trim((string) $name)), '.');
        $ascii = DomainName::toAscii($name);
        if ($ascii === null || $ascii === '' || preg_match('/[^a-z0-9.\-]/', $ascii)) {
            throw new InvalidArgumentException("Not a domain name: «{$name}».");
        }

        return $ascii;
    }

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'registered_at' => 'date',
            'transferred_at' => 'date',
            'expires_at' => 'date',
            'grace_ends_on' => 'date',
            'redemption_ends_on' => 'date',
            'years' => 'integer',
            'recurring_amount' => 'decimal:2',
            'auto_renew' => 'boolean',
            'transfer_lock' => 'boolean',
            'id_protection' => 'boolean',
            'dns_management' => 'boolean',
            'email_forwarding' => 'boolean',
            'dnssec_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'grace_days_override' => 'integer',
            'redemption_days_override' => 'integer',
            'grace_fee_override' => 'decimal:2',
            'redemption_fee_override' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<DomainTld, $this> */
    public function domainTld(): BelongsTo
    {
        return $this->belongsTo(DomainTld::class);
    }

    /** @return BelongsTo<DomainRegistrarConnection, $this> */
    public function registrarConnection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /** @return BelongsTo<Contact, $this> */
    public function registrantContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'registrant_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function adminContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'admin_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function techContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'tech_contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function billingContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'billing_contact_id');
    }

    /** @return HasMany<DomainNameserver, $this> */
    public function nameservers(): HasMany
    {
        return $this->hasMany(DomainNameserver::class)->orderBy('sort');
    }

    /** @return HasMany<DomainRegistrarLog, $this> */
    public function registrarLogs(): HasMany
    {
        return $this->hasMany(DomainRegistrarLog::class);
    }

    /** @return HasMany<DomainReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(DomainReminder::class);
    }

    /** The connection that serves it: its own (e.g. «manual» for CNIC, D-10), else its TLD's. */
    public function effectiveRegistrarConnection(): ?DomainRegistrarConnection
    {
        return $this->registrarConnection ?? $this->domainTld?->registrarConnection;
    }

    public function graceDays(): ?int
    {
        return $this->grace_days_override ?? $this->domainTld?->grace_days;
    }

    public function redemptionDays(): ?int
    {
        return $this->redemption_days_override ?? $this->domainTld?->redemption_days;
    }

    /**
     * Its stage dates are the registrar's own and still apply (adversarial D5a F1c): reported, and its registrar still has
     * an API that keeps them current (a domain moved to a manual registrar goes by its expiry and days again).
     */
    public function hasRegistrarStageDates(): bool
    {
        $connection = $this->effectiveRegistrarConnection();

        return ($this->grace_ends_on !== null || $this->redemption_ends_on !== null) && $connection !== null && $connection->isUsable();
    }

    /**
     * The last day of its grace (D5a): the registrar's date, else its expiry plus the grace days; null when it has no
     * grace (0 days or unknown) or no expiry.
     */
    public function graceEndsOn(): ?Carbon
    {
        if ($this->grace_ends_on !== null && $this->hasRegistrarStageDates()) {
            return $this->grace_ends_on->copy();
        }
        $days = $this->graceDays();

        return $this->expires_at !== null && $days !== null && $days > 0 ? $this->expires_at->copy()->addDays($days) : null;
    }

    /**
     * The last day of its redemption (D5a): the registrar's date, else the end of its grace (or its expiry) plus the
     * redemption days; null when it has none.
     */
    public function redemptionEndsOn(): ?Carbon
    {
        if ($this->redemption_ends_on !== null && $this->hasRegistrarStageDates()) {
            return $this->redemption_ends_on->copy();
        }
        $days = $this->redemptionDays();
        // Only after a known grace (D4 F6: an unknown grace never puts it in redemption the day after its expiry).
        if ((!$this->hasRegistrarStageDates() && $this->graceDays() === null) || $days === null || $days <= 0) {
            return null;
        }
        $from = $this->graceEndsOn() ?? $this->expires_at;

        return $from?->copy()->addDays($days);
    }

    /** @return MorphMany<InvoiceItem, $this> its invoice lines: renewals (with their period) and stage fees (D5b) */
    public function invoiceItems(): MorphMany
    {
        return $this->morphMany(InvoiceItem::class, 'reference');
    }

    /**
     * What its next period costs (D-4): its own recurring amount, else its TLD's renew price for its years, in its
     * currency; null when not offered (staff set one).
     */
    public function renewalPrice(): ?string
    {
        if ($this->recurring_amount !== null) {
            return (string) $this->recurring_amount;
        }

        return $this->domainTld?->loadMissing('prices')->priceFor('renew', max(1, (int) $this->years), $this->currency_code);
    }

    /**
     * The fee of its grace or redemption (D-8, D5a): its own override, else its TLD's, in its currency; null when there
     * is none (not offered, or not this stage).
     */
    public function stageFee(DomainStatus $stage): ?string
    {
        $operation = match ($stage) {
            DomainStatus::Grace => 'grace',
            DomainStatus::Redemption => 'redemption',
            default => null,
        };
        if ($operation === null) {
            return null;
        }
        $override = $operation === 'grace' ? $this->grace_fee_override : $this->redemption_fee_override;

        return $override !== null ? (string) $override : $this->domainTld?->loadMissing('prices')->priceFor($operation, 1, $this->currency_code);
    }

    /** Its renewal line's text, as the customer reads it (lang/). */
    public function describeRenewal(CarbonInterface $start, CarbonInterface $end): string
    {
        $format = __('services.invoice_period_date_format');

        return trans_choice('domains.invoice.renewal', max(1, (int) $this->years), ['domain' => $this->fqdn, 'years' => max(1, (int) $this->years),
            'start' => $start->translatedFormat($format), 'end' => $end->translatedFormat($format)]);
    }
}
