<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One MCP tool call (docs/mcp.md): who, which tool, its scrubbed arguments, how it ended. */
class McpCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['staff_id', 'api_key_id', 'tool', 'arguments', 'outcome', 'error', 'duration_ms', 'ip', 'created_at'];

    protected $casts = ['arguments' => 'array', 'created_at' => 'datetime'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
