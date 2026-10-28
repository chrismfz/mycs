<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CloudLinux's limits of each account as last read (cpanel:lve), and what it used at each reading, kept two days:
        // the peak of the last 24 hours against its limit (owner 2026-10-28, stage 3). CPU in percent of its own limit,
        // memory in MB, I/O in KB/s.
        Schema::table('cpanel_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('lve_memory_limit_mb')->nullable();
            $table->unsignedInteger('lve_entry_processes_limit')->nullable();
            $table->unsignedInteger('lve_processes_limit')->nullable();
            $table->unsignedBigInteger('lve_io_limit_kbs')->nullable();
            $table->unsignedInteger('lve_iops_limit')->nullable();
            $table->timestamp('lve_read_at')->nullable();
        });
        Schema::create('cpanel_lve_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cpanel_account_id')->constrained('cpanel_accounts')->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->unsignedSmallInteger('cpu')->nullable();
            $table->unsignedBigInteger('memory_mb')->nullable();
            $table->unsignedInteger('entry_processes')->nullable();
            $table->unsignedInteger('processes')->nullable();
            $table->unsignedBigInteger('io_kbs')->nullable();
            $table->unsignedInteger('iops')->nullable();
            $table->index(['cpanel_account_id', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpanel_lve_samples');
        Schema::table('cpanel_accounts', function (Blueprint $table) {
            $table->dropColumn(['lve_memory_limit_mb', 'lve_entry_processes_limit', 'lve_processes_limit', 'lve_io_limit_kbs', 'lve_iops_limit', 'lve_read_at']);
        });
    }
};
