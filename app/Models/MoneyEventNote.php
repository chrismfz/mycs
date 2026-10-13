<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The reason staff gave for a money event (a credit adjustment). Apart from the append-only journal because it is
 * free text: it may hold personal data, and anonymisation (docs/money-trail.md §9) may clear it.
 */
class MoneyEventNote extends Model
{
    protected $fillable = [
        'money_event_id',
        'note',
    ];

    /** @return BelongsTo<MoneyEvent, $this> */
    public function moneyEvent(): BelongsTo
    {
        return $this->belongsTo(MoneyEvent::class);
    }
}
