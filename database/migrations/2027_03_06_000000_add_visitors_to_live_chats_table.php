<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chats of anonymous visitors from our sites (docs/livechat.md §6, step 4): no client, a visitor's token kept hashed
 * (the token itself lives only in the visitor's server session, §3 Γ), the name and email they typed (checked, never
 * vouched for), their address for the limits and blocks, and the site and page the chat was opened from, as a label.
 * `started_anonymously` stays when a chat is later linked to a client and its token revoked (§6α, F2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->char('visitor_token_hash', 64)->nullable()->unique()->after('user_id');
            $table->boolean('started_anonymously')->default(false)->after('visitor_token_hash');
            $table->string('visitor_name', 60)->nullable()->after('started_anonymously');
            $table->string('visitor_email', 190)->nullable()->after('visitor_name');
            $table->string('ip', 45)->nullable()->after('visitor_email');
            $table->string('src', 100)->nullable()->after('ip');
            $table->string('page', 255)->nullable()->after('src');
            $table->index(['ip', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('live_chats', function (Blueprint $table) {
            $table->dropIndex(['ip', 'status']);
            $table->dropUnique(['visitor_token_hash']);
            $table->dropColumn(['visitor_token_hash', 'started_anonymously', 'visitor_name', 'visitor_email', 'ip', 'src', 'page']);
        });
    }
};
