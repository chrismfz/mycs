<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A mail MyCS sent, or tried to (docs/mail.md): its body as the log keeps it (secrets masked), who it went to and from,
 * which template and about what, who sent it, and its way out: pending (queued) → sending → sent, or failed.
 *
 * @property int $id
 * @property int|null $client_id
 * @property string $subject
 * @property string $to
 * @property string $body
 * @property string $status
 * @property string|null $error
 * @property string|null $from_address
 * @property list<string>|null $cc
 * @property list<string>|null $bcc
 * @property string|null $template_key
 * @property string|null $related_type
 * @property int|null $related_id
 * @property string $trigger
 * @property int|null $staff_id
 * @property int|null $resent_from_id
 * @property int $attempts
 * @property string|null $message_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $sending_at
 * @property Carbon|null $failed_at
 * @property Staff|null $staff
 */
class EmailLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_BATCH = 'batch';

    protected $fillable = [
        'client_id',
        'subject',
        'body',
        'to',
        'sent_at',
        'status',
        'error',
        'job_uuid',
        'from_address',
        'cc',
        'bcc',
        'template_key',
        'related_type',
        'related_id',
        'trigger',
        'staff_id',
        'resent_from_id',
        'attempts',
        'sending_at',
        'failed_at',
        'message_id',
    ];

    protected $casts = [
        'cc' => 'array',
        'bcc' => 'array',
        'sent_at' => 'datetime',
        'sending_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** What the mail was about: an invoice, a service, an order or a ticket. */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** Staff who resent it (null: the system sent it). */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function resentFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resent_from_id');
    }

    /** «Invoice #12», «Service #3» … for lists. */
    public function relatedLabel(): ?string
    {
        if ($this->related_type === null || $this->related_id === null) {
            return null;
        }

        return class_basename(Model::getActualClassNameForMorph($this->related_type)) . ' #' . $this->related_id;
    }
}
