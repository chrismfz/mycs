<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * DirectAdmin kept each account's password in clear in a property (and so in the audits, and on the staff's properties
 * tab: sweep §6 #2). It goes to the service's own field, encrypted (the `encrypted` cast's format), unless the service has
 * one already; the property goes, and the audits of those properties lose the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        $properties = DB::table('properties')->where('model_type', 'App\\Models\\Service')->where('key', 'directadmin_password')->get();
        foreach ($properties as $property) {
            if ($property->value !== null && $property->value !== '') {
                DB::table('services')->where('id', $property->model_id)->whereNull('password')
                    ->update(['password' => Crypt::encryptString((string) $property->value)]);
            }
            DB::table('audits')->where('auditable_type', 'App\\Models\\Property')->where('auditable_id', $property->id)
                ->update(['old_values' => null, 'new_values' => json_encode(['value' => '[redacted]'])]);
        }
        DB::table('properties')->whereIn('id', $properties->pluck('id'))->delete();
    }

    public function down(): void
    {
        // The passwords stay on the services, encrypted: the property is not written back in clear.
    }
};
