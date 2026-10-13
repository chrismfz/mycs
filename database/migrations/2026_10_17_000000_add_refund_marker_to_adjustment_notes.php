<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/money-trail.md, MT-1c: adjustment notes are append-only. A refund's note follows its transaction
 * (refunded_amount) and a cancellation's note follows the invoice's status: staff never void either by hand. The
 * refund notes written so far are found by the description ExtensionHelper::recordRefund gives them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustment_notes', function (Blueprint $table) {
            $table->boolean('is_refund')->default(false)->after('is_cancellation');
        });
        DB::table('adjustment_notes')->where('description', 'like', 'Refund for transaction #%')->update(['is_refund' => true]);
    }

    public function down(): void
    {
        Schema::table('adjustment_notes', function (Blueprint $table) {
            $table->dropColumn('is_refund');
        });
    }
};
