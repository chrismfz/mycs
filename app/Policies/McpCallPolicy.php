<?php

namespace App\Policies;

use App\Models\McpCall;
use App\Models\Staff;
use App\Models\User;

/** The MCP calls' record is read only (docs/mcp.md): written by the tools, seen by who sees the API keys. */
class McpCallPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.api_keys.viewAny');
    }

    public function view(User|Staff $user, McpCall $call): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, McpCall $call): bool
    {
        return false;
    }

    public function delete(User|Staff $user, McpCall $call): bool
    {
        return false;
    }
}
