<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\McpAuth;
use App\Models\McpProposal;
use App\Models\Staff;
use App\Services\Ops\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('proposal')]
#[Description('The outcome of a proposal you made (propose_*): pending, done (with its result), failed (why), rejected or expired. Without an id: your latest proposals.')]
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
        $mine = McpProposal::query()->where('proposed_by', $staff instanceof Staff ? $staff->id : 0);
        if ($request->get('proposal_id')) {
            return ProposeTool::describe((clone $mine)->find((int) $request->get('proposal_id')) ?? throw new NotFound('No such proposal of yours.'));
        }

        return ['proposals' => $mine->latest('id')->limit(20)->get()->map(fn (McpProposal $proposal) => ProposeTool::describe($proposal))->all()];
    }
}
