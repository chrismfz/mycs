<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of the staff's chat (docs/livechat.md §7γ). Plain text as written (ChatText cleans it on the way in and
 * escapes it on the way out); it stays with its author when the member leaves. Not Auditable (F12).
 *
 * @property int $id
 * @property int $thread_id
 * @property int $staff_id
 * @property string $body
 * @property Carbon|null $created_at
 */
class StaffMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['thread_id', 'staff_id', 'body'];

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** @return BelongsTo<StaffThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(StaffThread::class, 'thread_id');
    }
}
