<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Staff online» (docs/livechat.md §7β): each member's own status and when they were last seen; every role may see the
 * list (owner 2026-10-23: we work remotely too, and need to know who is in).
 */
return new class extends Migration
{
    public const PERMISSION = 'admin.staff_online.view';

    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('presence_status', 16)->default('available');
            $table->timestamp('last_seen_at')->nullable();
        });

        $this->grantToEveryRole();
    }

    /** Each role gets the permission once; a role with every permission (`*`) needs nothing. */
    public function grantToEveryRole(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (!is_array($permissions) || in_array('*', $permissions, true) || in_array(self::PERMISSION, $permissions, true)) {
                continue;
            }
            $permissions[] = self::PERMISSION;
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values($permissions))]);
        }
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['presence_status', 'last_seen_at']);
        });

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (is_array($permissions) && in_array(self::PERMISSION, $permissions, true)) {
                $kept = array_values(array_filter($permissions, fn ($name) => $name !== self::PERMISSION));
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($kept)]);
            }
        }
    }
};
