<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff's discount on an upgrade's difference (owner 2026-11-06, «Upgrade/Downgrade»): the net amount taken off, and the
 * percentage when it was given as one. Kept with the upgrade (who: staff_id): on its invoice it is a negative line under
 * the upgrade's; waived, there is no invoice and this is the only record of what was not charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->decimal('discount', 17, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
        });
    }
};
