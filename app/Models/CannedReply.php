<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A predefined reply staff put into a ticket's answer (docs/tickets.md §6ι). Its body may carry {{tokens}}
 * (CannedReplyExpander), filled in when inserted; staff read and send it as any reply.
 */
class CannedReply extends Model
{
    protected static function booted(): void
    {
        // Imported from WHMCS and deleted here: a re-run of the import never brings it back.
        static::deleted(function (CannedReply $record) {
            if ($record->whmcs_id !== null) {
                DB::table('ticket_whmcs_tombstones')->insertOrIgnore(['ref' => 'canned:' . $record->whmcs_id, 'created_at' => now()]);
            }
        });
    }

    protected $fillable = ['canned_reply_category_id', 'title', 'body', 'sort', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** @return BelongsTo<CannedReplyCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CannedReplyCategory::class, 'canned_reply_category_id');
    }

    /** @return array<string, array<int, string>> the active replies by category, for a select */
    public static function options(): array
    {
        $options = [];
        self::query()->where('is_active', true)->with('category')->get()
            ->sortBy(fn (CannedReply $reply) => [$reply->category->sort ?? PHP_INT_MAX, $reply->category->name ?? '', $reply->sort, $reply->title])
            ->each(function (CannedReply $reply) use (&$options) {
                $options[$reply->category->name ?? __('General')][$reply->id] = $reply->title;
            });

        return $options;
    }
}
