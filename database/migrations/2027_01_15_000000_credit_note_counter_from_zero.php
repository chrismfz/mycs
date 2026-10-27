<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The credit/debit note counter of an install seeded with the old default (1): it holds the last number given, so the
 * first note was number 2. Set back to 0 only where no note has a number yet; one that has given numbers keeps its
 * counter (a number given is never given again). Review of #477.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('adjustment_notes')->whereNotNull('number')->exists()) {
            return;
        }
        DB::table('settings')->where('key', 'credit_note_number')->whereNull('settingable_type')->where('value', '1')
            ->update(['value' => '0']);
    }

    public function down(): void
    {
        // Nothing: the counter was wrong before.
    }
};
