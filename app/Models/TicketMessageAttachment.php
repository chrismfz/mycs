<?php

namespace App\Models;

use App\Support\Tickets\TicketFiles;
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

    /** The file on disk, only inside the tickets' own folder: a path that leads anywhere else (`..`, a link) has none. */
    public function getLocalPathAttribute(): ?string
    {
        return self::inFolder((string) $this->path);
    }

    /** The real file a stored path names, when it is under tickets/uploads; null otherwise (or when it is missing). */
    public static function inFolder(string $path): ?string
    {
        $root = realpath(storage_path('app/' . TicketFiles::DIRECTORY));
        $file = realpath(storage_path('app/' . $path));

        return $root !== false && $file !== false && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? $file : null;
    }

    /** An image a page may show (never SVG: it can carry script). */
    public function canPreview(): bool
    {
        return in_array($this->mime_type, Attachments::PREVIEWABLE, true);
    }
}
