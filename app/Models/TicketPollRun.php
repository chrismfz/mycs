<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One read of a department's mailbox (docs/tickets.md A2): what it did, for the health check and staff. */
class TicketPollRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['department_id', 'started_at', 'finished_at', 'connected', 'fetched', 'threaded', 'opened', 'quarantined', 'skipped', 'errors'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'connected' => 'boolean',
        'errors' => 'array',
    ];

    /** @return BelongsTo<TicketDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class);
    }
}
