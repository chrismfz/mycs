<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service's price without its account's group discount, when it cannot be computed from its plan (WG-1, ADV-641-1/2):
 * WHMCS's amount, which the import discounts. A change of group reprices from it exactly (1.85 at 10% is 1.66, back to
 * 1.85; a 100% group's 0.00 back to its amount), never by ratio. Null when the price is staff's or the plan's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('undiscounted_price', 17, 2)->nullable()->after('group_percent');
        });
    }
};
