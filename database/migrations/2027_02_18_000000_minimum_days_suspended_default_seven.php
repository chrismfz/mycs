<?php

use App\Providers\SettingsProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Minimum days suspended before termination» defaults to 7 (owner 2026-11-10, sweep S7-CRON-BILLING-1): with 1, a
 * service suspended late (a hold that ended past Termination Days, a service staff brought back) lost its account the
 * day after its suspension. An install that stored the old default 1 moves to 7: a stored 1 cannot be told from a
 * chosen 1, and the longer wait is the safe side (staff set it back in Settings → Automation). Any other value (0 = as
 * WHMCS, 3, 14) was chosen and stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        $changed = DB::table('settings')->where('key', 'cronjob_terminate_min_suspended_days')->whereNull('settingable_type')
            ->where('value', '1')->update(['value' => '7']);
        if ($changed > 0) {
            SettingsProvider::flushCache();
        }
    }

    public function down(): void
    {
        // Not put back: a 7 here may be staff's own by now.
    }
};
