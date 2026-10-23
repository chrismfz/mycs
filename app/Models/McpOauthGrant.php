<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A member of staff's approval of an MCP client (docs/mcp.md): its one access token and one refresh token (hashes),
 * revoked by staff, by a replayed refresh token, or with the staff member or the key it was approved with.
 *
 * @property int $id
 * @property int $client_id
 * @property int $staff_id
 * @property int|null $api_key_id
 * @property string $approved_by
 * @property Carbon|null $access_expires_at
 * @property Carbon|null $refresh_expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property-read McpOauthClient $client
 * @property-read Staff $staff
 * @property-read ApiKey|null $apiKey
 */
class McpOauthGrant extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['client_id', 'staff_id', 'api_key_id', 'approved_by', 'access_hash', 'access_expires_at', 'refresh_hash',
        'previous_refresh_hash', 'refresh_expires_at', 'last_used_at', 'last_ip', 'revoked_at', 'revoked_reason', 'staff_session'];

    protected $hidden = ['access_hash', 'refresh_hash', 'previous_refresh_hash'];

    /** The token hashes and their use are not the audit's: approvals and revocations are. */
    protected $auditExclude = ['access_hash', 'access_expires_at', 'refresh_hash', 'previous_refresh_hash', 'refresh_expires_at', 'last_used_at', 'last_ip'];

    protected $casts = ['access_expires_at' => 'datetime', 'refresh_expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpOauthClient::class, 'client_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function revoke(string $reason): void
    {
        if ($this->revoked_at === null) {
            $this->update(['revoked_at' => now(), 'revoked_reason' => mb_substr($reason, 0, 100), 'access_hash' => null, 'refresh_hash' => null]);
        }
    }
}
