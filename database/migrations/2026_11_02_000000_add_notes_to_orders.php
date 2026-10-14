<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order's notes (WHMCS `tblorders.notes`: myip 570, nixpal 37): what the customer wrote at checkout, or staff on
 * the order. Staff see them; they are not printed on the invoice (that is the invoice's own notes, Σ8). `mediumText`:
 * the forms allow 65,535 characters and MariaDB's `text` holds 65,535 bytes (Greek is 2 bytes a letter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->mediumText('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
