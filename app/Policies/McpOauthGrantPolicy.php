<?php

namespace App\Policies;

use App\Models\McpOauthGrant;
use App\Models\Staff;
use App\Models\User;

/**
 * MCP authorizations (docs/mcp.md): each member of staff sees and revokes their own; who manages the API keys sees and
 * revokes everyone's. Never made or edited here: a consent makes them.
 */
class McpOauthGrantPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && ($user->hasPermission('admin.mcp.use') || $user->hasPermission('admin.api_keys.viewAny'));
    }

    public function view(User|Staff $user, McpOauthGrant $grant): bool
    {
        return $user instanceof Staff && ($grant->staff_id === $user->id || $user->hasPermission('admin.api_keys.viewAny'));
    }

    public function revoke(User|Staff $user, McpOauthGrant $grant): bool
    {
        return $user instanceof Staff && ($grant->staff_id === $user->id || $user->hasPermission('admin.api_keys.update'));
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, McpOauthGrant $grant): bool
    {
        return false;
    }

    public function delete(User|Staff $user, McpOauthGrant $grant): bool
    {
        return false;
    }
}
