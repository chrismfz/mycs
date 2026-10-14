<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change of billing cycle «from the next renewal» (SVC-14b): the plan the service moves to when its next period is
 * billed. Until then its plan and price stay those of the period it is in (what an upgrade or a credit is priced on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('next_plan_id')->nullable()->after('plan_id')->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_plan_id');
        });
    }
};
