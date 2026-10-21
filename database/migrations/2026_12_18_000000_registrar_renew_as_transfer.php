<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid renewal at this connection transferred to another instead (owner 2026-10-21: the WHMCS hook CNIC → Openprovider
 * as a setting of any registrar), except its TLDs whose transfer adds no year (.eu, .uk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_registrar_connections', function (Blueprint $table) {
            $table->foreignId('renew_transfer_to_id')->nullable()->after('low_balance')
                ->constrained('domain_registrar_connections')->nullOnDelete();
            $table->json('renew_transfer_except')->nullable()->after('renew_transfer_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('domain_registrar_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renew_transfer_to_id');
            $table->dropColumn('renew_transfer_except');
        });
    }
};
