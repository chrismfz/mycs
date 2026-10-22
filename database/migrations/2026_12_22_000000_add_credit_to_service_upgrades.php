<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a downgrade gave back as credit, and to which day (its days' end then): a later downgrade in the same period is
 * capped at what is left of what was paid less what earlier ones already gave back (adversarial F2 of #326).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_cents')->nullable()->after('type');
            $table->date('credit_until')->nullable()->after('credit_cents');
        });
    }

    public function down(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->dropColumn(['credit_cents', 'credit_until']);
        });
    }
};
