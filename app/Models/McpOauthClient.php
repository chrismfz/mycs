<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** An MCP client that registered itself (RFC 7591): public, PKCE only (docs/mcp.md). */
class McpOauthClient extends Model
{
    protected $fillable = ['client_id', 'name', 'redirect_uris', 'registered_ip'];

    protected $casts = ['redirect_uris' => 'array'];

    public function grants(): HasMany
    {
        return $this->hasMany(McpOauthGrant::class, 'client_id');
    }
}
