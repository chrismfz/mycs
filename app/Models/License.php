<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A nixpal license (WHMCS's mod_licensing row): what zlic.php answers for its key. Kept in step with its service by
 * the NixpalLicensing server extension (create, suspend, unsuspend, terminate).
 *
 * @property int $id
 * @property int $service_id
 * @property string $license_key
 * @property string $status
 * @property string|null $valid_domain
 * @property string|null $valid_ip
 * @property string|null $valid_directory
 * @property int $reissues
 * @property int|null $max_ips a bulk license's own limit for its customer (Ε4), else its product's
 * @property string|null $tiers a bulk license's own prices per IP (Ε6), else its product's
 * @property int|null $bulk_license_id the bulk license this standalone one joins at its expiry, or joined (§8)
 * @property Carbon|null $merged_at when it joined: its key answers as the bulk license since
 * @property-read int|null $listed_ips the licenses console's count of its active IPs (withCount)
 * @property-read int|null $silent_ips the licenses console's count of its silent IPs (withCount)
 * @property Carbon|null $last_access
 */
class License extends Model implements Auditable
{
    use Traits\Auditable;

    public const STATUS_ACTIVE = 'Active';

    public const STATUS_REISSUED = 'Reissued';

    public const STATUS_SUSPENDED = 'Suspended';

    public const STATUS_EXPIRED = 'Expired';

    /**
     * Not for a template: only its key and status are (`{{ service.license.license_key }}`, nixpal's welcome mail). A
     * service reaches mail sent to others too (a ticket's CC, code review of #291): where it is installed, what was seen
     * checking it and the WHMCS row stay staff's and the client area's. Its valid IP does reach a mail, as part of its
     * service's name only (Service::mailName, owner 2026-11-03), never as a field of its own.
     */
    public const TEMPLATE_HIDDEN = ['valid_domain', 'valid_ip', 'valid_directory', 'reissues', 'tiers', 'max_ips', 'bulk_license_id', 'merged_at', 'whmcs_id', 'last_access', 'last_ok_at',
        'last_seen_ip', 'last_seen_client', 'last_failure_at', 'last_failure'];

    protected $fillable = ['service_id', 'license_key', 'status', 'valid_domain', 'valid_ip', 'valid_directory', 'reissues', 'tiers', 'max_ips', 'bulk_license_id', 'merged_at', 'last_access', 'whmcs_id',
        'last_ok_at', 'last_seen_ip', 'last_seen_client', 'last_failure_at', 'last_failure'];

    protected $casts = [
        'last_access' => 'datetime',
        'last_ok_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'reissues' => 'integer',
        'max_ips' => 'integer',
        'bulk_license_id' => 'integer',
        'merged_at' => 'datetime',
    ];

    /** Checks touch last_access on every call: not an audited change. */
    protected $auditExclude = ['last_access', 'last_ok_at', 'last_seen_ip', 'last_seen_client', 'last_failure_at', 'last_failure'];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Its valid domains as names: zlic binds the reverse DNS of the IP, which is the IP itself (and «www.» + it) when
     * the address has none (review of #493). Those say nothing the valid IPs don't.
     *
     * @return list<string>
     */
    public function domainNames(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->valid_domain)),
            fn (string $domain) => $domain !== '' && filter_var(preg_replace('/^www\./i', '', $domain), FILTER_VALIDATE_IP) === false));
    }

    /** @return HasMany<LicenseIp, $this> a bulk license's IPs, with the removed ones */
    public function ips(): HasMany
    {
        return $this->hasMany(LicenseIp::class);
    }

    /** @return BelongsTo<License, $this> the bulk license this one joins at its expiry, or joined (§8) */
    public function bulkLicense(): BelongsTo
    {
        return $this->belongsTo(License::class, 'bulk_license_id');
    }

    /** @return HasMany<License, $this> a bulk license's standalone ones, joining or joined (§8) */
    public function joining(): HasMany
    {
        return $this->hasMany(License::class, 'bulk_license_id');
    }

    /** @return HasMany<LicenseUsagePeriod, $this> a bulk license's closed periods */
    public function usagePeriods(): HasMany
    {
        return $this->hasMany(LicenseUsagePeriod::class);
    }

    /** @return HasMany<LicenseAccessLog, $this> */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(LicenseAccessLog::class);
    }
}
