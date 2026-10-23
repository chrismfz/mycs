<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The IP the server says an account has (owner 2026-10-23), read with its usage: beside the service's dedicated IP. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_usages', fn (Blueprint $table) => $table->string('server_ip', 45)->nullable()->after('accounts_kind'));
    }

    public function down(): void
    {
        Schema::table('service_usages', fn (Blueprint $table) => $table->dropColumn('server_ip'));
    }
};
