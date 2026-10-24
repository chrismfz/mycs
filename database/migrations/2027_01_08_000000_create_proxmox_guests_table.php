<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Proxmox cluster's VMs and containers as last read (proxmox:sync), each mapped to the service it is, or marked
        // internal. A VMID is unique within its cluster only: two clusters are two servers.
        Schema::create('proxmox_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('extensions')->cascadeOnDelete();
            $table->unsignedInteger('vmid');
            $table->string('type', 8)->default('qemu');
            $table->string('node')->nullable();
            $table->string('name')->nullable();
            $table->string('status', 32)->nullable();
            $table->boolean('template')->default(false);
            $table->unsignedInteger('cpus')->default(0);
            $table->unsignedBigInteger('max_memory')->default(0);
            $table->unsignedBigInteger('max_disk')->default(0);
            $table->unsignedBigInteger('uptime')->default(0);
            $table->json('macs')->nullable();
            $table->json('ips')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('gone_at')->nullable();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->boolean('internal')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'vmid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxmox_guests');
    }
};
