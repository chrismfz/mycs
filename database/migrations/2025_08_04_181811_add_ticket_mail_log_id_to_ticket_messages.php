<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            // By name: the model went with the old piping (docs/tickets.md, A2).
            $table->foreignId('ticket_mail_log_id')->nullable()->after('message')->constrained('ticket_mail_logs')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropForeign(['ticket_mail_log_id']);
        });
    }
};
