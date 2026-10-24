<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxmox_guests', function (Blueprint $table) {
            // The pool it is in: MyCS acts only on a guest in its own pool (docs/proxmox.md).
            $table->string('pool')->nullable()->after('node');
            // Its «start at boot» before MyCS suspended it, given back by the unsuspension.
            $table->boolean('onboot_before_suspend')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('proxmox_guests', function (Blueprint $table) {
            $table->dropColumn(['pool', 'onboot_before_suspend']);
        });
    }
};
