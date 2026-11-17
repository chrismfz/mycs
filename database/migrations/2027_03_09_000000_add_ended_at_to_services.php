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

        // The first end since it last ran (as ServiceObserver::markEnd keeps it from one ended status to the other; review
        // of #910, R4).
        $audits = fn (int $id, array $statuses) => DB::table('audits')->where('auditable_type', 'App\\Models\\Service')
            ->where('auditable_id', $id)->where('event', 'updated')
            ->where(function ($query) use ($statuses) {
                foreach ($statuses as $status) {
                    $query->orWhere('new_values', 'like', '%"status":"' . $status . '"%');
                }
            });
        foreach (DB::table('services')->whereIn('status', ['terminated', 'cancelled'])->pluck('id')->chunk(500) as $chunk) {
            foreach ($chunk as $id) {
                $ran = $audits((int) $id, ['active', 'suspended', 'pending'])->max('created_at');
                $at = $audits((int) $id, ['terminated', 'cancelled'])->when($ran !== null, fn ($query) => $query->where('created_at', '>', $ran))->min('created_at');
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
