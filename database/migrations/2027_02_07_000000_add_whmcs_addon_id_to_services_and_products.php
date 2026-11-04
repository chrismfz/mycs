<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS's product addons, imported (docs/addons.md, step 4): `tblhostingaddons` ids collide with `tblhosting`'s, so an addon's
 * service takes a new id and keeps its own here; an addon product (`tbladdons`) keeps its, as a product keeps `whmcs_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('whmcs_addon_id')->nullable()->unique()->after('parent_service_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('whmcs_addon_id')->nullable()->unique()->after('whmcs_id');
        });
    }
};
