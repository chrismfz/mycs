<?php

namespace App\Mcp\Tools;

use App\Services\Ops\Charges;
use App\Services\Ops\Lookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('charges')]
#[Description('The automatic charges of saved payment methods, newest first, across accounts: when, source (cron, client, change, group), the method\'s name and gateway, amount, status (started, succeeded, failed, unknown, action_required), error, and an alert for what needs staff. Above them, a count of each status and the invoices whose money was taken but no payment recorded since, whose outcome is unknown, or that were charged more than once. Filters: status (or taken_not_recorded), account, invoice, days. For "why was this customer charged / not charged / charged twice". Never a method\'s token. Same as `mycs:charges`. Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class ChargesTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Only this status: started, succeeded, failed, unknown, action_required, or taken_not_recorded (succeeded, no payment recorded an hour later).'),
            'client' => $schema->string()->description('Only this account (id or email).'),
            'invoice' => $schema->string()->description('Only this invoice (number, id, or #id).'),
            'days' => $schema->integer()->description('Days back (default 7, at most 90).'),
            'limit' => $schema->integer()->description('How many attempts (default 30, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.invoices.viewAny';
    }

    protected function run(Request $request): array
    {
        return Charges::recent(
            filled($request->get('status')) ? (string) $request->get('status') : null,
            filled($request->get('client')) ? Lookup::client((string) $request->get('client')) : null,
            filled($request->get('invoice')) ? Lookup::invoice((string) $request->get('invoice')) : null,
            max(1, min(90, (int) ($request->get('days') ?? 7))),
            self::limit($request, 30, 200),
        );
    }
}
