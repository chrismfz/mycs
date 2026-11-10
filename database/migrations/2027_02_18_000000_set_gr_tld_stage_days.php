<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The .gr registry's stages (owner 2026-11-10, sweep 7 S7-DOMAINS-1): 15 days of grace after the expiry and no
 * redemption, for .gr and its second-level forms (com.gr, net.gr, org.gr, edu.gr, gov.gr). WHMCS left them «-1»
 * (imported as not known), so a lapsed .gr domain never reached the end of its stages. Only where a value is still
 * not known: one staff (or WHMCS) set is kept, and staff may change these like any TLD's (Domains → TLDs).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['grace_days' => 15, 'redemption_days' => 0] as $column => $days) {
            DB::table('domain_tlds')->whereNull($column)->where(fn ($tld) => $tld->where('tld', 'gr')->orWhere('tld', 'like', '%.gr'))
                ->update([$column => $days]);
        }
    }

    public function down(): void
    {
        // Nothing to take back: which rows were not known before is not kept.
    }
};
