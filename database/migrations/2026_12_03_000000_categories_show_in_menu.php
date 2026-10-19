<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which categories the shop menu and the home page list (docs/admin-ux.md, «Μενού επισκέπτη»): WHMCS «Hidden» on a
 * product group is the opposite. A category not in the menu still opens by its link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('show_in_menu')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('show_in_menu');
        });
    }
};
