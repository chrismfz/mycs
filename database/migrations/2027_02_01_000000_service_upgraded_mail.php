<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/** The «service changed» mail (an upgrade or downgrade applied, owner 2026-11-03), on instances seeded before it. */
return new class extends Migration
{
    public function up(): void
    {
        EmailTemplateSeeder::seedKeys(['service_upgraded']);
    }
};
