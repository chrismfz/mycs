<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mod_licensinglog row an access log row was imported from (L3): a re-run of the import updates its own rows and
 * never touches the ones MyCS's zlic.php wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_access_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('whmcs_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('license_access_logs', function (Blueprint $table) {
            $table->dropUnique(['whmcs_id']);
            $table->dropColumn('whmcs_id');
        });
    }
};
