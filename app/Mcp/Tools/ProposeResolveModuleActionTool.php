<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\Proposals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propose_resolve_module_action')]
#[Description('PROPOSE (does not run) marking an open server module action resolved without running anything — for an account already fixed by hand on the server. Once staff approve it in the admin it closes (a create: the service turns active). Returns the proposal\'s id; nothing changes until approved.')]
class ProposeResolveModuleActionTool extends ProposeTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['module_action_id' => $schema->integer()->description('The module action\'s id.')->required()];
    }

    protected function type(): string
    {
        return Proposals::RESOLVE_MODULE_ACTION;
    }

    protected function payload(Request $request): array
    {
        return ['module_action_id' => (int) $request->get('module_action_id')];
    }
}
