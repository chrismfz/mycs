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

#[Name('service')]
#[Description('Everything about one service: status, product, plan, price, server, dates (created, activated, expires, holds), order, cancellation request, license (masked key, bindings, last good check, last failure), its last invoices, module actions with their errors, mails, audit (fields only). By id, license key or domain. Same as `mycs:service`.')]
#[IsReadOnly]
#[IsIdempotent]
class ServiceTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['service' => $schema->string()->description('Service id, license key, or domain.')->required()];
    }

    protected function permission(): string
    {
        return 'admin.services.viewAny';
    }

    protected function run(Request $request): array
    {
        return Dossier::service(Lookup::service((string) $request->get('service')));
    }
}
