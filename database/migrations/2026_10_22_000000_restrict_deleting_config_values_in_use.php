<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A config option's value that services use is never deleted with them (audit sweep DATA-8): the cascade silently
 * took it off every service, so the next price (upgrade, coupon expiry, recalculate) under-billed and provisioning lost
 * the option. WHMCS keeps the services' values. Staff move the services to another value first. Deleting the whole
 * option is unchanged (staff confirm it, warned, and its services' rows go first).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild('restrictOnDelete');
    }

    public function down(): void
    {
        $this->rebuild('cascadeOnDelete');
    }

    private function rebuild(string $onDelete): void
    {
        foreach (Schema::getForeignKeys('service_configs') as $key) {
            if ($key['columns'] === ['config_value_id']) {
                Schema::table('service_configs', fn (Blueprint $table) => $table->dropForeign($key['name'] ?: ['config_value_id']));
            }
        }
        Schema::table('service_configs', fn (Blueprint $table) => $table->foreign('config_value_id')->references('id')->on('config_options')->{$onDelete}());
    }
};
