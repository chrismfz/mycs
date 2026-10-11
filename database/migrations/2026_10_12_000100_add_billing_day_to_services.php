<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day of month a service bills on (docs/billing-cursor.md): kept on the service, because the
 * history cannot always tell it (a cancelled invoice, free renewals leave no line, Feb 28 may be a
 * clamped 31st). Like WHMCS. Null until a period starts on an unambiguous day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_day')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('billing_day');
        });
    }
};
