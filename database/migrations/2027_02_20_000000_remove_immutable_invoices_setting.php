<?php

use App\Providers\SettingsProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Immutable Invoices» is gone (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-31): the fiscal document is
 * Ekdosi's, and staff correct an invoice here as freely as in WHMCS. Its stored row goes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'immutable_invoices_enabled')->delete() > 0) {
            SettingsProvider::flushCache();
        }
    }

    public function down(): void
    {
        // The setting no longer exists: nothing to put back.
    }
};
