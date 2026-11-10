<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an invoice was split off another (SplitInvoice, MoveToClient): its created_at is the first one's, so nothing said
 * when. Ekdosi's write-back asks whether the split came after the invoice was Ekdosi's to read (sweep 7,
 * S7-TAX-EKDOSI-1). Splits made before this column have none: taken as made before the read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('split_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('split_at');
        });
    }
};
