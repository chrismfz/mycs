<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the job of a failed server action was asked for besides the action (sweep 3, L-NOTIFICATIONS-2): a terminate or a
 * create staff chose to make silent («no email»), a welcome mail sent by staff's hand. Staff's Retry runs it again with
 * the same, not with the defaults (that mail the customer). Null: the defaults (rows made before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_actions', function (Blueprint $table) {
            $table->json('job_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('module_actions', function (Blueprint $table) {
            $table->dropColumn('job_options');
        });
    }
};
