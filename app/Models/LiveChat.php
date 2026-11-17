<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A live chat with a customer (docs/livechat.md §6, step 3α) or an anonymous visitor (step 4): waiting until an
 * operator of its department takes it, then active, then closed. Its state changes only through App\Support\LiveChats
 * and App\Support\ChatVisitor. Not Auditable: no message body is ever copied into the audit log (F12).
 *
 * A visitor's chat has no client: it is reached only by the token in the visitor's server session, kept here hashed
 * (§3 Γ). The name and email are the visitor's word, never vouched for (§3 Δ); src and page only a label (§4).
 *
 * @property int $id
 * @property int|null $client_id
 * @property int|null $user_id
 * @property int|null $department_id
 * @property string $status
 * @property string|null $visitor_token_hash
 * @property bool $started_anonymously
 * @property string|null $visitor_name
 * @property string|null $visitor_email
 * @property string|null $ip
 * @property string|null $src
 * @property string|null $page
 * @property Carbon|null $login_requested_at
 * @property int|null $handoff_user_id
 * @property Carbon|null $handoff_at
 * @property int|null $continued_from
 * @property int|null $linked_by
 * @property Carbon|null $linked_at
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

    protected $fillable = ['client_id', 'user_id', 'department_id', 'status', 'assigned_to', 'last_participant_message_at', 'closed_at',
        'started_anonymously', 'visitor_name', 'visitor_email', 'ip', 'src', 'page'];

    /** Never in an array, JSON or a Livewire snapshot (F7): the hash is the key to the chat's only proof. */
    protected $hidden = ['visitor_token_hash'];

    protected function casts(): array
    {
        return ['last_participant_message_at' => 'datetime', 'closed_at' => 'datetime', 'started_anonymously' => 'boolean',
            'login_requested_at' => 'datetime', 'handoff_at' => 'datetime', 'linked_at' => 'datetime'];
    }

    /** An anonymous visitor's chat, not (or not yet) linked to a client. */
    public function isVisitor(): bool
    {
        return $this->client_id === null && $this->started_anonymously;
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
