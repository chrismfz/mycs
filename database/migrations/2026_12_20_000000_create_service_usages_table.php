<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service's usage as its server last told it (owner 2026-10-22: WHMCS's «Usage Statistics», for plain accounts and
 * resellers). Its own table: read every few hours, never a change to the service (whose audit it would flood).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('disk_used_mb')->nullable();
            $table->unsignedBigInteger('disk_limit_mb')->nullable();
            $table->unsignedBigInteger('bandwidth_used_mb')->nullable();
            $table->unsignedBigInteger('bandwidth_limit_mb')->nullable();
            $table->unsignedInteger('accounts')->nullable();
            $table->unsignedInteger('accounts_limit')->nullable();
            $table->timestamp('read_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_usages');
    }
};
