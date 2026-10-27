<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat with customers (docs/livechat.md §6, step 3α): a chat per conversation, its messages, and who of the staff
 * answers each department's chats (F3: a list of its own, not the department's mail notifications). The customer's side
 * needs the account permission `chat`, given here to the logins that already have `tickets`.
 */
return new class extends Migration
{
    public const PERMISSIONS = ['admin.chats.view', 'admin.chats.reply'];

    public function up(): void
    {
        Schema::create('live_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            // The login that opened it (F10): the account owns the chat, the login wrote its messages.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->string('status', 16)->default('waiting');
            $table->foreignId('assigned_to')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('last_participant_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'department_id']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('live_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('live_chats')->cascadeOnDelete();
            $table->string('author_type', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index(['chat_id', 'id']);
        });

        Schema::create('live_chat_department_staff', function (Blueprint $table) {
            $table->foreignId('ticket_department_id')->constrained('ticket_departments')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->primary(['ticket_department_id', 'staff_id']);
        });

        $this->grantToEveryRole();
        $this->grantChatToTicketLogins();
    }

    /** Each role that has permissions gets the chat's (who answers is the department's list); `*` needs nothing. */
    public function grantToEveryRole(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (!is_array($permissions) || $permissions === [] || in_array('*', $permissions, true)) {
                continue;
            }
            $merged = array_values(array_unique([...$permissions, ...self::PERMISSIONS]));
            if ($merged !== $permissions) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($merged)]);
            }
        }
    }

    /** A login (or a pending invite) with `tickets` may chat too; owners and «all» grants have it already. */
    public function grantChatToTicketLogins(): void
    {
        foreach (['client_user', 'client_invites'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('permissions')->get(['id', 'permissions']) as $row) {
                $permissions = json_decode((string) $row->permissions, true);
                if (is_array($permissions) && in_array('tickets', $permissions, true) && !in_array('chat', $permissions, true)) {
                    $permissions[] = 'chat';
                    DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode(array_values($permissions))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_department_staff');
        Schema::dropIfExists('live_chat_messages');
        Schema::dropIfExists('live_chats');

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (is_array($permissions) && array_intersect(self::PERMISSIONS, $permissions) !== []) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_diff($permissions, self::PERMISSIONS)))]);
            }
        }
        foreach (['client_user', 'client_invites'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('permissions')->get(['id', 'permissions']) as $row) {
                $permissions = json_decode((string) $row->permissions, true);
                if (is_array($permissions) && in_array('chat', $permissions, true)) {
                    DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode(array_values(array_diff($permissions, ['chat'])))]);
                }
            }
        }
    }
};
