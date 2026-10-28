<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The VPS addresses (docs/proxmox.md, B3): a pool is a network with its gateway, mask and DNS; each address is
        // free, reserved (ours, a router's) or a service's. An IPv6 pool holds prefixes, one a service.
        Schema::create('ip_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->unsignedTinyInteger('family')->default(4);
            $table->string('network', 49)->nullable();
            $table->unsignedTinyInteger('prefix');
            $table->string('gateway', 45)->nullable();
            $table->string('nameservers', 191)->nullable();
            $table->foreignId('server_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->unsignedInteger('whmcs_id')->nullable()->unique();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_pool_id')->constrained('ip_pools')->cascadeOnDelete();
            $table->string('address', 49)->unique();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete(); // released first (ServiceObserver)
            $table->boolean('reserved')->default(false);
            $table->string('note', 191)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['ip_pool_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('ip_pools');
    }
};
