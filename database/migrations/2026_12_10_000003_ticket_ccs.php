<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CC on a ticket (docs/tickets.md §6λ): addresses staff add, which get staff's replies (each its own mail, no link) and
 * whose replies join the ticket; never one added by a mail's To or Cc (§3 Γ). A message keeps the address that wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_ccs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('added_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->unique(['ticket_id', 'email']);
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('sender_email')->nullable()->after('user_id');
        });
        if (!DB::table('notification_templates')->where('key', 'ticket_cc_message')->exists()) {
            DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping['ticket_cc_message'], [
                'key' => 'ticket_cc_message',
                'enabled' => true,
                'translations' => json_encode(EmailTemplateSeeder::translations['ticket_cc_message'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'ticket_cc_message')->delete();
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropColumn('sender_email');
        });
        Schema::dropIfExists('ticket_ccs');
    }
};
