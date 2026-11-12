<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bulk license's IPs and their history (docs/zonecloud-bulk-license.md §4.2): what zlic answers and, later, what is
 * billed per IP-day. Removing one sets removed_at; putting it back is a new row. Who did what: the audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            // As inet_ntop() writes it, so one address has one spelling.
            $table->string('ip', 45);
            $table->string('label', 100)->nullable();
            $table->dateTime('added_at');
            $table->dateTime('removed_at')->nullable();
            $table->dateTime('last_ok_at')->nullable();
            $table->timestamps();
            $table->index(['license_id', 'removed_at']);
            $table->index(['ip', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_ips');
    }
};
