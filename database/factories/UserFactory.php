<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->name(),
            'last_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    /**
     * As registration does: the user owns an account (docs/accounts.md, PR 2a). Its id is far from the user's, so a
     * test never passes by a user id standing in for an account id.
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null)
    {
        parent::callAfterCreating($instances, $parent);
        if ($this->withClient) {
            $instances->each(fn (User $user) => Client::openFor($user, $user->id + self::CLIENT_ID_OFFSET));
        }
    }

    public const CLIENT_ID_OFFSET = 100000;

    protected bool $withClient = true;

    /** A login without any account (WHMCS users without a client). */
    public function withoutClient(): static
    {
        $factory = $this->newInstance();
        $factory->withClient = false;

        return $factory;
    }

    protected function newInstance(array $arguments = [])
    {
        $factory = parent::newInstance($arguments);
        $factory->withClient = $this->withClient;

        return $factory;
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
