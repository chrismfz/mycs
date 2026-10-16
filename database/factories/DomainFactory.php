<?php

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Domain> */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        $sld = 'example-' . $this->faker->unique()->numberBetween(1000, 999999);

        return [
            'client_id' => fn () => Client::factory(),
            'sld' => $sld,
            'tld' => 'test',
            'fqdn' => $sld . '.test',
            'status' => DomainStatus::Active,
            'registered_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addMonths(6)->toDateString(),
            'years' => 1,
            'currency_code' => 'USD',
            'auto_renew' => true,
        ];
    }
}
