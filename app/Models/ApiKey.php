<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ApiKey extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = [
        'name',
        'permissions',
        'token',
        'user_id',
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

    /** The admin who created the key; its permissions cap the key's (AdminApi).
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
