<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No TLD stays behind (owner 2026-10-22: «ό,τι και να γίνει, να πάνε Openprovider και να κάνουν renew μόνο αν χρειάζεται»):
 * a transfer that added no year is renewed at the target (DomainSyncService::renewAfterTransfer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_registrar_connections', function (Blueprint $table) {
            $table->dropColumn('renew_transfer_except');
        });
    }

    public function down(): void
    {
        Schema::table('domain_registrar_connections', function (Blueprint $table) {
            $table->json('renew_transfer_except')->nullable()->after('renew_transfer_to_id');
        });
    }
};
