<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A grouped renewal invoice still to finish (PR C): its email, credit and saved-method charge come once all its lines are
 * on it. Set when the cron creates it, cleared when it is finished; one left set (a failure, a crash) is finished on the
 * next run (docs/billing-cursor.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('finish_pending_at')->nullable()->after('kept_as_debt_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('finish_pending_at');
        });
    }
};
