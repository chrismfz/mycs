<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\Proposals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propose_retry_module_action')]
#[Description('PROPOSE (does not run) retrying a server module action that was given up (see `module_actions`): once staff approve it in the admin, the action runs on the server now, as the admin\'s Retry, with its mail. Only for actions the queue gave up on. Returns the proposal\'s id; nothing changes until approved.')]
class ProposeRetryModuleActionTool extends ProposeTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['module_action_id' => $schema->integer()->description('The module action\'s id (from `module_actions` or `service`).')->required()];
    }

    protected function type(): string
    {
        return Proposals::RETRY_MODULE_ACTION;
    }

    protected function payload(Request $request): array
    {
        return ['module_action_id' => (int) $request->get('module_action_id')];
    }
}
