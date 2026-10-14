<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'credit_auto_apply' => true,
        ];
    }

    /** With an owner (a new user, or the one given). */
    public function ownedBy(?User $owner = null): static
    {
        return $this->afterCreating(function (Client $client) use ($owner) {
            $owner ??= User::factory()->withoutClient()->create();
            ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $owner->id, 'owner' => true,
                'owner_of_client_id' => $client->id, 'granted_at' => now()]);
            if (!$client->primaryContact()->exists()) {
                $client->contacts()->make(['first_name' => $owner->first_name, 'last_name' => $owner->last_name, 'email' => $owner->email])
                    ->forceFill(['primary_of_client_id' => $client->id])->save();
            }
        });
    }
}
