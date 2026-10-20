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
        DB::table('invoices')->where('status', 'paid')->orderBy('id')->chunkById(500, function ($invoices) {
            foreach ($invoices as $invoice) {
                $last = DB::table('invoice_transactions')->where('invoice_id', $invoice->id)->where('status', 'succeeded')->max('created_at');
                DB::table('invoices')->where('id', $invoice->id)->update(['paid_at' => $last ?? $invoice->updated_at]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropColumn('paid_at');
        });
    }
};
