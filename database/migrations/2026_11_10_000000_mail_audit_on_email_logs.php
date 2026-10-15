<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mail audit (docs/mail.md, ideas from Ekdosi's invoice_mail_log): who it went to and from, which template and
 * about what (invoice, service, order, ticket), who sent it (the system, or staff resending), and its way out
 * (pending → sending → sent / failed, the attempts, the SMTP Message-ID). A resend is a row of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('from_address')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('template_key', 100)->nullable()->index();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('trigger', 16)->default('auto'); // auto, manual (staff resend), batch
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->unsignedBigInteger('resent_from_id')->nullable()->index(); // no FK: the original may be pruned first
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('message_id')->nullable();
            $table->index(['related_type', 'related_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['related_type', 'related_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('staff_id');
            $table->dropIndex(['template_key']);
            $table->dropIndex(['resent_from_id']);
            $table->dropColumn(['from_address', 'cc', 'bcc', 'template_key', 'related_type', 'related_id', 'trigger', 'resent_from_id',
                'attempts', 'sending_at', 'failed_at', 'message_id']);
        });
    }
};
