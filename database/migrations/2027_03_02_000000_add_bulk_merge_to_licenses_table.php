<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standalone license moved into a bulk one (docs/zonecloud-bulk-license.md §8): which bulk it joins at its expiry, and
 * when it joined. From then on its key answers as the bulk license.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->foreignId('bulk_license_id')->nullable()->after('max_ips')->constrained('licenses')->nullOnDelete();
            $table->dateTime('merged_at')->nullable()->after('bulk_license_id');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bulk_license_id');
            $table->dropColumn('merged_at');
        });
    }
};
