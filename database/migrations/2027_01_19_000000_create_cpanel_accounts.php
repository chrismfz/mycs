<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A WHM server's accounts as last read (cpanel:sync), each mapped to the service it is, or marked internal; one no
        // longer listed is kept as gone, with its mapping (owner 2026-10-28: Utilities → Hosting Accounts, as Mail Domains).
        Schema::create('cpanel_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('extensions')->cascadeOnDelete();
            $table->string('user', 64);
            $table->string('domain', 253)->nullable();
            $table->string('owner', 64)->nullable();
            $table->string('plan', 191)->nullable();
            $table->string('ip', 45)->nullable();
            $table->boolean('reseller')->nullable();
            $table->unsignedBigInteger('disk_used_mb')->nullable();
            $table->unsignedBigInteger('disk_limit_mb')->nullable();
            $table->unsignedBigInteger('inodes_used')->nullable();
            $table->unsignedBigInteger('inodes_limit')->nullable();
            $table->unsignedBigInteger('bandwidth_used_mb')->nullable();
            $table->unsignedBigInteger('bandwidth_limit_mb')->nullable();
            $table->boolean('suspended')->default(false);
            $table->string('suspend_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->boolean('mail_held')->default(false);
            $table->boolean('mail_suspended')->default(false);
            $table->integer('max_email_per_hour')->nullable();
            $table->boolean('backup')->default(false);
            $table->timestamp('created_on_server_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('gone_at')->nullable();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->boolean('internal')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'user']);
            $table->index('owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpanel_accounts');
    }
};
