<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a domain's grace and redemption end, as its registrar reports them (docs/domains.md D5a: Openprovider's soft and
 * hard quarantine end dates on an expired domain). Without them, its expiry and its TLD's days give them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->date('grace_ends_on')->nullable()->after('expires_at');
            $table->date('redemption_ends_on')->nullable()->after('grace_ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['grace_ends_on', 'redemption_ends_on']);
        });
    }
};
