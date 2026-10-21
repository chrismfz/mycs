<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A mass mail (ROADMAP 6δ): staff's message to the accounts its filters find (BulkMailer), each once, off the request.
 * «important» is always sent (a server moving, the network changing); «news» only to those who did not turn news off.
 *
 * @property int $id
 * @property string $name
 * @property string $kind
 * @property string $subject
 * @property string $body
 * @property string|null $subject_el
 * @property string|null $body_el
 * @property array<string, mixed>|null $filters
 * @property string $status
 * @property int $recipients_count
 */
class BulkMail extends Model implements Auditable
{
    use Traits\Auditable;

    public const KIND_IMPORTANT = 'important';

    public const KIND_NEWS = 'news';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    protected $fillable = ['name', 'kind', 'subject', 'body', 'subject_el', 'body_el', 'filters'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    /** The notification template that is its layout and, for news, the customer's preference. */
    public function templateKey(): string
    {
        return $this->kind === self::KIND_NEWS ? 'announcement_news' : 'announcement';
    }

    /** @return HasMany<BulkMailRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(BulkMailRecipient::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
