<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The license key is the service's domain, as WHMCS keeps it: licenses issued before get it (a domain set is kept). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('licenses')->get(['service_id', 'license_key']) as $license) {
            DB::table('services')->where('id', $license->service_id)->where(fn ($q) => $q->whereNull('domain')->orWhere('domain', ''))
                ->update(['domain' => $license->license_key]);
        }
    }

    public function down(): void
    {
        // The domains stay: they are the services' data now.
    }
};
