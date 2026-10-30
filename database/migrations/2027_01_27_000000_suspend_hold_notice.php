<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Don't suspend until…» warns (sweep 9γ G1, owner 2026-10-30): the hold date a service's customer was told of, so the
 * notice goes once per date (again when staff move it), and its template on instances seeded before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->date('suspend_hold_notice_for')->nullable()->after('suspend_hold_until');
        });
        EmailTemplateSeeder::seedKeys(['service_hold_ending']);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('suspend_hold_notice_for');
        });
    }
};
