<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a service's plan starts to count, when the cron put it on the plan of a change of cycle «from the next
 * renewal» as it billed that renewal, days before the period paid ends (S7-PLAN-CYCLE-ADDONS-2): until that day the
 * service is on the new cycle's plan and price while the old cycle's period still runs, and a package change would be
 * priced by the new cycle over the old period's days. It is refused until then (Service::planStartsOn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->date('plan_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('plan_from');
        });
    }
};
