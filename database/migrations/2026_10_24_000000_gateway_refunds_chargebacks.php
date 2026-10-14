<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2c-2b (docs/payments.md): a chargeback is kept with the gateway's refunds, by its id there (origin chargeback), with
 * the journal event it wrote, so that a dispute won reverses exactly that event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_refunds', function (Blueprint $table) {
            $table->foreignId('money_event_id')->nullable()->after('origin')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_event_id');
        });
    }
};
