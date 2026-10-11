<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'mail_enabled',
        'in_app_enabled',
        'notification_template_id',
    ];

    protected $casts = [
        'mail_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
    ];

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function notificationTemplate(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
