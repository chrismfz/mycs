<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customers' OAuth server (Laravel Passport, from Paymenter) is gone (security audit AUTHN-10, SA-9; owner's
 * decision 2026-10-23: nothing uses it). Its tables go with it; the MCP's own OAuth (mcp_oauth_*) stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_personal_access_clients', 'oauth_device_codes', 'oauth_clients'] as $table) {
            Schema::dropIfExists($table);
        }

        // Its permissions out of every role: a role still holding one could not be saved (its box is gone, review of
        // #358).
        DB::table('roles')->select(['id', 'permissions'])->orderBy('id')->each(function (object $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (!is_array($permissions)) {
                return;
            }
            $kept = array_values(array_filter($permissions, fn ($permission) => !is_string($permission) || !str_starts_with($permission, 'admin.oauth_clients.')));
            if (count($kept) !== count($permissions)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($kept)]);
            }
        });
    }

    public function down(): void
    {
        // Not brought back: Passport is no longer installed.
    }
};
