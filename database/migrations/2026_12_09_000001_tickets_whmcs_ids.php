<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets, PR Δ (docs/tickets.md §5α): the WHMCS ticket import is re-runnable. A ticket keeps its WHMCS id, a message
 * where it came from (ticket:ID its opening, reply:ID, note:ID, merged:ID); a re-run finds them and touches nothing made
 * in MyCS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('whmcs_id')->nullable()->unique();
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('whmcs_ref', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropUnique(['whmcs_ref']);
            $table->dropColumn('whmcs_ref');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['whmcs_id']);
            $table->dropColumn('whmcs_id');
        });
    }
};
