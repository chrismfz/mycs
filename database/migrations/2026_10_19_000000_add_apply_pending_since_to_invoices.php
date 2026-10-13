<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice paid whose lines are not applied yet (audit sweep BIL-9): set in the same save that makes it paid,
 * cleared once its services are renewed and its upgrades done. A failure after the payment committed leaves it set,
 * and the cron applies it again; a paying customer is not suspended. Null for everything before (and for the WHMCS
 * import): only what MyCS marked paid is ever applied again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('apply_pending_since')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('apply_pending_since');
        });
    }
};
