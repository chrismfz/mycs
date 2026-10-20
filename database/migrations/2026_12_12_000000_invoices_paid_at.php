<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// When an invoice was paid (WHMCS `datepaid`): what the revenue reports count by (ROADMAP 6). Set when it becomes paid;
// the ones paid before, from their last payment (else their last change).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->index()->after('due_at');
        });
        // One statement (review F12 of #275): the last succeeded payment's date; an invoice closed without one (a note,
        // nothing due) its last change, the best there is.
        DB::statement("UPDATE invoices SET paid_at = COALESCE((SELECT MAX(t.created_at) FROM invoice_transactions t
            WHERE t.invoice_id = invoices.id AND t.status = 'succeeded'), updated_at) WHERE status = 'paid'");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropColumn('paid_at');
        });
    }
};
