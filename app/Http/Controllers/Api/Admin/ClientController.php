<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\Clients\CreateClientRequest;
use App\Http\Requests\Api\Admin\Clients\DeleteClientRequest;
use App\Http\Requests\Api\Admin\Clients\GetClientRequest;
use App\Http\Requests\Api\Admin\Clients\GetClientsRequest;
use App\Http\Requests\Api\Admin\Clients\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

/** The customer accounts (docs/accounts.md, PR 2a), WHMCS «Clients». */
#[Group(name: 'Clients', weight: 1)]
class ClientController extends ApiController
{
    protected const INCLUDES = [
        'users',
        'properties',
        'orders',
        'services',
        'invoices',
        'tickets',
        'credits',
    ];

    /**
     * List Clients
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetClientsRequest $request)
    {
        $clients = QueryBuilder::for(Client::query()->with(['primaryContact', 'ownerGrant.user']))
            ->allowedFilters('id')
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->allowedSorts('id', 'created_at')
            ->simplePaginate(min(max((int) request('per_page', 15), 1), 100));

        return ClientResource::collection($clients);
    }

    /**
     * Create a client with its owner: an existing user (`user_id`) or a new one.
     */
    public function store(CreateClientRequest $request)
    {
        $data = $request->validated();
        $client = DB::transaction(function () use ($data) {
            $owner = isset($data['user_id'])
                ? User::query()->findOrFail($data['user_id'])
                : User::query()->create(['first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null,
                    'email' => $data['email'], 'password' => $data['password']]);
            $client = Client::openFor($owner);
            if (array_key_exists('credit_auto_apply', $data)) {
                $client->forceFill(['credit_auto_apply' => (bool) $data['credit_auto_apply']])->save();
            }

            return $client;
        });

        return new ClientResource($this->loadAllowedIncludes($client, self::INCLUDES));
    }

    /**
     * Show a specific client
     */
    public function show(GetClientRequest $request, Client $client)
    {
        $client = QueryBuilder::for(Client::class)
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->findOrFail($client->id);

        return new ClientResource($client);
    }

    /**
     * Update a specific client
     */
    public function update(UpdateClientRequest $request, Client $client)
    {
        $client->update($request->validated());

        return new ClientResource($this->loadAllowedIncludes($client, self::INCLUDES));
    }

    /**
     * Delete a specific client
     */
    public function destroy(DeleteClientRequest $request, Client $client)
    {
        // An account with money history is never deleted (ClientObserver::deleting, docs/money-trail.md §8).
        if ($client->hasMoneyHistory()) {
            abort(422, 'A customer with payments or account credit history cannot be deleted.');
        }
        $client->delete();

        return $this->returnNoContent();
    }
}
