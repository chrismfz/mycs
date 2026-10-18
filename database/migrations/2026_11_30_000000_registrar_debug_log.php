<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A registrar call's transactions on its log (command, clTRID, the registry's svTRID, result code: what a registry's
 * helpdesk asks for), and a connection's debug log (its frames, secrets hidden, in storage/logs: off by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_registrar_logs', function (Blueprint $table) {
            $table->json('transactions')->nullable()->after('response');
        });
        Schema::table('domain_registrar_connections', function (Blueprint $table) {
            $table->boolean('debug_log')->default(false)->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('domain_registrar_logs', fn (Blueprint $table) => $table->dropColumn('transactions'));
        Schema::table('domain_registrar_connections', fn (Blueprint $table) => $table->dropColumn('debug_log'));
    }
};
