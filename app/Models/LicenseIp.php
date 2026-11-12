<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One IP of a bulk license, for as long as it was on its list (docs/zonecloud-bulk-license.md §4.2).
 *
 * @property int $id
 * @property int $license_id
 * @property string $ip
 * @property string|null $label
 * @property Carbon $added_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $last_ok_at
 */
class LicenseIp extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['license_id', 'ip', 'label', 'added_at', 'removed_at', 'last_ok_at'];

    protected $casts = [
        'added_at' => 'datetime',
        'removed_at' => 'datetime',
        'last_ok_at' => 'datetime',
    ];

    /** Checks touch it on every good answer: not an audited change. */
    protected $auditExclude = ['last_ok_at'];

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * @param  Builder<LicenseIp>  $query
     * @return Builder<LicenseIp>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /** The address as stored (inet_ntop of inet_pton), or null for anything that is not one. */
    public static function normalize(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return inet_ntop((string) inet_pton($ip)) ?: null;
    }
}
