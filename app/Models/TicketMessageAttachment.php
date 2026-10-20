<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use MyCS\TicketMail\Attachments;

class TicketMessageAttachment extends Model
{
    protected $fillable = [
        'path',
        'filename',
        'filesize',
        'mime_type',
        'ticket_message_id',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    /** @return BelongsTo<TicketMessage, $this> */
    public function ticketMessage(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class);
    }

    public function getLocalPathAttribute(): string
    {
        return storage_path("app/{$this->path}");
    }

    /** An image a page may show (never SVG: it can carry script). */
    public function canPreview(): bool
    {
        return in_array($this->mime_type, Attachments::PREVIEWABLE, true);
    }
}
