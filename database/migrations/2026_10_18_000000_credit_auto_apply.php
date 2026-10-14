<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/money-trail.md, decision 5 (MT-1e): account credit pays open invoices on its own, also in part. A customer can
 * keep it for something else (users.credit_auto_apply, on by default), and is told when credit paid part of an
 * invoice (notification template credit_applied).
 */
return new class extends Migration
{
    /** The flag is the account's: users then; clients once they are apart (docs/accounts.md, PR 2a), when replayed. */
    private function table(): string
    {
        return Schema::hasTable('clients') ? 'clients' : 'users';
    }

    public function up(): void
    {
        if (!Schema::hasColumn($this->table(), 'credit_auto_apply')) {
            Schema::table($this->table(), function (Blueprint $table) {
                $table->boolean('credit_auto_apply')->default(true);
            });
        }
        DB::table('notification_templates')->insertOrIgnore(
            array_merge(EmailTemplateSeeder::mapping['credit_applied'], ['key' => 'credit_applied', 'enabled' => true])
        );
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'credit_applied')->delete();
        if (Schema::hasColumn($this->table(), 'credit_auto_apply')) {
            Schema::table($this->table(), function (Blueprint $table) {
                $table->dropColumn('credit_auto_apply');
            });
        }
    }
};
