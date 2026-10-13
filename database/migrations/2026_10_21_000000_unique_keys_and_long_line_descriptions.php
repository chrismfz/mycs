<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database guarantees the models assume (audit sweep DATA-11), and line descriptions as long as WHMCS's (DATA-12).
 *
 * - coupons.code unique: a duplicate keeps working under a new code (its id appended); the oldest keeps its own.
 * - service_cancellations.service_id, invoice_snapshots.invoice_id unique (hasOne): the first row of each is kept.
 * - settings: one row per key and scope. MySQL/MariaDB treat NULLs as distinct, so the existing unique key never held
 *   for global settings (settingable NULL), the invoice counter among them: a generated scope column, NULL-free, is
 *   unique with the key. Duplicates go first: of a counter the highest value is kept (a number given is never given
 *   again), of anything else the latest row (the one read last when settings load).
 * - invoice_items.description: text (WHMCS: multi-line hosting, config option and domain descriptions).
 *
 * Credits are unique since MT-1d; invoice_transactions(gateway, transaction_id) belongs to the payments' own work.
 */
return new class extends Migration
{
    private const COUNTERS = ['invoice_number', 'credit_note_number'];

    public function up(): void
    {
        $seen = [];
        foreach (DB::table('coupons')->orderBy('id')->get(['id', 'code']) as $coupon) {
            $key = mb_strtolower((string) $coupon->code);
            if (isset($seen[$key])) {
                DB::table('coupons')->where('id', $coupon->id)->update(['code' => $coupon->code . '-' . $coupon->id]);
            }
            $seen[$key] = true;
        }
        foreach (['service_cancellations' => 'service_id', 'invoice_snapshots' => 'invoice_id'] as $table => $column) {
            $first = DB::table($table)->selectRaw("MIN(id) AS id, {$column}")->groupBy($column)->pluck('id');
            DB::table($table)->whereNotIn('id', $first)->delete();
        }
        $groups = DB::table('settings')->orderBy('id')->get(['id', 'key', 'value', 'settingable_type', 'settingable_id'])
            ->groupBy(fn ($row) => $row->key . '|' . ($row->settingable_type ?? '') . '|' . ($row->settingable_id ?? ''));
        foreach ($groups as $rows) {
            if ($rows->count() < 2) {
                continue;
            }
            $keep = in_array($rows->first()->key, self::COUNTERS, true) && $rows->first()->settingable_type === null
                ? $rows->sortByDesc(fn ($row) => (int) $row->value)->first()
                : $rows->last();
            DB::table('settings')->whereIn('id', $rows->pluck('id')->reject(fn ($id) => $id === $keep->id))->delete();
        }

        Schema::table('coupons', fn (Blueprint $table) => $table->unique('code'));
        Schema::table('service_cancellations', fn (Blueprint $table) => $table->unique('service_id'));
        Schema::table('invoice_snapshots', fn (Blueprint $table) => $table->unique('invoice_id'));
        $scope = DB::getDriverName() === 'sqlite'
            ? "COALESCE(settingable_type, '') || '#' || COALESCE(settingable_id, 0)"
            : "CONCAT(COALESCE(settingable_type, ''), '#', COALESCE(settingable_id, 0))";
        Schema::table('settings', function (Blueprint $table) use ($scope) {
            $table->string('scope', 191)->virtualAs($scope);
            $table->unique(['key', 'scope']);
        });
        Schema::table('invoice_items', fn (Blueprint $table) => $table->text('description')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $table) => $table->string('description')->nullable()->change());
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['key', 'scope']);
            $table->dropColumn('scope');
        });
        // MariaDB/MySQL use the unique key for the foreign key (it replaced the key's own index): an index of its own
        // first, or the unique key cannot be dropped (as in the credit ledger's migration).
        foreach (['invoice_snapshots' => 'invoice_id', 'service_cancellations' => 'service_id'] as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->index($column);
                $blueprint->dropUnique([$column]);
            });
        }
        Schema::table('coupons', fn (Blueprint $table) => $table->dropUnique(['code']));
    }
};
