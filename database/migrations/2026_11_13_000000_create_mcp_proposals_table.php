<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the MCP may only propose (docs/mcp.md): a change an agent asks for, which staff approve or reject in the admin
 * (Other → MCP Proposals) within a day. Approved once (an atomic claim), run with the approver's permissions checked
 * again, its result kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('type', 48);
            $table->json('payload');
            $table->string('summary', 500);
            $table->foreignId('proposed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('status', 16)->default('pending')->index(); // pending, rejected, expired, done, failed (approved while it runs)
            $table->foreignId('decided_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('result')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_proposals');
    }
};
