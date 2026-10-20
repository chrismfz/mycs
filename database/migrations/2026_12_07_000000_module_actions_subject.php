<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a domain's row is about, beyond its action (P1b-2): a contact push's handle. One role's push succeeding closes
 * only its own row, not another handle's still failing (adversarial A8 of #248).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_actions', function (Blueprint $table) {
            $table->string('subject', 191)->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('module_actions', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
