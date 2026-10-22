<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domains an account hosts as its server last told them (owner 2026-10-22): its main domain, addon, parked
 * (aliases) and subdomains; a reseller's, those of the accounts it owns too. Read with the usage (services:usage);
 * searched by staff (which service, which account, which server).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_hosted_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253)->index();
            $table->string('type', 16); // main, addon, parked, sub
            $table->string('account', 64)->nullable(); // the panel's account it is on (a reseller's customers' too)
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['service_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_hosted_domains');
    }
};
