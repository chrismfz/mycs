<?php

namespace App\Models;

use App\Contracts\StaffPricedNames;
use App\Enums\DomainStatus;
use App\Services\Billing\ClientGroupPricing;
use App\Services\Domains\DomainNameHold;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Payment\ItemPaymentMethod;
use Carbon\CarbonInterface;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 * @property string|null $live_fqdn = fqdn while it holds its name (holdsName, D-25), computed by the database: unique
 * @property DomainStatus $status
 * @property Carbon|null $name_held_at a pending order's hold of its name: paid, free as ordered, or sent (D-25); an expired row's
 *                                     until its recovery window ends (recoveryEndsOn) or while a renewal of it is paid and
 *                                     waiting (ADV-DNH-1); null otherwise
 * @property Carbon|null $registered_at
 * @property Carbon|null $transferred_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $grace_ends_on
 * @property Carbon|null $redemption_ends_on
 * @property int $years
 * @property string $currency_code
 * @property int|null $payment_gateway_id
 * @property bool $no_auto_charge its own «Do not charge automatically» (#568)
 * @property string|null $recurring_amount
 * @property bool $auto_renew
 * @property string|null $sync_error
 * @property int|null $contact_id the related contact, who its lines are invoiced to by Ekdosi (not a registrar role)
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
        'client_id', 'order_id', 'epp_code', 'domain_tld_id', 'registrar_connection_id', 'sld', 'tld', 'fqdn', 'status',
        'registered_at', 'transferred_at', 'expires_at', 'years', 'currency_code', 'payment_gateway_id', 'no_auto_charge', 'recurring_amount',
        'auto_renew', 'transfer_lock', 'id_protection', 'dns_management', 'email_forwarding', 'dnssec_enabled',
        'contact_id', 'registrant_contact_id', 'admin_contact_id', 'tech_contact_id', 'billing_contact_id',
        'registrant_handle', 'admin_handle', 'tech_handle', 'billing_handle',
        'registrar_domain_id', 'idn_script', 'last_synced_at', 'sync_error',
        'grace_days_override', 'grace_fee_override', 'redemption_days_override', 'redemption_fee_override', 'notes', 'meta',
    ];

    /**
     * Staff's notes, the registrar's raw data and handles never reach a template, the client area or a JSON of it
     * (`{{ domain }}` prints the model: review M1); nor its connection, whose settings are the credentials.
     */
    protected $hidden = ['notes', 'meta', 'epp_code', 'registrant_handle', 'admin_handle', 'tech_handle', 'billing_handle', 'registrarConnection', 'registrarLogs'];

    /** Bookkeeping of the sync, not a change of the domain (docs/domains.md D-9). */
    protected $auditExclude = ['last_synced_at', 'sync_error', 'epp_code', 'name_held_at']; // a transfer's code: never in an audit (D8b); name_held_at is bookkeeping of D-25, not a change staff made

    /**
     * Days after its expiry its registry may still give an expired domain back when its grace and redemption are not
     * known (D-25 and a deleted domain's recovery: myip's hook, 30 days).
     */
    public const RECOVERY_DEFAULT_DAYS = 30;

    public const TEMPLATE_HIDDEN = ['notes', 'meta', 'epp_code', 'registrant_handle', 'admin_handle', 'tech_handle', 'billing_handle', 'registrarConnection', 'registrarLogs'];

    protected static function booted(): void
    {
        // «Do not charge automatically» turned on (#568): it leaves an open invoice it shares with items still charged.
        static::updated(function (self $domain) {
            if ($domain->wasChanged('payment_gateway_id')) {
                ItemPaymentMethod::followOnOpenInvoices($domain); // its open invoices follow (owner 2026-11-01, #568)
            }
            if ($domain->wasChanged('no_auto_charge') && $domain->no_auto_charge) {
                ItemPaymentMethod::keepApart($domain);
            }
            // Expired again inside its recovery window without a hold (REV2-DNH-2: set back from cancelled): its customer's
            // still, it takes its name back, unless another row holds it by now (claim refuses; staff's, told by the cron).
            if ($domain->wasChanged('status') && $domain->status === DomainStatus::Expired && $domain->name_held_at === null
                && $domain->inRecoveryWindow()) {
                $id = (int) $domain->id;
                DB::afterCommit(function () use ($id) {
                    $fresh = Domain::query()->find($id);
                    if ($fresh !== null) {
                        DomainNameHold::claim($fresh);
                    }
                });
            }
            // It holds its name from now on (D-25): the other rows of the name that no longer hold it are closed.
            if ($domain->wasChanged(['status', 'name_held_at']) && $domain->holdsName()
                && !self::holding($domain->getOriginal('status'), $domain->getOriginal('name_held_at') !== null)) {
                $id = (int) $domain->id;
                DB::afterCommit(fn () => DomainNameHold::closeOthers($id));
            }
        });
        static::created(function (self $domain) {
            if ($domain->holdsName()) {
                $id = (int) $domain->id;
                DB::afterCommit(fn () => DomainNameHold::closeOthers($id));
            }
        });
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
            // A pending order's hold of its name (D-25): only a pending row has one, or an expired row until the end of
            // its registry's recovery window (owner 2026-11-08: expiry + grace + redemption, recoveryEndsOn) or while a
            // renewal of it is paid and waiting (ADV-DNH-1): becoming expired, it keeps the name it held; the cron's
            // domains_superseded step releases it once neither stands. Sent to its registrar, it holds (it was claimed
            // before it was sent); one of ours that becomes pending again (a renewal as a transfer) keeps it.
            if ($domain->status === DomainStatus::Expired) {
                if ($domain->isDirty('status')) {
                    $domain->name_held_at = $domain->exists && self::holding($domain->getOriginal('status'), $domain->getOriginal('name_held_at') !== null)
                        && ($domain->inRecoveryWindow() || DomainNameHold::renewalWaiting($domain)) ? ($domain->name_held_at ?? now()) : null;
                }
            } elseif (!$domain->status->isPending()) {
                $domain->name_held_at = null;
            } elseif ($domain->name_held_at === null && (!empty($domain->meta['sent_at'])
                || ($domain->exists && self::holding($domain->getOriginal('status'), false)))) {
                $domain->name_held_at = now();
            }
        });
    }

    /** It holds its name (D-25): active, grace, redemption, a pending order paid or sent, an expired row renewed (name_held_at). */
    public function holdsName(): bool
    {
        return self::holding($this->status, $this->name_held_at !== null);
    }

    private static function holding(mixed $status, bool $held): bool
    {
        return $status instanceof DomainStatus && $status->holdsName($held);
    }

    /** A name as it is stored: trimmed, lowercase, ASCII (punycode), without the root's trailing dot. */
    public static function normalise(?string $name): string
    {
        // Lowercased by IDNA, not mb_strtolower before it: that one makes a capital Σ «ς» or «σ» by what follows it,
        // so «ΚΑΛΌΣ» and «ΚΑΛΌΣ.GR» would disagree (review of the non-transitional switch).
        $name = rtrim(trim((string) $name), '.');
        $ascii = DomainName::toAscii($name);
        $ascii = $ascii === null ? null : strtolower($ascii);
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
            'no_auto_charge' => 'boolean',
            'transfer_lock' => 'boolean',
            'id_protection' => 'boolean',
            'dns_management' => 'boolean',
            'email_forwarding' => 'boolean',
            'dnssec_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'name_held_at' => 'datetime',
            'grace_days_override' => 'integer',
            'redemption_days_override' => 'integer',
            'grace_fee_override' => 'decimal:2',
            'redemption_fee_override' => 'decimal:2',
            'meta' => 'array',
            // A transfer's EPP code as the customer gave it (D8b, owner 2026-10-17: staff see it, to tell a wrong one),
            // encrypted at rest.
            'epp_code' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Order, $this> the order it came from (D8b) */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Gateway, $this> its own payment method (WHMCS's paymentmethod): its renewals are invoiced apart with
     *                                   it preferred, and a saved method of another gateway never charges them; none: the account's
     */
    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'payment_gateway_id');
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

    /**
     * Its related contact, as a service's (docs/accounts.md 3b): who its lines are invoiced to by Ekdosi
     * (docs/ekdosi-bridge.md). Not one of the roles at the registrar: changing it changes nothing there.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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

        // Its registrar's word while its connection is off for a while too (sweep L4): only a manual one goes by our days.
        return ($this->grace_ends_on !== null || $this->redemption_ends_on !== null) && $connection !== null
            && $connection->registrar !== DomainRegistrarConnection::MANUAL;
    }

    /**
     * The years a renewal in grace or redemption is for (D-13): its TLD's shortest renewal period in its currency (one
     * year; two for .gr, whose periods are even: G4a of grepp). A domain at a price set by hand, or a TLD with no renew
     * price in its currency, keeps its own period (review 3 of #189).
     */
    public function stageRenewalYears(): int
    {
        if ($this->recurring_amount !== null) {
            return max(1, (int) $this->years);
        }
        // Its shortest period offered: never a year at 0.00 WHMCS never sold, restored free (ADV-A5-2), and only in its own
        // registrar's steps (REV-DOMAINS-2: a .gr domain in grace never renews for one year, which billing refuses).
        $step = $this->renewYearStep();
        $years = $this->domainTld?->offeredYears('renew', (string) $this->currency_code)
            ->filter(fn (int $y) => $y >= 1 && $y % $step === 0)->min();

        return max(1, (int) ($years ?? $this->years ?? 1));
    }

    /** The years its next renewal is for: the stage's in grace or redemption, else its own (the cron, Renew now, reminders). */
    public function nextRenewalYears(): int
    {
        return in_array($this->status, [DomainStatus::Grace, DomainStatus::Redemption], true) ? $this->stageRenewalYears() : max(1, (int) $this->years);
    }

    /** The price of renewing it for $years: its own (recurring amount, or its TLD's for its period), else its TLD's for $years. */
    public function renewalPriceFor(int $years): ?string
    {
        if ($years === max(1, (int) $this->years)) {
            return $this->renewalPrice();
        }

        return $this->staffPriced() ? null : $this->catalogRenewalPrice($years);
    }

    /**
     * Its TLD's renew price for $years, in its currency, less its account's group discount when the group gives it on
     * domains too (owner 2026-11-05: a deal that follows the catalog); null when not priced. Never on a price of its own
     * (recurring_amount, a deal kept or staff's) nor on a grace or redemption fee. Never 100% or more: a free renewal the
     * registrar still charges for (ADV-656-1); the form refuses it, and one set otherwise renews at the catalog's.
     */
    public function catalogRenewalPrice(int $years): ?string
    {
        $price = $this->domainTld?->loadMissing('prices')->priceFor('renew', max(1, $years), (string) $this->currency_code);
        if ($price === null) {
            return null;
        }
        $group = $this->client->group;
        if ($group === null || !$group->applies_to_domains || (float) $group->discount_percent <= 0 || (float) $group->discount_percent >= 100) {
            return $price;
        }

        return ClientGroupPricing::discounted((string) $price, (string) $group->discount_percent);
    }

    /**
     * A name its registrar never does at its TLD's price (.gr: 2 characters, the EETT's 300 € a deed): no catalog price
     * applies, only its own set by staff (never billed otherwise; owner 2026-10-18).
     */
    public function staffPriced(): bool
    {
        // By its name first (F4 of #202: never lost with its registrar's connection off or another registrar's).
        if (GrEppRegistrar::twoCharacters((string) $this->fqdn)) {
            return true;
        }
        try {
            $connection = $this->effectiveRegistrarConnection();
            $registrar = $connection !== null ? app(DomainRegistrarFactory::class)->for($connection) : null;

            return $registrar instanceof StaffPricedNames && $registrar->staffPriced((string) $this->fqdn);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Its registrar's renewal step (the .gr registry: 2), 1 when it has none or no API: a period not in its steps is
     * never billed (review 2 of #189), only told to staff.
     */
    public function renewYearStep(): int
    {
        try {
            $connection = $this->effectiveRegistrarConnection();

            return $connection !== null && $connection->isUsable()
                ? max(1, app(DomainRegistrarFactory::class)->for($connection)->capabilities()->renewYearStep) : 1;
        } catch (\Throwable) {
            return 1;
        }
    }

    /** Whether its registrar renews it in its grace (no restore, no grace fee: the .gr registry). */
    public function renewsInGrace(): bool
    {
        try {
            $connection = $this->effectiveRegistrarConnection();

            return $connection !== null && $connection->isUsable()
                && app(DomainRegistrarFactory::class)->for($connection)->capabilities()->renewsInGrace;
        } catch (\Throwable) {
            return false;
        }
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
     * The last day its registry may still give it back to its customer (owner 2026-11-08, D-25): the end of its
     * redemption, else of its grace, else its expiry, by the same dates and days as its life (DomainLifecycle, D5a). When
     * neither its registrar's dates nor both its days (its own or its TLD's) are known, RECOVERY_DEFAULT_DAYS after the end
     * of what is known (its expiry when nothing is: as a deleted domain's recovery, CronJob::pastRecovery). Null without an expiry.
     */
    public function recoveryEndsOn(): ?Carbon
    {
        if ($this->expires_at === null) {
            return null;
        }
        $known = $this->hasRegistrarStageDates() || ($this->graceDays() !== null && $this->redemptionDays() !== null);

        return self::recoveryEnd($this->redemptionEndsOn() ?? $this->graceEndsOn() ?? $this->expires_at, $known);
    }

    /**
     * Its last recoverable day (D-25): $last, the end of the stages that are known, as it is when $known; else the default
     * days counted from it (REV2-DNH-1: from the end of a known grace, not from the expiry, which the grace would swallow),
     * so the part that is not known is always covered.
     */
    public static function recoveryEnd(Carbon $last, bool $known): Carbon
    {
        return $known ? $last->copy() : $last->copy()->addDays(self::RECOVERY_DEFAULT_DAYS);
    }

    /** Today is on or before its last recoverable day (D-25): an expired row holds its name till then. */
    public function inRecoveryWindow(): bool
    {
        $end = $this->recoveryEndsOn();

        return $end !== null && !$end->copy()->startOfDay()->lessThan(today());
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
        if ($this->staffPriced()) {
            return null; // never at its TLD's price
        }

        return $this->catalogRenewalPrice(max(1, (int) $this->years));
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
    public function describeRenewal(CarbonInterface $start, CarbonInterface $end, ?int $years = null): string
    {
        $format = __('services.invoice_period_date_format');
        $years ??= max(1, (int) $this->years);

        return trans_choice('domains.invoice.renewal', $years, ['domain' => $this->fqdn, 'years' => $years,
            'start' => $start->translatedFormat($format), 'end' => $end->translatedFormat($format)]);
    }
}
