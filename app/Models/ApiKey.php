<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int|null $staff_id
 */
class ApiKey extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = [
        'name',
        'permissions',
        'token',
        'staff_id',
        'type',
        'ip_addresses',
        'last_used_at',
        'enabled',
    ];

    protected $casts = [
        'permissions' => 'array',
        'ip_addresses' => 'array',
        'last_used_at' => 'datetime',
    ];

    protected $auditExclude = [
        'last_used_at',
    ];

    /** The member of staff who created the key; their permissions cap the key's (AdminApi), and it goes with them.
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
