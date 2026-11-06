<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A blank subscription id (spaces) is none (Service::subscriptionId): one stored before is cleared, so the SQL and PHP
 * checks agree on every row, on MariaDB as on SQLite. The live instance had none (2026-11-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('services')->whereNotNull('subscription_id')->whereRaw("TRIM(subscription_id) = ''")->update(['subscription_id' => null]);
    }
};
