<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets, PR A2 (docs/tickets.md §3, §5): a mailbox per department (its IMAP login, the authserv-id of our receiving
 * server, «Require DMARC»), the quarantine of what did not pass, blocked senders, and a record of every poll. The old
 * piping (one mailbox in the settings, ticket_mail_logs) goes: it threaded by a guessable number and trusted the From.
 * Polling starts off for every department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption', 10)->default('ssl');
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable(); // encrypted cast
            $table->string('imap_folder')->default('INBOX');
            // The authserv-id of OUR receiving server's Authentication-Results (§3 Β), e.g. mymail.myip.gr.
            $table->string('authserv_id')->nullable();
            $table->boolean('require_dmarc')->default(true);
            $table->boolean('polling')->default(false);
        });

        Schema::create('ticket_quarantine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->string('reason', 40);
            $table->string('message_id')->nullable()->unique();
            $table->string('from_email')->nullable()->index();
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->boolean('authenticated')->default(false);
            $table->string('auth_detail', 40)->nullable();
            $table->text('preview')->nullable();
            $table->json('refused_files')->nullable();
            // The raw mail on the private disk, for «attach» and «open» (its files), deleted with the row.
            $table->string('raw_path')->nullable();
            $table->unsignedInteger('raw_size')->default(0);
            $table->foreignId('suggested_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_blocked_senders', function (Blueprint $table) {
            $table->id();
            // An address (maria@example.com) or a whole domain (@example.com), lower case.
            $table->string('value')->unique();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ticket_poll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('ticket_departments')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->boolean('connected')->default(false);
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('threaded')->default(0);
            $table->unsignedInteger('opened')->default(0);
            $table->unsignedInteger('quarantined')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('errors')->nullable();
            $table->index(['department_id', 'started_at']);
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropForeign(['ticket_mail_log_id']);
            $table->dropColumn('ticket_mail_log_id');
        });
        Schema::dropIfExists('ticket_mail_logs');
        DB::table('settings')->whereIn('key', ['ticket_mail_piping', 'ticket_mail_host', 'ticket_mail_port', 'ticket_mail_email', 'ticket_mail_password'])
            ->whereNull('settingable_type')->delete();
    }

    public function down(): void
    {
        Schema::create('ticket_mail_logs', function (Blueprint $table) {
            $table->id();
            $table->string('message_id');
            $table->string('subject');
            $table->string('from');
            $table->string('to');
            $table->text('body');
            $table->string('status');
            $table->timestamps();
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->foreignId('ticket_mail_log_id')->nullable()->after('message')->constrained('ticket_mail_logs')->cascadeOnDelete();
        });
        Schema::dropIfExists('ticket_poll_runs');
        Schema::dropIfExists('ticket_blocked_senders');
        Schema::dropIfExists('ticket_quarantine');
        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->dropColumn(['imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'imap_folder',
                'authserv_id', 'require_dmarc', 'polling']);
        });
    }
};
