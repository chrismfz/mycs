<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * DirectAdmin kept each account's password in clear in a property (and so in the audits, and on the staff's properties
 * tab: sweep §6 #2). It goes to the service's own field, encrypted (the `encrypted` cast's format): it is the account's
 * own password, the one its SSO logs in with, so it wins over one the service had (review R11). The property goes, and
 * every audit of such a property, also of one deleted before, loses the value (review R9).
 */
return new class extends Migration
{
    public function up(): void
    {
        $properties = DB::table('properties')->where('model_type', 'App\\Models\\Service')->where('key', 'directadmin_password')->get();
        foreach ($properties as $property) {
            if ($property->value !== null && $property->value !== '') {
                DB::table('services')->where('id', $property->model_id)
                    ->update(['password' => Crypt::encryptString((string) $property->value)]);
            }
        }
        DB::table('properties')->whereIn('id', $properties->pluck('id'))->delete();

        $audited = DB::table('audits')->where('auditable_type', 'App\\Models\\Property')
            ->where(fn ($q) => $q->where('old_values', 'like', '%directadmin_password%')->orWhere('new_values', 'like', '%directadmin_password%'))
            ->pluck('auditable_id')->merge($properties->pluck('id'))->unique();
        DB::table('audits')->where('auditable_type', 'App\\Models\\Property')->whereIn('auditable_id', $audited)
            ->update(['old_values' => json_encode(['value' => '[redacted]']), 'new_values' => json_encode(['value' => '[redacted]'])]);
    }

    public function down(): void
    {
        // The passwords stay on the services, encrypted: the property is not written back in clear.
    }
};
