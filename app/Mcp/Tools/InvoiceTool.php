<?php

namespace App\Mcp\Tools;

use App\Services\Ops\Dossier;
use App\Services\Ops\Lookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('invoice')]
#[Description('Everything about one invoice: status, totals, what is left, due date, lines (what each bills and its period), transactions (gateway, amount, status), mails, audit (fields only). By number, id, or #id (the id for sure). Same as `mycs:invoice`.')]
#[IsReadOnly]
#[IsIdempotent]
class InvoiceTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['invoice' => $schema->string()->description('Invoice number, id, or #id.')->required()];
    }

    protected function permission(): string
    {
        return 'admin.invoices.viewAny';
    }

    protected function run(Request $request): array
    {
        return Dossier::invoice(Lookup::invoice((string) $request->get('invoice')));
    }
}
