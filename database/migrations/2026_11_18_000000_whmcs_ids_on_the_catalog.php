<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WHMCS ids of the catalog (`app:import-whmcs-catalog`): a re-run updates what it brought instead of adding it
 * again, and nothing already here is renumbered or taken over. Config options keep «option:N» or «value:N»: WHMCS
 * numbers its options and their values apart.
 */
return new class extends Migration
{
    private const TABLES = ['categories', 'products', 'extensions', 'server_groups', 'domain_tlds'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedInteger('whmcs_id')->nullable()->unique();
            });
        }
        Schema::table('config_options', function (Blueprint $table) {
            $table->string('whmcs_ref', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        // The unique index first: SQLite does not drop an indexed column (review 13).
        Schema::table('config_options', function (Blueprint $table) {
            $table->dropUnique(['whmcs_ref']);
            $table->dropColumn('whmcs_ref');
        });
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['whmcs_id']);
                $table->dropColumn('whmcs_id');
            });
        }
    }
};
