<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An order's status (Σ1-b2, decision Δ4; WHMCS's tblorders.status: myip 6.850 Active, 196 Cancelled, 2 Fraud): pending
 * until staff accept it or every service of it is set up; cancelled or fraud by staff (or when every service of it
 * ended unpaid). Orders so far: active when none of their services is pending, cancelled when all ended, else pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 16)->default('pending')->after('currency_code')->index();
            $table->timestamp('accepted_at')->nullable()->after('status');
            $table->foreignId('accepted_by_staff_id')->nullable()->after('accepted_at')->constrained('staff')->nullOnDelete();
        });
        $this->backfill();
    }

    public function backfill(): void
    {
        $ended = ['cancelled', 'terminated'];
        DB::table('orders')->whereExists(fn ($q) => $q->from('services')->whereColumn('services.order_id', 'orders.id'))
            ->whereNotExists(fn ($q) => $q->from('services')->whereColumn('services.order_id', 'orders.id')->whereNotIn('status', $ended))
            ->update(['status' => 'cancelled']);
        DB::table('orders')->where('status', 'pending')
            ->whereNotExists(fn ($q) => $q->from('services')->whereColumn('services.order_id', 'orders.id')->where('status', 'pending'))
            ->whereExists(fn ($q) => $q->from('services')->whereColumn('services.order_id', 'orders.id'))
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        // SQLite rebuilds orders to drop a key: the services' key to it, deferred to the commit (a rollback in the tests).
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by_staff_id');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'accepted_at']);
        });
    }
};
