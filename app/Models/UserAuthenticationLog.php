<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAuthenticationLog extends Model
{
    protected $fillable = [
        'user_id',
        'ip_address',
    ];

    /**
     * Get the user that owns the authentication log.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
