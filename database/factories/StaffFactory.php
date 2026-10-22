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
            // The admin needs 2FA (security audit, decided 2026-10-22): staff have it set up unless a test says otherwise.
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
        ];
    }

    /** Staff who have not set up 2FA yet (the admin asks them to before anything else). */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn () => ['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null]);
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
