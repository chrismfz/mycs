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
            // The order's own invoice (WHMCS's tblorders.invoiceid): what «Cancel & Refund» gives back, nothing later. No
            // key: the importer brings orders before invoices.
            $table->unsignedBigInteger('invoice_id')->nullable()->after('accepted_by_staff_id')->index();
        });
        $this->backfill();
    }

    public function backfill(): void
    {
        // Its own invoice: the first one that billed its services (made with the order).
        foreach (DB::table('orders')->whereNull('invoice_id')->pluck('id') as $order) {
            $first = DB::table('invoice_items')->where('reference_type', 'App\\Models\\Service')
                ->whereIn('reference_id', DB::table('services')->where('order_id', $order)->select('id'))->min('invoice_id');
            if ($first !== null) {
                DB::table('orders')->where('id', $order)->update(['invoice_id' => $first]);
            }
        }
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
            $table->dropIndex(['invoice_id']);
            $table->dropColumn('invoice_id');
            $table->dropConstrainedForeignId('accepted_by_staff_id');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'accepted_at']);
        });
    }
};
