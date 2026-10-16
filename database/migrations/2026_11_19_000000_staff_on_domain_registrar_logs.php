<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked a registrar call (docs/domains.md D4b): staff managing a domain from the admin. Null for the cron and the
 * importer. An EPP code read changes nothing on the domain, so this row is its only trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_registrar_logs', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->after('registrar_connection_id')->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domain_registrar_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_id');
        });
    }
};
