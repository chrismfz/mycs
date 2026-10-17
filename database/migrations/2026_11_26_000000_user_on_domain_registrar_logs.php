<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked a registrar for a change (D-22): staff (staff_id), or since the client area manages domains (D7-2) the
 * customer's login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_registrar_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('staff_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domain_registrar_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
