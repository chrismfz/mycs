<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every MCP tool call (docs/mcp.md): who (staff, API key), which tool with which arguments (scrubbed), how it ended and
 * how long it took. Kept 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('tool', 64);
            $table->json('arguments')->nullable();
            $table->string('outcome', 16); // ok, error, denied
            $table->string('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
    }
};
