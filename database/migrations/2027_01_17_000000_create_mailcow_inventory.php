<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A mailcow server's domains as last read (mailcow:sync), each mapped to the service it is, or marked internal; a
        // domain no longer listed is kept as gone, with its mapping (owner 2026-10-28: Utilities as for Proxmox).
        Schema::create('mailcow_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('extensions')->cascadeOnDelete();
            $table->string('domain');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('mailboxes')->default(0);
            $table->unsignedInteger('mailbox_limit')->default(0);
            $table->unsignedInteger('aliases')->default(0);
            $table->unsignedInteger('alias_limit')->default(0);
            $table->unsignedBigInteger('quota_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('messages')->default(0);
            $table->json('admins')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('gone_at')->nullable();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->boolean('internal')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'domain']);
        });

        // Its mailboxes as last read: a mirror of the server (one gone is deleted here), never staff's data.
        Schema::create('mailcow_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailcow_domain_id')->constrained('mailcow_domains')->cascadeOnDelete();
            $table->string('address');
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('quota_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('messages')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['mailcow_domain_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailcow_mailboxes');
        Schema::dropIfExists('mailcow_domains');
    }
};
