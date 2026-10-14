<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
        ];
    }

    /** A full administrator (role with `*`). */
    public function admin(): static
    {
        return $this->state(fn () => ['role_id' => Role::query()->create(['name' => 'Admin ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /** @param  array<int, string>  $permissions */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => ['role_id' => Role::query()->create(['name' => 'Staff ' . uniqid(), 'permissions' => $permissions])->id]);
    }
}
