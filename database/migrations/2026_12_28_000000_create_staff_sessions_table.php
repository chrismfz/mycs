<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A member of staff's logins, one per browser session, to see and end them (a lost or shared laptop). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // The session store's id, to destroy it when ended: never shown.
            $table->string('session_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->boolean('remembered')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // Ended with «End» (not a sign-out): what the session approved is dead with it.
            $table->boolean('terminated')->default(false);
            $table->foreignId('ended_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->index(['staff_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_sessions');
    }
};
