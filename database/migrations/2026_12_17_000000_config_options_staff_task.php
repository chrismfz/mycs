<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An option staff do by hand (owner 2026-10-21: a dedicated IP, an extra IPv4 on a VPS): its service's taking it, or
 * giving it back, opens a task for staff (OptionTasks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_options', function (Blueprint $table) {
            $table->boolean('staff_task')->default(false)->after('hidden');
        });
    }

    public function down(): void
    {
        Schema::table('config_options', function (Blueprint $table) {
            $table->dropColumn('staff_task');
        });
    }
};
