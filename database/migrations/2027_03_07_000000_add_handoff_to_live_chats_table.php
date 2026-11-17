<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's tools for visitors' chats (docs/livechat.md §3 Δ, §6α, step 4, PR 3): «Ask to log in» (when), the
 * move to a customer's chat after a login in the same session (pending for which login, since when; the new chat
 * names the one it continues, for the staff only), and the staff's manual link to a client (who, when).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->timestamp('login_requested_at')->nullable()->after('page');
            $table->foreignId('handoff_user_id')->nullable()->after('login_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('handoff_at')->nullable()->after('handoff_user_id');
            $table->foreignId('continued_from')->nullable()->after('handoff_at')->constrained('live_chats')->nullOnDelete();
            $table->foreignId('linked_by')->nullable()->after('continued_from')->constrained('staff')->nullOnDelete();
            $table->timestamp('linked_at')->nullable()->after('linked_by');
        });
    }

    public function down(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handoff_user_id');
            $table->dropConstrainedForeignId('continued_from');
            $table->dropConstrainedForeignId('linked_by');
            $table->dropColumn(['login_requested_at', 'handoff_at', 'linked_at']);
        });
    }
};
