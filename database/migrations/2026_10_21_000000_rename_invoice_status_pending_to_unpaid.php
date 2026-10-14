<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An unpaid invoice is «unpaid» in the database too, as WHMCS calls it and as every screen reads it (the fork's base called
 * it «pending»). The column default stays «draft» (2026_05_15). Older audit rows keep «pending»: they record what was
 * written then (lang/*\/money.php reads both).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')->where('status', 'pending')->update(['status' => 'unpaid']);
    }

    public function down(): void
    {
        DB::table('invoices')->where('status', 'unpaid')->update(['status' => 'pending']);
    }
};
