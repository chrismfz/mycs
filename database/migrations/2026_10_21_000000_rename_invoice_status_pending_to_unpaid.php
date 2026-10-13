<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An unpaid invoice is «unpaid» in the database too, as WHMCS calls it and as every screen reads it (Paymenter called
 * it «pending»). Older audit rows keep «pending»: they record what was written then (lang/*\/money.php reads both).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')->where('status', 'pending')->update(['status' => 'unpaid']);
        Schema::table('invoices', fn (Blueprint $table) => $table->string('status')->default('unpaid')->change());
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->string('status')->default('pending')->change());
        DB::table('invoices')->where('status', 'unpaid')->update(['status' => 'pending']);
    }
};
