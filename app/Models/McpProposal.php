<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A change an MCP agent proposed (docs/mcp.md), for staff to approve or reject in the admin (App\Services\Mcp\Proposals).
 *
 * @property int $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property int|null $proposed_by
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $result
 * @property Carbon $expires_at
 * @property-read Staff|null $proposer
 * @property-read Staff|null $decider
 */
class McpProposal extends Model implements Auditable
{
    use Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved'; // claimed, running

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['type', 'payload', 'summary', 'proposed_by', 'status', 'decided_by', 'decided_at', 'result', 'expires_at'];

    protected $casts = ['payload' => 'array', 'decided_at' => 'datetime', 'expires_at' => 'datetime'];

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'proposed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
