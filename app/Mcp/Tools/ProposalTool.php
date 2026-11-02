<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\McpAuth;
use App\Models\McpProposal;
use App\Models\Staff;
use App\Services\Mcp\Proposals;
use App\Services\Ops\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('proposal')]
#[Description('The outcome of a proposal (propose_*): pending, running, done (with its result), failed (why), rejected or expired. Yours, and those your role may decide. Without an id: the latest.')]
#[IsReadOnly]
#[IsIdempotent]
class ProposalTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['proposal_id' => $schema->integer()->description('The id a propose_* tool returned.')];
    }

    protected function permission(): string
    {
        return McpAuth::PERMISSION;
    }

    protected function run(Request $request): array
    {
        $staff = $request->user();
        // Theirs, and those they may decide: a request asked again is the same proposal (review R1).
        $visible = Proposals::visibleTo(McpProposal::query(), $staff instanceof Staff ? $staff : null);
        $staff = $staff instanceof Staff ? $staff : null;
        if ($request->get('proposal_id')) {
            $proposal = (clone $visible)->find((int) $request->get('proposal_id'));
            if ($proposal === null || !Proposals::followable($proposal, $staff)) {
                throw new NotFound('No such proposal you may follow.');
            }

            return ProposeTool::describe($proposal);
        }

        // Only those they may decide (or asked): never a domain's action for service-only staff (#584 review, F1).
        return ['proposals' => $visible->latest('id')->limit(100)->get()->filter(fn (McpProposal $proposal) => Proposals::followable($proposal, $staff))
            ->take(20)->map(fn (McpProposal $proposal) => ProposeTool::describe($proposal))->values()->all()];
    }
}
