<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The service's own data, as WHMCS keeps it in tblhosting (both companies use them: domain 713/715 and 350/350 active
 * services, username/password on every hosting account, dedicated IPs 28, notes 128, 2026-10-14): native fields, not
 * properties, so they are listed, searched («who has IP x», abuse) and read by every module the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('domain')->nullable()->index()->after('label');
            $table->string('username')->nullable()->after('domain');
            $table->text('password')->nullable()->after('username'); // encrypted cast
            $table->string('dedicated_ip', 45)->nullable()->index()->after('password');
            $table->text('assigned_ips')->nullable()->after('dedicated_ip'); // one per line, as WHMCS
            $table->text('notes')->nullable()->after('assigned_ips'); // staff only
            $table->string('suspend_reason')->nullable()->after('notes');
        });

        // The domain a checkout already wrote as a property (the server extensions' «domain» field).
        foreach (DB::table('properties')->where('model_type', 'App\\Models\\Service')->where('key', 'domain')->where('value', '!=', '')->get(['model_id', 'value']) as $property) {
            DB::table('services')->where('id', $property->model_id)->whereNull('domain')->update(['domain' => mb_substr($property->value, 0, 255)]);
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropIndex(['dedicated_ip']);
            $table->dropColumn(['domain', 'username', 'password', 'dedicated_ip', 'assigned_ips', 'notes', 'suspend_reason']);
        });
    }
};
