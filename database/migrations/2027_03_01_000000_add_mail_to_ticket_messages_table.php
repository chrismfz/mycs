<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a message that came by mail says of its mail (owner 2026-11-13: «is it legit?»): its From, the server our own
 * server took it from, whether our server vouched for it and why, and its header block. Mail from now on only: older
 * messages kept none of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->json('mail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', fn (Blueprint $table) => $table->dropColumn('mail'));
    }
};
