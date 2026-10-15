<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's pro rata billing (decision 3, PR D; WHMCS «Prorata Billing»): its services renew on the same day of the
 * month (nixpal: the 1st, 336 of 350 live services). Null day: off. Ordered on or after the «charge next month» day, the
 * first invoice also bills the next full period (nixpal: the 20th).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('prorata_day')->nullable()->after('taxable');
            $table->unsignedTinyInteger('prorata_next_after_day')->nullable()->after('prorata_day');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['prorata_day', 'prorata_next_after_day']);
        });
    }
};
