<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A thread of the staff's own chat (docs/livechat.md §7γ): the shared room, or later a private thread of two. Not
 * Auditable: no message body is ever copied into the audit log (F12).
 *
 * @property int $id
 * @property string $kind
 * @property string $direct_key
 */
class StaffThread extends Model
{
    public const ROOM = 'room';

    protected $fillable = ['kind', 'direct_key'];

    /** @return HasMany<StaffMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(StaffMessage::class, 'thread_id');
    }
}
