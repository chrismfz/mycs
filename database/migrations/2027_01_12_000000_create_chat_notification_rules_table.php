<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff notifications to a chat (WHMCS «Notifications» to Mattermost/Slack; sweep of myip, 2026-10-25): each rule
        // an incoming webhook (encrypted) and the events it is told of.
        Schema::create('chat_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('webhook_url');
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_notification_rules');
    }
};
