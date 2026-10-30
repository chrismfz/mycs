<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chargeback's part of the credit its payment left (sweep 9γ, owner 2026-10-30): what it took back from the credit,
 * what was spent already (refunded on the payment, never a debt), and the journal events of both, so a won dispute
 * gives each back as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_refunds', function (Blueprint $table) {
            $table->decimal('credit_taken', 12, 2)->default(0)->after('recorded_amount');
            $table->decimal('credit_spent', 12, 2)->default(0)->after('credit_taken');
            $table->json('credit_events')->nullable()->after('credit_spent');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_refunds', function (Blueprint $table) {
            $table->dropColumn(['credit_taken', 'credit_spent', 'credit_events']);
        });
    }
};
