<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An affiliate is the customer account's (docs/accounts.md, PR 2a). The core migration repoints the table when the
 * extension is already enabled; this does it when the extension is enabled later.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('ext_affiliates', 'user_id')) {
            return;
        }
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('ext_affiliates', fn (Blueprint $table) => $table->dropForeign(['user_id']));
            Schema::table('ext_affiliates', fn (Blueprint $table) => $table->renameColumn('user_id', 'client_id'));
            Schema::table('ext_affiliates', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete());
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('ext_affiliates', 'client_id')) {
            return;
        }
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('ext_affiliates', fn (Blueprint $table) => $table->dropForeign(['client_id']));
            // Back to the owner's user id, as the core migration's down() does (ids differ once new accounts are opened).
            if (Schema::hasTable('client_user')) {
                DB::table('ext_affiliates')->update(['client_id' => DB::raw(
                    '(SELECT user_id FROM client_user WHERE client_user.owner_of_client_id = ext_affiliates.client_id)')]);
            }
            Schema::table('ext_affiliates', fn (Blueprint $table) => $table->renameColumn('client_id', 'user_id'));
            Schema::table('ext_affiliates', fn (Blueprint $table) => $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete());
        });
    }
};
