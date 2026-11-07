<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A department reads mail sent from this moment on (sweep 2, F-SUPPORT-2): its mailbox may hold years of unread mail that
 * WHMCS's piping already made tickets of; older mail waits in the quarantine instead of opening tickets and mailing
 * «we received it» for each. Set when «Read the mailbox» is first turned on; a department already reading keeps none
 * (its backlog is already read).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->timestamp('mail_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->dropColumn('mail_since');
        });
    }
};
