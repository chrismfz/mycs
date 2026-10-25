<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A credit matched to the invoice it paid (automatic matching, the Bank transfer gateway's knob), or why not.
        Schema::table('bank_movements', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('status')->constrained('invoices')->nullOnDelete();
            $table->timestamp('matched_at')->nullable()->after('invoice_id');
            $table->string('match_note', 150)->nullable()->after('matched_at');
        });
    }

    public function down(): void
    {
        Schema::table('bank_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['matched_at', 'match_note']);
        });
    }
};
