<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `cronjob_invoice_reminder`: a setting of the upstream nothing reads (audit sweep EVT-5; the reminders are
 * cronjob_payment_reminder and cronjob_overdue_reminders). Its row went on misleading whoever read the settings (the
 * mini sweep of 2026-10-23 found «3» there).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'cronjob_invoice_reminder')->whereNull('settingable_type')->delete();
    }

    public function down(): void
    {
        // Nothing to bring back: it did nothing.
    }
};
