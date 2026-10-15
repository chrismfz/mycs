<?php

namespace App\Models;

/** A one-minute, single-use authorization code a consent minted (docs/mcp.md). Only its hash is kept. */
class McpOauthCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['code_hash', 'client_id', 'staff_id', 'api_key_id', 'redirect_uri', 'code_challenge', 'expires_at', 'used_at', 'created_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
}
