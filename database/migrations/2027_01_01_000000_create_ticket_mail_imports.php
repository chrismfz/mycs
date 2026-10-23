<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ticket Mail Import Log (owner 2026-10-23, WHMCS's): every mail the poller read and what came of it (a new ticket,
 * a reply, the quarantine and why, a blocked sender). The mail itself is in the ticket or the quarantine, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_mail_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->string('from_email', 250)->nullable();
            $table->string('from_name', 250)->nullable();
            $table->string('subject', 250)->nullable();
            $table->string('outcome', 16);   // InboundOutcome: opened, threaded, quarantined, blocked
            $table->string('reason', 32)->nullable(); // the quarantine's
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quarantine_id')->nullable()->constrained('ticket_quarantine')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_mail_imports');
    }
};
