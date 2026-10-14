<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer account apart from the login (docs/accounts.md, PR 2a), as WHMCS 8 has `tblclients`, `tblusers` and
 * `tblusers_clients`. Every user today is one account with one login: the account gets the user's id (the WHMCS
 * client id the importer kept) and the user becomes its owner. What belongs to the account moves from `user_id` to
 * `client_id`; what belongs to the login (sessions, 2FA, notifications, who wrote a ticket reply, who moved money)
 * stays on `user_id`.
 */
return new class extends Migration
{
    /**
     * Not in a transaction, with foreign keys off for the schema changes: SQLite rebuilds a table to rename a column
     * or change a foreign key, and with them on the rebuild of a parent deletes its children by cascade (as in the
     * staff split). `PRAGMA foreign_keys = OFF` has no effect inside a transaction.
     */
    public $withinTransaction = false;

    /** Table => what happens to its rows when the account goes (the same as with the user before). */
    private const ACCOUNT_TABLES = [
        'orders' => 'cascade',
        'services' => 'cascade',
        'invoices' => 'null',
        'credits' => 'cascade',
        'money_events' => 'restrict',
        'carts' => 'cascade',
        'billing_agreements' => 'cascade',
        'tickets' => 'cascade',
        'email_logs' => 'null',
        'ext_affiliates' => 'cascade',
    ];

    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            // Credit pays the account's invoices by itself unless the customer keeps it (docs/money-trail.md, MT-1e).
            $table->boolean('credit_auto_apply')->default(true);
            $table->timestamps();
        });

        // Who sees which account (tblusers_clients), with the history of grants (Ekdosi's customer_user_access).
        Schema::create('client_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('owner')->default(false);
            // = client_id for the owner, NULL otherwise: the unique index allows one owner per account (MariaDB has
            // no partial indexes; many NULLs are allowed in MariaDB and SQLite).
            $table->unsignedBigInteger('owner_of_client_id')->nullable()->unique();
            // The WHMCS permission names (profile, invoices, tickets…); NULL = all. The owner always has all.
            $table->json('permissions')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            // Revoked, not deleted: the grant stays as history; only grants with revoked_at NULL count.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
        });

        Schema::withoutForeignKeyConstraints(function () {
            DB::table('clients')->insertUsing(['id', 'credit_auto_apply', 'created_at', 'updated_at'],
                DB::table('users')->select('id', 'credit_auto_apply', 'created_at', 'updated_at'));
            DB::table('client_user')->insertUsing(
                ['client_id', 'user_id', 'owner', 'owner_of_client_id', 'granted_at', 'created_at', 'updated_at'],
                DB::table('users')->selectRaw('id, id, 1, id, created_at, created_at, updated_at'));

            foreach (self::ACCOUNT_TABLES as $name => $onDelete) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, 'user_id')) {
                    $this->repoint($name, 'user_id', 'client_id', 'clients', $onDelete);
                }
            }

            // The account's details (country, address, phone, gateway customer ids…) are the account's.
            DB::table('properties')->where('model_type', 'App\\Models\\User')->update(['model_type' => 'App\\Models\\Client']);
            DB::table('custom_properties')->where('model', 'App\\Models\\User')->update(['model' => 'App\\Models\\Client']);

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('credit_auto_apply');
            });

            // A reply belongs to the account's ticket, which outlives the login that wrote it: deleting a login (one
            // that is not an owner may go) leaves its replies without an author, as staff replies are kept.
            $this->rekeyReplyAuthor('null');
        });

        // Who managed customers (admin.users.*) manages the accounts too; the logins stay admin.users.*.
        $this->grantClientPermissions('roles', ['create', 'update', 'viewAny', 'delete']);
        $this->grantClientPermissions('api_keys', ['create', 'update', 'view', 'delete']);
    }

    /** @param  array<int, string>  $actions */
    private function grantClientPermissions(string $table, array $actions): void
    {
        foreach (DB::table($table)->whereNotNull('permissions')->get(['id', 'permissions']) as $row) {
            $permissions = json_decode((string) $row->permissions, true);
            if (!is_array($permissions)) {
                continue;
            }
            $added = array_values(array_filter(array_map(fn (string $action) => in_array("admin.users.$action", $permissions, true)
                ? "admin.clients.$action" : null, $actions)));
            if ($added !== []) {
                DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$added])))]);
            }
        }
    }

    public function down(): void
    {
        // Each account goes back to the user who owns it: only while every account has an owner and no user owns two
        // (two accounts would merge into one customer). Grants to other users have no place before the split: they go.
        $unowned = DB::table('clients')->whereNotIn('id', DB::table('client_user')->whereNotNull('owner_of_client_id')->select('client_id'))->count();
        $ownersOfMany = DB::table('client_user')->whereNotNull('owner_of_client_id')->select('user_id')->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')->get()->count();
        if ($unowned + $ownersOfMany > 0) {
            throw new RuntimeException('Accounts without an owner, or a user who owns several: cannot roll back.');
        }
        $ownerOf = fn (string $column) => DB::raw("(SELECT user_id FROM client_user WHERE client_user.owner_of_client_id = {$column})");

        Schema::withoutForeignKeyConstraints(function () use ($ownerOf) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('credit_auto_apply')->default(true);
            });
            foreach (DB::table('clients')->join('client_user', 'client_user.owner_of_client_id', '=', 'clients.id')
                ->get(['client_user.user_id', 'clients.credit_auto_apply']) as $row) {
                DB::table('users')->where('id', $row->user_id)->update(['credit_auto_apply' => $row->credit_auto_apply]);
            }
            DB::table('properties')->where('model_type', 'App\\Models\\Client')
                ->update(['model_type' => 'App\\Models\\User', 'model_id' => $ownerOf('properties.model_id')]);
            DB::table('custom_properties')->where('model', 'App\\Models\\Client')->update(['model' => 'App\\Models\\User']);

            foreach (self::ACCOUNT_TABLES as $name => $onDelete) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, 'client_id')) {
                    // Between the two keys, so the values are valid for whichever key is there (also with keys on).
                    $this->repoint($name, 'client_id', 'user_id', 'users', $onDelete,
                        fn () => DB::table($name)->whereNotNull('client_id')->update(['client_id' => $ownerOf("{$name}.client_id")]));
                }
            }

            $this->rekeyReplyAuthor('cascade');
            Schema::dropIfExists('client_user');
            Schema::dropIfExists('clients');
        });

        foreach (['roles', 'api_keys'] as $table) {
            foreach (DB::table($table)->whereNotNull('permissions')->get(['id', 'permissions']) as $row) {
                $permissions = json_decode((string) $row->permissions, true);
                if (is_array($permissions)) {
                    $kept = array_values(array_filter($permissions, fn ($permission) => !str_starts_with((string) $permission, 'admin.clients.')));
                    DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode($kept)]);
                }
            }
        }
    }

    private function rekeyReplyAuthor(string $onDelete): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('ticket_messages', function (Blueprint $table) use ($onDelete) {
            $foreign = $table->foreign('user_id')->references('id')->on('users');
            $onDelete === 'null' ? $foreign->nullOnDelete() : $foreign->cascadeOnDelete();
        });
    }

    private function repoint(string $name, string $from, string $to, string $references, string $onDelete, ?Closure $between = null): void
    {
        Schema::table($name, function (Blueprint $table) use ($from) {
            $table->dropForeign([$from]);
        });
        if ($between !== null) {
            $between();
        }
        Schema::table($name, function (Blueprint $table) use ($from, $to) {
            $table->renameColumn($from, $to);
        });
        Schema::table($name, function (Blueprint $table) use ($to, $references, $onDelete) {
            $foreign = $table->foreign($to)->references('id')->on($references);
            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }
};
