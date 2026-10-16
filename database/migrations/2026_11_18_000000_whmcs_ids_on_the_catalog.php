<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WHMCS ids of the catalog (`app:import-whmcs-catalog`): a re-run updates what it brought instead of adding it
 * again, and nothing already here is renumbered. Config options keep «option:N» or «value:N»: WHMCS numbers its
 * options and their values apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'products', 'extensions', 'server_groups'] as $table) {
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
        Schema::table('config_options', fn (Blueprint $table) => $table->dropColumn('whmcs_ref'));
        foreach (['categories', 'products', 'extensions', 'server_groups'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('whmcs_id'));
        }
    }
};
