<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login imported from WHMCS, its address still the one WHMCS had (REV-769-1): set by the importer, emptied when the
 * address changes (User). Mail to support from such an address reaches its account as it did in WHMCS, which routed
 * mail by address (InboundRouter::routes); it is no proof of the address, so `email_verified_at` stays WHMCS's own and
 * the provider login keeps refusing a login never verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('imported_email_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('imported_email_at');
        });
    }
};
