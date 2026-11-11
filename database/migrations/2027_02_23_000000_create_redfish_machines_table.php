<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Utilities → Machines (owner 2026-11-11): each Redfish server's BMC as redfish:sync last read it. One row a server,
 * the last read only: no history (graphs and alerts are the monitoring's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redfish_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained('extensions')->cascadeOnDelete();
            $table->string('power_state', 32)->nullable();
            $table->string('health', 16)->nullable();
            $table->unsignedInteger('watts')->nullable();
            $table->smallInteger('inlet_celsius')->nullable();
            // The parts staff should look at, one a line (Machine::attention()).
            $table->text('attention')->nullable();
            // The whole read (Machine::toArray()): the BMC's own text, shortened and stripped by the client.
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redfish_machines');
    }
};
