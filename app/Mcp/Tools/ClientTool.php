<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\LoginAttempt;
use App\Models\Staff;
use App\Models\UserAuthenticationLog;
use App\Services\Ops\Dossier;
use App\Services\Ops\Lookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('client')]
#[Description('Everything about one account: name, email, login, credit, its services (status, product, domain, expiry), last invoices, open invoices, mails, audit (fields only), and (to staff who may see the Login History) the addresses its logins signed in from, for "a firewall blocked the customer". By id or email (a primary contact\'s, a login\'s, then any contact\'s; several accounts are named). Same as `mycs:client`.')]
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

    /** The recent sign-ins listed, and the addresses its logins came from. */
    public const ADDRESSES = 20;

    protected function run(Request $request): array
    {
        $client = Lookup::client((string) $request->get('client'));
        $data = Dossier::client($client);
        // Where its logins came from (an address the firewall may have blocked: «I cannot see my site»), to staff who may
        // see the Login History, as in the admin (LoginAttemptPolicy).
        $staff = $request->user();
        $data['addresses'] = $staff instanceof Staff && $staff->hasPermission('admin.audit.viewAny') ? self::addresses($client) : null;

        return $data;
    }

    /** @return array{sign_ins: list<array<string, mixed>>, known: list<array<string, mixed>>} */
    private static function addresses(Client $client): array
    {
        $users = ClientUser::query()->where('client_id', $client->id)->whereNull('revoked_at')->pluck('user_id');

        return [
            'sign_ins' => LoginAttempt::query()->where('area', LoginAttempt::CLIENT)->whereIn('user_id', $users)->latest('id')->limit(self::ADDRESSES)->get()
                ->map(fn (LoginAttempt $attempt) => ['at' => $attempt->created_at?->toDateTimeString(), 'user_id' => $attempt->user_id,
                    'ip' => $attempt->ip_address, 'result' => $attempt->result])->all(),
            'known' => UserAuthenticationLog::query()->whereIn('user_id', $users)->latest('last_used_at')->limit(self::ADDRESSES)->get()
                ->map(fn (UserAuthenticationLog $log) => ['user_id' => $log->user_id, 'ip' => $log->ip_address, 'last_used' => (string) $log->last_used_at])->all(),
        ];
    }
}
