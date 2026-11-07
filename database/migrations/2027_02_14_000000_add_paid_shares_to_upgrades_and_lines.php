<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a credit for days left must not count as paid for them (sweep 2, f-plan-change):
 *
 * - `invoice_items.setup_fee`: the setup fee (net, per unit) a first invoice's line holds with its first period (an order,
 *   an addon): a one-time charge, never given back as the period's unused days (F-PLAN-CHANGE-1).
 * - `service_upgrades.charged`: the net price the system last put on the upgrade's charge line. A line at another price
 *   was changed by staff: the cron leaves it, and what a renewal paid meanwhile adds is worked out from this (F-PLAN-CHANGE-3).
 * - `service_upgrades.paid_until`: the service's expiry when the upgrade was applied, the end of the days its money paid
 *   for (a renewal paid ahead included; F-PLAN-CHANGE-2).
 * - `service_upgrades.charged_until`: the service's expiry when `charged` was put on the charge line, the end of the days
 *   that charge paid for; a renewal paid after it is the shortfall's, from there to `paid_until` (review REV2-PC-2).
 *
 * Rows from before this have none of it, and are read as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('setup_fee', 17, 2)->nullable();
        });
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->decimal('charged', 17, 2)->nullable();
            $table->date('paid_until')->nullable();
            $table->date('charged_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->dropColumn(['charged', 'paid_until', 'charged_until']);
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('setup_fee');
        });
    }
};
