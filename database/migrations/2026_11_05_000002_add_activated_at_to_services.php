<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a service first started (Σ1-b1): a paid one that waited gets its period from that day, once (review R2: a status
 * round trip by staff later must not move its date again). Services so far that are not pending started already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable()->after('expires_at');
        });
        DB::table('services')->where('status', '!=', 'pending')->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('activated_at');
        });
    }
};
