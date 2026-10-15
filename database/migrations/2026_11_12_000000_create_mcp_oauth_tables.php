<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth for the MCP server (docs/mcp.md), staff's own and apart from the customers' Passport: the clients that
 * registered (RFC 7591, public, PKCE), the one-minute codes a consent mints, and the grants staff approved, each with its
 * one access token and its one refresh token (rotated; a replayed one revokes the grant). Only hashes are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('name', 100);
            $table->json('redirect_uris');
            $table->string('registered_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_oauth_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('client_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('mcp_oauth_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // Approved by pasting a key: the key's life is the grant's (disabled, deleted or without the permission: dead).
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->cascadeOnDelete();
            $table->string('approved_by', 16); // session (staff logged in to the admin) or key
            $table->string('access_hash', 64)->nullable()->unique();
            $table->dateTime('access_expires_at')->nullable();
            $table->string('refresh_hash', 64)->nullable()->unique();
            $table->string('previous_refresh_hash', 64)->nullable()->index();
            $table->dateTime('refresh_expires_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_grants');
        Schema::dropIfExists('mcp_oauth_codes');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
