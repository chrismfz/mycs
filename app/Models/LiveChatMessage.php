<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of a live chat (docs/livechat.md §6): from the customer's login, from an operator, from the system,
 * or an operator's internal note.
 * Plain text as written (ChatText cleans it in and escapes it out). Not Auditable (F12).
 *
 * @property int $id
 * @property int $chat_id
 * @property string $author_type
 * @property int|null $user_id
 * @property int|null $staff_id
 * @property string $body
 * @property Carbon|null $created_at
 */
class LiveChatMessage extends Model
{
    public const CLIENT = 'client';

    public const STAFF = 'staff';

    public const SYSTEM = 'system';

    /** An operator's internal note (step 3β): the staff see it, the customer never does (F20). */
    public const NOTE = 'note';

    /** What the customer may ever read: an allowlist, so a new kind of message stays the staff's until it is added here. */
    public const CUSTOMER_VISIBLE = [self::CLIENT, self::STAFF];

    public const UPDATED_AT = null;

    protected $fillable = ['chat_id', 'author_type', 'user_id', 'staff_id', 'body'];

    /** @return BelongsTo<LiveChat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(LiveChat::class, 'chat_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
