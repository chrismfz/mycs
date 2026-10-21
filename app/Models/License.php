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
     * checking it and the WHMCS row stay staff's and the client area's.
     */
    public const TEMPLATE_HIDDEN = ['valid_domain', 'valid_ip', 'valid_directory', 'reissues', 'whmcs_id', 'last_access', 'last_ok_at',
        'last_seen_ip', 'last_seen_client', 'last_failure_at', 'last_failure'];

    protected $fillable = ['service_id', 'license_key', 'status', 'valid_domain', 'valid_ip', 'valid_directory', 'reissues', 'last_access', 'whmcs_id',
        'last_ok_at', 'last_seen_ip', 'last_seen_client', 'last_failure_at', 'last_failure'];

    protected $casts = [
        'last_access' => 'datetime',
        'last_ok_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'reissues' => 'integer',
    ];

    /** Checks touch last_access on every call: not an audited change. */
    protected $auditExclude = ['last_access', 'last_ok_at', 'last_seen_ip', 'last_seen_client', 'last_failure_at', 'last_failure'];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<LicenseAccessLog, $this> */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(LicenseAccessLog::class);
    }
}
