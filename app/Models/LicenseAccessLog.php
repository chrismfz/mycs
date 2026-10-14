<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** WHMCS's «License Access Logs» (mod_licensinglog): failed checks and reissues, kept LicenseLogPrune days. */
class LicenseAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['license_id', 'domain', 'ip', 'directory', 'message', 'created_at'];

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
