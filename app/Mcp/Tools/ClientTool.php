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

#[Name('client')]
#[Description('Everything about one account: name, email, login, credit, its services (status, product, domain, expiry), last invoices, open invoices, mails, audit (fields only). By id or email (a primary contact\'s, a login\'s, then any contact\'s; several accounts are named). Same as `mycs:client`.')]
#[IsReadOnly]
#[IsIdempotent]
class ClientTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['client' => $schema->string()->description('Account id, or an email.')->required()];
    }

    protected function permission(): string
    {
        return 'admin.clients.viewAny';
    }

    protected function run(Request $request): array
    {
        return Dossier::client(Lookup::client((string) $request->get('client')));
    }
}
