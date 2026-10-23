<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The staff's own chat (docs/livechat.md §6, §7γ): one shared room now, private threads of two later, on the same
 * tables. A message stays with its author's name when the member leaves (disabled, never deleted, Staff::hasHistory).
 */
return new class extends Migration
{
    public const PERMISSIONS = ['admin.staff_chat.view', 'admin.staff_chat.post'];

    public function up(): void
    {
        Schema::create('staff_threads', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            // `room` for the room, `<low id>-<high id>` for a pair: one thread each, even when two open it at once.
            $table->string('direct_key', 64)->unique();
            $table->timestamps();
        });

        Schema::create('staff_thread_members', function (Blueprint $table) {
            $table->foreignId('thread_id')->constrained('staff_threads')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->primary(['thread_id', 'staff_id']);
        });

        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('staff_threads')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index(['thread_id', 'id']);
        });

        $this->grantToEveryRole();
    }

    /** Each role gets the permissions once; a role with every permission (`*`) needs nothing. */
    public function grantToEveryRole(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            // A role with every permission needs nothing; one left empty on purpose stays empty (review of #373).
            if (!is_array($permissions) || $permissions === [] || in_array('*', $permissions, true)) {
                continue;
            }
            $merged = array_values(array_unique([...$permissions, ...self::PERMISSIONS]));
            if ($merged !== $permissions) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($merged)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
        Schema::dropIfExists('staff_thread_members');
        Schema::dropIfExists('staff_threads');

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (is_array($permissions) && array_intersect(self::PERMISSIONS, $permissions) !== []) {
                $kept = array_values(array_diff($permissions, self::PERMISSIONS));
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($kept)]);
            }
        }
    }
};
