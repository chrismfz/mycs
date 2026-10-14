<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inviting a login to a customer account (docs/accounts.md, PR 2b; WHMCS `tbluser_invites`): by its owner from the
 * client area or by staff. Only the token's hash is kept; the invite is used once, expires, and stays as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            // The WHMCS permission names the grant gets; NULL = all.
            $table->json('permissions')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invited_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'email']);
        });

        $key = 'client_invite';
        DB::table('notification_templates')->insertOrIgnore(array_merge(EmailTemplateSeeder::mapping[$key], [
            'key' => $key,
            'enabled' => true,
            'translations' => json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE),
        ]));
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'client_invite')->delete();
        Schema::dropIfExists('client_invites');
    }
};
