<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff marked the invoice paid with no payment for (all of) it (owner 2026-11-10, docs/staff-freedom.md): a fact on the
 * invoice, read by whatever must not take «paid» for money (the affiliates' reward, the money check, the income report,
 * the Ekdosi feed). Empty again when it leaves «paid» or when real money covers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('paid_by_staff_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('paid_by_staff_at');
        });
    }
};
