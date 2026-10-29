<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The invoice's preferred payment method (WHMCS's tblinvoices.paymentmethod): its page opens with it chosen and its
        // email's button names it; the customer may pay another way (owner 2026-10-29).
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('preferred_gateway_id')->nullable()->after('currency_code')->constrained('extensions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_gateway_id');
        });
    }
};
