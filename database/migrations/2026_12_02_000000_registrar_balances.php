<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A registrar connection's balance as last read (G8-1: amount, when, the error of a failed read) and the amount under
 * which staff are told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_registrar_connections', function (Blueprint $table) {
            $table->json('balance')->nullable()->after('debug_log');
            $table->decimal('low_balance', 12, 2)->nullable()->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('domain_registrar_connections', fn (Blueprint $table) => $table->dropColumn(['balance', 'low_balance']));
    }
};
