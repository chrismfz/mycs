<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plan or product that a service or an upgrade uses is never deleted (audit sweep BIL-6): deleting it set the
 * service's plan/product to null (the cron then failed on it every day, a payment could not renew it) and deleted the
 * upgrades on it with their line still payable. Staff hide it instead.
 *
 * Rows pointing at a plan/product that no longer exists (the WHMCS importer writes with foreign key checks off) are
 * cleaned first, as the old rules would have: a service's id goes null, an upgrade goes. Re-runnable: only the foreign
 * keys that exist are dropped, by their actual name.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['plan_id' => 'plans', 'product_id' => 'products'] as $column => $parent) {
            DB::table('services')->whereNotNull($column)->whereNotExists(fn ($query) => $query->from($parent)->whereColumn("{$parent}.id", "services.{$column}"))
                ->update([$column => null]);
            DB::table('service_upgrades')->whereNotExists(fn ($query) => $query->from($parent)->whereColumn("{$parent}.id", "service_upgrades.{$column}"))
                ->delete();
        }

        $this->rebuild(['services' => 'restrictOnDelete', 'service_upgrades' => 'restrictOnDelete']);
    }

    public function down(): void
    {
        $this->rebuild(['services' => 'nullOnDelete', 'service_upgrades' => 'cascadeOnDelete']);
    }

    /** @param  array<string, string>  $tables  table => onDelete rule for its plan_id and product_id */
    private function rebuild(array $tables): void
    {
        foreach ($tables as $table => $onDelete) {
            foreach (['plan_id', 'product_id'] as $column) {
                foreach (Schema::getForeignKeys($table) as $key) {
                    if ($key['columns'] === [$column]) {
                        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($key['name'] ?: [$column]));
                    }
                }
            }
            Schema::table($table, function (Blueprint $blueprint) use ($onDelete) {
                $blueprint->foreign('plan_id')->references('id')->on('plans')->{$onDelete}();
                $blueprint->foreign('product_id')->references('id')->on('products')->{$onDelete}();
            });
        }
    }
};
