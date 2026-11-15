<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which endpoint a license's last check came through (docs/licensing.md): v0 for zlic.php, v1 for /licensing/v1/check.
 * Who moved to the new releases, at a glance in the licenses console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('last_endpoint', 4)->nullable()->after('last_seen_client');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('last_endpoint');
        });
    }
};
