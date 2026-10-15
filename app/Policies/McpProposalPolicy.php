<?php

namespace App\Policies;

use App\Models\McpProposal;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mcp\Proposals;

/** MCP proposals (docs/mcp.md): seen by staff who use the MCP; approved or rejected by who may do what each asks. */
class McpProposalPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.mcp.use');
    }

    public function decide(User|Staff $user, McpProposal $proposal): bool
    {
        return $user instanceof Staff && isset(Proposals::PERMISSIONS[$proposal->type]) && $user->hasPermission(Proposals::PERMISSIONS[$proposal->type]);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, McpProposal $proposal): bool
    {
        return false;
    }

    public function delete(User|Staff $user, McpProposal $proposal): bool
    {
        return false;
    }
}
