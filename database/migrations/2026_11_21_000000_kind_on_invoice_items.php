<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a line is beyond its reference (docs/domains.md D5b): a domain's renewal is its period; a grace or redemption fee
 * is a line of the same domain on the same invoice (D-8, «existing invoice»), told apart here. Null: an ordinary line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
