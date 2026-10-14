<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The account's details (phone, company, address, city, state, postcode, country) are no longer custom properties:
 * they are the primary contact's columns (docs/accounts.md, PR 3a). Custom properties remain for extra fields staff
 * add; none is seeded.
 */
class CustomPropertySeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
