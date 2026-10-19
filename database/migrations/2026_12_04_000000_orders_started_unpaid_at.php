<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When staff's Accept started a service of the order not paid yet (owner 2026-10-19: Accept is staff's look at what came
 * in, and leaves a service waiting for its payment unless staff ask). The cron's «cancel unpaid orders» leaves those
 * orders alone, as it left every accepted one before (decision 4, ADV-4: their create may still be failing). Accepted
 * before this: Accept started everything then, so the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('started_unpaid_at')->nullable()->after('accepted_by_staff_id');
        });
        DB::table('orders')->whereNotNull('accepted_at')->update(['started_unpaid_at' => DB::raw('accepted_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('started_unpaid_at');
        });
    }
};
