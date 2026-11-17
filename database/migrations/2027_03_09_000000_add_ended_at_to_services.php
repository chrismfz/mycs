<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a service ended (owner 2026-11-17, docs/growth-churn.md Γ1): set when its status turns Terminated or Cancelled
 * (ServiceObserver), cleared when staff bring it back. A service already ended takes the time of the audit that ended it;
 * one ended in WHMCS takes WHMCS's termination_date (app:import-whmcs-end-dates, and the importer); none of either stays
 * empty: ended, not known when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->after('activated_at');
        });

        // The last time it went from running to ended, as ServiceObserver::markEnd stamps it: a move from one ended status
        // to the other is not an end (reviews of #910, R4 and A1). None: not known.
        $like = function ($query, string $column, array $statuses) {
            $query->where(function ($query) use ($column, $statuses) {
                foreach ($statuses as $status) {
                    $query->orWhere($column, 'like', '%"status":"' . $status . '"%');
                }
            });
        };
        foreach (DB::table('services')->whereIn('status', ['terminated', 'cancelled'])->pluck('id')->chunk(500) as $chunk) {
            foreach ($chunk as $id) {
                $at = DB::table('audits')->where('auditable_type', 'App\\Models\\Service')->where('auditable_id', $id)->where('event', 'updated')
                    ->tap(fn ($query) => $like($query, 'new_values', ['terminated', 'cancelled']))
                    ->tap(fn ($query) => $like($query, 'old_values', ['active', 'suspended', 'pending']))
                    ->max('created_at');
                if ($at !== null) {
                    DB::table('services')->where('id', $id)->update(['ended_at' => $at]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('ended_at');
        });
    }
};
