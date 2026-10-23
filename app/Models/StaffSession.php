<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One login of a member of staff, in one browser (App\Support\StaffSessions).
 *
 * @property int $id
 * @property string $ulid
 * @property int $staff_id
 * @property ?string $session_id
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property bool $remembered
 * @property ?Carbon $created_at
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $ended_at
 * @property ?int $ended_by_staff_id
 * @property bool $terminated
 */
class StaffSession extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['staff_id', 'session_id', 'ip_address', 'user_agent', 'remembered', 'created_at', 'last_seen_at', 'ended_at', 'ended_by_staff_id', 'terminated'];

    protected $hidden = ['session_id'];

    protected function casts(): array
    {
        return [
            'remembered' => 'boolean',
            'terminated' => 'boolean',
            'created_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
