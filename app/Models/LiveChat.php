<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A live chat with a customer (docs/livechat.md §6, step 3α): waiting until an operator of its department takes it,
 * then active, then closed. Its state changes only through App\Support\LiveChats. Not Auditable: no message body is
 * ever copied into the audit log (F12).
 *
 * @property int $id
 * @property int|null $client_id
 * @property int|null $user_id
 * @property int|null $department_id
 * @property string $status
 * @property int|null $assigned_to
 * @property Carbon|null $last_participant_message_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 */
class LiveChat extends Model
{
    public const WAITING = 'waiting';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    protected $fillable = ['client_id', 'user_id', 'department_id', 'status', 'assigned_to', 'last_participant_message_at', 'closed_at'];

    protected function casts(): array
    {
        return ['last_participant_message_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status !== self::CLOSED;
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TicketDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'department_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_to');
    }

    /** @return HasMany<LiveChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(LiveChatMessage::class, 'chat_id');
    }
}
