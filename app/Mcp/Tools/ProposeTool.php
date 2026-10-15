<?php

namespace App\Mcp\Tools;

use App\Admin\Resources\McpProposalResource;
use App\Models\McpProposal;
use App\Models\Staff;
use App\Services\Mcp\Proposals;
use Laravel\Mcp\Request;

/**
 * A tool that only proposes (docs/mcp.md): it checks the request and files it for staff to approve or reject in the
 * admin within a day; nothing changes until they do. The answer says so, with the proposal's id and where to approve.
 */
abstract class ProposeTool extends McpTool
{
    abstract protected function type(): string;

    /** @return array<string, mixed> */
    abstract protected function payload(Request $request): array;

    protected function permission(): string
    {
        return Proposals::permission($this->type());
    }

    protected function run(Request $request): array
    {
        $staff = $request->user();
        if (!$staff instanceof Staff) {
            throw new \RuntimeException('Not a member of staff.');
        }
        $proposal = Proposals::propose($this->type(), $this->payload($request), $staff);

        return [
            'proposed' => true,
            'done' => false,
            'proposal_id' => $proposal->id,
            'status' => $proposal->status,
            'summary' => $proposal->summary,
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'approve_at' => McpProposalResource::getUrl('index'),
            'note' => 'Nothing has changed yet: a member of staff approves or rejects this in the admin (Other → MCP Proposals). Ask `proposal` for its outcome.',
        ];
    }

    public static function describe(McpProposal $proposal): array
    {
        return ['proposal_id' => $proposal->id, 'type' => $proposal->type, 'status' => $proposal->status, 'summary' => $proposal->summary,
            'result' => $proposal->result, 'decided_at' => $proposal->decided_at?->toIso8601String(), 'expires_at' => $proposal->expires_at->toIso8601String()];
    }
}
