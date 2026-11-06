<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change of package kept as the service's history (owner 2026-11-04, as WHMCS's tblupgrades): what it was before (product,
 * plan, recurring amount), what it renews at after, who asked for it (the customer or staff, and which staff) and when it
 * was applied. Rows from before this have none of it: the service's audit has their old values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->foreignId('from_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->decimal('from_price', 17, 2)->nullable();
            $table->decimal('to_price', 17, 2)->nullable();
            $table->string('source', 16)->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
        });
    }
};
