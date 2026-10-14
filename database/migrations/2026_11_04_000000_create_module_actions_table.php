<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the server modules were asked to do (Σ1, WHMCS's Module Log and Module Queue): one row per action. A failure stays
 * open, its attempts counted, until the action succeeds, an opposite one does (an unsuspend after a failed suspend), or
 * staff mark it resolved: the open ones are the «Pending Module Actions» (myip's tblmodulequeue: 935 in all, 150
 * suspends, 65 creates, 29 terminates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('action', 16); // create | suspend | unsuspend | terminate | upgrade
            $table->string('module', 64)->nullable(); // the server's extension at the last attempt
            $table->string('status', 16); // open | completed | resolved
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('given_up_at')->nullable(); // no automatic retry left: staff's
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution')->nullable(); // succeeded | superseded by … | marked resolved by staff
            $table->foreignId('resolved_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'action']);
            $table->index(['service_id', 'action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_actions');
    }
};
