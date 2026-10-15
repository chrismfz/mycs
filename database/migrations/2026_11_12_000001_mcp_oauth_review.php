<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth review (MCPO-4, MCPO-5): every refresh token a grant ever used (any of them coming back revokes the grant, not
 * only the last one), and the grant a code bought (a code that comes back revokes it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_used_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grant_id')->constrained('mcp_oauth_grants')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('created_at');
        });
        Schema::table('mcp_oauth_codes', function (Blueprint $table) {
            $table->foreignId('grant_id')->nullable()->constrained('mcp_oauth_grants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_oauth_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grant_id');
        });
        Schema::dropIfExists('mcp_oauth_used_refresh_tokens');
    }
};
