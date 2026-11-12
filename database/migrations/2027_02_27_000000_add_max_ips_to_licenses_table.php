<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many IPs a customer may keep on a bulk license without staff (Ε4, docs/zonecloud-bulk-license.md §6.0): its
 * product's «max_ips» (200), or this one set by staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->unsignedInteger('max_ips')->nullable()->after('tiers');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', fn (Blueprint $table) => $table->dropColumn('max_ips'));
    }
};
