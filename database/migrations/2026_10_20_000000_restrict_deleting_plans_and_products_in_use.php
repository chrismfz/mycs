<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan or product that a service or an upgrade uses is never deleted (audit sweep BIL-6, as WHMCS: «cannot delete a
 * product in use»). Deleting it set the service's plan/product to null (the cron then failed on it every day, and a
 * payment could not renew it) and deleted the upgrades on it with their line still payable. Staff hide it instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['services', 'service_upgrades'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['plan_id']);
                $table->dropForeign(['product_id']);
            });
            Schema::table($table, function (Blueprint $table) {
                $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['services' => 'nullOnDelete', 'service_upgrades' => 'cascadeOnDelete'] as $table => $onDelete) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['plan_id']);
                $table->dropForeign(['product_id']);
            });
            Schema::table($table, function (Blueprint $table) use ($onDelete) {
                $table->foreign('plan_id')->references('id')->on('plans')->{$onDelete}();
                $table->foreign('product_id')->references('id')->on('products')->{$onDelete}();
            });
        }
    }
};
