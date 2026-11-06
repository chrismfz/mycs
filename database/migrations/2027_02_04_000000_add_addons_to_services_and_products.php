<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addons (docs/addons.md, owner 2026-11-04): a service of its own fastened to another (its parent), on a product made for
 * it. A parent deleted leaves its ended addons as services of their own (one with live addons is never deleted:
 * DeleteService). A cancellation request made with its parent's says so: withdrawn with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('parent_service_id')->nullable()->after('order_id')->constrained('services')->nullOnDelete();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_addon')->default(false)->index();
            $table->json('addon_targets')->nullable();
        });
        Schema::table('service_cancellations', function (Blueprint $table) {
            $table->boolean('with_parent')->default(false);
        });
    }
};
