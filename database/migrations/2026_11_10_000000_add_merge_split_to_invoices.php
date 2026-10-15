<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merge and split for staff (PR E, sweep Π2.4/Π2.13): where an invoice went, and where one came from. A merged invoice is
 * kept, cancelled, with the invoice its lines moved to (WHMCS deletes it); a split one points at the invoice it left.
 *
 * SQLite (tests) gets no foreign keys: adding one rebuilds `invoices` there, and inside a transaction the rebuild deletes
 * its children by cascade (as in the C2 migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('invoices', function (Blueprint $table) use ($sqlite) {
            $table->unsignedBigInteger('merged_into_id')->nullable()->after('cancellation_reason');
            $table->unsignedBigInteger('split_from_id')->nullable()->after('merged_into_id');
            if (!$sqlite) {
                $table->foreign('merged_into_id')->references('id')->on('invoices')->nullOnDelete();
                $table->foreign('split_from_id')->references('id')->on('invoices')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('invoices', function (Blueprint $table) use ($sqlite) {
            if (!$sqlite) {
                $table->dropForeign(['merged_into_id']);
                $table->dropForeign(['split_from_id']);
            }
            $table->dropColumn(['merged_into_id', 'split_from_id']);
        });
    }
};
