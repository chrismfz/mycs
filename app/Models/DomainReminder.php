<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An expiry notice sent (docs/domains.md D-14): one per domain, days and expiry, so none goes twice. Negative days are
 * after the expiry.
 *
 * @property int $id
 * @property int $domain_id
 * @property int $days
 * @property Carbon $expires_on
 * @property Carbon|null $sent_at
 */
class DomainReminder extends Model
{
    protected $fillable = ['domain_id', 'days', 'expires_on', 'sent_at'];

    protected function casts(): array
    {
        return ['days' => 'integer', 'expires_on' => 'date', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
