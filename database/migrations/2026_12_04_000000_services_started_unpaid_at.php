<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When staff's Accept started this service not paid yet (owner 2026-10-19: Accept is staff's look at what came in, and
 * leaves a service waiting for its payment unless staff ask; a product set up on Accept is started by it). The cron's
 * «cancel unpaid orders» leaves such a service alone, as it left every accepted order before (decision 4, ADV-4: its
 * create may still be failing). Per service (code review, 2026-10-19: another service of the order, left waiting for its
 * payment, is still cancelled when never paid). The services of orders accepted before this: the old Accept started
 * all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('started_unpaid_at')->nullable();
        });
        DB::table('services')->whereIn('order_id', DB::table('orders')->whereNotNull('accepted_at')->select('id'))
            ->update(['started_unpaid_at' => DB::raw('(select orders.accepted_at from orders where orders.id = services.order_id)')]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('started_unpaid_at');
        });
    }
};
