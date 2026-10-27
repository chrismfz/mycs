<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Send to Ekdosi» (E5, docs/ekdosi-bridge.md): staff ask Ekdosi for a document of one invoice that its feed leaves out,
 * as the WHMCS plugin's button above the invoices did: an account with «ignore invoicing», or one not paid yet (the
 * customer wants the document before paying; Ekdosi issues it on credit). Who asked and when; the feed's `requested`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('ekdosi_requested_at')->nullable()->index();
            $table->foreignId('ekdosi_requested_by')->nullable()->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ekdosi_requested_by');
            $table->dropIndex(['ekdosi_requested_at']);
            $table->dropColumn('ekdosi_requested_at');
        });
    }
};
