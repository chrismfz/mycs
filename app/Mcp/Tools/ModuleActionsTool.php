<?php

namespace App\Mcp\Tools;

use App\Models\ModuleAction;
use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('module_actions')]
#[Description('Server module actions (create, suspend, unsuspend, terminate, change package) still open or given up — the admin\'s «Pending Module Actions»: the service, the action, attempts, when it was given up, the last error (secrets masked). For "the account was not created / suspended on the server".')]
#[IsReadOnly]
#[IsIdempotent]
class ModuleActionsTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'given_up_only' => $schema->boolean()->description('Only those given up (need staff).'),
            'limit' => $schema->integer()->description('How many (default 50, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.services.viewAny';
    }

    protected function run(Request $request): array
    {
        $query = ModuleAction::query()->where('status', ModuleAction::STATUS_OPEN)->latest('id')
            ->when($request->get('given_up_only'), fn ($query) => $query->whereNotNull('given_up_at'));

        return ['actions' => $query->limit(self::limit($request, 50, 200))->get()->map(fn (ModuleAction $action) => [
            'id' => $action->id, 'service_id' => $action->service_id, 'action' => $action->action, 'attempts' => $action->attempts,
            'given_up' => $action->given_up_at ? (string) $action->given_up_at : null, 'error' => Scrub::text($action->last_error),
            'updated' => $action->updated_at?->toDateTimeString(),
        ])->all()];
    }
}
