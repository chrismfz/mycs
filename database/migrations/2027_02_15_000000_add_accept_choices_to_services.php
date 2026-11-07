<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff's choices on an Accept that left the service waiting for its payment (a one-time plan not paid, of a product
 * staff set up: AcceptOrder::waitsForPayment, sweep 3 L-SERVICE-STATE-2 and review REV3-LSS-1): its payment starts it as
 * staff chose then («Run module create», «Send welcome email»), since no second Accept comes. Null: no Accept waits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('accepted_run_create')->nullable();
            $table->boolean('accepted_send_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['accepted_run_create', 'accepted_send_email']);
        });
    }
};
