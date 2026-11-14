<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One install of the licensed software as it describes itself on /licensing/v1/check (App\Services\Licensing\
 * LicenseTelemetry). Written by the checks only, never by staff: not audited.
 *
 * @property int $id
 * @property int $license_id
 * @property string $ip
 * @property string $product
 * @property string $install_id
 * @property string|null $version
 * @property Carbon|null $build_time
 * @property string|null $commit
 * @property string|null $binary_sha256
 * @property string|null $os
 * @property string|null $arch
 * @property int|null $clock_skew
 * @property bool|null $licensed
 * @property int|null $failures
 * @property string|null $last_status
 * @property int $checks
 * @property string|null $previous_version
 * @property Carbon|null $downgraded_at
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
class LicenseInstall extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'build_time' => 'datetime',
        'clock_skew' => 'integer',
        'licensed' => 'boolean',
        'failures' => 'integer',
        'checks' => 'integer',
        'downgraded_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
